#!/usr/bin/python3
"""
PiDoors Access Control - Door Controller
Wiegand-based access control system for Raspberry Pi

Features:
- Wiegand 26-bit and 34-bit card reading
- Local 24-hour cache for offline operation
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
import hashlib

# Try to import optional dependencies
try:
    import pymysql
    MYSQL_AVAILABLE = True
except ImportError:
    MYSQL_AVAILABLE = False
    print("Warning: pymysql not installed. Database features disabled.")

# Configuration
DEBUG_MODE = False
CONF_DIR = "/home/pi/pidoors/conf/"
CACHE_DIR = "/home/pi/pidoors/cache/"
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
    global running
    running = True

    GPIO.setmode(GPIO.BCM)
    GPIO.setwarnings(False)
    syslog.openlog("accesscontrol", syslog.LOG_PID, syslog.LOG_AUTH)

    report("Initializing PiDoors Access Control")

    # Ensure cache directory exists
    os.makedirs(CACHE_DIR, exist_ok=True)

    # Load configurations
    read_configs()

    # Load local cache
    load_cache()

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
        sql = """
            SELECT card_id, user_id, facility, bstr, firstname, lastname,
                   doors, active, group_id, schedule_id, valid_from, valid_until
            FROM cards
            WHERE active = 1
              AND (doors LIKE %s OR doors LIKE %s OR doors = %s)
        """
        cursor.execute(sql, (f"%{zone}%", f"%{zone} %", zone))
        cards = cursor.fetchall()

        # Fetch schedules
        cursor.execute("SELECT * FROM access_schedules")
        schedules = {s['id']: s for s in cursor.fetchall()}

        # Fetch master cards
        cursor.execute("SELECT * FROM master_cards WHERE active = 1")
        master_cards = cursor.fetchall()

        # Fetch holidays
        cursor.execute("SELECT * FROM holidays WHERE date >= CURDATE()")
        holidays = cursor.fetchall()

        # Fetch door settings for this zone
        cursor.execute("SELECT * FROM doors WHERE name = %s", (zone,))
        door_info = cursor.fetchone()

        db.close()
        db_connected = True

        # Build the cache
        new_cache = {
            'schedules': schedules,
            'holidays': [{'date': str(h['date']), 'name': h['name'], 'access_denied': h['access_denied']}
                        for h in holidays],
            'door_settings': door_info,
            'master_cards': {},
            'cards': {}
        }

        # Add master cards to cache
        for mc in master_cards:
            key = f"{mc['facility']},{mc['user_id']}"
            new_cache['master_cards'][key] = {
                'card_id': mc['card_id'],
                'description': mc['description']
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

        local_cache = new_cache
        save_cache()
        report(f"Cache synced from server: {len(new_cache['cards'])} cards")

    except pymysql.Error as e:
        db_connected = False
        report(f"Database sync failed: {e}")
        debug("Will use local cache for access decisions")
    except Exception as e:
        db_connected = False
        report(f"Cache sync error: {e}")


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
    unlock_briefly(config[zone]["latch_gpio"])


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


def active(gpio):
    """Get the active (unlock) value for a GPIO"""
    z = zone_by_pin.get(gpio)
    if z:
        return config[z].get("unlock_value", 1)
    return 1


# ============================================================
# WIEGAND CARD READING
# ============================================================

def data_pulse(channel):
    """Handle Wiegand data pulse"""
    reader_name = zone_by_pin.get(channel)
    if not reader_name:
        return

    reader = config[reader_name]

    if channel == reader["d0"]:
        reader["stream"] += "0"
    elif channel == reader["d1"]:
        reader["stream"] += "1"

    kick_timer(reader)


def kick_timer(reader):
    """Start/restart the Wiegand stream timeout timer"""
    if reader["timer"] is None:
        reader["timer"] = threading.Timer(0.2, wiegand_stream_done, args=[reader])
        reader["timer"].start()


def wiegand_stream_done(reader):
    """Process completed Wiegand stream"""
    if reader["stream"] == "":
        return

    bitstring = reader["stream"]
    reader["stream"] = ""
    reader["timer"] = None

    validate_bits(bitstring)


def validate_bits(bstr):
    """Validate Wiegand bit stream and extract card data"""
    bit_len = len(bstr)

    # Support 26-bit and 34-bit Wiegand formats
    if bit_len == 26:
        return validate_26bit(bstr)
    elif bit_len == 34:
        return validate_34bit(bstr)
    else:
        debug(f"Unsupported Wiegand format: {bit_len} bits")
        return False


def validate_26bit(bstr):
    """Validate and decode 26-bit Wiegand format"""
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


def validate_34bit(bstr):
    """Validate and decode 34-bit Wiegand format"""
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

    # First check: Master cards (from cache)
    if is_cache_valid():
        master_cards = local_cache.get('master_cards', {})
        if card_key in master_cards:
            report(f"Master card access granted: {master_cards[card_key].get('description', 'Master')}")
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
        cached_card = local_cache.get('cards', {}).get(card_key)

        if cached_card:
            # Check if card has access to this zone
            doors = cached_card.get('doors', '')
            if zone in doors or doors == '*':
                # Check validity dates
                valid_from = cached_card.get('valid_from')
                valid_until = cached_card.get('valid_until')

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
    if not db_connected and (time.time() - last_db_attempt) < DB_RETRY_INTERVAL:
        return False

    zone_config = config.get(zone, {})
    sqladdr = zone_config.get("sqladdr")
    sqluser = zone_config.get("sqluser")
    sqlpass = zone_config.get("sqlpass")
    sqldb = zone_config.get("sqldb")

    if not all([sqladdr, sqluser, sqlpass, sqldb]):
        return False

    last_db_attempt = time.time()

    try:
        db = pymysql.connect(
            host=sqladdr,
            user=sqluser,
            password=sqlpass,
            database=sqldb,
            connect_timeout=5
        )
        cursor = db.cursor(pymysql.cursors.DictCursor)
        db_connected = True

        # Check for master card first
        cursor.execute("""
            SELECT * FROM master_cards
            WHERE user_id = %s AND card_id = %s AND facility = %s AND active = 1
        """, (user_id, card_id, facility))

        if cursor.fetchone():
            report("Master card access via database")
            open_door(user_id, "Master")

            # Log to database
            cursor.execute("""
                INSERT INTO logs (user_id, Date, Granted, Location, doorip)
                VALUES (%s, %s, 1, %s, %s)
            """, (user_id, now, zone, myip))
            db.commit()
            db.close()
            return True

        # Look up regular card
        cursor.execute("""
            SELECT * FROM cards
            WHERE user_id = %s AND card_id = %s AND facility = %s
        """, (user_id, card_id, facility))

        card = cursor.fetchone()

        if card:
            if card['active'] != 1:
                reject_card(user_id, "Card inactive")
                cursor.execute("""
                    INSERT INTO logs (user_id, Date, Granted, Location, doorip)
                    VALUES (%s, %s, 0, %s, %s)
                """, (user_id, now, zone, myip))
                db.commit()
                db.close()
                return True

            doors = card.get('doors', '')
            if zone not in doors and doors != '*':
                reject_card(user_id, "No access to this door")
                cursor.execute("""
                    INSERT INTO logs (user_id, Date, Granted, Location, doorip)
                    VALUES (%s, %s, 0, %s, %s)
                """, (user_id, now, zone, myip))
                db.commit()
                db.close()
                return True

            # Check validity dates
            valid_from = card.get('valid_from')
            valid_until = card.get('valid_until')

            if valid_from and now.date() < valid_from:
                reject_card(user_id, "Card not yet valid")
                cursor.execute("""
                    INSERT INTO logs (user_id, Date, Granted, Location, doorip)
                    VALUES (%s, %s, 0, %s, %s)
                """, (user_id, now, zone, myip))
                db.commit()
                db.close()
                return True

            if valid_until and now.date() > valid_until:
                reject_card(user_id, "Card expired")
                cursor.execute("""
                    INSERT INTO logs (user_id, Date, Granted, Location, doorip)
                    VALUES (%s, %s, 0, %s, %s)
                """, (user_id, now, zone, myip))
                db.commit()
                db.close()
                return True

            # Check schedule
            schedule_id = card.get('schedule_id')
            if schedule_id and not check_schedule_from_db(cursor, schedule_id, now):
                reject_card(user_id, "Outside scheduled hours")
                cursor.execute("""
                    INSERT INTO logs (user_id, Date, Granted, Location, doorip)
                    VALUES (%s, %s, 0, %s, %s)
                """, (user_id, now, zone, myip))
                db.commit()
                db.close()
                return True

            # Check holidays
            if is_holiday_denied_db(cursor, now):
                reject_card(user_id, "Access denied on holiday")
                cursor.execute("""
                    INSERT INTO logs (user_id, Date, Granted, Location, doorip)
                    VALUES (%s, %s, 0, %s, %s)
                """, (user_id, now, zone, myip))
                db.commit()
                db.close()
                return True

            # Access granted!
            name = f"{card.get('firstname', '')} {card.get('lastname', '')}".strip() or user_id
            open_door(user_id, name)

            cursor.execute("""
                INSERT INTO logs (user_id, Date, Granted, Location, doorip)
                VALUES (%s, %s, 1, %s, %s)
            """, (user_id, now, zone, myip))
            db.commit()
            db.close()
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

            db.close()
            reject_card(user_id, "Unknown card")
            return True

    except pymysql.Error as e:
        db_connected = False
        debug(f"Database error: {e}")
        return False
    except Exception as e:
        db_connected = False
        debug(f"Database lookup error: {e}")
        return False


def check_schedule(schedule_id, now):
    """Check if current time is within the schedule (from cache)"""
    if not schedule_id:
        return True  # No schedule = always allowed

    schedules = local_cache.get('schedules', {})
    schedule = schedules.get(schedule_id)

    if not schedule:
        return True  # Schedule not found = allow access

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

    # Parse time strings
    if isinstance(start_time, str):
        start_time = datetime.strptime(start_time, '%H:%M:%S').time()
    if isinstance(end_time, str):
        end_time = datetime.strptime(end_time, '%H:%M:%S').time()

    return start_time <= current_time <= end_time


def check_schedule_from_db(cursor, schedule_id, now):
    """Check schedule from database"""
    cursor.execute("SELECT * FROM access_schedules WHERE id = %s", (schedule_id,))
    schedule = cursor.fetchone()

    if not schedule:
        return True

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
    holidays = local_cache.get('holidays', [])
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

    # Check for repeat swipe (3 swipes within 30 seconds = toggle lock)
    if user_id == last_card and now <= repeat_read_timeout:
        repeat_read_count += 1
    else:
        repeat_read_count = 0
        repeat_read_timeout = now + 30

    last_card = user_id

    zone_config = config.get(zone, {})

    if repeat_read_count >= 2:
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
            unlock_briefly(zone_config["latch_gpio"])


def reject_card(user_id, reason="Access denied"):
    """Handle card rejection"""
    global repeat_read_count
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
        logs = []
        if os.path.exists(log_file):
            with open(log_file, 'r') as f:
                logs = json.load(f)

        logs.append(entry)

        # Keep only last 1000 entries
        if len(logs) > 1000:
            logs = logs[-1000:]

        with open(log_file, 'w') as f:
            json.dump(logs, f, indent=2)
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
        logs = []
        if os.path.exists(log_file):
            with open(log_file, 'r') as f:
                logs = json.load(f)

        logs.append(entry)

        # Keep only last 500 entries
        if len(logs) > 500:
            logs = logs[-500:]

        with open(log_file, 'w') as f:
            json.dump(logs, f, indent=2)
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
            if time.time() - cache_last_sync > 3600:
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
        db.close()
        db_connected = True

    except pymysql.Error as e:
        db_connected = False
        debug(f"Heartbeat failed: {e}")


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
    except:
        pass

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
        db.close()
    except:
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
