#!/usr/bin/python3
"""
PiDoors Access Control - Door Controller
Wiegand-based access control system for Raspberry Pi

Features:
- Wiegand 26, 32, 34, 35, 36, 37, 48-bit card reading
- OSDP encrypted reader support
- NFC/RFID reader support (PN532, MFRC522)
- Local 24-hour cache for offline operation
- Persistent master cards (never expire for emergency access)
- Time-based access schedules
- Anti-passback support
- Door sensor monitoring
- REX (Request to Exit) button support
- Health check/heartbeat to server
- Automatic database reconnection
"""

from datetime import datetime, timedelta
import sys
import os

# Ensure install dir is in sys.path for local imports (formats, readers)
# even when WorkingDirectory is set to a runtime dir for lgpio temp files
_install_dir = os.environ.get('PIDOORS_DIR', '/opt/pidoors')
if _install_dir not in sys.path:
    sys.path.insert(0, _install_dir)

import RPi.GPIO as GPIO
import time
import signal
import json
import threading
import syslog
import socket
import fcntl
import subprocess
import hmac

# Try to import optional dependencies
try:
    import pymysql
    MYSQL_AVAILABLE = True
except ImportError:
    MYSQL_AVAILABLE = False
    print("Warning: pymysql not installed. Database features disabled.")

# Import Wiegand format registry
try:
    from formats.wiegand_formats import FormatRegistry, init_registry
    FORMAT_REGISTRY_AVAILABLE = True
except ImportError:
    FORMAT_REGISTRY_AVAILABLE = False
    print("Warning: Format registry not available. Using legacy format support.")

# Version
def _read_version():
    """Read version from VERSION file, fallback to 'unknown'"""
    version_file = os.path.join(os.environ.get('PIDOORS_DIR', '/opt/pidoors'), 'VERSION')
    try:
        with open(version_file, 'r') as f:
            return f.read().strip()
    except Exception:
        return 'unknown'

VERSION = _read_version()

# Configuration
DEBUG_MODE = False
INSTALL_DIR = os.environ.get('PIDOORS_DIR', '/opt/pidoors')
CONF_DIR = os.path.join(INSTALL_DIR, 'conf') + '/'
CACHE_DIR = os.path.join(INSTALL_DIR, 'cache') + '/'
MASTER_CARDS_FILE = os.path.join(CACHE_DIR, 'master_cards.json')
_OLD_MASTER_CARDS_FILE = os.path.join(CONF_DIR, 'master_cards.json')
CUSTOM_FORMATS_FILE = os.path.join(CONF_DIR, 'formats.json')
SSL_CA_PATH = os.path.join(CONF_DIR, 'ca.pem')
SSL_CA_STALE_PATH = os.path.join(CONF_DIR, 'ca.pem.stale')
CACHE_DURATION = 86400  # 24 hours in seconds
HEARTBEAT_INTERVAL = 300  # seconds (server-initiated pings handle status; heartbeat is safety net)
DB_RETRY_INTERVAL = 30  # seconds

# Global variables
zone = None
config = None
last_card = None
zone_by_pin = {}
repeat_read_count = 0
repeat_read_timeout = time.time()
db_connected = False
last_db_attempt = 0
ssl_mode = None  # None = not determined, 'tls' = using ca.pem, 'plain' = ssl_disabled
local_cache = {}
cache_last_sync = 0
heartbeat_thread = None
command_poll_thread = None
running = True
door_unlocked = False  # Real-time lock state tracking
master_cards = {}  # Persistent master cards (never expire)
format_registry = None  # Wiegand format registry
door_sensor_open = None  # Current door sensor state (None/True/False)
current_sensor_pin = None  # Currently active door sensor GPIO pin

# Thread locks for shared state
state_lock = threading.Lock()  # For db_connected, last_db_attempt, cache_last_sync
cache_lock = threading.Lock()  # For local_cache access
card_lock = threading.Lock()   # For last_card, repeat_read_count, repeat_read_timeout
master_lock = threading.Lock() # For master_cards access
wiegand_lock = threading.Lock() # For legacy Wiegand stream access


def _try_db_connect(kwargs, use_tls):
    """Attempt a database connection with or without TLS."""
    kw = dict(kwargs)
    if use_tls and os.path.isfile(SSL_CA_PATH) and os.path.getsize(SSL_CA_PATH) > 0:
        kw['ssl'] = {'ca': SSL_CA_PATH}
    else:
        kw['ssl_disabled'] = True
    return pymysql.connect(**kw)


def _fetch_server_ca(sqladdr):
    """Download ca.pem from the server's web root. Returns True if saved."""
    import urllib.request
    url = f"http://{sqladdr}/ca.pem"
    try:
        req = urllib.request.Request(url, method='GET')
        with urllib.request.urlopen(req, timeout=10) as resp:
            data = resp.read()
            # Sanity check: must look like a PEM certificate
            if b'-----BEGIN CERTIFICATE-----' not in data:
                debug(f"TLS: {url} returned non-certificate data, ignoring")
                return False
            with open(SSL_CA_PATH, 'wb') as f:
                f.write(data)
            # Fix ownership if running as root (install/update context)
            try:
                import pwd
                pw = pwd.getpwnam('pidoors')
                os.chown(SSL_CA_PATH, pw.pw_uid, pw.pw_gid)
            except Exception:
                pass
            os.chmod(SSL_CA_PATH, 0o600)
            report(f"TLS: downloaded fresh ca.pem from server")
            return True
    except Exception as e:
        debug(f"TLS: could not fetch {url}: {e}")
        return False


def get_db_connection(timeout=5):
    """
    Create a database connection with self-healing TLS.

    Flow:
    1. If ssl_mode is already determined, use it directly.
    2. If ca.pem exists, try TLS. On SSL error:
       a. Fetch fresh ca.pem from http://<server>/ca.pem
       b. If new cert works, use TLS going forward.
       c. If fetch fails or still errors, fall back to plain.
    3. If no ca.pem, try to fetch one from server.
       If fetched, use TLS. Otherwise, use plain.
    """
    global ssl_mode

    if not MYSQL_AVAILABLE:
        return None

    zone_config = config.get(zone, {})
    sqladdr = zone_config.get("sqladdr")
    sqluser = zone_config.get("sqluser")
    sqlpass = zone_config.get("sqlpass")
    sqldb = zone_config.get("sqldb")

    if not all([sqladdr, sqluser, sqlpass, sqldb]):
        return None

    kwargs = {
        'host': sqladdr,
        'user': sqluser,
        'password': sqlpass,
        'database': sqldb,
        'connect_timeout': timeout,
    }

    # Fast path: mode already determined from a previous connection
    if ssl_mode == 'tls':
        try:
            return _try_db_connect(kwargs, use_tls=True)
        except Exception as e:
            err = str(e).upper()
            if 'SSL' in err or 'CERTIFICATE' in err or 'TLS' in err:
                report(f"TLS: connection failed with cached cert, re-negotiating: {e}")
                ssl_mode = None  # Reset and fall through to negotiation
            else:
                raise  # Non-TLS error, propagate normally

    if ssl_mode == 'plain':
        return _try_db_connect(kwargs, use_tls=False)

    # ── First connection or re-negotiation ──

    has_cert = os.path.isfile(SSL_CA_PATH) and os.path.getsize(SSL_CA_PATH) > 0

    # Step 1: If we have a cert, try TLS
    if has_cert:
        try:
            conn = _try_db_connect(kwargs, use_tls=True)
            ssl_mode = 'tls'
            report("TLS: connected with existing ca.pem")
            return conn
        except Exception as e:
            err = str(e).upper()
            if 'SSL' not in err and 'CERTIFICATE' not in err and 'TLS' not in err:
                raise  # Non-TLS error, don't try to heal

            report(f"TLS: existing ca.pem failed: {e}")

            # Move stale cert out of the way
            try:
                os.rename(SSL_CA_PATH, SSL_CA_STALE_PATH)
                debug("TLS: moved stale ca.pem to ca.pem.stale")
            except OSError:
                pass

    # Step 2: Try to fetch a (fresh) cert from the server
    if _fetch_server_ca(sqladdr):
        try:
            conn = _try_db_connect(kwargs, use_tls=True)
            ssl_mode = 'tls'
            report("TLS: connected with fresh ca.pem from server")
            # Clean up stale cert
            try:
                os.remove(SSL_CA_STALE_PATH)
            except OSError:
                pass
            return conn
        except Exception as e:
            report(f"TLS: fresh ca.pem also failed: {e}")
            # Remove the bad cert
            try:
                os.remove(SSL_CA_PATH)
            except OSError:
                pass

    # Step 3: Fall back to plain (no TLS)
    try:
        conn = _try_db_connect(kwargs, use_tls=False)
        ssl_mode = 'plain'
        report("TLS: server has no TLS, using plain connection")
        return conn
    except Exception:
        raise  # Genuine connection failure, propagate


