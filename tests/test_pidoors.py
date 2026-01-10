"""
Tests for Main PiDoors Controller
pidoors/pidoors.py

These tests mock GPIO and database to test logic without hardware.
"""

import pytest
import sys
import os
import json
import tempfile
import threading
import time
from unittest.mock import MagicMock, patch, mock_open
from datetime import datetime, timedelta

# Mock GPIO before importing pidoors
sys.modules['RPi'] = MagicMock()
sys.modules['RPi.GPIO'] = MagicMock()

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'pidoors'))


class TestThreadLocks:
    """Tests for thread lock usage"""

    def test_locks_are_defined(self):
        """Test that all required locks are defined"""
        # Import with mocked GPIO
        import pidoors

        assert hasattr(pidoors, 'state_lock')
        assert hasattr(pidoors, 'cache_lock')
        assert hasattr(pidoors, 'card_lock')
        assert hasattr(pidoors, 'master_lock')
        assert hasattr(pidoors, 'wiegand_lock')

        # All should be Lock instances
        assert isinstance(pidoors.state_lock, type(threading.Lock()))
        assert isinstance(pidoors.cache_lock, type(threading.Lock()))
        assert isinstance(pidoors.card_lock, type(threading.Lock()))
        assert isinstance(pidoors.master_lock, type(threading.Lock()))
        assert isinstance(pidoors.wiegand_lock, type(threading.Lock()))


class TestHelperFunctions:
    """Tests for helper functions"""

    def test_get_local_ip(self):
        """Test getting local IP address"""
        import pidoors

        ip = pidoors.get_local_ip()
        # Should return an IP address string
        assert isinstance(ip, str)
        # Should have IP format or be localhost
        parts = ip.split('.')
        assert len(parts) == 4 or ip == '127.0.0.1'


class TestCacheManagement:
    """Tests for cache management functions"""

    def test_get_cache_file(self):
        """Test getting cache file path"""
        import pidoors

        pidoors.zone = 'test_zone'
        cache_file = pidoors.get_cache_file()

        assert 'test_zone' in cache_file
        assert cache_file.endswith('.json')

    def test_is_cache_valid_no_sync(self):
        """Test cache validity when never synced"""
        import pidoors

        pidoors.cache_last_sync = 0
        assert pidoors.is_cache_valid() == False

    def test_is_cache_valid_recent(self):
        """Test cache validity when recently synced"""
        import pidoors

        pidoors.cache_last_sync = time.time() - 3600  # 1 hour ago
        assert pidoors.is_cache_valid() == True

    def test_is_cache_valid_expired(self):
        """Test cache validity when expired"""
        import pidoors

        pidoors.cache_last_sync = time.time() - 100000  # > 24 hours ago
        assert pidoors.is_cache_valid() == False


class TestMasterCardFunctions:
    """Tests for master card management"""

    def test_is_master_card_not_found(self, sample_master_cards):
        """Test checking for non-existent master card"""
        import pidoors

        with pidoors.master_lock:
            pidoors.master_cards = sample_master_cards

        result = pidoors.is_master_card('000', '00000')
        assert result is None

    def test_is_master_card_found(self, sample_master_cards):
        """Test checking for existing master card"""
        import pidoors

        with pidoors.master_lock:
            pidoors.master_cards = sample_master_cards

        result = pidoors.is_master_card('999', '11111')
        assert result is not None
        assert result['card_id'] == 'master001'

    def test_get_master_card_info(self, sample_master_cards):
        """Test getting master card info"""
        import pidoors

        with pidoors.master_lock:
            pidoors.master_cards = sample_master_cards

        info = pidoors.get_master_card_info('999', '11111')
        assert info is not None
        assert info['description'] == 'Emergency Master'
        # Should be a copy
        assert info is not sample_master_cards['999,11111']


