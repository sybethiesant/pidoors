"""
Base Reader Abstract Class
PiDoors Access Control System

Defines the interface that all card readers must implement.
"""

from abc import ABC, abstractmethod
from dataclasses import dataclass
from typing import Optional, Callable, Dict, Any
from enum import Enum
import threading


class ReaderType(Enum):
    """Supported reader types"""
    WIEGAND = "wiegand"
    OSDP = "osdp"
    NFC_PN532 = "nfc_pn532"
    NFC_MFRC522 = "nfc_mfrc522"


class ReaderStatus(Enum):
    """Reader status states"""
    UNINITIALIZED = "uninitialized"
    INITIALIZING = "initializing"
    READY = "ready"
    READING = "reading"
    ERROR = "error"
    STOPPED = "stopped"


@dataclass
class CardRead:
    """Represents a card read event"""
    card_id: str            # Hex representation of full card data
    facility: str           # Facility code (may be empty for some formats)
    user_id: str            # User/card number
    bitstring: str          # Raw bit string (for Wiegand)
    bit_length: int         # Number of bits read
    format_name: str        # Name of the detected format
    reader_name: str        # Name/identifier of the reader
    raw_data: Optional[bytes] = None  # Raw bytes (for NFC/OSDP)


class BaseReader(ABC):
    """
    Abstract base class for all card readers.

    Subclasses must implement:
    - initialize(): Setup hardware and prepare for reading
    - start(): Begin reading cards
    - stop(): Stop reading and release resources
    - get_status(): Return current reader status

    The on_card_read callback will be called when a card is successfully read.
    """

    def __init__(self, name: str, config: Dict[str, Any],
                 on_card_read: Optional[Callable[[CardRead], None]] = None):
        """
        Initialize the reader.

        Args:
            name: Unique identifier for this reader instance
            config: Configuration dictionary with reader-specific settings
            on_card_read: Callback function for card read events
        """
        self.name = name
        self.config = config
        self.on_card_read = on_card_read
        self._status = ReaderStatus.UNINITIALIZED
        self._status_lock = threading.Lock()
        self._error_message: Optional[str] = None

    @property
    def status(self) -> ReaderStatus:
        """Get current reader status"""
        with self._status_lock:
            return self._status

    @status.setter
    def status(self, value: ReaderStatus):
        """Set reader status"""
        with self._status_lock:
            self._status = value

    @property
    def error_message(self) -> Optional[str]:
        """Get last error message if status is ERROR"""
        return self._error_message

    def set_error(self, message: str):
        """Set reader to error state with message"""
        self._error_message = message
        self.status = ReaderStatus.ERROR

    def clear_error(self):
        """Clear error state"""
        self._error_message = None

    @abstractmethod
    def initialize(self) -> bool:
        """
        Initialize the reader hardware.

        Returns:
            True if initialization successful, False otherwise
        """
        pass

    @abstractmethod
    def start(self) -> bool:
        """
        Start reading cards.

        Returns:
            True if started successfully, False otherwise
        """
        pass

    @abstractmethod
    def stop(self) -> bool:
        """
        Stop reading and release resources.

        Returns:
            True if stopped successfully, False otherwise
        """
        pass

    @abstractmethod
    def get_status(self) -> Dict[str, Any]:
        """
        Get detailed reader status information.

        Returns:
            Dictionary containing status details
        """
        pass

    def report_card(self, card_read: CardRead):
        """
        Report a card read event to the callback.

        Args:
            card_read: CardRead object with card data
        """
        if self.on_card_read:
            self.on_card_read(card_read)

    def get_config_value(self, key: str, default: Any = None) -> Any:
        """
        Get a configuration value with default.

        Args:
            key: Configuration key
            default: Default value if key not found

        Returns:
            Configuration value or default
        """
        return self.config.get(key, default)

    @staticmethod
    def get_reader_type() -> ReaderType:
        """
        Get the type of this reader.

        Returns:
            ReaderType enum value
        """
        raise NotImplementedError("Subclass must implement get_reader_type()")

    def __repr__(self) -> str:
        return f"{self.__class__.__name__}(name='{self.name}', status={self.status.value})"
