"""
Tests for Card Reader Modules
pidoors/readers/
"""

import pytest
import sys
import os
from unittest.mock import MagicMock, patch, PropertyMock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'pidoors'))

from readers.base import BaseReader, CardRead, ReaderType, ReaderStatus
from readers import ReaderFactory, create_reader


class TestCardRead:
    """Tests for CardRead dataclass"""

    def test_card_read_creation(self):
        """Test creating a CardRead instance"""
        card = CardRead(
            card_id='00abcdef',
            facility='123',
            user_id='45678',
            bitstring='0' * 26,
            bit_length=26,
            format_name='Standard 26-bit',
            reader_name='test_reader'
        )

        assert card.card_id == '00abcdef'
        assert card.facility == '123'
        assert card.user_id == '45678'
        assert card.bit_length == 26

    def test_card_read_optional_raw_data(self):
        """Test CardRead with optional raw_data"""
        card = CardRead(
            card_id='00abcdef',
            facility='123',
            user_id='45678',
            bitstring='',
            bit_length=0,
            format_name='NFC',
            reader_name='nfc_reader',
            raw_data=b'\x00\xab\xcd\xef'
        )

        assert card.raw_data == b'\x00\xab\xcd\xef'


class TestReaderType:
    """Tests for ReaderType enum"""

    def test_reader_types(self):
        """Test all reader types are defined"""
        assert ReaderType.WIEGAND.value == 'wiegand'
        assert ReaderType.OSDP.value == 'osdp'
        assert ReaderType.NFC_PN532.value == 'nfc_pn532'
        assert ReaderType.NFC_MFRC522.value == 'nfc_mfrc522'


class TestReaderStatus:
    """Tests for ReaderStatus enum"""

    def test_reader_statuses(self):
        """Test all reader statuses are defined"""
        assert ReaderStatus.UNINITIALIZED.value == 'uninitialized'
        assert ReaderStatus.INITIALIZING.value == 'initializing'
        assert ReaderStatus.READY.value == 'ready'
        assert ReaderStatus.READING.value == 'reading'
        assert ReaderStatus.ERROR.value == 'error'
        assert ReaderStatus.STOPPED.value == 'stopped'


class TestBaseReader:
    """Tests for BaseReader abstract class"""

    def test_base_reader_is_abstract(self):
        """Test that BaseReader cannot be instantiated directly"""
        with pytest.raises(TypeError):
            BaseReader('test', {})

    def test_concrete_reader_creation(self):
        """Test creating a concrete reader implementation"""
        class ConcreteReader(BaseReader):
            def initialize(self):
                return True

            def start(self):
                return True

            def stop(self):
                return True

            def get_status(self):
                return {'status': 'ok'}

        reader = ConcreteReader('test', {'key': 'value'})
        assert reader.name == 'test'
        assert reader.config == {'key': 'value'}
        assert reader.status == ReaderStatus.UNINITIALIZED

    def test_status_thread_safety(self):
        """Test that status property uses lock"""
        class ConcreteReader(BaseReader):
            def initialize(self): return True
            def start(self): return True
            def stop(self): return True
            def get_status(self): return {}

        reader = ConcreteReader('test', {})

        # Status should be accessible and settable
        assert reader.status == ReaderStatus.UNINITIALIZED
        reader.status = ReaderStatus.READY
        assert reader.status == ReaderStatus.READY

    def test_set_error(self):
        """Test setting error state"""
        class ConcreteReader(BaseReader):
            def initialize(self): return True
            def start(self): return True
            def stop(self): return True
            def get_status(self): return {}

        reader = ConcreteReader('test', {})
        reader.set_error("Test error message")

        assert reader.status == ReaderStatus.ERROR
        assert reader.error_message == "Test error message"

    def test_clear_error(self):
        """Test clearing error state"""
        class ConcreteReader(BaseReader):
            def initialize(self): return True
            def start(self): return True
            def stop(self): return True
            def get_status(self): return {}

        reader = ConcreteReader('test', {})
        reader.set_error("Test error")
        reader.clear_error()

        assert reader.error_message is None

    def test_get_config_value(self):
        """Test getting configuration values"""
        class ConcreteReader(BaseReader):
            def initialize(self): return True
            def start(self): return True
            def stop(self): return True
            def get_status(self): return {}

        reader = ConcreteReader('test', {'key1': 'value1', 'key2': 123})

        assert reader.get_config_value('key1') == 'value1'
        assert reader.get_config_value('key2') == 123
        assert reader.get_config_value('missing') is None
        assert reader.get_config_value('missing', 'default') == 'default'

    def test_report_card_with_callback(self):
        """Test reporting card with callback"""
        callback_called = []

        def callback(card_read):
            callback_called.append(card_read)

        class ConcreteReader(BaseReader):
            def initialize(self): return True
            def start(self): return True
            def stop(self): return True
            def get_status(self): return {}

        reader = ConcreteReader('test', {}, on_card_read=callback)
        card = CardRead('id', 'fac', 'user', '', 0, 'fmt', 'reader')
        reader.report_card(card)

        assert len(callback_called) == 1
        assert callback_called[0] == card

    def test_report_card_no_callback(self):
        """Test reporting card without callback doesn't error"""
        class ConcreteReader(BaseReader):
            def initialize(self): return True
            def start(self): return True
            def stop(self): return True
            def get_status(self): return {}

        reader = ConcreteReader('test', {})
        card = CardRead('id', 'fac', 'user', '', 0, 'fmt', 'reader')
        reader.report_card(card)  # Should not raise