class TestScheduleChecking:
    """Tests for schedule validation"""

    def test_check_schedule_no_schedule(self, sample_cache):
        """Test that no schedule means always allowed"""
        import pidoors

        with pidoors.cache_lock:
            pidoors.local_cache = sample_cache

        result = pidoors.check_schedule(None, datetime.now())
        assert result == True

    def test_check_schedule_24_7(self, sample_cache):
        """Test 24/7 schedule always allows"""
        import pidoors

        with pidoors.cache_lock:
            pidoors.local_cache = sample_cache

        # Any time should be allowed for 24/7 schedule
        result = pidoors.check_schedule(1, datetime.now())
        assert result == True

    def test_check_schedule_not_found(self, sample_cache):
        """Test that missing schedule denies access (fail secure)"""
        import pidoors

        with pidoors.cache_lock:
            pidoors.local_cache = sample_cache

        result = pidoors.check_schedule(999, datetime.now())
        assert result == False

    def test_check_schedule_within_hours(self, sample_cache):
        """Test schedule within business hours"""
        import pidoors

        with pidoors.cache_lock:
            pidoors.local_cache = sample_cache

        # Monday at 10:00 (within business hours)
        test_time = datetime(2026, 1, 13, 10, 0, 0)  # A Monday
        result = pidoors.check_schedule(2, test_time)
        assert result == True

    def test_check_schedule_outside_hours(self, sample_cache):
        """Test schedule outside business hours"""
        import pidoors

        with pidoors.cache_lock:
            pidoors.local_cache = sample_cache

        # Monday at 20:00 (outside business hours)
        test_time = datetime(2026, 1, 13, 20, 0, 0)  # A Monday
        result = pidoors.check_schedule(2, test_time)
        assert result == False


class TestHolidayChecking:
    """Tests for holiday validation"""

    def test_is_holiday_denied_no_holidays(self, sample_cache):
        """Test no holidays returns False"""
        import pidoors

        with pidoors.cache_lock:
            pidoors.local_cache = sample_cache

        result = pidoors.is_holiday_denied(datetime.now())
        assert result == False

    def test_is_holiday_denied_with_holiday(self, sample_cache):
        """Test holiday that denies access"""
        import pidoors

        sample_cache['holidays'] = [
            {'date': datetime.now().strftime('%Y-%m-%d'), 'access_denied': True, 'name': 'Test Holiday'}
        ]

        with pidoors.cache_lock:
            pidoors.local_cache = sample_cache

        result = pidoors.is_holiday_denied(datetime.now())
        assert result == True

    def test_is_holiday_denied_allow_access(self, sample_cache):
        """Test holiday that allows access"""
        import pidoors

        sample_cache['holidays'] = [
            {'date': datetime.now().strftime('%Y-%m-%d'), 'access_denied': False, 'name': 'Open Day'}
        ]

        with pidoors.cache_lock:
            pidoors.local_cache = sample_cache

        result = pidoors.is_holiday_denied(datetime.now())
        assert result == False


class TestWiegandValidation:
    """Tests for Wiegand bitstring validation"""

    def test_validate_bits_26bit(self, valid_26bit_card):
        """Test validating 26-bit card"""
        import pidoors

        # Set up mock for open_door/reject_card
        pidoors.zone = 'test_zone'
        pidoors.config = {'test_zone': {'latch_gpio': 18}}

        # Initialize format registry
        if pidoors.FORMAT_REGISTRY_AVAILABLE:
            from formats.wiegand_formats import init_registry
            pidoors.format_registry = init_registry()

            # Mock lookup_card to capture the call
            original_lookup = pidoors.lookup_card
            lookup_calls = []

            def mock_lookup(card_id, facility, user_id, bstr):
                lookup_calls.append((card_id, facility, user_id))

            pidoors.lookup_card = mock_lookup

            try:
                result = pidoors.validate_bits(valid_26bit_card['bitstring'])
                assert result == True
                assert len(lookup_calls) == 1
                assert lookup_calls[0][1] == valid_26bit_card['facility']
                assert lookup_calls[0][2] == valid_26bit_card['user_id']
            finally:
                pidoors.lookup_card = original_lookup

    def test_validate_bits_parity_error(self, valid_26bit_card):
        """Test that parity errors are rejected"""
        import pidoors

        if pidoors.FORMAT_REGISTRY_AVAILABLE:
            from formats.wiegand_formats import init_registry
            pidoors.format_registry = init_registry()

            # Corrupt a data bit (not parity bit) to cause parity error
            # Flip bit 5 (a data bit in the facility code)
            bits = list(valid_26bit_card['bitstring'])
            bits[5] = '1' if bits[5] == '0' else '0'
            bad_bits = ''.join(bits)
            result = pidoors.validate_bits(bad_bits)
            assert result == False

    def test_validate_bits_unsupported_length(self):
        """Test that unsupported lengths are rejected"""
        import pidoors

        if pidoors.FORMAT_REGISTRY_AVAILABLE:
            from formats.wiegand_formats import init_registry
            pidoors.format_registry = init_registry()

            result = pidoors.validate_bits('0' * 99)
            assert result == False