def get_local_ip():
    """Get the local IP address of this device"""
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(('1.1.1.1', 53))
        ip = s.getsockname()[0]
        s.close()
        return ip
    except Exception:
        return '127.0.0.1'


myip = '127.0.0.1'


def initialize():
    """Initialize the access control system"""
    global running, format_registry, myip
    running = True

    # Detect local IP (network should be ready by now, unlike module load time)
    myip = get_local_ip()

    GPIO.setmode(GPIO.BCM)
    GPIO.setwarnings(False)
    syslog.openlog("accesscontrol", syslog.LOG_PID, syslog.LOG_AUTH)

    report("Initializing PiDoors Access Control")

    # Ensure cache directory exists
    os.makedirs(CACHE_DIR, exist_ok=True)

    # Load configurations
    read_configs()

    # Initialize format registry with optional custom formats
    if FORMAT_REGISTRY_AVAILABLE:
        custom_formats = CUSTOM_FORMATS_FILE if os.path.exists(CUSTOM_FORMATS_FILE) else None
        format_registry = init_registry(custom_formats)
        supported = format_registry.get_supported_lengths()
        report(f"Wiegand format registry initialized: {supported}")
    else:
        report("Using legacy Wiegand format support (26/34-bit only)")

    # Load local cache and persistent master cards
    load_cache()
    load_master_cards()

    # Setup GPIO
    setup_output_GPIOs()
    setup_readers()
    setup_door_sensor()
    setup_rex_button()

    # Initial door state: locked
    lock_door()

    # Catch exit signals
    signal.signal(signal.SIGINT, cleanup)
    signal.signal(signal.SIGTERM, cleanup)
    signal.signal(signal.SIGHUP, rehash)
    signal.signal(signal.SIGUSR2, rehash)
    signal.signal(signal.SIGWINCH, toggle_debug)

    # Start heartbeat thread
    start_heartbeat_thread()

    # Start command poll thread (lightweight fast-poll for remote unlock)
    start_command_poll_thread()

    # Start push listener (HTTPS server for instant commands from server)
    start_push_listener()

    # Sync cache from server
    sync_cache_from_server()

    report(f"{zone} access control is online (IP: {myip}, version: {VERSION})")


def report(subject):
    """Log to syslog and debug output"""
    syslog.syslog(subject)
    debug(subject)


def debug(message):
    """Print debug message if debug mode is enabled"""
    if DEBUG_MODE:
        timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        print(f"[{timestamp}] {message}")


def rehash(sig=None, frame=None):
    """Reload configurations and sync cache"""
    report("Rehashing configuration")
    read_configs()
    sync_cache_from_server()


def toggle_debug(sig=None, frame=None):
    """Toggle debug mode on/off"""
    global DEBUG_MODE
    DEBUG_MODE = not DEBUG_MODE
    debug(f"Debug mode: {'enabled' if DEBUG_MODE else 'disabled'}")


def read_configs():
    """Load configuration files"""
    global zone, config
    try:
        jzone = load_json(CONF_DIR + "zone.json")
        config = load_json(CONF_DIR + "config.json")
        zone = jzone.get("zone", "default")
        debug(f"Configuration loaded for zone: {zone}")
    except Exception as e:
        report(f"Error loading configuration: {e}")
        sys.exit(1)


def load_json(filename):
    """Load and parse a JSON file"""
    with open(filename, 'r') as f:
        return json.load(f)


def save_json(filename, data):
    """Save data to a JSON file"""
    with open(filename, 'w') as f:
        json.dump(data, f, indent=2, default=str)


# ============================================================
# LOCAL CACHE MANAGEMENT (24-hour offline capability)
# ============================================================

def get_cache_file():
    """Get the path to the cache file for this zone"""
    return os.path.join(CACHE_DIR, f"{zone}_access_cache.json")


def load_cache():
    """Load the local access cache from disk"""
    global local_cache, cache_last_sync
    cache_file = get_cache_file()

    try:
        if os.path.exists(cache_file):
            with open(cache_file, 'r') as f:
                cache_data = json.load(f)
                cache_last_sync = cache_data.get('sync_time', 0)

                # New flat format has 'schedules' at top level alongside 'cards'
                if 'schedules' in cache_data:
                    local_cache = cache_data
                else:
                    # Legacy format: full cache nested under 'cards' key
                    local_cache = cache_data.get('cards', {})

                # Check if cache is still valid (within 24 hours)
                if time.time() - cache_last_sync > CACHE_DURATION:
                    report("Local cache expired (>24 hours old)")
                else:
                    card_count = len(local_cache.get('cards', {}))
                    report(f"Loaded {card_count} cards from local cache")
    except Exception as e:
        report(f"Error loading cache: {e}")
        local_cache = {}
        cache_last_sync = 0


def save_cache():
    """Save the local access cache to disk"""
    global cache_last_sync
    cache_file = get_cache_file()

    try:
        # Save local_cache structure (cards, schedules, holidays, door_settings)
        # with metadata at the same level
        cache_data = dict(local_cache)
        cache_data['zone'] = zone
        cache_data['sync_time'] = time.time()
        cache_data['sync_datetime'] = datetime.now().isoformat()
        save_json(cache_file, cache_data)
        cache_last_sync = cache_data['sync_time']
        debug(f"Cache saved with {len(local_cache.get('cards', {}))} cards")
    except Exception as e:
        report(f"Error saving cache: {e}")


# ============================================================
# MASTER CARD MANAGEMENT (Persistent - Never Expires)
# ============================================================

def load_master_cards():
    """
    Load master cards from persistent storage.
    Master cards never expire - they are used for emergency access.
    """
    global master_cards

    try:
        # Migrate from old location (conf/) to new location (cache/) if needed
        if not os.path.exists(MASTER_CARDS_FILE) and os.path.exists(_OLD_MASTER_CARDS_FILE):
            try:
                import shutil
                shutil.copy2(_OLD_MASTER_CARDS_FILE, MASTER_CARDS_FILE)
                report("Migrated master_cards.json from conf/ to cache/")
            except Exception as e:
                report(f"Could not migrate master_cards.json: {e}")

        if os.path.exists(MASTER_CARDS_FILE):
            with open(MASTER_CARDS_FILE, 'r') as f:
                data = json.load(f)
                with master_lock:
                    master_cards = data.get('cards', {})
                card_count = len(master_cards)
                if card_count > 0:
                    report(f"Loaded {card_count} master cards from persistent storage")
    except Exception as e:
        report(f"Error loading master cards: {e}")
        with master_lock:
            master_cards = {}


def save_master_cards():
    """Save master cards to persistent storage"""
    try:
        with master_lock:
            data = {
                'last_sync': datetime.now().isoformat(),
                'cards': dict(master_cards)
            }
        save_json(MASTER_CARDS_FILE, data)
        debug(f"Master cards saved: {len(master_cards)} cards")
    except Exception as e:
        report(f"Error saving master cards: {e}")


