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


class TestDebugFunctions:
    """Tests for debug and logging functions"""

    def test_debug_mode_disabled(self, capsys):
        """Test debug output when DEBUG_MODE is disabled"""
        import pidoors

        pidoors.DEBUG_MODE = False
        pidoors.debug("Test message")

        captured = capsys.readouterr()
        assert "Test message" not in captured.out

    def test_debug_mode_enabled(self, capsys):
        """Test debug output when DEBUG_MODE is enabled"""
        import pidoors

        pidoors.DEBUG_MODE = True
        pidoors.debug("Test debug message")

        captured = capsys.readouterr()
        assert "Test debug message" in captured.out
        pidoors.DEBUG_MODE = False

    def test_toggle_debug(self):
        """Test toggling debug mode"""
        import pidoors

        original = pidoors.DEBUG_MODE
        pidoors.toggle_debug()
        assert pidoors.DEBUG_MODE != original

        pidoors.toggle_debug()
        assert pidoors.DEBUG_MODE == original


class TestCacheLoadSave:
    """Tests for cache loading and saving"""

    def test_load_cache_no_file(self):
        """Test loading cache when file doesn't exist"""
        import pidoors

        with tempfile.TemporaryDirectory() as tmpdir:
            pidoors.CACHE_DIR = tmpdir
            pidoors.zone = 'test_zone'
            pidoors.local_cache = {'old': 'data'}
            pidoors.cache_last_sync = 999

            pidoors.load_cache()

            # Should not change cache if file doesn't exist
            assert pidoors.local_cache == {'old': 'data'}

    def test_load_cache_valid_file(self):
        """Test loading cache from valid file"""
        import pidoors

        with tempfile.TemporaryDirectory() as tmpdir:
            pidoors.CACHE_DIR = tmpdir
            pidoors.zone = 'test_zone'

            # Create cache file
            cache_file = os.path.join(tmpdir, 'test_zone_access_cache.json')
            cache_data = {
                'cards': {'123,456': {'card_id': 'test'}},
                'sync_time': time.time()
            }
            with open(cache_file, 'w') as f:
                json.dump(cache_data, f)

            pidoors.load_cache()

            assert '123,456' in pidoors.local_cache

    def test_load_cache_expired(self, capsys):
        """Test loading expired cache"""
        import pidoors

        pidoors.DEBUG_MODE = False

        with tempfile.TemporaryDirectory() as tmpdir:
            pidoors.CACHE_DIR = tmpdir
            pidoors.zone = 'test_zone'

            # Create expired cache file
            cache_file = os.path.join(tmpdir, 'test_zone_access_cache.json')
            cache_data = {
                'cards': {'123,456': {'card_id': 'test'}},
                'sync_time': time.time() - 100000  # >24 hours ago
            }
            with open(cache_file, 'w') as f:
                json.dump(cache_data, f)

            # Mock syslog to capture report
            with patch('pidoors.syslog'):
                pidoors.load_cache()

    def test_load_cache_corrupted(self):
        """Test loading corrupted cache file"""
        import pidoors

        with tempfile.TemporaryDirectory() as tmpdir:
            pidoors.CACHE_DIR = tmpdir
            pidoors.zone = 'test_zone'

            # Create corrupted cache file
            cache_file = os.path.join(tmpdir, 'test_zone_access_cache.json')
            with open(cache_file, 'w') as f:
                f.write('not valid json {{{')

            pidoors.local_cache = {}
            pidoors.cache_last_sync = 999

            with patch('pidoors.syslog'):
                pidoors.load_cache()

            # Should reset on error
            assert pidoors.local_cache == {}
            assert pidoors.cache_last_sync == 0

    def test_save_cache(self):
        """Test saving cache to file"""
        import pidoors

        with tempfile.TemporaryDirectory() as tmpdir:
            pidoors.CACHE_DIR = tmpdir
            pidoors.zone = 'test_zone'
            pidoors.local_cache = {'cards': {'123,456': {'card_id': 'test'}}}

            pidoors.save_cache()

            cache_file = os.path.join(tmpdir, 'test_zone_access_cache.json')
            assert os.path.exists(cache_file)

            with open(cache_file, 'r') as f:
                saved_data = json.load(f)

            assert saved_data['zone'] == 'test_zone'
            assert 'sync_time' in saved_data


