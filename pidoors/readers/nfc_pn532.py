"""
PN532 NFC/RFID Reader Module
PiDoors Access Control System

PN532 NFC reader support over I2C or SPI interface.
Supports reading Mifare Classic, Ultralight, NTAG, and other ISO14443A cards.

Requires:
  - I2C: smbus2 package
  - SPI: spidev package
"""

import threading
import time
from typing import Dict, Any, Optional, List
from enum import IntEnum

from .base import BaseReader, CardRead, ReaderType, ReaderStatus

# Try to import I2C library
try:
    import smbus2
    I2C_AVAILABLE = True
except ImportError:
    I2C_AVAILABLE = False

# Try to import SPI library
try:
    import spidev
    SPI_AVAILABLE = True
except ImportError:
    SPI_AVAILABLE = False


class PN532Command(IntEnum):
    """PN532 command codes"""
    GET_FIRMWARE_VERSION = 0x02
    SAM_CONFIGURATION = 0x14
    RF_CONFIGURATION = 0x32
    IN_LIST_PASSIVE_TARGET = 0x4A
    IN_DATA_EXCHANGE = 0x40
    IN_COMMUNICATE_THRU = 0x42
    IN_DESELECT = 0x44
    IN_RELEASE = 0x52
    IN_SELECT = 0x54
    IN_AUTOPOLL = 0x60
    POWERDOWN = 0x16


class CardType(IntEnum):
    """NFC card types"""
    MIFARE_CLASSIC_1K = 0x08
    MIFARE_CLASSIC_4K = 0x18
    MIFARE_ULTRALIGHT = 0x44
    MIFARE_PLUS = 0x10
    ISO14443_4 = 0x20