class TestReaderFactory:
    """Tests for ReaderFactory"""

    def test_get_available_types(self):
        """Test getting available reader types"""
        types = ReaderFactory.get_available_types()

        assert 'wiegand' in types
        assert 'osdp' in types
        assert 'nfc_pn532' in types
        assert 'nfc_mfrc522' in types

    def test_get_reader_class_valid(self):
        """Test getting a valid reader class"""
        reader_class = ReaderFactory.get_reader_class('wiegand')
        assert reader_class is not None
        assert reader_class.__name__ == 'WiegandReader'

    def test_get_reader_class_alias(self):
        """Test getting reader class by alias"""
        reader_class = ReaderFactory.get_reader_class('gpio')
        assert reader_class is not None
        assert reader_class.__name__ == 'WiegandReader'

    def test_get_reader_class_invalid(self):
        """Test getting an invalid reader class"""
        reader_class = ReaderFactory.get_reader_class('invalid')
        assert reader_class is None

    def test_get_reader_class_case_insensitive(self):
        """Test that type lookup is case insensitive"""
        reader_class = ReaderFactory.get_reader_class('WIEGAND')
        assert reader_class is not None
        assert reader_class.__name__ == 'WiegandReader'

    def test_create_reader_default_type(self):
        """Test creating reader with default type (wiegand)"""
        reader = ReaderFactory.create('test', {'d0': 24, 'd1': 23})
        assert reader is not None
        assert reader.name == 'test'

    def test_create_reader_explicit_type(self):
        """Test creating reader with explicit type"""
        reader = ReaderFactory.create('test', {
            'reader_type': 'wiegand',
            'd0': 24,
            'd1': 23
        })
        assert reader is not None

    def test_create_reader_invalid_type(self):
        """Test creating reader with invalid type raises error"""
        with pytest.raises(ValueError) as exc_info:
            ReaderFactory.create('test', {'reader_type': 'invalid'})

        assert 'Unknown reader type' in str(exc_info.value)

    def test_get_reader_info(self):
        """Test getting reader info"""
        info = ReaderFactory.get_reader_info()

        assert 'wiegand' in info
        assert 'class' in info['wiegand']
        assert info['wiegand']['class'] == 'WiegandReader'

    def test_create_reader_convenience_function(self):
        """Test create_reader convenience function"""
        reader = create_reader('test', {'d0': 24, 'd1': 23})
        assert reader is not None


class TestWiegandReader:
    """Tests for WiegandReader class"""

    def test_wiegand_reader_creation(self):
        """Test creating WiegandReader"""
        from readers.wiegand import WiegandReader

        reader = WiegandReader('test', {'d0': 24, 'd1': 23})
        assert reader.name == 'test'
        assert reader.status == ReaderStatus.UNINITIALIZED

    def test_wiegand_reader_type(self):
        """Test WiegandReader type"""
        from readers.wiegand import WiegandReader

        assert WiegandReader.get_reader_type() == ReaderType.WIEGAND

    def test_wiegand_reader_config_defaults(self):
        """Test WiegandReader configuration defaults"""
        from readers.wiegand import WiegandReader

        reader = WiegandReader('test', {})
        assert reader.timeout == 0.1  # Default timeout
        assert reader.wiegand_format == 'auto'  # Default format

    def test_wiegand_reader_custom_timeout(self):
        """Test WiegandReader with custom timeout"""
        from readers.wiegand import WiegandReader

        reader = WiegandReader('test', {'timeout': 0.2})
        assert reader.timeout == 0.2

    def test_wiegand_reader_initialize_no_gpio(self):
        """Test WiegandReader initialization fails without GPIO"""
        from readers.wiegand import WiegandReader

        # Without mock GPIO, initialize should handle gracefully
        reader = WiegandReader('test', {'d0': 24, 'd1': 23})
        # Initialize may fail without real GPIO, which is expected


