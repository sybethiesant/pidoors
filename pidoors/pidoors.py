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

import RPi.GPIO as GPIO
from datetime import datetime, timedelta
import sys
import time
import signal
import json
import threading
import syslog
import socket
import os
import fcntl

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

# Configuration
DEBUG_MODE = False
INSTALL_DIR = os.environ.get('PIDOORS_DIR', '/opt/pidoors')
CONF_DIR = os.path.join(INSTALL_DIR, 'conf') + '/'
CACHE_DIR = os.path.join(INSTALL_DIR, 'cache') + '/'
MASTER_CARDS_FILE = os.path.join(CONF_DIR, 'master_cards.json')
CUSTOM_FORMATS_FILE = os.path.join(CONF_DIR, 'formats.json')
CACHE_DURATION = 86400  # 24 hours in seconds
HEARTBEAT_INTERVAL = 60  # seconds
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
local_cache = {}
cache_last_sync = 0
heartbeat_thread = None
running = True
master_cards = {}  # Persistent master cards (never expire)
format_registry = None  # Wiegand format registry

# Thread locks for shared state
state_lock = threading.Lock()  # For db_connected, last_db_attempt, cache_last_sync
cache_lock = threading.Lock()  # For local_cache access
card_lock = threading.Lock()   # For last_card, repeat_read_count, repeat_read_timeout
master_lock = threading.Lock() # For master_cards access
wiegand_lock = threading.Lock() # For legacy Wiegand stream access


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


myip = get_local_ip()


