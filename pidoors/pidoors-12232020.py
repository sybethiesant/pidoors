#!/usr/bin/python
#
# vim: et ai sw=4

import RPi.GPIO as GPIO
from datetime import datetime
import sys,time
import signal
import subprocess
import json
import smtplib
import threading
import syslog
import pymysql




debug_mode = True
conf_dir = "./conf/"

def initialize():
    GPIO.setmode(GPIO.BCM)
    syslog.openlog("accesscontrol", syslog.LOG_PID, syslog.LOG_AUTH)
    report("Initializing")
    read_configs()
    setup_output_GPIOs()
    setup_readers()
    GPIO.output(25, 1)
    # Catch some exit signals
    signal.signal(signal.SIGINT, cleanup)   # Ctrl-C
    signal.signal(signal.SIGTERM, cleanup)  # killall python
    # These signals will reload users
    signal.signal(signal.SIGHUP, rehash)    # killall -HUP python
    signal.signal(signal.SIGUSR2, rehash)   # killall -USR2 python
    # This one will toggle debug messages
    signal.signal(signal.SIGWINCH, toggle_debug)    # killall -WINCH python
    report("%s access control is online" % zone)

def report(subject):
    syslog.syslog(subject)
    debug(subject)

def debug(message):
    if debug_mode:
        print(message)

def rehash(signal=None, b=None):
    global users
    report("nothing to rehash")

def read_configs():
    global zone, users, config
    jzone = load_json(conf_dir + "zone.json")
    users = load_json(conf_dir + "users.json")
    config = load_json(conf_dir + "config.json")
    zone = jzone["zone"]

def load_json(filename):
    file_handle = open(filename)
    config = json.load(file_handle)
    file_handle.close()
    return config

def setup_output_GPIOs():
    zone_by_pin[config[zone]["latch_gpio"]] = zone
    init_GPIO(config[zone]["latch_gpio"])

def init_GPIO(gpio):
    GPIO.setup(gpio, GPIO.OUT)
    GPIO.setup(25, GPIO.OUT)
    lock(gpio)

def lock(gpio):
    GPIO.output(gpio, active(gpio)^1)
    GPIO.output(25, 1)

def unlock(gpio):
    GPIO.output(gpio, active(gpio))
    GPIO.output(25, 0)

def active(gpio):
    zone = zone_by_pin[gpio]
    return config[zone]["unlock_value"]

def unlock_briefly(gpio):
    unlock(gpio)
    time.sleep(config[zone]["open_delay"])
    lock(gpio)

def setup_readers():
    global zone_by_pin
    for name in iter(config):
        if name == "<zone>":
            continue
        if (type(config[name]) is dict and config[name].get("d0")
                                       and config[name].get("d1")):
            reader = config[name]
            reader["stream"] = ""
            reader["timer"] = None
            reader["name"] = name
            reader["unlocked"] = False
            zone_by_pin[reader["d0"]] = name
            zone_by_pin[reader["d1"]] = name
            GPIO.setup(reader["d0"], GPIO.IN)
            GPIO.setup(reader["d1"], GPIO.IN)
            GPIO.add_event_detect(reader["d0"], GPIO.FALLING,
                                  callback=data_pulse)
            GPIO.add_event_detect(reader["d1"], GPIO.FALLING,
                                  callback=data_pulse)

def data_pulse(channel):
    reader = config[zone_by_pin[channel]]
    if channel == reader["d0"]:
        reader["stream"] += "0"
    elif channel == reader["d1"]:
        reader["stream"] += "1"
    kick_timer(reader)

def kick_timer(reader):
    if reader["timer"] is None:
        reader["timer"] = threading.Timer(0.2, wiegand_stream_done,
                                          args=[reader])
        reader["timer"].start()

def wiegand_stream_done(reader):
    if reader["stream"] == "":
        return
    bitstring = reader["stream"]
    reader["stream"] = ""
    reader["timer"] = None
    validate_bits(bitstring)

def validate_bits(bstr):
    if len(bstr) != 26:
        debug("Incorrect string length received: %i" % len(bstr))
        debug(":%s:" % bstr)
        return False
    lparity = int(bstr[0])
    facility = int(bstr[1:9], 2)
    user_id = int(bstr[9:25], 2)
    rparity = int(bstr[25])
    debug("%s is: %i %i %i %i" % (bstr, lparity, facility, user_id, rparity))

    calculated_lparity = 0
    calculated_rparity = 1
    for iter in range(0, 12):
        calculated_lparity ^= int(bstr[iter+1])
        calculated_rparity ^= int(bstr[iter+13])
    if (calculated_lparity != lparity or calculated_rparity != rparity):
        debug("Parity error in received string!")
        return False

    card_id = "%08x" % int(bstr, 2)
    debug("Successfully decoded %s facility=%i user=%i" %
          (card_id, facility, user_id))
    lookup_card(card_id, str(facility), str(user_id))



