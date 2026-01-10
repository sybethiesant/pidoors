"""
MFRC522 NFC/RFID Reader Module
PiDoors Access Control System

MFRC522 NFC reader support over SPI interface.
Supports reading Mifare Classic, Ultralight, and other ISO14443A cards.

Requires:
  - spidev package
  - RPi.GPIO for reset pin
"""

import threading
import time
from typing import Dict, Any, Optional, List
from enum import IntEnum

from .base import BaseReader, CardRead, ReaderType, ReaderStatus

# Try to import SPI library
try:
    import spidev
    SPI_AVAILABLE = True
except ImportError:
    SPI_AVAILABLE = False

# Try to import GPIO library
try:
    import RPi.GPIO as GPIO
    GPIO_AVAILABLE = True
except ImportError:
    GPIO_AVAILABLE = False


class MFRC522Register(IntEnum):
    """MFRC522 register addresses"""
    # Command and Status
    COMMAND = 0x01
    COMIEN = 0x02
    DIVIEN = 0x03
    COMIRQ = 0x04
    DIVIRQ = 0x05
    ERROR = 0x06
    STATUS1 = 0x07
    STATUS2 = 0x08
    FIFODATA = 0x09
    FIFOLEVEL = 0x0A
    WATERLEVEL = 0x0B
    CONTROL = 0x0C
    BITFRAMING = 0x0D
    COLL = 0x0E

    # Command
    MODE = 0x11
    TXMODE = 0x12
    RXMODE = 0x13
    TXCONTROL = 0x14
    TXASK = 0x15
    TXSEL = 0x16
    RXSEL = 0x17
    RXTHRESHOLD = 0x18
    DEMOD = 0x19
    MFTX = 0x1C
    MFRX = 0x1D
    SERIALSPEED = 0x1F

    # Configuration
    CRCRESULTM = 0x21
    CRCRESULTL = 0x22
    MODWIDTH = 0x24
    RFCFG = 0x26
    GSN = 0x27
    CWGSP = 0x28
    MODGSP = 0x29
    TMODE = 0x2A
    TPRESCALER = 0x2B
    TRELOADH = 0x2C
    TRELOADL = 0x2D
    TCOUNTERH = 0x2E
    TCOUNTERL = 0x2F

    # Test
    TESTSEL1 = 0x31
    TESTSEL2 = 0x32
    TESTPINEN = 0x33
    TESTPINVALUE = 0x34
    TESTBUS = 0x35
    AUTOTEST = 0x36
    VERSION = 0x37
    ANALOGTEST = 0x38
    TESTDAC1 = 0x39
    TESTDAC2 = 0x3A
    TESTADC = 0x3B


class MFRC522Command(IntEnum):
    """MFRC522 commands"""
    IDLE = 0x00
    MEM = 0x01
    GENERATE_RANDOM_ID = 0x02
    CALCCRC = 0x03
    TRANSMIT = 0x04
    NOCMDCHANGE = 0x07
    RECEIVE = 0x08
    TRANSCEIVE = 0x0C
    MFAUTHENT = 0x0E
    SOFTRESET = 0x0F


class PICommand(IntEnum):
    """ISO14443A commands"""
    REQIDL = 0x26
    REQALL = 0x52
    ANTICOLL1 = 0x93
    ANTICOLL2 = 0x95
    ANTICOLL3 = 0x97
    SELECT1 = 0x93
    SELECT2 = 0x95
    SELECT3 = 0x97
    AUTHENT1A = 0x60
    AUTHENT1B = 0x61
    READ = 0x30
    WRITE = 0xA0
    DECREMENT = 0xC0
    INCREMENT = 0xC1
    RESTORE = 0xC2
    TRANSFER = 0xB0
    HALT = 0x50


