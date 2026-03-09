"""
Mock RPi.GPIO module for running pidoors.py in Docker without real hardware.
Registers itself in sys.modules so `import RPi.GPIO as GPIO` works transparently.
"""
import sys
import types


class MockGPIO:
    """Fake GPIO with all constants and no-op methods."""
    BCM = 11
    BOARD = 10
    IN = 1
    OUT = 0
    FALLING = 32
    RISING = 31
    BOTH = 33
    PUD_UP = 22
    PUD_DOWN = 21
    PUD_OFF = 20
    HIGH = 1
    LOW = 0

    @staticmethod
    def setmode(mode): pass
    @staticmethod
    def setwarnings(flag): pass
    @staticmethod
    def setup(channel, direction, pull_up_down=None, initial=None): pass
    @staticmethod
    def output(channel, value): pass
    @staticmethod
    def input(channel): return 0
    @staticmethod
    def add_event_detect(channel, edge, callback=None, bouncetime=None): pass
    @staticmethod
    def remove_event_detect(channel): pass
    @staticmethod
    def cleanup(channel=None): pass


# Register as RPi.GPIO in sys.modules
_rpi = types.ModuleType('RPi')
_rpi.GPIO = MockGPIO
sys.modules['RPi'] = _rpi
sys.modules['RPi.GPIO'] = MockGPIO