class TestMasterCardLoadSave:
    """Tests for master card loading and saving"""

    def test_load_master_cards_no_file(self):
        """Test loading master cards when file doesn't exist"""
        import pidoors

        with tempfile.TemporaryDirectory() as tmpdir:
            pidoors.MASTER_CARDS_FILE = os.path.join(tmpdir, 'master_cards.json')

            with pidoors.master_lock:
                pidoors.master_cards = {'old': 'data'}

            with patch('pidoors.syslog'):
                pidoors.load_master_cards()

            # Should not change if file doesn't exist
            with pidoors.master_lock:
                assert pidoors.master_cards == {'old': 'data'}

    def test_load_master_cards_valid_file(self):
        """Test loading master cards from valid file"""
        import pidoors

        with tempfile.TemporaryDirectory() as tmpdir:
            master_file = os.path.join(tmpdir, 'master_cards.json')
            pidoors.MASTER_CARDS_FILE = master_file

            master_data = {
                'cards': {
                    '999,11111': {
                        'card_id': 'master001',
                        'description': 'Test Master'
                    }
                }
            }
            with open(master_file, 'w') as f:
                json.dump(master_data, f)

            with patch('pidoors.syslog'):
                pidoors.load_master_cards()

            with pidoors.master_lock:
                assert '999,11111' in pidoors.master_cards

    def test_load_master_cards_corrupted(self):
        """Test loading corrupted master cards file"""
        import pidoors

        with tempfile.TemporaryDirectory() as tmpdir:
            master_file = os.path.join(tmpdir, 'master_cards.json')
            pidoors.MASTER_CARDS_FILE = master_file

            with open(master_file, 'w') as f:
                f.write('invalid json')

            with patch('pidoors.syslog'):
                pidoors.load_master_cards()

            with pidoors.master_lock:
                assert pidoors.master_cards == {}

    def test_save_master_cards(self):
        """Test saving master cards to file"""
        import pidoors

        with tempfile.TemporaryDirectory() as tmpdir:
            master_file = os.path.join(tmpdir, 'master_cards.json')
            pidoors.MASTER_CARDS_FILE = master_file

            with pidoors.master_lock:
                pidoors.master_cards = {
                    '999,11111': {
                        'card_id': 'master001',
                        'description': 'Test Master'
                    }
                }

            pidoors.save_master_cards()

            assert os.path.exists(master_file)
            with open(master_file, 'r') as f:
                saved_data = json.load(f)

            assert '999,11111' in saved_data['cards']