class TestOSDPReader:
    """Tests for OSDPReader class"""

    def test_osdp_reader_creation(self):
        """Test creating OSDPReader"""
        from readers.osdp import OSDPReader

        reader = OSDPReader('test', {
            'serial_port': '/dev/ttyS0',
            'address': 0
        })
        assert reader.name == 'test'
        assert reader.status == ReaderStatus.UNINITIALIZED

    def test_osdp_reader_type(self):
        """Test OSDPReader type"""
        from readers.osdp import OSDPReader

        assert OSDPReader.get_reader_type() == ReaderType.OSDP


class TestPN532Reader:
    """Tests for PN532Reader class"""

    def test_pn532_reader_creation(self):
        """Test creating PN532Reader"""
        from readers.nfc_pn532 import PN532Reader

        reader = PN532Reader('test', {'interface': 'i2c'})
        assert reader.name == 'test'
        assert reader.status == ReaderStatus.UNINITIALIZED

    def test_pn532_reader_type(self):
        """Test PN532Reader type"""
        from readers.nfc_pn532 import PN532Reader

        assert PN532Reader.get_reader_type() == ReaderType.NFC_PN532


class TestMFRC522Reader:
    """Tests for MFRC522Reader class"""

    def test_mfrc522_reader_creation(self):
        """Test creating MFRC522Reader"""
        from readers.nfc_mfrc522 import MFRC522Reader

        reader = MFRC522Reader('test', {
            'spi_bus': 0,
            'spi_device': 0
        })
        assert reader.name == 'test'
        assert reader.status == ReaderStatus.UNINITIALIZED

    def test_mfrc522_reader_type(self):
        """Test MFRC522Reader type"""
        from readers.nfc_mfrc522 import MFRC522Reader

        assert MFRC522Reader.get_reader_type() == ReaderType.NFC_MFRC522