def lookup_card(card_id, facility, user_id):
    global repeat_read_count
    sqladdr = "192.168.1.99"
    sqluser = "pidoors"
    sqlpass = "p1d00r4p@ss!"
    sqldatabase = "access"
    now = datetime.now()
    formatted_date = now.strftime('%Y-%m-%d %H:%M:%S')

    user = (users.get("%s,%s" % (facility, user_id)) or users.get(card_id) or users.get(card_id.upper()) or users.get(user_id))

    config[zone]["latch_gpio"]

    #select * from users WHERE user_email='$user_email' AND user_pass='$user_pass' AND admin='1'"
    db = pymysql.connect(sqladdr, sqluser, sqlpass, sqldatabase)
    cursor = db.cursor()
    sql = "SELECT user_id FROM cards WHERE user_id ='%s' AND card_id='%s' AND facility='%s' AND active='1'" %(user_id, card_id, facility)
    debug("%s" % sql)
    debug("%s" % zone)
    try:
        sqlresults = cursor.execute(sql)
        founduser = user_id
        #founduser = cursor.fetchall()
        debug("SQL User Found - %s" %(founduser))
        debug("SQL Results - %s" %(sqlresults))
        #lcd_string("User Found..",LCD_LINE_2)

    except:
        debug("SQL User Not Found")
        #lcd_string("No User Found..",LCD_LINE_2)

    if (sqlresults):
        logsql = "INSERT INTO logs (user_id, Date, Granted, Location) VALUES ('%s', '%s', '1', '%s');"  %(user_id, now, zone)
        debug("%s" % logsql)
        open_door(founduser)
        #time.sleep(3)
        #lcd_string("PolyGlass USA",LCD_LINE_1)
        #lcd_string("Scan Access Card",LCD_LINE_2)
        cursor.execute(logsql)
        db.commit()

    else:
        logsql = "INSERT INTO logs (user_id, Date, Granted, Location) VALUES ('%s', '%s', '0', '%s');"  %(user_id, now, zone)
        debug("%s" % logsql)
        debug("Access Denied!")
        repeat_read_count = 0
        #lcd_string("Access Denied",LCD_LINE_1)
        #time.sleep(3)
        #lcd_init()
        #lcd_string("PolyGlass USA",LCD_LINE_1)
        #lcd_string("Scan Access Card",LCD_LINE_2)
        cursor.execute(logsql)
        db.commit()
        reject_card()


    db.close()





def reject_card():
    #log to database rejected card attempt.
    report("A card was presented at %s and access was denied" % zone)
    return False

def open_door(user_id):
    global open_hours, last_card, repeat_read_timeout, repeat_read_count
    now = time.time()
    name = user_id
    if (user_id == last_card and now <= repeat_read_timeout):
        repeat_read_count += 1
    else:
        repeat_read_count = 0
        repeat_read_timeout = now + 30
    last_card = user_id
    if (repeat_read_count >= 2):
        config[zone]["unlocked"] ^= True
        if config[zone]["unlocked"]:
            report("%s unlocked by %s" % (zone, name))
            unlock(config[zone]["latch_gpio"])

        else:
            report("%s locked by %s" % (zone, name))
            lock(config[zone]["latch_gpio"])
    else:
        if config[zone]["unlocked"]:
            report("%s found %s is already unlocked" % (name, zone))
        else:
            report("%s has entered %s" % (user_id, zone))
            unlock_briefly(config[zone]["latch_gpio"])




def toggle_debug(a=None, b=None):
    global debug_mode
    if debug_mode:
        debug("Disabling debug messages")
    debug_mode ^= True
    if debug_mode:
        debug("Enabling debug messages")



def cleanup(a=None, b=None):
    message = ""
    if zone:
        message = "%s " % zone
    message += "access control is going offline"
    report(message)
    GPIO.setwarnings(False)
    GPIO.cleanup()
    sys.exit(0)



# Globalize some variables for later
zone = None
users = None
config = None
last_card = None
zone_by_pin = {}
repeat_read_count = 0
repeat_read_timeout = time.time()



initialize()
while True:
    # The main thread should open a command socket or something
    time.sleep(1000)