def sync_master_cards_from_db(cursor):
    """
    Sync master cards from database.
    Called during cache sync - updates local persistent storage.
    Removes cards that have been deleted from the database.
    """
    global master_cards

    try:
        cursor.execute("SELECT card_id, user_id, facility, description, active FROM master_cards WHERE active = 1")
        db_master_cards = cursor.fetchall()

        new_master_cards = {}
        for mc in db_master_cards:
            key = f"{mc['facility']},{mc['user_id']}"
            new_master_cards[key] = {
                'card_id': mc['card_id'],
                'user_id': mc['user_id'],
                'facility': mc['facility'],
                'description': mc.get('description', 'Master Card')
            }

        with master_lock:
            # Log changes
            old_keys = set(master_cards.keys())
            new_keys = set(new_master_cards.keys())

            added = new_keys - old_keys
            removed = old_keys - new_keys

            if added:
                report(f"Master cards added: {len(added)}")
            if removed:
                report(f"Master cards revoked: {len(removed)}")

            master_cards = new_master_cards

        save_master_cards()
        debug(f"Master cards synced: {len(new_master_cards)} active cards")

    except Exception as e:
        report(f"Error syncing master cards: {e}")


def verify_master_card_in_db(cursor, facility, user_id, card_id):
    """
    Verify a master card is still active in the database.
    Called when database is available during access attempt.
    Returns True if card is still valid, False if revoked.
    """
    try:
        cursor.execute("""
            SELECT active FROM master_cards
            WHERE user_id = %s AND card_id = %s AND facility = %s
        """, (user_id, card_id, facility))
        result = cursor.fetchone()

        if result and result.get('active', 0) == 1:
            return True

        # Card not found or inactive - remove from local storage
        key = f"{facility},{user_id}"
        with master_lock:
            if key in master_cards:
                report(f"Master card revoked: {key}")
                del master_cards[key]
                save_master_cards()

        return False

    except Exception as e:
        debug(f"Error verifying master card: {e}")
        # On error, allow access (fail-open for master cards only)
        return True


def is_master_card(facility, user_id):
    """Check if a card is a master card (from local persistent storage)"""
    key = f"{facility},{user_id}"
    with master_lock:
        return master_cards.get(key)


def get_master_card_info(facility, user_id):
    """Get master card info if exists"""
    key = f"{facility},{user_id}"
    with master_lock:
        if key in master_cards:
            return dict(master_cards[key])
    return None


def verify_master_card_online(card_id, facility, user_id):
    """
    Verify master card is still active in database (when online).
    Returns True if valid, False if revoked.
    On database error, returns True (fail-open for emergency access).
    """
    global db_connected

    db = None
    try:
        db = get_db_connection(timeout=3)
        if db is None:
            return True  # Can't verify, allow access

        cursor = db.cursor(pymysql.cursors.DictCursor)

        result = verify_master_card_in_db(cursor, facility, user_id, card_id)
        return result

    except Exception as e:
        debug(f"Master card verification error: {e}")
        # On error, allow access (emergency access must work)
        return True
    finally:
        if db:
            try:
                db.close()
            except Exception:
                pass


def sync_cache_from_server():
    """Sync the local cache from the database server"""
    global local_cache, cache_last_sync, db_connected

    if not MYSQL_AVAILABLE:
        return

    db = None
    try:
        db = get_db_connection(timeout=10)
        if db is None:
            debug("Database configuration incomplete")
            return

        cursor = db.cursor(pymysql.cursors.DictCursor)

        # Fetch all cards that have access to this zone
        # Use FIND_IN_SET for proper comma-delimited matching (prevents "main" matching "maintenance")
        sql = """
            SELECT card_id, user_id, facility, bstr, firstname, lastname,
                   doors, active, group_id, schedule_id, valid_from, valid_until,
                   daily_scan_limit
            FROM cards
            WHERE active = 1
              AND (FIND_IN_SET(%s, doors) > 0 OR doors = '*')
        """
        cursor.execute(sql, (zone,))
        cards = cursor.fetchall()

        # Fetch schedules
        cursor.execute("SELECT * FROM access_schedules")
        schedules = {s['id']: s for s in cursor.fetchall()}

        # Sync master cards to persistent storage (never expires)
        sync_master_cards_from_db(cursor)

        # Fetch holidays
        cursor.execute("SELECT * FROM holidays WHERE date >= CURDATE()")
        holidays = cursor.fetchall()

        # Fetch door settings for this zone
        cursor.execute("SELECT * FROM doors WHERE name = %s", (zone,))
        door_info = cursor.fetchone()

        with state_lock:
            db_connected = True

        # Build the cache (master cards are stored separately in persistent storage)
        new_cache = {
            'schedules': schedules,
            'holidays': [{'date': str(h['date']), 'name': h['name'], 'access_denied': h['access_denied'], 'recurring': h.get('recurring', 0)}
                        for h in holidays],
            'door_settings': door_info,
            'cards': {}
        }

        # Add regular cards to cache
        for card in cards:
            key = f"{card['facility']},{card['user_id']}"
            new_cache['cards'][key] = {
                'card_id': card['card_id'],
                'firstname': card['firstname'],
                'lastname': card['lastname'],
                'doors': card['doors'],
                'schedule_id': card['schedule_id'],
                'valid_from': str(card['valid_from']) if card['valid_from'] else None,
                'valid_until': str(card['valid_until']) if card['valid_until'] else None,
                'daily_scan_limit': card.get('daily_scan_limit')
            }

        with cache_lock:
            local_cache = new_cache
        save_cache()
        report(f"Cache synced from server: {len(new_cache['cards'])} cards")

    except pymysql.Error as e:
        with state_lock:
            db_connected = False
        report(f"Database sync failed: {e}")
        debug("Will use local cache for access decisions")
    except Exception as e:
        with state_lock:
            db_connected = False
        report(f"Cache sync error: {e}")
    finally:
        if db:
            try:
                db.close()
            except Exception:
                pass


def is_cache_valid():
    """Check if the local cache is still valid (within 24 hours)"""
    return cache_last_sync > 0 and (time.time() - cache_last_sync) < CACHE_DURATION


# ============================================================
# GPIO SETUP
# ============================================================

def setup_output_GPIOs():
    """Setup output GPIO pins for door lock and status LEDs"""
    zone_config = config.get(zone, {})
    latch_gpio = zone_config.get("latch_gpio")

    # Status LEDs must be set up BEFORE lock_door() which uses them
    GPIO.setup(25, GPIO.OUT)  # Green LED / Granted
    GPIO.setup(22, GPIO.OUT)  # Red LED / Denied

    if latch_gpio:
        zone_by_pin[latch_gpio] = zone
        GPIO.setup(latch_gpio, GPIO.OUT)
        lock_door()
    else:
        # No latch configured - just set LED initial state
        GPIO.output(25, 0)
        GPIO.output(22, 1)


def setup_readers():
    """Setup Wiegand reader GPIO pins"""
    for name in config:
        if name == "<zone>" or not isinstance(config[name], dict):
            continue

        reader = config[name]
        if reader.get("d0") and reader.get("d1"):
            reader["stream"] = ""
            reader["timer"] = None
            reader["name"] = name
            reader["unlocked"] = False

            zone_by_pin[reader["d0"]] = name
            zone_by_pin[reader["d1"]] = name

            GPIO.setup(reader["d0"], GPIO.IN)
            GPIO.setup(reader["d1"], GPIO.IN)

            GPIO.add_event_detect(reader["d0"], GPIO.FALLING, callback=data_pulse)
            GPIO.add_event_detect(reader["d1"], GPIO.FALLING, callback=data_pulse)

            debug(f"Reader configured on GPIO {reader['d0']}/{reader['d1']}")


def _read_door_sensor(pin):
    """Read door sensor state, accounting for invert setting.
    Default: LOW (grounded) = closed, HIGH = open.
    With invert: LOW = open, HIGH = closed.
    Returns True if door is open, False if closed."""
    raw = GPIO.input(pin)
    inverted = config.get(zone, {}).get("door_sensor_invert", False)
    if inverted:
        return raw == GPIO.LOW   # Inverted: grounded = open
    return raw == GPIO.HIGH      # Normal: grounded = closed, not grounded = open