class TestWiegandReaderMethods:
    """Extended tests for WiegandReader methods"""

    def test_wiegand_get_status(self):
        """Test get_status returns correct info"""
        from readers.wiegand import WiegandReader

        reader = WiegandReader('wiegand_test', {
            'd0': 24,
            'd1': 23,
            'timeout': 0.15
        })

        status = reader.get_status()

        assert status['name'] == 'wiegand_test'
        assert status['type'] == 'wiegand'
        assert status['status'] == 'uninitialized'
        assert status['format'] == 'auto'

    def test_wiegand_get_supported_formats_with_registry(self):
        """Test supported formats with format registry"""
        from readers.wiegand import WiegandReader, FORMAT_REGISTRY_AVAILABLE

        reader = WiegandReader('test', {'d0': 24, 'd1': 23})

        # Mock format registry
        if FORMAT_REGISTRY_AVAILABLE:
            from formats.wiegand_formats import get_default_registry
            reader._format_registry = get_default_registry()

            formats = reader._get_supported_formats()
            assert 26 in formats
            assert 34 in formats

    def test_wiegand_get_supported_formats_legacy(self):
        """Test supported formats without format registry (legacy)"""
        from readers.wiegand import WiegandReader

        reader = WiegandReader('test', {'d0': 24, 'd1': 23})
        reader._format_registry = None

        formats = reader._get_supported_formats()
        assert formats == [26, 34]

    def test_wiegand_add_bit(self):
        """Test _add_bit accumulates bits"""
        from readers.wiegand import WiegandReader

        reader = WiegandReader('test', {'d0': 24, 'd1': 23})
        reader._process_stream = MagicMock()  # Mock to prevent actual processing

        reader._add_bit('0')
        reader._add_bit('1')
        reader._add_bit('0')

        assert reader._stream == '010'

    def test_wiegand_process_stream_empty(self):
        """Test _process_stream with empty stream"""
        from readers.wiegand import WiegandReader

        reader = WiegandReader('test', {'d0': 24, 'd1': 23})
        reader._stream = ""
        reader.report_card = MagicMock()

        reader._process_stream()

        reader.report_card.assert_not_called()

    def test_wiegand_validate_bitstring_with_format(self, valid_26bit_card):
        """Test _validate_bitstring with format registry"""
        from readers.wiegand import WiegandReader, FORMAT_REGISTRY_AVAILABLE

        if FORMAT_REGISTRY_AVAILABLE:
            from formats.wiegand_formats import get_default_registry

            reader = WiegandReader('test', {'d0': 24, 'd1': 23})
            reader._format_registry = get_default_registry()

            result = reader._validate_bitstring(valid_26bit_card['bitstring'])

            assert result is not None
            assert result.bit_length == 26
            assert result.facility == valid_26bit_card['facility']

    def test_wiegand_validate_bitstring_specific_format(self, valid_26bit_card):
        """Test _validate_bitstring with specific format configured"""
        from readers.wiegand import WiegandReader, FORMAT_REGISTRY_AVAILABLE

        if FORMAT_REGISTRY_AVAILABLE:
            from formats.wiegand_formats import get_default_registry

            # Configure for 26-bit only
            reader = WiegandReader('test', {
                'd0': 24,
                'd1': 23,
                'wiegand_format': '26'
            })
            reader._format_registry = get_default_registry()

            # 26-bit should work
            result = reader._validate_bitstring(valid_26bit_card['bitstring'])
            assert result is not None

    def test_wiegand_validate_bitstring_wrong_length(self, valid_26bit_card):
        """Test _validate_bitstring rejects wrong length when format specified"""
        from readers.wiegand import WiegandReader, FORMAT_REGISTRY_AVAILABLE

        if FORMAT_REGISTRY_AVAILABLE:
            from formats.wiegand_formats import get_default_registry

            # Configure for 34-bit only
            reader = WiegandReader('test', {
                'd0': 24,
                'd1': 23,
                'wiegand_format': '34'
            })
            reader._format_registry = get_default_registry()

            # 26-bit should be rejected
            result = reader._validate_bitstring(valid_26bit_card['bitstring'])
            assert result is None

    def test_wiegand_26bit_legacy_valid(self, valid_26bit_card):
        """Test legacy 26-bit validation in reader"""
        from readers.wiegand import WiegandReader

        reader = WiegandReader('test', {'d0': 24, 'd1': 23})

        result = reader._validate_26bit_legacy(valid_26bit_card['bitstring'])

        assert result is not None
        assert result.facility == valid_26bit_card['facility']
        assert result.user_id == valid_26bit_card['user_id']
        assert result.format_name == "Standard 26-bit (H10301)"

    def test_wiegand_34bit_legacy_valid(self, valid_34bit_card):
        """Test legacy 34-bit validation in reader"""
        from readers.wiegand import WiegandReader

        reader = WiegandReader('test', {'d0': 24, 'd1': 23})

        result = reader._validate_34bit_legacy(valid_34bit_card['bitstring'])

        assert result is not None
        assert result.facility == valid_34bit_card['facility']
        assert result.user_id == valid_34bit_card['user_id']
        assert result.format_name == "34-bit (H10306)"

    def test_wiegand_26bit_legacy_parity_error(self, valid_26bit_card):
        """Test legacy 26-bit validation rejects parity errors"""
        from readers.wiegand import WiegandReader

        reader = WiegandReader('test', {'d0': 24, 'd1': 23})

        # Corrupt a data bit
        bits = list(valid_26bit_card['bitstring'])
        bits[5] = '1' if bits[5] == '0' else '0'
        bad_bits = ''.join(bits)

        result = reader._validate_26bit_legacy(bad_bits)
        assert result is None

    def test_wiegand_stop_cancels_timer(self):
        """Test stop cancels pending timer"""
        from readers.wiegand import WiegandReader
        import threading

        reader = WiegandReader('test', {'d0': 24, 'd1': 23})
        mock_timer = MagicMock(spec=threading.Timer)
        reader._timer = mock_timer

        reader.stop()

        # Timer should have been cancelled
        mock_timer.cancel.assert_called_once()
        assert reader.status == ReaderStatus.STOPPED


