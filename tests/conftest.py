"""
Pytest configuration and fixtures for PiDoors tests
"""

import pytest
import sys
import os
from unittest.mock import MagicMock, patch
from datetime import datetime, time as dtime

# Add parent directory to path for imports
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'pidoors'))


# =============================================================================
# GPIO Mock
# =============================================================================

class MockGPIO:
    """Mock RPi.GPIO module for testing without hardware"""
    BCM = 11
    OUT = 0
    IN = 1
    LOW = 0
    HIGH = 1
    FALLING = 1
    RISING = 2
    BOTH = 3
    PUD_UP = 21
    PUD_DOWN = 22

    _pin_states = {}
    _event_callbacks = {}

    @classmethod
    def setmode(cls, mode):
        pass

    @classmethod
    def setwarnings(cls, flag):
        pass

    @classmethod
    def setup(cls, pin, mode, pull_up_down=None):
        cls._pin_states[pin] = 0

    @classmethod
    def output(cls, pin, value):
        cls._pin_states[pin] = value

    @classmethod
    def input(cls, pin):
        return cls._pin_states.get(pin, 0)

    @classmethod
    def add_event_detect(cls, pin, edge, callback=None, bouncetime=None):
        cls._event_callbacks[pin] = callback

    @classmethod
    def remove_event_detect(cls, pin):
        if pin in cls._event_callbacks:
            del cls._event_callbacks[pin]

    @classmethod
    def cleanup(cls):
        cls._pin_states.clear()
        cls._event_callbacks.clear()

    @classmethod
    def simulate_pulse(cls, pin):
        """Simulate a GPIO pulse for testing"""
        if pin in cls._event_callbacks and cls._event_callbacks[pin]:
            cls._event_callbacks[pin](pin)


@pytest.fixture
def mock_gpio():
    """Fixture to provide mocked GPIO"""
    MockGPIO.cleanup()
    with patch.dict(sys.modules, {'RPi.GPIO': MockGPIO, 'RPi': MagicMock()}):
        yield MockGPIO
    MockGPIO.cleanup()


# =============================================================================
# Database Mock
# =============================================================================

class MockCursor:
    """Mock database cursor"""
    def __init__(self, data=None):
        self.data = data or []
        self.index = 0
        self.last_query = None
        self.last_params = None

    def execute(self, query, params=None):
        self.last_query = query
        self.last_params = params

    def fetchone(self):
        if self.index < len(self.data):
            result = self.data[self.index]
            self.index += 1
            return result
        return None

    def fetchall(self):
        return self.data


class MockConnection:
    """Mock database connection"""
    def __init__(self, cursor_data=None):
        self._cursor = MockCursor(cursor_data)

    def cursor(self, cursor_class=None):
        return self._cursor

    def commit(self):
        pass

    def close(self):
        pass


@pytest.fixture
def mock_db():
    """Fixture to provide mocked database connection"""
    return MockConnection


# =============================================================================
# Test Data Fixtures
# =============================================================================

@pytest.fixture
def valid_26bit_card():
    """Valid 26-bit Wiegand card data (H10301 format)"""
    # Facility: 123, User ID: 45678
    # Bit layout: P FFFFFFFF CCCCCCCCCCCCCCCC P
    # P = parity bits
    facility = 123
    user_id = 45678

    # Build bitstring: even parity + 8-bit facility + 16-bit user + odd parity
    facility_bits = format(facility, '08b')
    user_bits = format(user_id, '016b')

    # Calculate even parity (bits 1-12)
    data_bits = facility_bits + user_bits
    even_parity = sum(int(b) for b in data_bits[:12]) % 2

    # Calculate odd parity (bits 13-24)
    odd_parity = 1 - (sum(int(b) for b in data_bits[12:]) % 2)

    bitstring = str(even_parity) + data_bits + str(odd_parity)

    return {
        'bitstring': bitstring,
        'facility': str(facility),
        'user_id': str(user_id),
        'bit_length': 26
    }


@pytest.fixture
def valid_34bit_card():
    """Valid 34-bit Wiegand card data (H10306 format)"""
    # Facility: 1234, User ID: 56789
    facility = 1234
    user_id = 56789

    # Build bitstring: even parity + 16-bit facility + 16-bit user + odd parity
    facility_bits = format(facility, '016b')
    user_bits = format(user_id, '016b')

    data_bits = facility_bits + user_bits
    even_parity = sum(int(b) for b in data_bits[:16]) % 2
    odd_parity = 1 - (sum(int(b) for b in data_bits[16:]) % 2)

    bitstring = str(even_parity) + data_bits + str(odd_parity)

    return {
        'bitstring': bitstring,
        'facility': str(facility),
        'user_id': str(user_id),
        'bit_length': 34
    }


@pytest.fixture
def sample_config():
    """Sample configuration for testing"""
    return {
        'zone': 'test_zone',
        'test_zone': {
            'unlock_value': 1,
            'open_delay': 3,
            'latch_gpio': 18,
            'd0': 24,
            'd1': 23,
            'sqladdr': 'localhost',
            'sqluser': 'test',
            'sqlpass': 'test',
            'sqldb': 'test_access'
        }
    }


@pytest.fixture
def sample_cache():
    """Sample cache data for testing"""
    return {
        'cards': {
            '123,45678': {
                'card_id': '00abcdef',
                'firstname': 'John',
                'lastname': 'Doe',
                'doors': 'test_zone,front_door',  # Comma-separated zone list
                'schedule_id': 1,
                'valid_from': None,
                'valid_until': None
            }
        },
        'schedules': {
            1: {
                'id': 1,
                'name': '24/7 Access',
                'is_24_7': 1
            },
            2: {
                'id': 2,
                'name': 'Business Hours',
                'is_24_7': 0,
                'monday_start': dtime(8, 0, 0),
                'monday_end': dtime(18, 0, 0),
                'tuesday_start': dtime(8, 0, 0),
                'tuesday_end': dtime(18, 0, 0),
                'wednesday_start': dtime(8, 0, 0),
                'wednesday_end': dtime(18, 0, 0),
                'thursday_start': dtime(8, 0, 0),
                'thursday_end': dtime(18, 0, 0),
                'friday_start': dtime(8, 0, 0),
                'friday_end': dtime(18, 0, 0),
                'saturday_start': None,
                'saturday_end': None,
                'sunday_start': None,
                'sunday_end': None
            }
        },
        'holidays': [],
        'door_settings': {
            'name': 'test_zone',
            'unlock_duration': 5
        }
    }


@pytest.fixture
def sample_master_cards():
    """Sample master cards for testing"""
    return {
        '999,11111': {
            'card_id': 'master001',
            'user_id': '11111',
            'facility': '999',
            'description': 'Emergency Master'
        }
    }