def setup_door_sensor():
    """Setup door sensor GPIO pin (optional, from zone config for first boot)"""
    global current_sensor_pin, door_sensor_open
    zone_config = config.get(zone, {})
    door_sensor_gpio = zone_config.get("door_sensor_gpio")

    if door_sensor_gpio:
        door_sensor_gpio = int(door_sensor_gpio)
        GPIO.setup(door_sensor_gpio, GPIO.IN, pull_up_down=GPIO.PUD_UP)
        GPIO.add_event_detect(door_sensor_gpio, GPIO.BOTH,
                             callback=door_sensor_event, bouncetime=200)
        current_sensor_pin = door_sensor_gpio
        door_sensor_open = _read_door_sensor(door_sensor_gpio)
        debug(f"Door sensor configured on GPIO {door_sensor_gpio}")


def reconfigure_door_sensor(new_pin):
    """Reconfigure door sensor GPIO at runtime (called when DB pin changes)"""
    global current_sensor_pin, door_sensor_open

    # Remove old pin detection
    if current_sensor_pin is not None:
        try:
            GPIO.remove_event_detect(current_sensor_pin)
        except Exception:
            pass
        debug(f"Door sensor removed from GPIO {current_sensor_pin}")

    current_sensor_pin = new_pin
    if new_pin is not None:
        new_pin = int(new_pin)
        GPIO.setup(new_pin, GPIO.IN, pull_up_down=GPIO.PUD_UP)
        GPIO.add_event_detect(new_pin, GPIO.BOTH,
                             callback=door_sensor_event, bouncetime=200)
        current_sensor_pin = new_pin
        door_sensor_open = _read_door_sensor(new_pin)
        debug(f"Door sensor reconfigured to GPIO {new_pin}")
    else:
        door_sensor_open = None
        debug("Door sensor disabled")


def setup_rex_button():
    """Setup REX (Request to Exit) button GPIO pin (optional)"""
    zone_config = config.get(zone, {})
    rex_gpio = zone_config.get("rex_gpio")

    if rex_gpio:
        GPIO.setup(rex_gpio, GPIO.IN, pull_up_down=GPIO.PUD_UP)
        GPIO.add_event_detect(rex_gpio, GPIO.FALLING,
                             callback=rex_button_pressed, bouncetime=500)
        debug(f"REX button configured on GPIO {rex_gpio}")


def door_sensor_event(channel):
    """Handle door sensor state change"""
    global door_sensor_open
    door_open = _read_door_sensor(channel)
    door_sensor_open = door_open

    if door_open:
        report(f"Door opened at {zone}")
        log_door_event('door_opened')
    else:
        report(f"Door closed at {zone}")
        log_door_event('door_closed')


def rex_button_pressed(channel):
    """Handle REX button press - unlock door temporarily"""
    report(f"REX button pressed at {zone}")
    log_door_event('rex_activated')
    zone_config = config.get(zone, {})
    latch_gpio = zone_config.get("latch_gpio")
    if latch_gpio:
        unlock_briefly(latch_gpio)
    else:
        debug(f"REX: latch_gpio not configured for zone {zone}")


# ============================================================
# DOOR LOCK CONTROL
# ============================================================

def lock_door():
    """Lock the door"""
    global door_unlocked
    zone_config = config.get(zone, {})
    latch_gpio = zone_config.get("latch_gpio")
    unlock_value = zone_config.get("unlock_value", 1)

    if latch_gpio:
        GPIO.output(latch_gpio, unlock_value ^ 1)
        GPIO.output(25, 0)  # Green LED off
        GPIO.output(22, 1)  # Red LED on
        with state_lock:
            door_unlocked = False


def unlock_door():
    """Unlock the door"""
    global door_unlocked
    zone_config = config.get(zone, {})
    latch_gpio = zone_config.get("latch_gpio")
    unlock_value = zone_config.get("unlock_value", 1)

    if latch_gpio:
        GPIO.output(latch_gpio, unlock_value)
        GPIO.output(25, 1)  # Green LED on
        GPIO.output(22, 0)  # Red LED off
        with state_lock:
            door_unlocked = True


def unlock_briefly(gpio):
    """Unlock the door temporarily using DB unlock_duration, falling back to config open_delay"""
    # Priority: DB door setting > config.json open_delay > default 5
    unlock_time = None
    with cache_lock:
        door_settings = local_cache.get('door_settings')
        if door_settings and door_settings.get('unlock_duration'):
            unlock_time = int(door_settings['unlock_duration'])

    if not unlock_time:
        zone_config = config.get(zone, {})
        unlock_time = zone_config.get("open_delay", 5)

    debug(f"Unlocking for {unlock_time} seconds")
    # Set unlocked state immediately so poll loop sees it right away
    unlock_door()
    def _do_relock():
        time.sleep(unlock_time)
        lock_door()
    threading.Thread(target=_do_relock, daemon=True).start()


# ============================================================
# WIEGAND CARD READING
# ============================================================

def data_pulse(channel):
    """Handle Wiegand data pulse"""
    reader_name = zone_by_pin.get(channel)
    if not reader_name:
        return

    reader = config[reader_name]

    with wiegand_lock:
        if channel == reader["d0"]:
            reader["stream"] += "0"
        elif channel == reader["d1"]:
            reader["stream"] += "1"

        # Start timer if not already running
        if reader["timer"] is None:
            reader["timer"] = threading.Timer(0.2, wiegand_stream_done, args=[reader])
            reader["timer"].start()


def wiegand_stream_done(reader):
    """Process completed Wiegand stream"""
    with wiegand_lock:
        if reader["stream"] == "":
            reader["timer"] = None
            return

        bitstring = reader["stream"]
        reader["stream"] = ""
        reader["timer"] = None

    # Process outside the lock
    validate_bits(bitstring)


def validate_bits(bstr):
    """Validate Wiegand bit stream and extract card data using format registry"""
    bit_len = len(bstr)

    # Use format registry if available
    if FORMAT_REGISTRY_AVAILABLE and format_registry:
        result = format_registry.validate(bstr)
        if result:
            card_id, facility, user_id = result
            fmt = format_registry.get_format(bit_len)
            debug(f"{bit_len}-bit card ({fmt.name}): facility={facility} user={user_id} card_id={card_id}")
            lookup_card(card_id, facility, user_id, bstr)
            return True
        elif format_registry.get_format(bit_len):
            # Format is supported but validation failed (parity error)
            debug(f"Parity error in {bit_len}-bit Wiegand stream")
            return False
        else:
            debug(f"Unsupported Wiegand format: {bit_len} bits")
            return False

    # Legacy fallback: Support 26-bit and 34-bit only
    if bit_len == 26:
        return validate_26bit_legacy(bstr)
    elif bit_len == 34:
        return validate_34bit_legacy(bstr)
    else:
        debug(f"Unsupported Wiegand format: {bit_len} bits (use format registry for more formats)")
        return False


def validate_26bit_legacy(bstr):
    """Validate and decode 26-bit Wiegand format (legacy fallback)"""
    lparity = int(bstr[0])
    facility = int(bstr[1:9], 2)
    user_id = int(bstr[9:25], 2)
    rparity = int(bstr[25])

    # Calculate expected parities
    calc_lparity = 0
    calc_rparity = 1
    for i in range(12):
        calc_lparity ^= int(bstr[i + 1])
        calc_rparity ^= int(bstr[i + 13])

    if calc_lparity != lparity or calc_rparity != rparity:
        debug("Parity error in 26-bit Wiegand stream")
        return False

    card_id = "%08x" % int(bstr, 2)
    debug(f"26-bit card: facility={facility} user={user_id} card_id={card_id}")

    lookup_card(card_id, str(facility), str(user_id), bstr)
    return True


def validate_34bit_legacy(bstr):
    """Validate and decode 34-bit Wiegand format (legacy fallback)"""
    lparity = int(bstr[0])
    facility = int(bstr[1:17], 2)
    user_id = int(bstr[17:33], 2)
    rparity = int(bstr[33])

    # Calculate expected parities (even parity for first half, odd for second)
    calc_lparity = 0
    calc_rparity = 1
    for i in range(16):
        calc_lparity ^= int(bstr[i + 1])
        calc_rparity ^= int(bstr[i + 17])

    if calc_lparity != lparity or calc_rparity != rparity:
        debug("Parity error in 34-bit Wiegand stream")
        return False

    card_id = "%09x" % int(bstr, 2)
    debug(f"34-bit card: facility={facility} user={user_id} card_id={card_id}")

    lookup_card(card_id, str(facility), str(user_id), bstr)
    return True