class TestLegacyWiegandValidation:
    """Tests for legacy 26-bit and 34-bit Wiegand validation"""

    def test_validate_26bit_legacy_valid(self, valid_26bit_card):
        """Test legacy 26-bit validation with valid card"""
        import pidoors

        pidoors.zone = 'test_zone'
        pidoors.config = {'test_zone': {'latch_gpio': 18}}

        # Mock lookup_card
        lookup_calls = []
        original_lookup = pidoors.lookup_card

        def mock_lookup(card_id, facility, user_id, bstr):
            lookup_calls.append((card_id, facility, user_id))

        pidoors.lookup_card = mock_lookup

        try:
            # Temporarily disable format registry to test legacy
            orig_registry = pidoors.FORMAT_REGISTRY_AVAILABLE
            pidoors.FORMAT_REGISTRY_AVAILABLE = False

            result = pidoors.validate_26bit_legacy(valid_26bit_card['bitstring'])
            assert result == True
            assert len(lookup_calls) == 1
            assert lookup_calls[0][1] == valid_26bit_card['facility']
        finally:
            pidoors.lookup_card = original_lookup
            pidoors.FORMAT_REGISTRY_AVAILABLE = orig_registry

    def test_validate_26bit_legacy_parity_error(self, valid_26bit_card):
        """Test legacy 26-bit validation with parity error"""
        import pidoors

        # Corrupt a data bit
        bits = list(valid_26bit_card['bitstring'])
        bits[5] = '1' if bits[5] == '0' else '0'
        bad_bits = ''.join(bits)

        result = pidoors.validate_26bit_legacy(bad_bits)
        assert result == False

    def test_validate_34bit_legacy_valid(self, valid_34bit_card):
        """Test legacy 34-bit validation with valid card"""
        import pidoors

        pidoors.zone = 'test_zone'
        pidoors.config = {'test_zone': {'latch_gpio': 18}}

        lookup_calls = []
        original_lookup = pidoors.lookup_card

        def mock_lookup(card_id, facility, user_id, bstr):
            lookup_calls.append((card_id, facility, user_id))

        pidoors.lookup_card = mock_lookup

        try:
            orig_registry = pidoors.FORMAT_REGISTRY_AVAILABLE
            pidoors.FORMAT_REGISTRY_AVAILABLE = False

            result = pidoors.validate_34bit_legacy(valid_34bit_card['bitstring'])
            assert result == True
            assert len(lookup_calls) == 1
        finally:
            pidoors.lookup_card = original_lookup
            pidoors.FORMAT_REGISTRY_AVAILABLE = orig_registry

    def test_validate_34bit_legacy_parity_error(self, valid_34bit_card):
        """Test legacy 34-bit validation with parity error"""
        import pidoors

        # Corrupt a data bit
        bits = list(valid_34bit_card['bitstring'])
        bits[10] = '1' if bits[10] == '0' else '0'
        bad_bits = ''.join(bits)

        result = pidoors.validate_34bit_legacy(bad_bits)
        assert result == False

    def test_validate_bits_legacy_fallback(self):
        """Test validate_bits falls back to legacy for unsupported lengths"""
        import pidoors

        orig_registry = pidoors.FORMAT_REGISTRY_AVAILABLE
        pidoors.FORMAT_REGISTRY_AVAILABLE = False

        try:
            # 40-bit is unsupported in legacy mode
            result = pidoors.validate_bits('0' * 40)
            assert result == False
        finally:
            pidoors.FORMAT_REGISTRY_AVAILABLE = orig_registry


class TestOpenDoorTripleSwipe:
    """Tests for triple swipe door toggle functionality"""

    def test_triple_swipe_unlocks_door(self):
        """Test that triple swipe permanently unlocks door"""
        import pidoors

        pidoors.zone = 'test_zone'
        pidoors.config = {'test_zone': {'latch_gpio': 18, 'unlocked': False}}
        pidoors.unlock_briefly = MagicMock()
        pidoors.unlock_door = MagicMock()
        pidoors.log_door_event = MagicMock()

        # Reset state - need repeat_read_count = 1 so next swipe makes it 2 (triggers toggle)
        # The logic is: >= 2 triggers toggle, so swipe 1 sets to 0, swipe 2 sets to 1, swipe 3 sets to 2
        with pidoors.card_lock:
            pidoors.last_card = 'user1'
            pidoors.repeat_read_count = 1  # After two swipes
            pidoors.repeat_read_timeout = time.time() + 30

        # Mock report to avoid syslog
        with patch('pidoors.syslog'):
            # Third swipe - should increment to 2 and toggle lock
            pidoors.open_door('user1', 'Test User')

        # Door should now be unlocked (repeat_read_count became 2, which is >= 2)
        assert pidoors.config['test_zone']['unlocked'] == True
        pidoors.unlock_door.assert_called()

    def test_triple_swipe_locks_door(self):
        """Test that triple swipe on unlocked door locks it"""
        import pidoors

        pidoors.zone = 'test_zone'
        pidoors.config = {'test_zone': {'latch_gpio': 18, 'unlocked': True}}
        pidoors.lock_door = MagicMock()
        pidoors.log_door_event = MagicMock()

        # Setup for third swipe
        with pidoors.card_lock:
            pidoors.last_card = 'user1'
            pidoors.repeat_read_count = 2
            pidoors.repeat_read_timeout = time.time() + 30

        with patch('pidoors.syslog'):
            pidoors.open_door('user1', 'Test User')

        # Door should now be locked
        assert pidoors.config['test_zone']['unlocked'] == False
        pidoors.lock_door.assert_called()

    def test_normal_access_when_unlocked(self):
        """Test normal access when door is already unlocked"""
        import pidoors

        pidoors.zone = 'test_zone'
        pidoors.config = {'test_zone': {'latch_gpio': 18, 'unlocked': True}}
        pidoors.unlock_briefly = MagicMock()

        # Reset state for single swipe
        with pidoors.card_lock:
            pidoors.last_card = None
            pidoors.repeat_read_count = 0
            pidoors.repeat_read_timeout = 0

        with patch('pidoors.syslog'):
            pidoors.open_door('user1', 'Test User')

        # unlock_briefly should NOT be called when door is already unlocked
        pidoors.unlock_briefly.assert_not_called()