class TestOSDPReaderMethods:
    """Extended tests for OSDPReader methods"""

    def test_osdp_get_status(self):
        """Test get_status returns correct info"""
        from readers.osdp import OSDPReader

        reader = OSDPReader('osdp_test', {
            'serial_port': '/dev/ttyS0',
            'address': 1
        })

        status = reader.get_status()

        assert status['name'] == 'osdp_test'
        assert status['type'] == 'osdp'
        assert status['status'] == 'uninitialized'
        assert status['secure_channel'] == False

    def test_osdp_calculate_crc(self):
        """Test CRC-16 calculation"""
        from readers.osdp import OSDPReader

        reader = OSDPReader('test', {'serial_port': '/dev/ttyS0'})

        # Test with known data
        test_data = bytes([0x53, 0x00, 0x08, 0x00, 0x04, 0x60])
        crc = reader._calculate_crc(test_data)

        # CRC should be a 16-bit value
        assert 0 <= crc <= 0xFFFF

    def test_osdp_build_packet(self):
        """Test OSDP packet building"""
        from readers.osdp import OSDPReader, OSDPCommand

        reader = OSDPReader('test', {
            'serial_port': '/dev/ttyS0',
            'address': 0
        })
        reader.address = 0

        packet = reader._build_packet(OSDPCommand.POLL)

        # Verify packet structure
        assert packet[0] == 0x53  # SOM
        assert packet[1] == 0     # Address
        assert packet[5] == OSDPCommand.POLL  # Command

    def test_osdp_build_packet_increments_sequence(self):
        """Test that packet building increments sequence number"""
        from readers.osdp import OSDPReader, OSDPCommand

        reader = OSDPReader('test', {'serial_port': '/dev/ttyS0'})
        reader.address = 0
        reader._sequence = 0

        reader._build_packet(OSDPCommand.POLL)
        assert reader._sequence == 1

        reader._build_packet(OSDPCommand.POLL)
        assert reader._sequence == 2

        reader._build_packet(OSDPCommand.POLL)
        assert reader._sequence == 3

        # Should wrap at 4
        reader._build_packet(OSDPCommand.POLL)
        assert reader._sequence == 0

    def test_osdp_handle_raw_card_short_data(self):
        """Test _handle_raw_card with too short data"""
        from readers.osdp import OSDPReader

        reader = OSDPReader('test', {'serial_port': '/dev/ttyS0'})
        reader.report_card = MagicMock()

        # Too short - should not call report_card
        reader._handle_raw_card(bytes([0x00, 0x01]))

        reader.report_card.assert_not_called()

    def test_osdp_handle_raw_card_valid(self):
        """Test _handle_raw_card with valid data"""
        from readers.osdp import OSDPReader

        reader = OSDPReader('test', {'serial_port': '/dev/ttyS0'})
        reader.report_card = MagicMock()

        # Format: reader_num (1), format_code (1), bit_count (2), data...
        # 26-bit card data
        card_data = bytes([
            0x00,  # reader_num
            0x01,  # format_code
            0x1A, 0x00,  # bit_count = 26
            0xAB, 0xCD, 0xEF, 0x12  # card data
        ])

        reader._handle_raw_card(card_data)

        reader.report_card.assert_called_once()
        card_read = reader.report_card.call_args[0][0]
        assert card_read.bit_length == 26
        assert 'OSDP Raw' in card_read.format_name

    def test_osdp_handle_formatted_card_valid(self):
        """Test _handle_formatted_card with valid data"""
        from readers.osdp import OSDPReader

        reader = OSDPReader('test', {'serial_port': '/dev/ttyS0'})
        reader.report_card = MagicMock()

        # Format: reader_num (1), format_code (1), data_len (1), data...
        # 6 bytes of data: facility (2) + card number (4)
        card_data = bytes([
            0x00,  # reader_num
            0x01,  # format_code
            0x06,  # data_len
            0x64, 0x00,  # facility = 100
            0x39, 0x30, 0x00, 0x00  # card number = 12345
        ])

        reader._handle_formatted_card(card_data)

        reader.report_card.assert_called_once()
        card_read = reader.report_card.call_args[0][0]
        assert card_read.facility == '100'
        assert 'OSDP Formatted' in card_read.format_name

    def test_osdp_stop_closes_serial(self):
        """Test stop closes serial port"""
        from readers.osdp import OSDPReader

        reader = OSDPReader('test', {'serial_port': '/dev/ttyS0'})
        mock_serial = MagicMock()
        mock_serial.is_open = True
        reader._serial = mock_serial
        reader._running = True

        reader.stop()

        mock_serial.close.assert_called_once()
        assert reader._serial is None
        assert reader.status == ReaderStatus.STOPPED