def initialize():
    """Initialize the access control system"""
    global running, format_registry
    running = True

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

    # Sync cache from server
    sync_cache_from_server()

    report(f"{zone} access control is online (IP: {myip})")


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
                local_cache = cache_data.get('cards', {})
                cache_last_sync = cache_data.get('sync_time', 0)

                # Check if cache is still valid (within 24 hours)
                if time.time() - cache_last_sync > CACHE_DURATION:
                    report("Local cache expired (>24 hours old)")
                else:
                    card_count = len(local_cache)
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
        cache_data = {
            'zone': zone,
            'sync_time': time.time(),
            'sync_datetime': datetime.now().isoformat(),
            'cards': local_cache
        }
        save_json(cache_file, cache_data)
        cache_last_sync = cache_data['sync_time']
        debug(f"Cache saved with {len(local_cache)} cards")
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

    zone_config = config.get(zone, {})
    sqladdr = zone_config.get("sqladdr")
    sqluser = zone_config.get("sqluser")
    sqlpass = zone_config.get("sqlpass")
    sqldb = zone_config.get("sqldb")

    if not all([sqladdr, sqluser, sqlpass, sqldb]):
        return True  # Can't verify, allow access

    db = None
    try:
        db = pymysql.connect(
            host=sqladdr,
            user=sqluser,
            password=sqlpass,
            database=sqldb,
            connect_timeout=3  # Short timeout for quick check
        )
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

    zone_config = config.get(zone, {})
    sqladdr = zone_config.get("sqladdr")
    sqluser = zone_config.get("sqluser")
    sqlpass = zone_config.get("sqlpass")
    sqldb = zone_config.get("sqldb")

    if not all([sqladdr, sqluser, sqlpass, sqldb]):
        debug("Database configuration incomplete")
        return

    db = None
    try:
        db = pymysql.connect(
            host=sqladdr,
            user=sqluser,
            password=sqlpass,
            database=sqldb,
            connect_timeout=10
        )
        cursor = db.cursor(pymysql.cursors.DictCursor)

        # Fetch all cards that have access to this zone
        # Use FIND_IN_SET for proper comma-delimited matching (prevents "main" matching "maintenance")
        sql = """
            SELECT card_id, user_id, facility, bstr, firstname, lastname,
                   doors, active, group_id, schedule_id, valid_from, valid_until
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
            'holidays': [{'date': str(h['date']), 'name': h['name'], 'access_denied': h['access_denied']}
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
                'valid_until': str(card['valid_until']) if card['valid_until'] else None
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

    if latch_gpio:
        zone_by_pin[latch_gpio] = zone
        GPIO.setup(latch_gpio, GPIO.OUT)
        lock_door()

    # Status LEDs: Green (access granted) and Red (access denied)
    GPIO.setup(25, GPIO.OUT)  # Green LED / Granted
    GPIO.setup(22, GPIO.OUT)  # Red LED / Denied

    # Initial state: Red LED on (door locked)
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


def setup_door_sensor():
    """Setup door sensor GPIO pin (optional)"""
    zone_config = config.get(zone, {})
    door_sensor_gpio = zone_config.get("door_sensor_gpio")

    if door_sensor_gpio:
        GPIO.setup(door_sensor_gpio, GPIO.IN, pull_up_down=GPIO.PUD_UP)
        GPIO.add_event_detect(door_sensor_gpio, GPIO.BOTH,
                             callback=door_sensor_event, bouncetime=200)
        debug(f"Door sensor configured on GPIO {door_sensor_gpio}")


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
    zone_config = config.get(zone, {})
    door_open = GPIO.input(channel) == GPIO.LOW  # Assuming active-low sensor

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
    zone_config = config.get(zone, {})
    latch_gpio = zone_config.get("latch_gpio")
    unlock_value = zone_config.get("unlock_value", 1)

    if latch_gpio:
        GPIO.output(latch_gpio, unlock_value ^ 1)
        GPIO.output(25, 0)  # Green LED off
        GPIO.output(22, 1)  # Red LED on


def unlock_door():
    """Unlock the door"""
    zone_config = config.get(zone, {})
    latch_gpio = zone_config.get("latch_gpio")
    unlock_value = zone_config.get("unlock_value", 1)

    if latch_gpio:
        GPIO.output(latch_gpio, unlock_value)
        GPIO.output(25, 1)  # Green LED on
        GPIO.output(22, 0)  # Red LED off


def unlock_briefly(gpio):
    """Unlock the door temporarily"""
    zone_config = config.get(zone, {})
    open_delay = zone_config.get("open_delay", 5)

    unlock_door()
    time.sleep(open_delay)
    lock_door()


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
        open_door(user_id, "Master")
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
                        if not is_holiday_denied(now):
                            access_granted = True
                            access_reason = "Cached access granted"
                        else:
                            access_reason = "Access denied on holiday"
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

    # Rate limit database connection attempts
    with state_lock:
        if not db_connected and (time.time() - last_db_attempt) < DB_RETRY_INTERVAL:
            return False
        last_db_attempt = time.time()

    zone_config = config.get(zone, {})
    sqladdr = zone_config.get("sqladdr")
    sqluser = zone_config.get("sqluser")
    sqlpass = zone_config.get("sqlpass")
    sqldb = zone_config.get("sqldb")

    if not all([sqladdr, sqluser, sqlpass, sqldb]):
        return False

    db = None
    result = False
    try:
        db = pymysql.connect(
            host=sqladdr,
            user=sqluser,
            password=sqlpass,
            database=sqldb,
            connect_timeout=5
        )
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
            open_door(user_id, "Master")
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
        schedule = schedules.get(schedule_id)
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
        if holiday.get('date') == today and holiday.get('access_denied'):
            return True

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


def open_door(user_id, name):
    """Handle door open logic with repeat swipe detection"""
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

    if current_repeat_count >= 2:
        # Triple swipe - toggle permanent lock/unlock
        zone_config["unlocked"] = not zone_config.get("unlocked", False)

        if zone_config["unlocked"]:
            report(f"{zone} UNLOCKED permanently by {name}")
            unlock_door()
            log_door_event('unlock', f"By {name}")
        else:
            report(f"{zone} LOCKED by {name}")
            lock_door()
            log_door_event('lock', f"By {name}")
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
    global running

    while running:
        try:
            send_heartbeat()

            # Sync cache every hour
            with state_lock:
                last_sync = cache_last_sync
            if time.time() - last_sync > 3600:
                sync_cache_from_server()

        except Exception as e:
            debug(f"Heartbeat error: {e}")

        time.sleep(HEARTBEAT_INTERVAL)


def send_heartbeat():
    """Send heartbeat to update door status in database"""
    global db_connected

    if not MYSQL_AVAILABLE:
        return

    zone_config = config.get(zone, {})
    sqladdr = zone_config.get("sqladdr")
    sqluser = zone_config.get("sqluser")
    sqlpass = zone_config.get("sqlpass")
    sqldb = zone_config.get("sqldb")

    if not all([sqladdr, sqluser, sqlpass, sqldb]):
        return

    db = None
    try:
        db = pymysql.connect(
            host=sqladdr,
            user=sqluser,
            password=sqlpass,
            database=sqldb,
            connect_timeout=5
        )
        cursor = db.cursor()

        # Update door status
        cursor.execute("""
            UPDATE doors
            SET status = 'online',
                last_seen = NOW(),
                ip_address = %s,
                locked = %s
            WHERE name = %s
        """, (myip, 1 if not config.get(zone, {}).get("unlocked", False) else 0, zone))

        db.commit()
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

    zone_config = config.get(zone, {})
    sqladdr = zone_config.get("sqladdr")
    sqluser = zone_config.get("sqluser")
    sqlpass = zone_config.get("sqlpass")
    sqldb = zone_config.get("sqldb")

    if not all([sqladdr, sqluser, sqlpass, sqldb]):
        return

    db = None
    try:
        db = pymysql.connect(
            host=sqladdr,
            user=sqluser,
            password=sqlpass,
            database=sqldb,
            connect_timeout=3
        )
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