class TestRejectCard:
    """Tests for card rejection functionality"""

    def test_reject_card_resets_repeat_count(self):
        """Test that reject_card resets the repeat swipe counter"""
        import pidoors

        with pidoors.card_lock:
            pidoors.repeat_read_count = 5

        # Mock GPIO and syslog
        with patch('pidoors.GPIO'), patch('pidoors.syslog'):
            pidoors.reject_card('user123', 'Test rejection')

        with pidoors.card_lock:
            assert pidoors.repeat_read_count == 0

    def test_reject_card_logs_reason(self, capsys):
        """Test that reject_card reports the reason"""
        import pidoors

        pidoors.zone = 'test_zone'

        with patch('pidoors.GPIO'), patch('pidoors.syslog') as mock_syslog:
            pidoors.reject_card('user123', 'Card expired')

        # Check syslog was called with the reason
        mock_syslog.syslog.assert_called()
        call_args = str(mock_syslog.syslog.call_args)
        assert 'Card expired' in call_args or 'user123' in call_args


class TestLookupCardWithCache:
    """Tests for lookup_card using cache"""

    def test_lookup_card_cache_access_granted(self, sample_cache):
        """Test card lookup grants access from cache"""
        import pidoors

        pidoors.zone = 'test_zone'
        pidoors.config = {'test_zone': {'latch_gpio': 18}}
        pidoors.db_connected = False
        pidoors.cache_last_sync = time.time()  # Valid cache
        pidoors.MYSQL_AVAILABLE = False

        with pidoors.cache_lock:
            pidoors.local_cache = sample_cache

        with pidoors.master_lock:
            pidoors.master_cards = {}

        open_door_calls = []
        log_access_calls = []

        def mock_open_door(user_id, name):
            open_door_calls.append((user_id, name))

        def mock_log_access(user_id, card_id, facility, granted, reason=''):
            log_access_calls.append((user_id, card_id, facility, granted, reason))

        original_open = pidoors.open_door
        original_log = pidoors.log_access
        pidoors.open_door = mock_open_door
        pidoors.log_access = mock_log_access

        try:
            with patch('pidoors.syslog'):
                pidoors.lookup_card('00abcdef', '123', '45678', '')

            assert len(open_door_calls) == 1
            assert open_door_calls[0][1] == 'John Doe'
            assert log_access_calls[0][3] == True  # granted
        finally:
            pidoors.open_door = original_open
            pidoors.log_access = original_log

    def test_lookup_card_cache_no_access_to_door(self, sample_cache):
        """Test card lookup denies access when card has no door access"""
        import pidoors

        pidoors.zone = 'other_zone'  # Different zone
        pidoors.config = {'other_zone': {'latch_gpio': 18}}
        pidoors.db_connected = False
        pidoors.cache_last_sync = time.time()
        pidoors.MYSQL_AVAILABLE = False

        with pidoors.cache_lock:
            pidoors.local_cache = sample_cache

        with pidoors.master_lock:
            pidoors.master_cards = {}

        reject_calls = []
        log_access_calls = []

        def mock_reject(user_id, reason=''):
            reject_calls.append((user_id, reason))

        def mock_log_access(user_id, card_id, facility, granted, reason=''):
            log_access_calls.append((user_id, card_id, facility, granted, reason))

        original_reject = pidoors.reject_card
        original_log = pidoors.log_access
        pidoors.reject_card = mock_reject
        pidoors.log_access = mock_log_access

        try:
            with patch('pidoors.syslog'):
                pidoors.lookup_card('00abcdef', '123', '45678', '')

            assert len(reject_calls) == 1
            assert 'No access' in reject_calls[0][1]
        finally:
            pidoors.reject_card = original_reject
            pidoors.log_access = original_log

    def test_lookup_card_not_in_cache(self, sample_cache):
        """Test card lookup when card is not in cache"""
        import pidoors

        pidoors.zone = 'test_zone'
        pidoors.config = {'test_zone': {'latch_gpio': 18}}
        pidoors.db_connected = False
        pidoors.cache_last_sync = time.time()
        pidoors.MYSQL_AVAILABLE = False

        with pidoors.cache_lock:
            pidoors.local_cache = sample_cache

        with pidoors.master_lock:
            pidoors.master_cards = {}

        reject_calls = []

        def mock_reject(user_id, reason=''):
            reject_calls.append((user_id, reason))

        original_reject = pidoors.reject_card
        pidoors.reject_card = mock_reject
        pidoors.log_access = MagicMock()

        try:
            with patch('pidoors.syslog'):
                pidoors.lookup_card('unknown', '000', '00000', '')

            assert len(reject_calls) == 1
            assert 'not in cache' in reject_calls[0][1]
        finally:
            pidoors.reject_card = original_reject

    def test_lookup_card_expired_cache(self):
        """Test card lookup when cache is expired"""
        import pidoors

        pidoors.zone = 'test_zone'
        pidoors.config = {'test_zone': {'latch_gpio': 18}}
        pidoors.db_connected = False
        pidoors.cache_last_sync = 0  # Expired cache
        pidoors.MYSQL_AVAILABLE = False

        with pidoors.master_lock:
            pidoors.master_cards = {}

        reject_calls = []

        def mock_reject(user_id, reason=''):
            reject_calls.append((user_id, reason))

        original_reject = pidoors.reject_card
        pidoors.reject_card = mock_reject
        pidoors.log_access = MagicMock()

        try:
            with patch('pidoors.syslog'):
                pidoors.lookup_card('00abcdef', '123', '45678', '')

            assert len(reject_calls) == 1
            assert 'offline' in reject_calls[0][1].lower() or 'cache' in reject_calls[0][1].lower()
        finally:
            pidoors.reject_card = original_reject