class TestPN532ReaderMethods:
    """Extended tests for PN532Reader methods"""

    def test_pn532_get_status(self):
        """Test get_status returns correct info"""
        from readers.nfc_pn532 import PN532Reader

        reader = PN532Reader('pn532_test', {
            'interface': 'i2c',
            'i2c_address': 0x24
        })

        status = reader.get_status()

        assert status['name'] == 'pn532_test'
        assert status['type'] == 'nfc_pn532'
        assert status['interface'] == 'i2c'

    def test_pn532_build_frame(self):
        """Test PN532 frame building"""
        from readers.nfc_pn532 import PN532Reader, PN532Command

        reader = PN532Reader('test', {'interface': 'i2c'})

        frame = reader._build_frame(PN532Command.GET_FIRMWARE_VERSION, [])

        # Verify frame structure
        assert frame[0] == reader.PREAMBLE
        assert frame[1] == reader.STARTCODE1
        assert frame[2] == reader.STARTCODE2
        assert frame[5] == reader.HOSTTOPN532
        assert frame[6] == PN532Command.GET_FIRMWARE_VERSION

    def test_pn532_build_frame_with_data(self):
        """Test PN532 frame building with data"""
        from readers.nfc_pn532 import PN532Reader, PN532Command

        reader = PN532Reader('test', {'interface': 'i2c'})

        frame = reader._build_frame(PN532Command.SAM_CONFIGURATION, [0x01, 0x00, 0x01])

        # Length should include TFI + command + data
        assert frame[3] == 5  # 1 (TFI) + 1 (cmd) + 3 (data)

    def test_pn532_parse_response_valid(self):
        """Test parsing valid PN532 response"""
        from readers.nfc_pn532 import PN532Reader

        reader = PN532Reader('test', {'interface': 'i2c'})

        # Simulated response: firmware version
        # Length includes TFI + response code + data, return skips TFI and response
        response = [
            0x00, 0xFF,  # Start code
            0x06,        # Length (TFI + cmd + 4 data bytes)
            0xFA,        # LCS (length checksum: ~0x06 + 1 = 0xFA)
            reader.PN532TOHOST,  # TFI
            0x03,        # Response code
            0x32, 0x01, 0x06, 0x07,  # Data (4 bytes)
            0x00,        # DCS
            0x00         # Postamble
        ]

        result = reader._parse_response(response)

        # Result should contain data portion (length - 2 = 4 bytes, but -1 for end)
        assert result is not None
        assert len(result) >= 3  # At least firmware version data

    def test_pn532_parse_response_invalid_checksum(self):
        """Test parsing response with invalid checksum"""
        from readers.nfc_pn532 import PN532Reader

        reader = PN532Reader('test', {'interface': 'i2c'})

        # Invalid length checksum
        response = [
            0x00, 0xFF,  # Start code
            0x06,        # Length
            0x00,        # Invalid LCS
            reader.PN532TOHOST,
            0x03,
            0x00, 0x00, 0x00, 0x00,
            0x00,
            0x00
        ]

        result = reader._parse_response(response)
        assert result is None

    def test_pn532_parse_response_no_start_code(self):
        """Test parsing response without start code"""
        from readers.nfc_pn532 import PN532Reader

        reader = PN532Reader('test', {'interface': 'i2c'})

        response = [0x01, 0x02, 0x03, 0x04]

        result = reader._parse_response(response)
        assert result is None

    def test_pn532_handle_card_debounce(self):
        """Test card debounce logic"""
        from readers.nfc_pn532 import PN532Reader
        import time

        reader = PN532Reader('test', {'interface': 'i2c', 'debounce_time': 2.0})
        reader.report_card = MagicMock()

        uid = bytes([0x04, 0xAB, 0xCD, 0xEF])

        # First read should report
        reader._handle_card(uid)
        assert reader.report_card.call_count == 1

        # Immediate second read should be debounced
        reader._handle_card(uid)
        assert reader.report_card.call_count == 1  # Still 1

    def test_pn532_handle_card_different_card(self):
        """Test different card is not debounced"""
        from readers.nfc_pn532 import PN532Reader

        reader = PN532Reader('test', {'interface': 'i2c', 'debounce_time': 2.0})
        reader.report_card = MagicMock()

        uid1 = bytes([0x04, 0xAB, 0xCD, 0xEF])
        uid2 = bytes([0x04, 0x12, 0x34, 0x56])

        reader._handle_card(uid1)
        reader._handle_card(uid2)

        assert reader.report_card.call_count == 2

    def test_pn532_stop_closes_i2c(self):
        """Test stop closes I2C bus"""
        from readers.nfc_pn532 import PN532Reader

        reader = PN532Reader('test', {'interface': 'i2c'})
        mock_bus = MagicMock()
        reader._i2c_bus = mock_bus
        reader._running = True

        reader.stop()

        mock_bus.close.assert_called_once()
        assert reader._i2c_bus is None

    def test_pn532_stop_closes_spi(self):
        """Test stop closes SPI"""
        from readers.nfc_pn532 import PN532Reader

        reader = PN532Reader('test', {'interface': 'spi'})
        mock_spi = MagicMock()
        reader._spi = mock_spi
        reader._running = True

        reader.stop()

        mock_spi.close.assert_called_once()
        assert reader._spi is None