# ============================================================
# ACCESS CONTROL LOGIC
# ============================================================

def count_todays_granted_scans(user_id):
    """Count today's granted scans for a user from the local access log (cache fallback)"""
    log_file = os.path.join(CACHE_DIR, f"{zone}_access_log.json")
    today_str = datetime.now().strftime('%Y-%m-%d')
    count = 0

    try:
        if os.path.exists(log_file):
            with open(log_file, 'r') as f:
                fcntl.flock(f.fileno(), fcntl.LOCK_SH)
                try:
                    logs = json.load(f)
                    for entry in logs:
                        if (entry.get('user_id') == user_id
                                and entry.get('granted')
                                and entry.get('timestamp', '').startswith(today_str)):
                            count += 1
                finally:
                    fcntl.flock(f.fileno(), fcntl.LOCK_UN)
    except Exception as e:
        debug(f"Error counting daily scans: {e}")

    return count


def lookup_card(card_id, facility, user_id, bstr):
    """Look up card and determine if access should be granted"""
    global db_connected

    now = datetime.now()
    card_key = f"{facility},{user_id}"

    debug(f"Looking up card: {card_key}")

    # First check: Master cards (from persistent storage - never expires)
    master_info = get_master_card_info(facility, user_id)
    if master_info:
        # Master card found in local storage
        # If database is available, verify it's still active
        if MYSQL_AVAILABLE and db_connected:
            if not verify_master_card_online(card_id, facility, user_id):
                # Master card was revoked - deny access
                reject_card(user_id, "Master card revoked")
                log_access(user_id, card_id, facility, False, "Master card revoked")
                return

        # Grant master card access
        description = master_info.get('description', 'Master')
        report(f"Master card access granted: {description}")
        open_door(user_id, "Master", is_master=True)
        log_access(user_id, card_id, facility, True, "Master card")
        return

    # Try database lookup first (if available)
    access_granted = False
    access_reason = ""

    if MYSQL_AVAILABLE and try_database_lookup(card_id, facility, user_id, bstr, now):
        return  # Database handled it

    # Fall back to local cache
    if is_cache_valid():
        debug("Using local cache for access decision")
        with cache_lock:
            cached_card = local_cache.get('cards', {}).get(card_key)
            # Make a copy to avoid holding the lock during processing
            if cached_card:
                cached_card = dict(cached_card)

        if cached_card:
            # Check if card has access to this zone
            # Use proper comma-delimited matching (prevents "main" matching "maintenance")
            doors = cached_card.get('doors', '')
            door_list = [d.strip() for d in doors.split(',') if d.strip()]
            if zone in door_list or doors == '*':
                # Check validity dates with error handling
                valid_from = cached_card.get('valid_from')
                valid_until = cached_card.get('valid_until')

                try:
                    if valid_from and now.date() < datetime.strptime(valid_from, '%Y-%m-%d').date():
                        access_reason = "Card not yet valid"
                    elif valid_until and now.date() > datetime.strptime(valid_until, '%Y-%m-%d').date():
                        access_reason = "Card expired"
                    elif check_schedule(cached_card.get('schedule_id'), now):
                        if is_holiday_denied(now):
                            access_reason = "Access denied on holiday"
                        elif cached_card.get('daily_scan_limit') and int(cached_card['daily_scan_limit']) > 0:
                            # Check daily scan count from local access log
                            limit = int(cached_card['daily_scan_limit'])
                            today_count = count_todays_granted_scans(user_id)
                            if today_count >= limit:
                                access_reason = f"Daily scan limit reached ({today_count}/{limit})"
                            else:
                                access_granted = True
                                access_reason = "Cached access granted"
                        else:
                            access_granted = True
                            access_reason = "Cached access granted"
                    else:
                        access_reason = "Outside scheduled hours"
                except ValueError as e:
                    debug(f"Date parsing error: {e}")
                    access_reason = "Invalid date format in card data"
            else:
                access_reason = "No access to this door"
        else:
            access_reason = "Card not in cache"

        if access_granted:
            name = f"{cached_card.get('firstname', '')} {cached_card.get('lastname', '')}".strip() or user_id
            open_door(user_id, name)
            log_access(user_id, card_id, facility, True, access_reason)
        else:
            reject_card(user_id, access_reason)
            log_access(user_id, card_id, facility, False, access_reason)
    else:
        # No valid cache available
        report("WARNING: No valid cache and database unavailable!")
        reject_card(user_id, "System offline - no cached access data")
        log_access(user_id, card_id, facility, False, "Cache expired/unavailable")


def try_database_lookup(card_id, facility, user_id, bstr, now):
    """Try to look up card in the database"""
    global db_connected, last_db_attempt

    if not MYSQL_AVAILABLE:
        return False

    # Rate limit database connection attempts
    with state_lock:
        if not db_connected and (time.time() - last_db_attempt) < DB_RETRY_INTERVAL:
            return False
        last_db_attempt = time.time()

    db = None
    result = False
    try:
        db = get_db_connection(timeout=5)
        if db is None:
            return False

        cursor = db.cursor(pymysql.cursors.DictCursor)
        with state_lock:
            db_connected = True

        # Check for master card first
        cursor.execute("""
            SELECT * FROM master_cards
            WHERE user_id = %s AND card_id = %s AND facility = %s AND active = 1
        """, (user_id, card_id, facility))

        if cursor.fetchone():
            report("Master card access via database")
            open_door(user_id, "Master", is_master=True)
            cursor.execute("""
                INSERT INTO logs (user_id, Date, Granted, Location, doorip)
                VALUES (%s, %s, 1, %s, %s)
            """, (user_id, now, zone, myip))
            db.commit()
            return True

        # Look up regular card
        cursor.execute("""
            SELECT * FROM cards
            WHERE user_id = %s AND card_id = %s AND facility = %s
        """, (user_id, card_id, facility))

        card = cursor.fetchone()

        if card:
            granted = False
            reason = ""

            # Use proper comma-delimited matching (prevents "main" matching "maintenance")
            card_doors = card.get('doors', '')
            card_door_list = [d.strip() for d in card_doors.split(',') if d.strip()]
            has_door_access = zone in card_door_list or card_doors == '*'

            if card['active'] != 1:
                reason = "Card inactive"
            elif not has_door_access:
                reason = "No access to this door"
            elif card.get('valid_from') and now.date() < card['valid_from']:
                reason = "Card not yet valid"
            elif card.get('valid_until') and now.date() > card['valid_until']:
                reason = "Card expired"
            elif card.get('schedule_id') and not check_schedule_from_db(cursor, card['schedule_id'], now):
                reason = "Outside scheduled hours"
            elif is_holiday_denied_db(cursor, now):
                reason = "Access denied on holiday"
            elif card.get('daily_scan_limit') and int(card['daily_scan_limit']) > 0:
                # Check daily scan count
                limit = int(card['daily_scan_limit'])
                cursor.execute("""
                    SELECT COUNT(*) as cnt FROM logs
                    WHERE user_id = %s AND Location = %s
                      AND DATE(Date) = CURDATE() AND Granted = 1
                """, (user_id, zone))
                count_row = cursor.fetchone()
                today_count = int(count_row['cnt']) if count_row else 0
                if today_count >= limit:
                    reason = f"Daily scan limit reached ({today_count}/{limit})"
                else:
                    granted = True
                    name = f"{card.get('firstname', '')} {card.get('lastname', '')}".strip() or user_id
                    open_door(user_id, name)
            else:
                granted = True
                name = f"{card.get('firstname', '')} {card.get('lastname', '')}".strip() or user_id
                open_door(user_id, name)

            if not granted:
                reject_card(user_id, reason)

            cursor.execute("""
                INSERT INTO logs (user_id, Date, Granted, Location, doorip)
                VALUES (%s, %s, %s, %s, %s)
            """, (user_id, now, 1 if granted else 0, zone, myip))
            db.commit()
            return True

        else:
            # Card not found - add to database as inactive for enrollment
            debug("Card not found, adding to database as inactive")
            try:
                cursor.execute("""
                    INSERT INTO cards (card_id, user_id, facility, bstr, firstname, lastname, doors, active)
                    VALUES (%s, %s, %s, %s, '', '', '', 0)
                """, (card_id, user_id, facility, bstr))
                cursor.execute("""
                    INSERT INTO logs (user_id, Date, Granted, Location, doorip)
                    VALUES (%s, %s, 0, %s, %s)
                """, (user_id, now, zone, myip))
                db.commit()
            except pymysql.IntegrityError:
                pass  # Card already exists
            reject_card(user_id, "Unknown card")
            return True

    except pymysql.Error as e:
        with state_lock:
            db_connected = False
        debug(f"Database error: {e}")
        return False
    except Exception as e:
        with state_lock:
            db_connected = False
        debug(f"Database lookup error: {e}")
        return False
    finally:
        if db:
            try:
                db.close()
            except Exception:
                pass