class TestLogFunctions:
    """Tests for logging functions with file locking"""

    def test_log_access_creates_entry(self):
        """Test log_access creates proper entry"""
        import pidoors

        pidoors.zone = 'test_zone'
        pidoors.myip = '192.168.1.100'

        with tempfile.TemporaryDirectory() as tmpdir:
            pidoors.CACHE_DIR = tmpdir

            # Call log_access
            pidoors.log_access('user123', 'card456', '100', True, 'Test access')

            # Check log file was created
            log_file = os.path.join(tmpdir, 'test_zone_access_log.json')
            assert os.path.exists(log_file)

            with open(log_file, 'r') as f:
                logs = json.load(f)

            assert len(logs) == 1
            assert logs[0]['user_id'] == 'user123'
            assert logs[0]['card_id'] == 'card456'
            assert logs[0]['granted'] == True

    def test_log_access_limits_entries(self):
        """Test log_access keeps only last 1000 entries"""
        import pidoors

        pidoors.zone = 'test_zone'
        pidoors.myip = '192.168.1.100'

        with tempfile.TemporaryDirectory() as tmpdir:
            pidoors.CACHE_DIR = tmpdir

            # Create log file with 1000 entries
            log_file = os.path.join(tmpdir, 'test_zone_access_log.json')
            existing_logs = [{'entry': i} for i in range(1000)]
            with open(log_file, 'w') as f:
                json.dump(existing_logs, f)

            # Add one more entry
            pidoors.log_access('user', 'card', '100', True)

            # Check we still have 1000 entries
            with open(log_file, 'r') as f:
                logs = json.load(f)

            assert len(logs) == 1000
            # First entry should be entry 1, not entry 0
            assert logs[0]['entry'] == 1

    def test_log_door_event_creates_entry(self):
        """Test log_door_event creates proper entry"""
        import pidoors

        pidoors.zone = 'test_zone'

        with tempfile.TemporaryDirectory() as tmpdir:
            pidoors.CACHE_DIR = tmpdir

            pidoors.log_door_event('door_opened', 'Manual open')

            log_file = os.path.join(tmpdir, 'test_zone_door_events.json')
            assert os.path.exists(log_file)

            with open(log_file, 'r') as f:
                logs = json.load(f)

            assert len(logs) == 1
            assert logs[0]['event_type'] == 'door_opened'
            assert logs[0]['details'] == 'Manual open'

    def test_log_handles_corruption(self):
        """Test log functions handle corrupted JSON gracefully"""
        import pidoors

        pidoors.zone = 'test_zone'
        pidoors.myip = '192.168.1.100'

        with tempfile.TemporaryDirectory() as tmpdir:
            pidoors.CACHE_DIR = tmpdir

            # Create corrupted log file
            log_file = os.path.join(tmpdir, 'test_zone_access_log.json')
            with open(log_file, 'w') as f:
                f.write('not valid json {{{')

            # This should not raise, should reset the log
            pidoors.log_access('user', 'card', '100', True)

            # Check log was reset and new entry added
            with open(log_file, 'r') as f:
                logs = json.load(f)

            assert len(logs) == 1


class TestRepeatSwipeDetection:
    """Tests for repeat swipe detection in open_door"""

    def test_repeat_swipe_resets_on_different_card(self):
        """Test repeat count resets when different card is used"""
        import pidoors

        # Reset state
        with pidoors.card_lock:
            pidoors.last_card = 'user1'
            pidoors.repeat_read_count = 2
            pidoors.repeat_read_timeout = time.time() + 30

        # Mock unlock_briefly to avoid actual GPIO calls
        pidoors.unlock_briefly = MagicMock()
        pidoors.zone = 'test_zone'
        pidoors.config = {'test_zone': {'latch_gpio': 18}}
        pidoors.log_door_event = MagicMock()

        # Simulate different user
        pidoors.open_door('user2', 'User Two')

        with pidoors.card_lock:
            assert pidoors.repeat_read_count == 0
            assert pidoors.last_card == 'user2'


class TestConfigurationLoading:
    """Tests for configuration loading"""

    def test_load_json(self):
        """Test JSON file loading"""
        import pidoors

        with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
            json.dump({'key': 'value'}, f)
            f.flush()

            try:
                result = pidoors.load_json(f.name)
                assert result == {'key': 'value'}
            finally:
                os.unlink(f.name)

    def test_save_json(self):
        """Test JSON file saving"""
        import pidoors

        with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
            temp_path = f.name

        try:
            pidoors.save_json(temp_path, {'key': 'value', 'num': 123})

            with open(temp_path, 'r') as f:
                result = json.load(f)

            assert result['key'] == 'value'
            assert result['num'] == 123
        finally:
            os.unlink(temp_path)
