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
import socket

myip = [l for l in ([ip for ip in socket.gethostbyname_ex(socket.gethostname())[2] if not ip.startswith("127.")][:1], [[(s.connect(('1.1.1.1', 53)), s.getsockname()[0], s.close()) for s in [socket.socket(socket.AF_INET, socket.SOCK_DGRAM)]][0][1]]) if l][0][0]




debug_mode = False
conf_dir = "/home/pi/pidoors/conf/"

def initialize():
    GPIO.setmode(GPIO.BCM)
    syslog.openlog("accesscontrol", syslog.LOG_PID, syslog.LOG_AUTH)
    report("Initializing")
    read_configs()
    setup_output_GPIOs()
    setup_readers()
    GPIO.output(25, 0)
    GPIO.output(22, 1)
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

    report("nothing to rehash")

def read_configs():
    global zone, config
    jzone = load_json(conf_dir + "zone.json")

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
    GPIO.setup(22, GPIO.OUT)

    lock(gpio)

def lock(gpio):
    GPIO.output(gpio, active(gpio)^1)
    GPIO.output(25, 0)
    GPIO.output(22, 1)

def unlock(gpio):
    GPIO.output(gpio, active(gpio))
    GPIO.output(25, 1)
    GPIO.output(22, 0)

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
    lookup_card(card_id, str(facility), str(user_id), bstr)



def lookup_card(card_id, facility, user_id, bstr):

    debug("Data sent to lookup card function. Card ID: %s User ID: %s Facility: %s BSTR: %s" %(card_id, user_id, facility, bstr))
    global repeat_read_count
    sqladdr = config[zone]["sqladdr"]
    sqluser = config[zone]["sqluser"]
    sqlpass = config[zone]["sqlpass"]
    sqldb = config[zone]["sqldb"]
    debug("test1")
    now = datetime.now()
    formatted_date = now.strftime('%Y-%m-%d %H:%M:%S')
 
    if (user_id == '15278') and (card_id == '02c8775d') and (facility == '100') and (bstr == '10110010000111011101011101'):
        open_door('Master Card')
    elif (user_id == '15304') and (card_id == '02c87791') and (facility == '100') and (bstr == '10110010000111011110010001'):
        open_door('Master Card')

    else:
        db = pymysql.connect(sqladdr, sqluser, sqlpass, sqldb)
        cursor = db.cursor()
        sql = "SELECT * FROM cards WHERE user_id ='%s' AND card_id='%s' AND facility='%s'" %(user_id, card_id, facility)
        debug("%s" % sql)
        debug("%s" % zone)
        try:
            sqlresults = cursor.execute(sql)
            sqldata = cursor.fetchone()
            debug("SQL Query Finished Successfully..")
            #lcd_string("User Found..",LCD_LINE_2)

        except:
            debug("SQL Error?")
            #lcd_string("No User Found..",LCD_LINE_2)

        if (sqlresults):
            debug("Card ID-- %s" %(sqldata[0]))
            debug("User ID-- %s" %(sqldata[1]))
            debug("Facility-- %s" %(sqldata[2]))
            debug("Bstr-- %s" %(sqldata[3]))
            debug("First Name-- %s" %(sqldata[4]))
            debug("Last Name-- %s" %(sqldata[5]))
            debug("Doors-- %s" %(sqldata[6]))
            debug("Active-- %s" %(sqldata[7]))
            resultuser = sqldata[1]
            alloweddoors = sqldata[6]
            isactive = sqldata[7]

            #check if card is active.. if so open door. if not then log failed entry. p
        
            if (isactive == 1) and (zone in alloweddoors):
                debug("Card Active and has access to this door.. Opening..")
                open_door(resultuser)
                logsql = "INSERT INTO logs (user_id, Date, Granted, Location, doorip) VALUES ('%s', '%s', '1', '%s', '%s');"  %(user_id, now, zone, myip) 
                cursor.execute(logsql)
                db.commit()
                
    
            elif (isactive == 1) and (zone not in alloweddoors):
                debug("Card Active but does not have access to this door.. Rejecting..")
                logsql = "INSERT INTO logs (user_id, Date, Granted, Location, doorip) VALUES ('%s', '%s', '0', '%s', '%s');"  %(user_id, now, zone, myip) 
                cursor.execute(logsql)
                db.commit()
                repeat_read_count = 0
                reject_card()

            elif (isactive == 0):
                debug("Card Found but is inactive, Rejecting..")
                logsql = "INSERT INTO logs (user_id, Date, Granted, Location, doorip) VALUES ('%s', '%s', '0', '%s', '%s');"  %(user_id, now, zone, myip) 
                cursor.execute(logsql)
                db.commit()
                repeat_read_count = 0
                reject_card()

        else:
            #if we're here this means card is not in the system at all. add the card to the database as inactive.
            debug("Card not found.. Adding to database...")
            logsql = "INSERT INTO logs (user_id, Date, Granted, Location, doorip) VALUES ('%s', '%s', '0', '%s', '%s');"  %(user_id, now, zone, myip) 
            addcardsql = "INSERT INTO cards (card_id, user_id, facility, bstr, firstname, lastname, doors, active) VALUES ('%s', '%s', '%s', '%s', '', '', '', 0);"  %(card_id, user_id, facility, bstr)

            debug("%s" % logsql)
            debug("%s" % addcardsql)
            repeat_read_count = 0
            #lcd_string("Access Denied",LCD_LINE_1)
            #time.sleep(3)
            #lcd_init()
            #lcd_string("PolyGlass USA",LCD_LINE_1)
            #lcd_string("Scan Access Card",LCD_LINE_2)
            cursor.execute(logsql)
            cursor.execute(addcardsql)
            db.commit()
            debug("Card Added to Database as Inactive... Denying Access..")
            repeat_read_count = 0
            reject_card()


        db.close()





def reject_card():
    #log to database rejected card attempt.
    report("A card was presented at %s and access was denied" % zone)
    return False

def open_door(user_id):
    global last_card, repeat_read_timeout, repeat_read_count
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
config = None
last_card = None
zone_by_pin = {}
repeat_read_count = 0
repeat_read_timeout = time.time()



initialize()
while True:
    time.sleep(1000)