class TestScheduleWithStringTimes:
    """Tests for schedule checking with string time values"""

    def test_check_schedule_string_times(self):
        """Test schedule with string time values"""
        import pidoors

        cache_with_string_times = {
            'schedules': {
                3: {
                    'id': 3,
                    'name': 'String Times',
                    'is_24_7': 0,
                    'monday_start': '08:00:00',
                    'monday_end': '18:00:00'
                }
            }
        }

        with pidoors.cache_lock:
            pidoors.local_cache = cache_with_string_times

        # Monday at 10:00 (within hours) - January 12, 2026 is a Monday
        test_time = datetime(2026, 1, 12, 10, 0, 0)
        result = pidoors.check_schedule(3, test_time)
        assert result == True

        # Monday at 20:00 (outside hours)
        test_time = datetime(2026, 1, 12, 20, 0, 0)
        result = pidoors.check_schedule(3, test_time)
        assert result == False

    def test_check_schedule_no_day_times(self):
        """Test schedule when day has no times configured"""
        import pidoors

        cache_with_no_saturday = {
            'schedules': {
                4: {
                    'id': 4,
                    'name': 'Weekdays Only',
                    'is_24_7': 0,
                    'saturday_start': None,
                    'saturday_end': None
                }
            }
        }

        with pidoors.cache_lock:
            pidoors.local_cache = cache_with_no_saturday

        # Saturday
        test_time = datetime(2026, 1, 10, 10, 0, 0)  # A Saturday
        result = pidoors.check_schedule(4, test_time)
        assert result == False