def check_schedule(schedule_id, now):
    """Check if current time is within the schedule (from cache)"""
    if not schedule_id:
        return True  # No schedule = always allowed

    with cache_lock:
        schedules = local_cache.get('schedules', {})
        schedule = schedules.get(str(schedule_id)) or schedules.get(schedule_id)
        # Make a copy to avoid holding the lock during processing
        if schedule:
            schedule = dict(schedule)

    if not schedule:
        return False  # Schedule not found = deny access (fail secure)

    if schedule.get('is_24_7'):
        return True

    # Get current day and time
    day_names = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
    current_day = day_names[now.weekday()]
    current_time = now.time()

    start_key = f"{current_day}_start"
    end_key = f"{current_day}_end"

    start_time = schedule.get(start_key)
    end_time = schedule.get(end_key)

    if not start_time or not end_time:
        return False  # No access on this day

    # Parse time strings with error handling
    try:
        if isinstance(start_time, str):
            start_time = datetime.strptime(start_time, '%H:%M:%S').time()
        if isinstance(end_time, str):
            end_time = datetime.strptime(end_time, '%H:%M:%S').time()
        return start_time <= current_time <= end_time
    except ValueError as e:
        debug(f"Schedule time parsing error: {e}")
        return False  # Fail secure on parsing errors


def check_schedule_from_db(cursor, schedule_id, now):
    """Check schedule from database"""
    cursor.execute("SELECT * FROM access_schedules WHERE id = %s", (schedule_id,))
    schedule = cursor.fetchone()

    if not schedule:
        return False  # Schedule not found = deny access (fail secure)

    if schedule.get('is_24_7'):
        return True

    day_names = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
    current_day = day_names[now.weekday()]
    current_time = now.time()

    start_time = schedule.get(f"{current_day}_start")
    end_time = schedule.get(f"{current_day}_end")

    if not start_time or not end_time:
        return False

    return start_time <= current_time <= end_time


def is_holiday_denied(now):
    """Check if today is a holiday with access denied (from cache)"""
    with cache_lock:
        holidays = list(local_cache.get('holidays', []))
    today = now.strftime('%Y-%m-%d')

    for holiday in holidays:
        if not holiday.get('access_denied'):
            continue
        if holiday.get('date') == today:
            return True
        # Check recurring holidays by month/day
        if holiday.get('recurring'):
            h_date = holiday.get('date', '')
            try:
                h_month_day = h_date[5:]  # "MM-DD"
                if h_month_day == now.strftime('%m-%d'):
                    return True
            except (IndexError, TypeError):
                pass

    return False


def is_holiday_denied_db(cursor, now):
    """Check if today is a holiday with access denied (from database)"""
    today = now.date()

    cursor.execute("""
        SELECT * FROM holidays
        WHERE (date = %s OR (recurring = 1 AND MONTH(date) = %s AND DAY(date) = %s))
          AND access_denied = 1
    """, (today, today.month, today.day))

    return cursor.fetchone() is not None


def open_door(user_id, name, is_master=False):
    """Handle door open logic with repeat swipe detection.
    Only master cards can toggle held-open state.
    A single master scan releases held-open; triple scan enters it."""
    global last_card, repeat_read_timeout, repeat_read_count

    now = time.time()

    with card_lock:
        # Check for repeat swipe (3 swipes within 30 seconds = toggle lock)
        if user_id == last_card and now <= repeat_read_timeout:
            repeat_read_count += 1
        else:
            repeat_read_count = 0
            repeat_read_timeout = now + 30

        last_card = user_id
        current_repeat_count = repeat_read_count

    zone_config = config.get(zone, {})

    if is_master and zone_config.get("unlocked"):
        # Single master scan while held-open -> release hold
        zone_config["unlocked"] = False
        report(f"{zone} hold released by {name}")
        lock_door()
        log_door_event('lock', f"Hold released by {name}")
        with card_lock:
            repeat_read_count = 0
    elif is_master and current_repeat_count >= 2:
        # Triple master scan -> enter held-open
        zone_config["unlocked"] = True
        report(f"{zone} HELD OPEN by {name}")
        unlock_door()
        log_door_event('door_held_open', f"Held open by {name}")
    else:
        if zone_config.get("unlocked"):
            report(f"{name} entered {zone} (already unlocked)")
        else:
            report(f"{name} granted access to {zone}")
            latch_gpio = zone_config.get("latch_gpio")
            if latch_gpio:
                unlock_briefly(latch_gpio)
            else:
                debug(f"Warning: latch_gpio not configured for zone {zone}")


def reject_card(user_id, reason="Access denied"):
    """Handle card rejection"""
    global repeat_read_count
    with card_lock:
        repeat_read_count = 0

    report(f"Access denied at {zone} for user {user_id}: {reason}")

    # Flash red LED to indicate rejection
    for _ in range(3):
        GPIO.output(22, 0)
        time.sleep(0.1)
        GPIO.output(22, 1)
        time.sleep(0.1)


# ============================================================
# LOGGING
# ============================================================

def log_access(user_id, card_id, facility, granted, reason=""):
    """Log access attempt to local file (for offline backup)"""
    log_file = os.path.join(CACHE_DIR, f"{zone}_access_log.json")

    entry = {
        'timestamp': datetime.now().isoformat(),
        'user_id': user_id,
        'card_id': card_id,
        'facility': facility,
        'granted': granted,
        'reason': reason,
        'zone': zone,
        'ip': myip
    }

    try:
        # Use file locking to prevent race conditions
        with open(log_file, 'a+') as f:
            fcntl.flock(f.fileno(), fcntl.LOCK_EX)
            try:
                f.seek(0)
                content = f.read()
                if content:
                    try:
                        logs = json.loads(content)
                    except (json.JSONDecodeError, ValueError):
                        debug(f"JSON corruption detected in {log_file}, resetting")
                        logs = []
                else:
                    logs = []

                logs.append(entry)

                # Keep only last 1000 entries
                if len(logs) > 1000:
                    logs = logs[-1000:]

                f.seek(0)
                f.truncate()
                json.dump(logs, f, indent=2)
            finally:
                fcntl.flock(f.fileno(), fcntl.LOCK_UN)
    except Exception as e:
        debug(f"Error writing access log: {e}")


def log_door_event(event_type, details=""):
    """Log door events (door open/close, REX, lock/unlock)"""
    log_file = os.path.join(CACHE_DIR, f"{zone}_door_events.json")

    entry = {
        'timestamp': datetime.now().isoformat(),
        'event_type': event_type,
        'details': details,
        'zone': zone
    }

    try:
        # Use file locking to prevent race conditions
        with open(log_file, 'a+') as f:
            fcntl.flock(f.fileno(), fcntl.LOCK_EX)
            try:
                f.seek(0)
                content = f.read()
                if content:
                    try:
                        logs = json.loads(content)
                    except (json.JSONDecodeError, ValueError):
                        debug(f"JSON corruption detected in {log_file}, resetting")
                        logs = []
                else:
                    logs = []

                logs.append(entry)

                # Keep only last 500 entries
                if len(logs) > 500:
                    logs = logs[-500:]

                f.seek(0)
                f.truncate()
                json.dump(logs, f, indent=2)
            finally:
                fcntl.flock(f.fileno(), fcntl.LOCK_UN)
    except Exception as e:
        debug(f"Error writing door event log: {e}")