class PN532Reader(BaseReader):
    """
    PN532 NFC/RFID card reader.

    Configuration options:
        interface: "i2c" or "spi" (required)
        i2c_address: I2C address (default: 0x24)
        i2c_bus: I2C bus number (default: 1)
        spi_bus: SPI bus number (default: 0)
        spi_device: SPI device number (default: 0)
        spi_speed: SPI speed in Hz (default: 1000000)
        poll_interval: Card polling interval in seconds (default: 0.2)
        debounce_time: Time to wait before reading same card again (default: 2.0)

    Example config:
        {
            "interface": "i2c",
            "i2c_address": 36,
            "i2c_bus": 1,
            "poll_interval": 0.2
        }
    """

    # PN532 constants
    PREAMBLE = 0x00
    STARTCODE1 = 0x00
    STARTCODE2 = 0xFF
    POSTAMBLE = 0x00
    HOSTTOPN532 = 0xD4
    PN532TOHOST = 0xD5

    # I2C specific
    I2C_ADDRESS = 0x24
    I2C_READY = 0x01

    # SPI specific
    SPI_STATREAD = 0x02
    SPI_DATAWRITE = 0x01
    SPI_DATAREAD = 0x03
    SPI_READY = 0x01

    def __init__(self, name: str, config: Dict[str, Any], on_card_read=None):
        super().__init__(name, config, on_card_read)

        self.interface: str = "i2c"
        self.poll_interval: float = 0.2
        self.debounce_time: float = 2.0

        self._i2c_bus = None
        self._i2c_address: int = self.I2C_ADDRESS
        self._spi = None
        self._running: bool = False
        self._poll_thread: Optional[threading.Thread] = None
        self._last_card: Optional[str] = None
        self._last_card_time: float = 0

    @staticmethod
    def get_reader_type() -> ReaderType:
        return ReaderType.NFC_PN532

    def initialize(self) -> bool:
        """Initialize the PN532 reader"""
        self.status = ReaderStatus.INITIALIZING

        self.interface = self.get_config_value('interface', 'i2c').lower()
        self.poll_interval = self.get_config_value('poll_interval', 0.2)
        self.debounce_time = self.get_config_value('debounce_time', 2.0)

        if self.interface == 'i2c':
            return self._init_i2c()
        elif self.interface == 'spi':
            return self._init_spi()
        else:
            self.set_error(f"Unknown interface: {self.interface}")
            return False

    def _init_i2c(self) -> bool:
        """Initialize I2C interface"""
        if not I2C_AVAILABLE:
            self.set_error("smbus2 not available - install with: pip install smbus2")
            return False

        self._i2c_address = self.get_config_value('i2c_address', self.I2C_ADDRESS)
        bus_num = self.get_config_value('i2c_bus', 1)

        try:
            self._i2c_bus = smbus2.SMBus(bus_num)

            # Wake up and configure PN532
            if not self._wakeup():
                self.set_error("Failed to wake up PN532")
                return False

            if not self._configure_sam():
                self.set_error("Failed to configure PN532 SAM")
                return False

            self.status = ReaderStatus.READY
            return True

        except Exception as e:
            self.set_error(f"I2C initialization failed: {e}")
            return False

    def _init_spi(self) -> bool:
        """Initialize SPI interface"""
        if not SPI_AVAILABLE:
            self.set_error("spidev not available - install with: pip install spidev")
            return False

        bus = self.get_config_value('spi_bus', 0)
        device = self.get_config_value('spi_device', 0)
        speed = self.get_config_value('spi_speed', 1000000)

        try:
            self._spi = spidev.SpiDev()
            self._spi.open(bus, device)
            self._spi.max_speed_hz = speed
            self._spi.mode = 0

            # Wake up and configure PN532
            if not self._wakeup():
                self.set_error("Failed to wake up PN532")
                return False

            if not self._configure_sam():
                self.set_error("Failed to configure PN532 SAM")
                return False

            self.status = ReaderStatus.READY
            return True

        except Exception as e:
            self.set_error(f"SPI initialization failed: {e}")
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

        if self._i2c_bus:
            try:
                self._i2c_bus.close()
            except Exception:
                pass
            self._i2c_bus = None

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
            'interface': self.interface,
            'i2c_address': self._i2c_address if self.interface == 'i2c' else None,
        }

    def _wakeup(self) -> bool:
        """Wake up PN532 from power down"""
        if self.interface == 'i2c':
            # Send dummy data to wake up
            try:
                self._i2c_bus.write_byte(self._i2c_address, 0x00)
            except Exception:
                pass
        elif self.interface == 'spi':
            # Toggle chip select
            try:
                self._spi.xfer([0x00])
            except Exception:
                pass

        time.sleep(0.1)

        # Get firmware version to verify communication
        version = self._get_firmware_version()
        return version is not None

    def _get_firmware_version(self) -> Optional[tuple]:
        """Get PN532 firmware version"""
        response = self._send_command(PN532Command.GET_FIRMWARE_VERSION)
        if response and len(response) >= 4:
            return (response[0], response[1], response[2], response[3])
        return None

    def _configure_sam(self) -> bool:
        """Configure Security Access Module"""
        # Mode 1 = Normal, timeout 0, IRQ not used
        response = self._send_command(
            PN532Command.SAM_CONFIGURATION,
            [0x01, 0x00, 0x01]
        )
        return response is not None

    def _poll_loop(self):
        """Main card polling loop"""
        while self._running:
            try:
                card_uid = self._read_passive_target()
                if card_uid:
                    self._handle_card(card_uid)
            except Exception:
                pass

            time.sleep(self.poll_interval)

    def _read_passive_target(self) -> Optional[bytes]:
        """
        Attempt to read a passive ISO14443A target.
        Returns the card UID if found, None otherwise.
        """
        # Command: InListPassiveTarget, max 1 target, 106 kbps Type A
        response = self._send_command(
            PN532Command.IN_LIST_PASSIVE_TARGET,
            [0x01, 0x00],  # Max 1 target, 106 kbps baud
            timeout=0.5
        )

        if not response or len(response) < 1:
            return None

        num_targets = response[0]
        if num_targets < 1:
            return None

        # Parse response: target_num, sens_res(2), sel_res, nfcid_len, nfcid...
        if len(response) < 6:
            return None

        nfcid_len = response[4]
        if len(response) < 5 + nfcid_len:
            return None

        return bytes(response[5:5 + nfcid_len])

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
        # NFC UID is used directly as card_id and user_id
        card_read = CardRead(
            card_id=uid_hex,
            facility="",  # NFC doesn't have facility code
            user_id=uid_hex,  # Use UID as user_id for lookup
            bitstring="",
            bit_length=len(uid) * 8,
            format_name=f"NFC {len(uid) * 8}-bit UID",
            reader_name=self.name,
            raw_data=uid
        )
        self.report_card(card_read)

    def _send_command(self, command: int, data: List[int] = None,
                      timeout: float = 1.0) -> Optional[List[int]]:
        """Send command to PN532 and get response"""
        if data is None:
            data = []

        frame = self._build_frame(command, data)

        if self.interface == 'i2c':
            return self._send_command_i2c(frame, timeout)
        elif self.interface == 'spi':
            return self._send_command_spi(frame, timeout)
        return None

    def _build_frame(self, command: int, data: List[int]) -> bytes:
        """Build PN532 command frame"""
        length = len(data) + 2  # TFI + command + data
        lcs = (~length + 1) & 0xFF  # Length checksum

        frame = [
            self.PREAMBLE,
            self.STARTCODE1,
            self.STARTCODE2,
            length,
            lcs,
            self.HOSTTOPN532,
            command
        ]
        frame.extend(data)

        # Data checksum
        dcs = self.HOSTTOPN532 + command + sum(data)
        dcs = (~dcs + 1) & 0xFF
        frame.append(dcs)
        frame.append(self.POSTAMBLE)

        return bytes(frame)

    def _send_command_i2c(self, frame: bytes, timeout: float) -> Optional[List[int]]:
        """Send command over I2C"""
        try:
            # Write command
            self._i2c_bus.write_i2c_block_data(
                self._i2c_address,
                frame[0],
                list(frame[1:])
            )

            # Wait for response
            start = time.time()
            while (time.time() - start) < timeout:
                try:
                    # Check if ready
                    status = self._i2c_bus.read_byte(self._i2c_address)
                    if status & self.I2C_READY:
                        break
                except Exception:
                    pass
                time.sleep(0.01)
            else:
                return None

            # Read response (max 64 bytes)
            response = self._i2c_bus.read_i2c_block_data(
                self._i2c_address,
                self.PREAMBLE,
                32
            )

            return self._parse_response(response)

        except Exception:
            return None

    def _send_command_spi(self, frame: bytes, timeout: float) -> Optional[List[int]]:
        """Send command over SPI"""
        try:
            # Reverse bits for SPI (PN532 quirk)
            def reverse_byte(b):
                return int('{:08b}'.format(b)[::-1], 2)

            # Write command
            spi_frame = [self.SPI_DATAWRITE]
            spi_frame.extend([reverse_byte(b) for b in frame])
            self._spi.xfer(spi_frame)

            # Wait for response
            start = time.time()
            while (time.time() - start) < timeout:
                status = self._spi.xfer([self.SPI_STATREAD, 0x00])
                if reverse_byte(status[1]) & self.SPI_READY:
                    break
                time.sleep(0.01)
            else:
                return None

            # Read response
            read_cmd = [self.SPI_DATAREAD] + [0x00] * 32
            response = self._spi.xfer(read_cmd)
            response = [reverse_byte(b) for b in response[1:]]

            return self._parse_response(response)

        except Exception:
            return None

    def _parse_response(self, data: List[int]) -> Optional[List[int]]:
        """Parse PN532 response frame"""
        # Find start code
        try:
            idx = 0
            while idx < len(data) - 2:
                if data[idx] == self.STARTCODE1 and data[idx + 1] == self.STARTCODE2:
                    idx += 2
                    break
                idx += 1
            else:
                return None

            # Get length
            length = data[idx]
            lcs = data[idx + 1]

            # Verify length checksum
            if (length + lcs) & 0xFF != 0:
                return None

            idx += 2

            # Get TFI (should be PN532TOHOST)
            if data[idx] != self.PN532TOHOST:
                return None

            # Return data portion (skip TFI and response code)
            return list(data[idx + 2:idx + length - 1])

        except (IndexError, ValueError):
            return None
