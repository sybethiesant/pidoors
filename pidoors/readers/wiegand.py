"""
Wiegand Card Reader Module
PiDoors Access Control System

Supports all standard Wiegand formats: 26, 32, 34, 35, 36, 37, 48-bit
Uses GPIO interrupts for data capture with automatic format detection.
"""

import threading
import time
from typing import Dict, Any, Optional

try:
    import RPi.GPIO as GPIO
    GPIO_AVAILABLE = True
except ImportError:
    GPIO_AVAILABLE = False

from .base import BaseReader, CardRead, ReaderType, ReaderStatus

# Import format registry
try:
    from formats.wiegand_formats import FormatRegistry, get_default_registry
    FORMAT_REGISTRY_AVAILABLE = True
except ImportError:
    FORMAT_REGISTRY_AVAILABLE = False


class WiegandReader(BaseReader):
    """
    Wiegand card reader using GPIO pins.

    Configuration options:
        d0: GPIO pin for Data 0 line (required)
        d1: GPIO pin for Data 1 line (required)
        timeout: Bit stream timeout in seconds (default: 0.1)
        wiegand_format: "auto" for auto-detect, or specific bit length (default: "auto")
        custom_formats_file: Path to custom format definitions (optional)

    Example config:
        {
            "d0": 24,
            "d1": 23,
            "timeout": 0.1,
            "wiegand_format": "auto"
        }
    """

    # Bit stream timeout - time to wait for additional bits before processing
    DEFAULT_TIMEOUT = 0.1  # 100ms

    def __init__(self, name: str, config: Dict[str, Any], on_card_read=None):
        super().__init__(name, config, on_card_read)

        self.d0_pin: Optional[int] = None
        self.d1_pin: Optional[int] = None
        self.timeout = self.get_config_value('timeout', self.DEFAULT_TIMEOUT)
        self.wiegand_format = self.get_config_value('wiegand_format', 'auto')

        # Bit stream state
        self._stream = ""
        self._stream_lock = threading.Lock()
        self._timer: Optional[threading.Timer] = None

        # Format registry
        self._format_registry: Optional[FormatRegistry] = None

    @staticmethod
    def get_reader_type() -> ReaderType:
        return ReaderType.WIEGAND

    def initialize(self) -> bool:
        """Initialize GPIO pins and format registry"""
        self.status = ReaderStatus.INITIALIZING

        # Check GPIO availability
        if not GPIO_AVAILABLE:
            self.set_error("RPi.GPIO not available")
            return False

        # Get pin configuration
        self.d0_pin = self.get_config_value('d0')
        self.d1_pin = self.get_config_value('d1')

        if not self.d0_pin or not self.d1_pin:
            self.set_error("Missing d0 or d1 GPIO pin configuration")
            return False

        # Initialize format registry
        if FORMAT_REGISTRY_AVAILABLE:
            custom_formats = self.get_config_value('custom_formats_file')
            if custom_formats:
                self._format_registry = FormatRegistry(custom_formats)
            else:
                self._format_registry = get_default_registry()
        else:
            self._format_registry = None

        self.status = ReaderStatus.READY
        return True

    def start(self) -> bool:
        """Start reading cards by setting up GPIO interrupts"""
        if self.status != ReaderStatus.READY:
            if not self.initialize():
                return False

        try:
            # Setup GPIO pins as inputs
            GPIO.setup(self.d0_pin, GPIO.IN)
            GPIO.setup(self.d1_pin, GPIO.IN)

            # Add falling edge detection for data pulses
            GPIO.add_event_detect(
                self.d0_pin,
                GPIO.FALLING,
                callback=self._data_pulse_d0
            )
            GPIO.add_event_detect(
                self.d1_pin,
                GPIO.FALLING,
                callback=self._data_pulse_d1
            )

            self.status = ReaderStatus.READING
            return True

        except Exception as e:
            self.set_error(f"Failed to start reader: {e}")
            return False

    def stop(self) -> bool:
        """Stop reading and cleanup GPIO"""
        try:
            # Cancel any pending timer
            if self._timer:
                self._timer.cancel()
                self._timer = None

            # Remove event detection
            if GPIO_AVAILABLE and self.d0_pin and self.d1_pin:
                try:
                    GPIO.remove_event_detect(self.d0_pin)
                    GPIO.remove_event_detect(self.d1_pin)
                except Exception:
                    pass  # Ignore if already removed

            self.status = ReaderStatus.STOPPED
            return True

        except Exception as e:
            self.set_error(f"Failed to stop reader: {e}")
            return False

    def get_status(self) -> Dict[str, Any]:
        """Get detailed reader status"""
        return {
            'name': self.name,
            'type': self.get_reader_type().value,
            'status': self.status.value,
            'error': self.error_message,
            'd0_pin': self.d0_pin,
            'd1_pin': self.d1_pin,
            'format': self.wiegand_format,
            'supported_formats': self._get_supported_formats(),
        }

    def _get_supported_formats(self) -> list:
        """Get list of supported bit lengths"""
        if self._format_registry:
            return self._format_registry.get_supported_lengths()
        return [26, 34]  # Legacy support

    def _data_pulse_d0(self, channel):
        """Handle D0 pulse (represents a 0 bit)"""
        self._add_bit('0')

    def _data_pulse_d1(self, channel):
        """Handle D1 pulse (represents a 1 bit)"""
        self._add_bit('1')

    def _add_bit(self, bit: str):
        """Add a bit to the stream and reset timer"""
        with self._stream_lock:
            self._stream += bit

            # Cancel existing timer
            if self._timer:
                self._timer.cancel()

            # Start new timer
            self._timer = threading.Timer(
                self.timeout,
                self._process_stream
            )
            self._timer.start()

    def _process_stream(self):
        """Process the completed bit stream"""
        with self._stream_lock:
            bitstring = self._stream
            self._stream = ""
            self._timer = None

        if not bitstring:
            return

        # Validate and decode the card
        card_read = self._validate_bitstring(bitstring)
        if card_read:
            self.report_card(card_read)

    def _validate_bitstring(self, bitstring: str) -> Optional[CardRead]:
        """Validate bitstring and create CardRead object"""
        bit_length = len(bitstring)

        # Check for specific format if configured
        if self.wiegand_format != 'auto':
            try:
                expected_length = int(self.wiegand_format)
                if bit_length != expected_length:
                    return None
            except ValueError:
                pass  # Invalid format config, use auto

        # Use format registry if available
        if self._format_registry:
            result = self._format_registry.validate(bitstring)
            if result:
                card_id, facility, user_id = result
                fmt = self._format_registry.get_format(bit_length)
                return CardRead(
                    card_id=card_id,
                    facility=facility,
                    user_id=user_id,
                    bitstring=bitstring,
                    bit_length=bit_length,
                    format_name=fmt.name if fmt else f"{bit_length}-bit",
                    reader_name=self.name
                )
            return None

        # Legacy fallback for 26-bit and 34-bit
        if bit_length == 26:
            return self._validate_26bit_legacy(bitstring)
        elif bit_length == 34:
            return self._validate_34bit_legacy(bitstring)

        return None

    def _validate_26bit_legacy(self, bstr: str) -> Optional[CardRead]:
        """Validate 26-bit Wiegand format (legacy fallback)"""
        lparity = int(bstr[0])
        facility = int(bstr[1:9], 2)
        user_id = int(bstr[9:25], 2)
        rparity = int(bstr[25])

        # Calculate expected parities
        calc_lparity = 0
        calc_rparity = 1
        for i in range(12):
            calc_lparity ^= int(bstr[i + 1])
            calc_rparity ^= int(bstr[i + 13])

        if calc_lparity != lparity or calc_rparity != rparity:
            return None

        card_id = "%08x" % int(bstr, 2)
        return CardRead(
            card_id=card_id,
            facility=str(facility),
            user_id=str(user_id),
            bitstring=bstr,
            bit_length=26,
            format_name="Standard 26-bit (H10301)",
            reader_name=self.name
        )

    def _validate_34bit_legacy(self, bstr: str) -> Optional[CardRead]:
        """Validate 34-bit Wiegand format (legacy fallback)"""
        lparity = int(bstr[0])
        facility = int(bstr[1:17], 2)
        user_id = int(bstr[17:33], 2)
        rparity = int(bstr[33])

        # Calculate expected parities
        calc_lparity = 0
        calc_rparity = 1
        for i in range(16):
            calc_lparity ^= int(bstr[i + 1])
            calc_rparity ^= int(bstr[i + 17])

        if calc_lparity != lparity or calc_rparity != rparity:
            return None

        card_id = "%09x" % int(bstr, 2)
        return CardRead(
            card_id=card_id,
            facility=str(facility),
            user_id=str(user_id),
            bitstring=bstr,
            bit_length=34,
            format_name="34-bit (H10306)",
            reader_name=self.name
        )