class TestCardValidityDates:
    """Tests for card validity date checking"""

    def test_card_not_yet_valid(self):
        """Test card that is not yet valid"""
        import pidoors

        pidoors.zone = 'test_zone'
        pidoors.config = {'test_zone': {'latch_gpio': 18}}
        pidoors.db_connected = False
        pidoors.cache_last_sync = time.time()
        pidoors.MYSQL_AVAILABLE = False

        future_date = (datetime.now() + timedelta(days=30)).strftime('%Y-%m-%d')
        cache_with_future_card = {
            'cards': {
                '123,45678': {
                    'card_id': '00abcdef',
                    'firstname': 'Future',
                    'lastname': 'User',
                    'doors': 'test_zone',
                    'schedule_id': None,
                    'valid_from': future_date,
                    'valid_until': None
                }
            },
            'schedules': {},
            'holidays': []
        }

        with pidoors.cache_lock:
            pidoors.local_cache = cache_with_future_card

        with pidoors.master_lock:
            pidoors.master_cards = {}

        reject_calls = []

        def mock_reject(user_id, reason=''):
            reject_calls.append((user_id, reason))

        original_reject = pidoors.reject_card
        pidoors.reject_card = mock_reject
        pidoors.log_access = MagicMock()

        try:
            with patch('pidoors.syslog'):
                pidoors.lookup_card('00abcdef', '123', '45678', '')

            assert len(reject_calls) == 1
            assert 'not yet valid' in reject_calls[0][1]
        finally:
            pidoors.reject_card = original_reject

    def test_card_expired(self):
        """Test card that has expired"""
        import pidoors

        pidoors.zone = 'test_zone'
        pidoors.config = {'test_zone': {'latch_gpio': 18}}
        pidoors.db_connected = False
        pidoors.cache_last_sync = time.time()
        pidoors.MYSQL_AVAILABLE = False

        past_date = (datetime.now() - timedelta(days=30)).strftime('%Y-%m-%d')
        cache_with_expired_card = {
            'cards': {
                '123,45678': {
                    'card_id': '00abcdef',
                    'firstname': 'Expired',
                    'lastname': 'User',
                    'doors': 'test_zone',
                    'schedule_id': None,
                    'valid_from': None,
                    'valid_until': past_date
                }
            },
            'schedules': {},
            'holidays': []
        }

        with pidoors.cache_lock:
            pidoors.local_cache = cache_with_expired_card

        with pidoors.master_lock:
            pidoors.master_cards = {}

        reject_calls = []

        def mock_reject(user_id, reason=''):
            reject_calls.append((user_id, reason))

        original_reject = pidoors.reject_card
        pidoors.reject_card = mock_reject
        pidoors.log_access = MagicMock()

        try:
            with patch('pidoors.syslog'):
                pidoors.lookup_card('00abcdef', '123', '45678', '')

            assert len(reject_calls) == 1
            assert 'expired' in reject_calls[0][1].lower()
        finally:
            pidoors.reject_card = original_reject


class TestMasterCardAccess:
    """Tests for master card access"""

    def test_master_card_grants_access(self, sample_master_cards):
        """Test that master card grants access"""
        import pidoors

        pidoors.zone = 'test_zone'
        pidoors.config = {'test_zone': {'latch_gpio': 18}}
        pidoors.db_connected = False
        pidoors.MYSQL_AVAILABLE = False

        with pidoors.master_lock:
            pidoors.master_cards = sample_master_cards

        open_door_calls = []

        def mock_open_door(user_id, name):
            open_door_calls.append((user_id, name))

        original_open = pidoors.open_door
        pidoors.open_door = mock_open_door
        pidoors.log_access = MagicMock()

        try:
            with patch('pidoors.syslog'):
                pidoors.lookup_card('master001', '999', '11111', '')

            assert len(open_door_calls) == 1
            assert 'Master' in open_door_calls[0][1]
        finally:
            pidoors.open_door = original_open
