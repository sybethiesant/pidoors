#!/bin/bash
exec &>/dev/null

PID=`pidof python3`
if [ -z "$PID" ]; then
	cd /home/pi/pidoors/
	python3 pidoors.py
fi

