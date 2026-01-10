"""
PiDoors Card Reader Module
Provides a unified interface for various card reader types.

Supported reader types:
- Wiegand (26, 32, 34, 35, 36, 37, 48-bit)
- OSDP (RS-485 encrypted)
- NFC/RFID PN532 (I2C/SPI)
- NFC/RFID MFRC522 (SPI)
"""

from typing import Dict, Any, Optional, Type, Callable

from .base import BaseReader, CardRead, ReaderType, ReaderStatus
from .wiegand import WiegandReader
from .osdp import OSDPReader
from .nfc_pn532 import PN532Reader
from .nfc_mfrc522 import MFRC522Reader


class ReaderFactory:
    """
    Factory for creating card reader instances.

    Usage:
        # Create a reader from configuration
        reader = ReaderFactory.create("front_door", {
            "reader_type": "wiegand",
            "d0": 24,
            "d1": 23
        }, on_card_callback)

        # Or get reader class directly
        reader_class = ReaderFactory.get_reader_class("wiegand")
    """

    # Mapping of reader type names to classes
    _reader_types: Dict[str, Type[BaseReader]] = {
        'wiegand': WiegandReader,
        'osdp': OSDPReader,
        'nfc_pn532': PN532Reader,
        'nfc_mfrc522': MFRC522Reader,
    }

    # Aliases for convenience
    _aliases: Dict[str, str] = {
        'gpio': 'wiegand',
        'rs485': 'osdp',
        'pn532': 'nfc_pn532',
        'mfrc522': 'nfc_mfrc522',
        'rfid': 'nfc_mfrc522',  # Default RFID to MFRC522
    }

    @classmethod
    def create(cls, name: str, config: Dict[str, Any],
               on_card_read: Optional[Callable[[CardRead], None]] = None) -> Optional[BaseReader]:
        """
        Create a reader instance based on configuration.

        Args:
            name: Unique name for this reader instance
            config: Configuration dictionary containing reader_type and other settings
            on_card_read: Callback function for card read events

        Returns:
            Reader instance or None if type not found

        Raises:
            ValueError: If reader_type is not specified or unknown
        """
        reader_type = config.get('reader_type', 'wiegand').lower()

        # Check for aliases
        if reader_type in cls._aliases:
            reader_type = cls._aliases[reader_type]

        # Get reader class
        reader_class = cls._reader_types.get(reader_type)

        if not reader_class:
            raise ValueError(f"Unknown reader type: {reader_type}. "
                           f"Available types: {list(cls._reader_types.keys())}")

        return reader_class(name, config, on_card_read)

    @classmethod
    def get_reader_class(cls, reader_type: str) -> Optional[Type[BaseReader]]:
        """
        Get reader class by type name.

        Args:
            reader_type: Type name (e.g., "wiegand", "osdp")

        Returns:
            Reader class or None if not found
        """
        reader_type = reader_type.lower()

        # Check aliases
        if reader_type in cls._aliases:
            reader_type = cls._aliases[reader_type]

        return cls._reader_types.get(reader_type)

    @classmethod
    def get_available_types(cls) -> list:
        """Get list of available reader type names"""
        return list(cls._reader_types.keys())

    @classmethod
    def register_reader(cls, type_name: str, reader_class: Type[BaseReader]):
        """
        Register a custom reader type.

        Args:
            type_name: Name for the reader type
            reader_class: Reader class (must inherit from BaseReader)
        """
        if not issubclass(reader_class, BaseReader):
            raise TypeError("Reader class must inherit from BaseReader")
        cls._reader_types[type_name.lower()] = reader_class

    @classmethod
    def get_reader_info(cls) -> Dict[str, Dict[str, Any]]:
        """
        Get information about all available reader types.

        Returns:
            Dictionary with reader type info
        """
        info = {}
        for name, reader_class in cls._reader_types.items():
            info[name] = {
                'class': reader_class.__name__,
                'type_enum': reader_class.get_reader_type().value if hasattr(reader_class, 'get_reader_type') else None,
                'doc': reader_class.__doc__.strip().split('\n')[0] if reader_class.__doc__ else None,
            }
        return info


# Convenience function for backward compatibility
def create_reader(name: str, config: Dict[str, Any],
                 on_card_read: Optional[Callable[[CardRead], None]] = None) -> Optional[BaseReader]:
    """
    Create a reader instance. Convenience wrapper for ReaderFactory.create().

    Args:
        name: Unique name for this reader instance
        config: Configuration dictionary
        on_card_read: Callback for card read events

    Returns:
        Reader instance
    """
    return ReaderFactory.create(name, config, on_card_read)


__all__ = [
    'BaseReader',
    'CardRead',
    'ReaderType',
    'ReaderStatus',
    'WiegandReader',
    'OSDPReader',
    'PN532Reader',
    'MFRC522Reader',
    'ReaderFactory',
    'create_reader',
]