# ============================================================
# HEARTBEAT / HEALTH CHECK
# ============================================================

def start_heartbeat_thread():
    """Start the heartbeat thread for server communication"""
    global heartbeat_thread
    heartbeat_thread = threading.Thread(target=heartbeat_loop, daemon=True)
    heartbeat_thread.start()


def heartbeat_loop():
    """Send periodic heartbeat to server and sync cache"""
    global running, myip

    while running:
        try:
            # Refresh IP in case network came up after boot
            if myip == '127.0.0.1':
                myip = get_local_ip()

            send_heartbeat()

            # Sync cache every hour
            with state_lock:
                last_sync = cache_last_sync
            if time.time() - last_sync > 3600:
                sync_cache_from_server()

        except Exception as e:
            report(f"Heartbeat error: {e}")

        time.sleep(HEARTBEAT_INTERVAL)


def send_heartbeat():
    """Send heartbeat to update door status in database"""
    global db_connected, door_sensor_open

    if not MYSQL_AVAILABLE:
        return

    db = None
    try:
        db = get_db_connection(timeout=5)
        if db is None:
            return

        cursor = db.cursor(pymysql.cursors.DictCursor)

        zone_config = config.get(zone, {})
        with state_lock:
            locked_status = 0 if door_unlocked else 1
        held_open_val = 1 if zone_config.get("unlocked", False) else 0
        reader = zone_config.get("reader_type", "wiegand")

        # Update door status with version, listen port, api_key, and door sensor state
        controller_api_key = zone_config.get("api_key", "")
        door_open_val = None
        if door_sensor_open is True:
            door_open_val = 1
        elif door_sensor_open is False:
            door_open_val = 0
        cursor.execute("""
            UPDATE doors
            SET status = 'online',
                last_seen = NOW(),
                ip_address = %s,
                locked = %s,
                held_open = %s,
                controller_version = %s,
                listen_port = %s,
                api_key = %s,
                door_open = %s
            WHERE name = %s
        """, (myip, locked_status, held_open_val, VERSION, push_listener_port, controller_api_key, door_open_val, zone))

        # Auto-register door if it doesn't exist yet
        if cursor.rowcount == 0:
            cursor.execute("""
                INSERT INTO doors (name, ip_address, status, last_seen, locked, held_open, reader_type, controller_version, listen_port, api_key, door_open)
                VALUES (%s, %s, 'online', NOW(), %s, %s, %s, %s, %s, %s, %s)
            """, (zone, myip, locked_status, held_open_val, reader, VERSION, push_listener_port, controller_api_key, door_open_val))
            report(f"Door '{zone}' auto-registered in database")

        # Clear stale "updating" status — if we're heartbeating, the update finished
        cursor.execute("""
            UPDATE doors
            SET update_status = %s, update_status_time = NOW()
            WHERE name = %s AND update_status LIKE 'updating%%'
        """, (f'success: running {VERSION}', zone))

        db.commit()

        # Check if an update has been requested
        cursor.execute("""
            SELECT update_requested, door_sensor_gpio, door_sensor_invert FROM doors WHERE name = %s
        """, (zone,))
        row = cursor.fetchone()
        if row and row.get('update_requested'):
            report("Update requested by server, initiating update...")
            cursor.execute("""
                UPDATE doors
                SET update_requested = 0,
                    update_status = 'updating',
                    update_status_time = NOW()
                WHERE name = %s
            """, (zone,))
            db.commit()
            trigger_update()

        # Check if door sensor GPIO pin or invert setting changed in DB
        if row:
            db_sensor_pin = int(row['door_sensor_gpio']) if row.get('door_sensor_gpio') is not None else None
            db_invert = bool(int(row['door_sensor_invert'])) if row.get('door_sensor_invert') is not None else False
            zone_config = config.get(zone, {})
            old_invert = zone_config.get("door_sensor_invert", False)

            # Update invert setting in config
            if db_invert != old_invert:
                report(f"Door sensor invert changed: {old_invert} -> {db_invert}")
                zone_config["door_sensor_invert"] = db_invert
                config[zone] = zone_config
                # Re-read sensor with new invert setting
                if current_sensor_pin is not None:
                    door_sensor_open = _read_door_sensor(current_sensor_pin)

            if db_sensor_pin != current_sensor_pin:
                report(f"Door sensor GPIO changed: {current_sensor_pin} -> {db_sensor_pin}")
                reconfigure_door_sensor(db_sensor_pin)

        with state_lock:
            db_connected = True

    except pymysql.Error as e:
        with state_lock:
            db_connected = False
        debug(f"Heartbeat failed: {e}")
    finally:
        if db:
            try:
                db.close()
            except Exception:
                pass


# ============================================================
# COMMAND POLL (lightweight fast-polling for remote commands)
# ============================================================

def start_command_poll_thread():
    """Start the command poll thread for remote unlock and real-time lock state"""
    global command_poll_thread
    command_poll_thread = threading.Thread(target=command_poll_loop, daemon=True)
    command_poll_thread.start()


def command_poll_loop():
    """Lightweight fast-polling loop that checks for remote commands and sends lock state"""
    global running

    if not MYSQL_AVAILABLE:
        return

    zone_config = config.get(zone, {})
    db = None
    # When push listener is active, use longer fallback interval (safety net only)
    poll_interval = 15 if push_listener_port else 3

    while running:
        try:
            # Reconnect if needed
            if db is None:
                db = get_db_connection(timeout=5)
                if db is None:
                    time.sleep(DB_RETRY_INTERVAL)
                    continue
                debug("Command poll: connected to database")

            cursor = db.cursor(pymysql.cursors.DictCursor)

            # Check for remote commands and read current poll interval
            cursor.execute(
                "SELECT unlock_requested, hold_requested, poll_interval FROM doors WHERE name = %s",
                (zone,)
            )
            row = cursor.fetchone()

            if row:
                # Update poll interval dynamically
                new_interval = row.get('poll_interval')
                if new_interval and 1 <= int(new_interval) <= 60:
                    poll_interval = int(new_interval)

                # Handle remote unlock request
                if row.get('unlock_requested'):
                    cursor.execute(
                        "UPDATE doors SET unlock_requested = 0 WHERE name = %s",
                        (zone,)
                    )

                    log_door_event('remote_unlock', 'Unlocked by remote request')
                    report("Remote unlock requested by server")

                    latch_gpio = zone_config.get("latch_gpio")
                    if latch_gpio:
                        unlock_briefly(latch_gpio)

                # Handle hold_requested from web UI
                hold_req = int(row.get('hold_requested') or 0)
                if hold_req == 1 and not zone_config.get("unlocked", False):
                    zone_config["unlocked"] = True
                    unlock_door()
                    log_door_event('door_held_open', 'Held open by admin')
                    report("Door held open by admin request")
                    cursor.execute(
                        "UPDATE doors SET hold_requested = 0, held_open = 1 WHERE name = %s",
                        (zone,)
                    )
                elif hold_req == 2 and zone_config.get("unlocked", False):
                    zone_config["unlocked"] = False
                    lock_door()
                    log_door_event('lock', 'Hold released by admin')
                    report("Door hold released by admin request")
                    cursor.execute(
                        "UPDATE doors SET hold_requested = 0, held_open = 0 WHERE name = %s",
                        (zone,)
                    )
                elif hold_req:
                    # Stale request (e.g. hold requested but already held), just clear it
                    cursor.execute(
                        "UPDATE doors SET hold_requested = 0 WHERE name = %s",
                        (zone,)
                    )

            # Send real-time lock state and held_open AFTER processing commands
            with state_lock:
                locked_status = 0 if door_unlocked else 1
            held_open_val = 1 if zone_config.get("unlocked", False) else 0
            cursor.execute(
                "UPDATE doors SET locked = %s, held_open = %s WHERE name = %s",
                (locked_status, held_open_val, zone)
            )
            db.commit()

        except pymysql.Error as e:
            debug(f"Command poll error: {e}")
            if db:
                try:
                    db.close()
                except Exception:
                    pass
            db = None
            # Wait a bit longer on connection errors before retrying
            time.sleep(DB_RETRY_INTERVAL)
            continue

        except Exception as e:
            debug(f"Command poll unexpected error: {e}")

        time.sleep(poll_interval)

    # Cleanup on exit
    if db:
        try:
            db.close()
        except Exception:
            pass


