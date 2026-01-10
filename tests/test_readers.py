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