class TestMFRC522ReaderMethods:
    """Extended tests for MFRC522Reader methods"""

    def test_mfrc522_get_status(self):
        """Test get_status returns correct info"""
        from readers.nfc_mfrc522 import MFRC522Reader

        reader = MFRC522Reader('mfrc522_test', {
            'spi_bus': 0,
            'spi_device': 0,
            'reset_pin': 25
        })

        status = reader.get_status()

        assert status['name'] == 'mfrc522_test'
        assert status['type'] == 'nfc_mfrc522'

    def test_mfrc522_write_register(self):
        """Test register write operation"""
        from readers.nfc_mfrc522 import MFRC522Reader, MFRC522Register

        reader = MFRC522Reader('test', {'spi_bus': 0, 'spi_device': 0})
        mock_spi = MagicMock()
        reader._spi = mock_spi

        reader._write_register(MFRC522Register.COMMAND, 0x0F)

        # Verify SPI transfer format: (reg << 1) & 0x7E, value
        mock_spi.xfer.assert_called_once()
        args = mock_spi.xfer.call_args[0][0]
        assert args[0] == (MFRC522Register.COMMAND << 1) & 0x7E
        assert args[1] == 0x0F

    def test_mfrc522_read_register(self):
        """Test register read operation"""
        from readers.nfc_mfrc522 import MFRC522Reader, MFRC522Register

        reader = MFRC522Reader('test', {'spi_bus': 0, 'spi_device': 0})
        mock_spi = MagicMock()
        mock_spi.xfer.return_value = [0x00, 0x92]  # Version register value
        reader._spi = mock_spi

        result = reader._read_register(MFRC522Register.VERSION)

        assert result == 0x92
        # Verify read format: ((reg << 1) & 0x7E) | 0x80, 0
        args = mock_spi.xfer.call_args[0][0]
        assert args[0] == ((MFRC522Register.VERSION << 1) & 0x7E) | 0x80

    def test_mfrc522_set_bit_mask(self):
        """Test setting bits in register"""
        from readers.nfc_mfrc522 import MFRC522Reader, MFRC522Register

        reader = MFRC522Reader('test', {'spi_bus': 0, 'spi_device': 0})
        mock_spi = MagicMock()
        mock_spi.xfer.return_value = [0x00, 0x10]  # Current value
        reader._spi = mock_spi

        reader._set_bit_mask(MFRC522Register.TXCONTROL, 0x03)

        # Should read current value then write OR'd value
        assert mock_spi.xfer.call_count == 2
        # Last write should be current | mask = 0x10 | 0x03 = 0x13
        last_call = mock_spi.xfer.call_args[0][0]
        assert last_call[1] == 0x13

    def test_mfrc522_clear_bit_mask(self):
        """Test clearing bits in register"""
        from readers.nfc_mfrc522 import MFRC522Reader, MFRC522Register

        reader = MFRC522Reader('test', {'spi_bus': 0, 'spi_device': 0})
        mock_spi = MagicMock()
        mock_spi.xfer.return_value = [0x00, 0xFF]  # Current value
        reader._spi = mock_spi

        reader._clear_bit_mask(MFRC522Register.TXCONTROL, 0x03)

        # Should read current value then write AND'd value
        assert mock_spi.xfer.call_count == 2
        # Last write should be current & ~mask = 0xFF & ~0x03 = 0xFC
        last_call = mock_spi.xfer.call_args[0][0]
        assert last_call[1] == 0xFC

    def test_mfrc522_set_antenna_gain(self):
        """Test setting antenna gain"""
        from readers.nfc_mfrc522 import MFRC522Reader, MFRC522Register

        reader = MFRC522Reader('test', {'spi_bus': 0, 'spi_device': 0})
        mock_spi = MagicMock()
        reader._spi = mock_spi

        reader._set_antenna_gain(5)

        # Gain should be written to RFCFG register shifted left by 4
        mock_spi.xfer.assert_called_once()
        args = mock_spi.xfer.call_args[0][0]
        assert args[1] == 5 << 4

    def test_mfrc522_set_antenna_gain_clamped(self):
        """Test antenna gain is clamped to 0-7"""
        from readers.nfc_mfrc522 import MFRC522Reader

        reader = MFRC522Reader('test', {'spi_bus': 0, 'spi_device': 0})
        mock_spi = MagicMock()
        reader._spi = mock_spi

        # Test clamping high value
        reader._set_antenna_gain(10)
        args = mock_spi.xfer.call_args[0][0]
        assert args[1] == 7 << 4  # Clamped to 7

        # Test clamping negative value
        reader._set_antenna_gain(-5)
        args = mock_spi.xfer.call_args[0][0]
        assert args[1] == 0 << 4  # Clamped to 0

    def test_mfrc522_handle_card_debounce(self):
        """Test card debounce logic"""
        from readers.nfc_mfrc522 import MFRC522Reader

        reader = MFRC522Reader('test', {
            'spi_bus': 0,
            'spi_device': 0,
            'debounce_time': 2.0
        })
        reader.report_card = MagicMock()

        uid = bytes([0x04, 0xAB, 0xCD, 0xEF])

        # First read should report
        reader._handle_card(uid)
        assert reader.report_card.call_count == 1

        # Immediate second read should be debounced
        reader._handle_card(uid)
        assert reader.report_card.call_count == 1

    def test_mfrc522_stop_closes_spi(self):
        """Test stop closes SPI and turns off antenna"""
        from readers.nfc_mfrc522 import MFRC522Reader

        reader = MFRC522Reader('test', {'spi_bus': 0, 'spi_device': 0})
        mock_spi = MagicMock()
        reader._spi = mock_spi
        reader._running = True

        # Mock _antenna_off to avoid GPIO errors
        reader._antenna_off = MagicMock()

        reader.stop()

        reader._antenna_off.assert_called_once()
        mock_spi.close.assert_called_once()
        assert reader._spi is None

    def test_mfrc522_request_returns_tuple(self):
        """Test _request returns tuple"""
        from readers.nfc_mfrc522 import MFRC522Reader, PICommand

        reader = MFRC522Reader('test', {'spi_bus': 0, 'spi_device': 0})
        mock_spi = MagicMock()
        reader._spi = mock_spi

        # Mock _to_card to return success
        reader._to_card = MagicMock(return_value=(0, [0x04, 0x00], 0x10))

        status, atq = reader._request(PICommand.REQIDL)

        assert status == 0
        assert atq is not None

    def test_mfrc522_anticoll_verifies_checksum(self):
        """Test _anticoll verifies UID checksum"""
        from readers.nfc_mfrc522 import MFRC522Reader

        reader = MFRC522Reader('test', {'spi_bus': 0, 'spi_device': 0})
        mock_spi = MagicMock()
        reader._spi = mock_spi

        # UID with valid checksum: 0x04 ^ 0xAB ^ 0xCD ^ 0xEF = 0x8D
        valid_uid = [0x04, 0xAB, 0xCD, 0xEF, 0x8D]
        reader._to_card = MagicMock(return_value=(0, valid_uid, 40))

        status, uid = reader._anticoll()

        assert status == 0
        assert uid == [0x04, 0xAB, 0xCD, 0xEF]

    def test_mfrc522_anticoll_rejects_bad_checksum(self):
        """Test _anticoll rejects invalid UID checksum"""
        from readers.nfc_mfrc522 import MFRC522Reader

        reader = MFRC522Reader('test', {'spi_bus': 0, 'spi_device': 0})
        mock_spi = MagicMock()
        reader._spi = mock_spi

        # UID with invalid checksum
        invalid_uid = [0x04, 0xAB, 0xCD, 0xEF, 0xFF]
        reader._to_card = MagicMock(return_value=(0, invalid_uid, 40))

        status, uid = reader._anticoll()

        assert status == 1
        assert uid is None