# ============================================================
# HTTPS PUSH LISTENER
# ============================================================

push_listener_thread = None
push_listener_port = None


def start_push_listener():
    """Start the HTTPS push listener if api_key and listen_port are configured."""
    global push_listener_thread, push_listener_port

    zone_config = config.get(zone, {})
    api_key = zone_config.get('api_key')
    listen_port = zone_config.get('listen_port')

    if not api_key or not listen_port:
        debug("Push listener: no api_key or listen_port in config, skipping")
        return

    listen_port = int(listen_port)
    cert_file = os.path.join(CONF_DIR, 'listener.crt')
    key_file = os.path.join(CONF_DIR, 'listener.key')

    if not os.path.isfile(cert_file) or not os.path.isfile(key_file):
        report("Push listener: TLS cert/key not found, skipping")
        return

    push_listener_port = listen_port
    push_listener_thread = threading.Thread(
        target=_run_push_listener,
        args=(listen_port, api_key, cert_file, key_file),
        daemon=True
    )
    push_listener_thread.start()
    report(f"Push listener started on port {listen_port}")


def _get_status_dict():
    """Build the status response dict for /ping and /status."""
    zone_config = config.get(zone, {})
    with state_lock:
        locked_val = not door_unlocked
        connected = db_connected
    with state_lock:
        last_sync = cache_last_sync

    door_open_val = None
    if door_sensor_open is True:
        door_open_val = 1
    elif door_sensor_open is False:
        door_open_val = 0

    return {
        'zone': zone,
        'version': VERSION,
        'locked': locked_val,
        'held_open': zone_config.get('unlocked', False),
        'uptime': int(time.time() - _start_time),
        'cache_age': int(time.time() - last_sync) if last_sync else None,
        'db_connected': connected,
        'listen_port': push_listener_port,
        'door_open': door_open_val,
    }


def _run_push_listener(port, api_key, cert_file, key_file):
    """Run the HTTPS listener in a thread. Stdlib only."""
    import ssl
    from http.server import HTTPServer, BaseHTTPRequestHandler

    controller_api_key = api_key

    class PushHandler(BaseHTTPRequestHandler):

        def _check_auth(self):
            auth = self.headers.get('Authorization', '')
            expected = f'Bearer {controller_api_key}'
            if not hmac.compare_digest(auth.encode(), expected.encode()):
                self._respond(403, {'ok': False, 'error': 'Forbidden'})
                return False
            return True

        def _respond(self, code, body):
            payload = json.dumps(body).encode()
            self.send_response(code)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)

        def do_POST(self):
            if not self._check_auth():
                return

            client_ip = self.client_address[0]
            path = self.path.rstrip('/')
            zone_config = config.get(zone, {})

            if path == '/cmd/unlock':
                latch_gpio = zone_config.get('latch_gpio')
                if latch_gpio:
                    log_door_event('remote_unlock', 'Unlocked by push command')
                    report(f"Push: remote unlock from {client_ip}")
                    unlock_briefly(latch_gpio)
                with state_lock:
                    locked = not door_unlocked
                self._respond(200, {'ok': True, 'locked': locked})

            elif path == '/cmd/hold':
                if not zone_config.get('unlocked', False):
                    zone_config['unlocked'] = True
                    unlock_door()
                    log_door_event('door_held_open', 'Held open by push command')
                    report(f"Push: door held open from {client_ip}")
                self._respond(200, {'ok': True, 'held_open': True})

            elif path == '/cmd/release':
                if zone_config.get('unlocked', False):
                    zone_config['unlocked'] = False
                    lock_door()
                    log_door_event('lock', 'Hold released by push command')
                    report(f"Push: hold released from {client_ip}")
                self._respond(200, {'ok': True, 'held_open': False})

            elif path == '/cmd/update':
                report(f"Push: update requested from {client_ip}")
                trigger_update()
                self._respond(200, {'ok': True, 'updating': True})

            elif path == '/cmd/sync':
                report(f"Push: cache sync requested from {client_ip}")
                threading.Thread(target=sync_cache_from_server, daemon=True).start()
                self._respond(200, {'ok': True})

            elif path == '/ping':
                self._respond(200, _get_status_dict())

            else:
                self._respond(404, {'ok': False, 'error': 'Not found'})

        def do_GET(self):
            if not self._check_auth():
                return

            path = self.path.rstrip('/')
            if path == '/status':
                self._respond(200, _get_status_dict())
            else:
                self._respond(404, {'ok': False, 'error': 'Not found'})

        def log_message(self, format, *args):
            # Suppress default stderr logging
            pass

    class ThreadedHTTPServer(HTTPServer):
        """Handle each request in a new thread."""
        daemon_threads = True

        def process_request(self, request, client_address):
            t = threading.Thread(target=self.process_request_thread,
                                 args=(request, client_address), daemon=True)
            t.start()

        def process_request_thread(self, request, client_address):
            try:
                self.finish_request(request, client_address)
            except Exception:
                self.handle_error(request, client_address)
            finally:
                self.shutdown_request(request)

    try:
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.minimum_version = ssl.TLSVersion.TLSv1_2
        ctx.set_ciphers('ECDHE+AESGCM:ECDHE+CHACHA20:DHE+AESGCM:DHE+CHACHA20:!aNULL:!MD5:!DSS')
        ctx.load_cert_chain(cert_file, key_file)

        server = ThreadedHTTPServer(('0.0.0.0', port), PushHandler)
        server.socket = ctx.wrap_socket(server.socket, server_side=True)

        debug(f"Push listener: HTTPS server bound to 0.0.0.0:{port}")
        server.serve_forever()
    except Exception as e:
        report(f"Push listener failed: {e}")


# Track process start time for uptime calculation
_start_time = time.time()


# ============================================================
# SELF-UPDATE
# ============================================================

def trigger_update():
    """Launch the update script detached so it survives service restart"""
    update_script = os.path.join(INSTALL_DIR, 'pidoors-update.sh')
    if not os.path.isfile(update_script):
        report(f"Update script not found: {update_script}")
        return
    try:
        # Use systemd-run to launch the update in its own transient service.
        # This fully escapes the pidoors.service cgroup so the update script
        # is not killed when systemctl stop pidoors runs.
        subprocess.Popen(
            ['sudo', 'systemd-run', '--unit=pidoors-update',
             '--description=PiDoors Controller Update',
             '--property=Type=oneshot',
             '--property=RemainAfterExit=no',
             update_script, zone],
            start_new_session=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL
        )
        report("Update script launched")
    except Exception as e:
        report(f"Failed to launch update script: {e}")


# ============================================================
# CLEANUP
# ============================================================

def cleanup(sig=None, frame=None):
    """Clean up GPIO and exit"""
    global running
    running = False

    message = f"{zone} access control is going offline" if zone else "Access control is going offline"
    report(message)

    # Update status in database
    try:
        send_offline_status()
    except Exception:
        pass  # Ignore errors during cleanup

    GPIO.cleanup()
    sys.exit(0)


def send_offline_status():
    """Update database to show door is offline"""
    if not MYSQL_AVAILABLE:
        return

    db = None
    try:
        db = get_db_connection(timeout=3)
        if db is None:
            return

        cursor = db.cursor()
        cursor.execute("UPDATE doors SET status = 'offline' WHERE name = %s", (zone,))
        db.commit()
    except Exception:
        pass
    finally:
        if db:
            try:
                db.close()
            except Exception:
                pass


# ============================================================
# MAIN
# ============================================================

if __name__ == "__main__":
    initialize()

    try:
        while running:
            time.sleep(1)
    except KeyboardInterrupt:
        cleanup()