class MFRC522Reader(BaseReader):
    """
    MFRC522 NFC/RFID card reader over SPI.

    Configuration options:
        spi_bus: SPI bus number (default: 0)
        spi_device: SPI device/chip select (default: 0)
        spi_speed: SPI speed in Hz (default: 1000000)
        reset_pin: GPIO pin for hardware reset (default: 25)
        poll_interval: Card polling interval in seconds (default: 0.2)
        debounce_time: Time to wait before reading same card again (default: 2.0)
        antenna_gain: RF gain 0-7 (default: 4)

    Example config:
        {
            "spi_bus": 0,
            "spi_device": 0,
            "reset_pin": 25,
            "poll_interval": 0.2,
            "antenna_gain": 4
        }
    """

    MAX_LEN = 16

    def __init__(self, name: str, config: Dict[str, Any], on_card_read=None):
        super().__init__(name, config, on_card_read)

        self.poll_interval: float = 0.2
        self.debounce_time: float = 2.0

        self._spi = None
        self._reset_pin: Optional[int] = None
        self._running: bool = False
        self._poll_thread: Optional[threading.Thread] = None
        self._last_card: Optional[str] = None
        self._last_card_time: float = 0

    @staticmethod
    def get_reader_type() -> ReaderType:
        return ReaderType.NFC_MFRC522

    def initialize(self) -> bool:
        """Initialize the MFRC522 reader"""
        self.status = ReaderStatus.INITIALIZING

        if not SPI_AVAILABLE:
            self.set_error("spidev not available - install with: pip install spidev")
            return False

        if not GPIO_AVAILABLE:
            self.set_error("RPi.GPIO not available")
            return False

        bus = self.get_config_value('spi_bus', 0)
        device = self.get_config_value('spi_device', 0)
        speed = self.get_config_value('spi_speed', 1000000)
        self._reset_pin = self.get_config_value('reset_pin', 25)
        self.poll_interval = self.get_config_value('poll_interval', 0.2)
        self.debounce_time = self.get_config_value('debounce_time', 2.0)
        antenna_gain = self.get_config_value('antenna_gain', 4)

        try:
            # Setup GPIO for reset
            GPIO.setmode(GPIO.BCM)
            GPIO.setup(self._reset_pin, GPIO.OUT)
            GPIO.output(self._reset_pin, GPIO.HIGH)

            # Open SPI
            self._spi = spidev.SpiDev()
            self._spi.open(bus, device)
            self._spi.max_speed_hz = speed
            self._spi.mode = 0

            # Reset and initialize
            self._hard_reset()
            self._soft_reset()

            # Timer configuration
            self._write_register(MFRC522Register.TMODE, 0x8D)
            self._write_register(MFRC522Register.TPRESCALER, 0x3E)
            self._write_register(MFRC522Register.TRELOADL, 30)
            self._write_register(MFRC522Register.TRELOADH, 0)

            # Force 100% ASK modulation
            self._write_register(MFRC522Register.TXASK, 0x40)

            # Default mode
            self._write_register(MFRC522Register.MODE, 0x3D)

            # Set antenna gain
            self._set_antenna_gain(antenna_gain)

            # Turn on antenna
            self._antenna_on()

            # Verify chip version
            version = self._read_register(MFRC522Register.VERSION)
            if version not in [0x91, 0x92]:
                self.set_error(f"Unknown MFRC522 version: {version:#x}")
                return False

            self.status = ReaderStatus.READY
            return True

        except Exception as e:
            self.set_error(f"Initialization failed: {e}")
            return False

    def start(self) -> bool:
        """Start reading cards"""
        if self.status != ReaderStatus.READY:
            if not self.initialize():
                return False

        self._running = True
        self._poll_thread = threading.Thread(target=self._poll_loop, daemon=True)
        self._poll_thread.start()

        self.status = ReaderStatus.READING
        return True

    def stop(self) -> bool:
        """Stop reading and release resources"""
        self._running = False

        if self._poll_thread and self._poll_thread.is_alive():
            self._poll_thread.join(timeout=2.0)

        # Turn off antenna
        try:
            self._antenna_off()
        except Exception:
            pass

        if self._spi:
            try:
                self._spi.close()
            except Exception:
                pass
            self._spi = None

        self.status = ReaderStatus.STOPPED
        return True

    def get_status(self) -> Dict[str, Any]:
        """Get detailed reader status"""
        return {
            'name': self.name,
            'type': self.get_reader_type().value,
            'status': self.status.value,
            'error': self.error_message,
            'reset_pin': self._reset_pin,
        }

    def _hard_reset(self):
        """Perform hardware reset via GPIO"""
        GPIO.output(self._reset_pin, GPIO.LOW)
        time.sleep(0.05)
        GPIO.output(self._reset_pin, GPIO.HIGH)
        time.sleep(0.05)

    def _soft_reset(self):
        """Perform software reset"""
        self._write_register(MFRC522Register.COMMAND, MFRC522Command.SOFTRESET)
        time.sleep(0.05)

    def _write_register(self, reg: int, value: int):
        """Write a byte to register"""
        self._spi.xfer([(reg << 1) & 0x7E, value])

    def _read_register(self, reg: int) -> int:
        """Read a byte from register"""
        result = self._spi.xfer([((reg << 1) & 0x7E) | 0x80, 0])
        return result[1]

    def _set_bit_mask(self, reg: int, mask: int):
        """Set bits in register"""
        current = self._read_register(reg)
        self._write_register(reg, current | mask)

    def _clear_bit_mask(self, reg: int, mask: int):
        """Clear bits in register"""
        current = self._read_register(reg)
        self._write_register(reg, current & (~mask))

    def _antenna_on(self):
        """Turn on antenna"""
        current = self._read_register(MFRC522Register.TXCONTROL)
        if not (current & 0x03):
            self._set_bit_mask(MFRC522Register.TXCONTROL, 0x03)

    def _antenna_off(self):
        """Turn off antenna"""
        self._clear_bit_mask(MFRC522Register.TXCONTROL, 0x03)

    def _set_antenna_gain(self, gain: int):
        """Set antenna gain (0-7)"""
        gain = max(0, min(7, gain))
        self._write_register(MFRC522Register.RFCFG, gain << 4)

    def _poll_loop(self):
        """Main card polling loop"""
        while self._running:
            try:
                uid = self._read_card()
                if uid:
                    self._handle_card(uid)
            except Exception:
                pass

            time.sleep(self.poll_interval)

    def _read_card(self) -> Optional[bytes]:
        """Attempt to read a card"""
        # Request card
        status, atq = self._request(PICommand.REQIDL)
        if status != 0:
            return None

        # Anti-collision
        status, uid = self._anticoll()
        if status != 0:
            return None

        return bytes(uid)

    def _request(self, req_mode: int) -> tuple:
        """Send request command"""
        self._write_register(MFRC522Register.BITFRAMING, 0x07)

        status, back_data, back_len = self._to_card(
            MFRC522Command.TRANSCEIVE,
            [req_mode]
        )

        if status != 0 or back_len != 0x10:
            return (1, None)

        return (0, back_data)

    def _anticoll(self) -> tuple:
        """Anti-collision detection"""
        self._write_register(MFRC522Register.BITFRAMING, 0x00)

        status, back_data, back_len = self._to_card(
            MFRC522Command.TRANSCEIVE,
            [PICommand.ANTICOLL1, 0x20]
        )

        if status != 0:
            return (1, None)

        if len(back_data) == 5:
            # Verify checksum
            checksum = 0
            for byte in back_data[:4]:
                checksum ^= byte
            if checksum != back_data[4]:
                return (1, None)
            return (0, back_data[:4])

        return (1, None)

    def _to_card(self, command: int, send_data: List[int]) -> tuple:
        """Send command to card"""
        back_data = []
        back_len = 0
        irq_en = 0x00
        wait_irq = 0x00

        if command == MFRC522Command.MFAUTHENT:
            irq_en = 0x12
            wait_irq = 0x10
        elif command == MFRC522Command.TRANSCEIVE:
            irq_en = 0x77
            wait_irq = 0x30

        self._write_register(MFRC522Register.COMIEN, irq_en | 0x80)
        self._clear_bit_mask(MFRC522Register.COMIRQ, 0x80)
        self._set_bit_mask(MFRC522Register.FIFOLEVEL, 0x80)

        self._write_register(MFRC522Register.COMMAND, MFRC522Command.IDLE)

        # Write data to FIFO
        for byte in send_data:
            self._write_register(MFRC522Register.FIFODATA, byte)

        # Execute command
        self._write_register(MFRC522Register.COMMAND, command)

        if command == MFRC522Command.TRANSCEIVE:
            self._set_bit_mask(MFRC522Register.BITFRAMING, 0x80)

        # Wait for completion
        i = 2000
        while True:
            n = self._read_register(MFRC522Register.COMIRQ)
            i -= 1
            if not ((i != 0) and (not (n & 0x01)) and (not (n & wait_irq))):
                break

        self._clear_bit_mask(MFRC522Register.BITFRAMING, 0x80)

        if i != 0:
            error = self._read_register(MFRC522Register.ERROR)
            if not (error & 0x1B):
                if command == MFRC522Command.TRANSCEIVE:
                    n = self._read_register(MFRC522Register.FIFOLEVEL)
                    last_bits = self._read_register(MFRC522Register.CONTROL) & 0x07
                    if last_bits != 0:
                        back_len = (n - 1) * 8 + last_bits
                    else:
                        back_len = n * 8

                    if n == 0:
                        n = 1
                    if n > self.MAX_LEN:
                        n = self.MAX_LEN

                    # Read FIFO data
                    for _ in range(n):
                        back_data.append(self._read_register(MFRC522Register.FIFODATA))

                return (0, back_data, back_len)
            else:
                return (1, [], 0)

        return (1, [], 0)

    def _handle_card(self, uid: bytes):
        """Handle a detected card"""
        uid_hex = uid.hex()
        current_time = time.time()

        # Debounce: don't report same card within debounce_time
        if uid_hex == self._last_card and (current_time - self._last_card_time) < self.debounce_time:
            return

        self._last_card = uid_hex
        self._last_card_time = current_time

        # Create card read event
        card_read = CardRead(
            card_id=uid_hex,
            facility="",
            user_id=uid_hex,
            bitstring="",
            bit_length=len(uid) * 8,
            format_name=f"Mifare {len(uid) * 8}-bit UID",
            reader_name=self.name,
            raw_data=uid
        )
        self.report_card(card_read)
