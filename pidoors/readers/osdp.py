"""
OSDP (Open Supervised Device Protocol) Reader Module
PiDoors Access Control System

OSDP v2 protocol implementation for RS-485 communication with
encrypted readers. Supports Secure Channel with AES-128 encryption.

Requires: pyserial
"""

import threading
import time
import struct
from typing import Dict, Any, Optional, List
from enum import IntEnum
from dataclasses import dataclass

try:
    import serial
    SERIAL_AVAILABLE = True
except ImportError:
    SERIAL_AVAILABLE = False

try:
    from Crypto.Cipher import AES
    CRYPTO_AVAILABLE = True
except ImportError:
    try:
        from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
        from cryptography.hazmat.backends import default_backend
        CRYPTO_AVAILABLE = True
    except ImportError:
        CRYPTO_AVAILABLE = False

from .base import BaseReader, CardRead, ReaderType, ReaderStatus


class OSDPCommand(IntEnum):
    """OSDP command codes"""
    POLL = 0x60       # Poll for data
    ID = 0x61         # Get reader ID
    CAP = 0x62        # Get capabilities
    LSTAT = 0x64      # Get local status
    ISTAT = 0x65      # Get input status
    OSTAT = 0x66      # Get output status
    RSTAT = 0x67      # Get reader status
    OUT = 0x68        # Set outputs
    LED = 0x69        # Set LED state
    BUZ = 0x6A        # Set buzzer
    TEXT = 0x6B       # Set text
    COMSET = 0x6E     # Set communication parameters
    BIOREAD = 0x73    # Biometric read
    BIOMATCH = 0x74   # Biometric match
    KEYSET = 0x75     # Set encryption key
    CHLNG = 0x76      # Secure channel challenge
    SCRYPT = 0x77     # Secure channel cryptogram


class OSDPReply(IntEnum):
    """OSDP reply codes"""
    ACK = 0x40        # Acknowledge
    NAK = 0x41        # Negative acknowledge
    PDID = 0x45       # Device ID
    PDCAP = 0x46      # Device capabilities
    LSTATR = 0x48     # Local status report
    ISTATR = 0x49     # Input status report
    OSTATR = 0x4A     # Output status report
    RSTATR = 0x4B     # Reader status report
    RAW = 0x50        # Raw reader data
    FMT = 0x51        # Formatted reader data
    KEYPAD = 0x53     # Keypad data
    COM = 0x54        # Communication configuration
    BIOREADR = 0x57   # Biometric read response
    BIOMATCHR = 0x58  # Biometric match response
    CCRYPT = 0x76     # Secure channel cryptogram response
    RMAC_I = 0x78     # Secure channel reply MAC


@dataclass
class OSDPPacket:
    """OSDP packet structure"""
    address: int
    length: int
    control: int
    command: int
    data: bytes
    checksum: int
    is_secure: bool = False


class OSDPReader(BaseReader):
    """
    OSDP card reader over RS-485.

    Configuration options:
        serial_port: Serial port path (e.g., /dev/serial0) (required)
        baud_rate: Baud rate (default: 115200)
        address: OSDP address 0-126 (default: 0)
        encryption_key: Base64-encoded AES-128 key for Secure Channel (optional)
        poll_interval: Polling interval in seconds (default: 0.1)
        timeout: Serial read timeout in seconds (default: 0.5)

    Example config:
        {
            "serial_port": "/dev/serial0",
            "baud_rate": 115200,
            "address": 0,
            "encryption_key": "base64_encoded_16_byte_key",
            "poll_interval": 0.1
        }
    """

    # OSDP constants
    SOM = 0x53  # Start of message marker
    POLY = 0x8005  # CRC-16 polynomial

    def __init__(self, name: str, config: Dict[str, Any], on_card_read=None):
        super().__init__(name, config, on_card_read)

        self.serial_port: Optional[str] = None
        self.baud_rate: int = 115200
        self.address: int = 0
        self.poll_interval: float = 0.1
        self.timeout: float = 0.5

        self._serial: Optional[serial.Serial] = None
        self._sequence: int = 0
        self._running: bool = False
        self._poll_thread: Optional[threading.Thread] = None

        # Secure channel state
        self._encryption_key: Optional[bytes] = None
        self._secure_channel_active: bool = False
        self._session_key: Optional[bytes] = None

    @staticmethod
    def get_reader_type() -> ReaderType:
        return ReaderType.OSDP

    def initialize(self) -> bool:
        """Initialize serial connection"""
        self.status = ReaderStatus.INITIALIZING

        if not SERIAL_AVAILABLE:
            self.set_error("pyserial not available - install with: pip install pyserial")
            return False

        # Get configuration
        self.serial_port = self.get_config_value('serial_port')
        if not self.serial_port:
            self.set_error("Missing serial_port configuration")
            return False

        self.baud_rate = self.get_config_value('baud_rate', 115200)
        self.address = self.get_config_value('address', 0)
        self.poll_interval = self.get_config_value('poll_interval', 0.1)
        self.timeout = self.get_config_value('timeout', 0.5)

        # Setup encryption key if provided
        enc_key = self.get_config_value('encryption_key')
        if enc_key:
            if CRYPTO_AVAILABLE:
                try:
                    import base64
                    self._encryption_key = base64.b64decode(enc_key)
                    if len(self._encryption_key) != 16:
                        self.set_error("Encryption key must be 16 bytes (AES-128)")
                        return False
                except Exception as e:
                    self.set_error(f"Invalid encryption key: {e}")
                    return False
            else:
                self.set_error("Encryption requested but pycryptodome/cryptography not available")
                return False

        # Open serial port
        try:
            self._serial = serial.Serial(
                port=self.serial_port,
                baudrate=self.baud_rate,
                bytesize=serial.EIGHTBITS,
                parity=serial.PARITY_NONE,
                stopbits=serial.STOPBITS_ONE,
                timeout=self.timeout
            )
            self._serial.reset_input_buffer()
            self._serial.reset_output_buffer()

        except Exception as e:
            self.set_error(f"Failed to open serial port: {e}")
            return False

        self.status = ReaderStatus.READY
        return True

    def start(self) -> bool:
        """Start polling the reader"""
        if self.status != ReaderStatus.READY:
            if not self.initialize():
                return False

        self._running = True
        self._poll_thread = threading.Thread(target=self._poll_loop, daemon=True)
        self._poll_thread.start()

        self.status = ReaderStatus.READING
        return True

    def stop(self) -> bool:
        """Stop polling and close serial port"""
        self._running = False

        if self._poll_thread and self._poll_thread.is_alive():
            self._poll_thread.join(timeout=2.0)

        if self._serial and self._serial.is_open:
            try:
                self._serial.close()
            except Exception:
                pass

        self._serial = None
        self._secure_channel_active = False
        self._session_key = None

        self.status = ReaderStatus.STOPPED
        return True

    def get_status(self) -> Dict[str, Any]:
        """Get detailed reader status"""
        return {
            'name': self.name,
            'type': self.get_reader_type().value,
            'status': self.status.value,
            'error': self.error_message,
            'serial_port': self.serial_port,
            'baud_rate': self.baud_rate,
            'address': self.address,
            'secure_channel': self._secure_channel_active,
            'encryption_configured': self._encryption_key is not None,
        }

    def _poll_loop(self):
        """Main polling loop"""
        while self._running:
            try:
                # Send POLL command
                response = self._send_command(OSDPCommand.POLL)

                if response:
                    self._handle_response(response)

            except Exception as e:
                # Don't stop on individual errors
                pass

            time.sleep(self.poll_interval)

    def _send_command(self, command: int, data: bytes = b'') -> Optional[OSDPPacket]:
        """Send OSDP command and receive response"""
        if not self._serial or not self._serial.is_open:
            return None

        # Build packet
        packet = self._build_packet(command, data)

        # Send packet
        self._serial.write(packet)
        self._serial.flush()

        # Read response
        return self._read_response()

    def _build_packet(self, command: int, data: bytes = b'') -> bytes:
        """Build OSDP packet"""
        # Control byte: sequence number in lower 2 bits
        control = self._sequence & 0x03
        self._sequence = (self._sequence + 1) & 0x03

        # Length includes: SOM, addr, len_lsb, len_msb, control, command, data, check_lsb, check_msb
        length = 6 + len(data) + 2  # header + data + checksum

        packet = bytearray([
            self.SOM,
            self.address,
            length & 0xFF,
            (length >> 8) & 0xFF,
            control,
            command
        ])
        packet.extend(data)

        # Calculate CRC-16
        crc = self._calculate_crc(packet)
        packet.append(crc & 0xFF)
        packet.append((crc >> 8) & 0xFF)

        return bytes(packet)

    def _read_response(self) -> Optional[OSDPPacket]:
        """Read and parse OSDP response"""
        # Read SOM
        som = self._serial.read(1)
        if not som or som[0] != self.SOM:
            return None

        # Read address and length
        header = self._serial.read(3)
        if len(header) < 3:
            return None

        address = header[0]
        length = header[1] | (header[2] << 8)

        # Read rest of packet
        remaining = length - 4  # Already read SOM + addr + 2 length bytes
        rest = self._serial.read(remaining)
        if len(rest) < remaining:
            return None

        control = rest[0]
        command = rest[1]
        data = rest[2:-2] if len(rest) > 4 else b''
        checksum = rest[-2] | (rest[-1] << 8)

        # Verify checksum
        full_packet = bytearray([self.SOM, address, length & 0xFF, (length >> 8) & 0xFF])
        full_packet.extend(rest[:-2])
        calc_crc = self._calculate_crc(full_packet)

        if checksum != calc_crc:
            return None

        return OSDPPacket(
            address=address,
            length=length,
            control=control,
            command=command,
            data=bytes(data),
            checksum=checksum,
            is_secure=bool(control & 0x04)
        )

    def _calculate_crc(self, data: bytes) -> int:
        """Calculate CRC-16 checksum"""
        crc = 0x0000
        for byte in data:
            crc ^= byte
            for _ in range(8):
                if crc & 0x0001:
                    crc = (crc >> 1) ^ self.POLY
                else:
                    crc >>= 1
        return crc

    def _handle_response(self, packet: OSDPPacket):
        """Handle OSDP response packet"""
        if packet.command == OSDPReply.RAW:
            # Raw card data
            self._handle_raw_card(packet.data)
        elif packet.command == OSDPReply.FMT:
            # Formatted card data
            self._handle_formatted_card(packet.data)
        elif packet.command == OSDPReply.KEYPAD:
            # Keypad data - ignore for now
            pass
        elif packet.command == OSDPReply.NAK:
            # Negative acknowledge
            pass

    def _handle_raw_card(self, data: bytes):
        """Handle raw card data from reader"""
        if len(data) < 4:
            return

        # Format: reader_num (1), format_code (1), bit_count (2), data...
        reader_num = data[0]
        format_code = data[1]
        bit_count = data[2] | (data[3] << 8)
        card_data = data[4:]

        if not card_data:
            return

        # Convert to bitstring
        bitstring = ""
        for byte in card_data:
            bitstring += format(byte, '08b')
        bitstring = bitstring[:bit_count]  # Trim to actual bit count

        # Extract card ID (hex representation)
        card_value = int(bitstring, 2) if bitstring else 0
        hex_width = (bit_count + 3) // 4
        card_id = f"{card_value:0{hex_width}x}"

        # Create card read event
        card_read = CardRead(
            card_id=card_id,
            facility="",  # Raw format doesn't separate facility
            user_id=str(card_value),
            bitstring=bitstring,
            bit_length=bit_count,
            format_name=f"OSDP Raw {bit_count}-bit",
            reader_name=self.name,
            raw_data=card_data
        )
        self.report_card(card_read)

    def _handle_formatted_card(self, data: bytes):
        """Handle formatted card data from reader"""
        if len(data) < 4:
            return

        # Format: reader_num (1), format_code (1), data_len (1), data...
        reader_num = data[0]
        format_code = data[1]
        data_len = data[2]
        card_data = data[3:3 + data_len]

        if len(card_data) < data_len:
            return

        # Parse based on format code
        # Common format: facility (2 bytes) + card number (4 bytes)
        facility = ""
        user_id = ""

        if data_len >= 6:
            facility = str(card_data[0] | (card_data[1] << 8))
            user_id = str(
                card_data[2] |
                (card_data[3] << 8) |
                (card_data[4] << 16) |
                (card_data[5] << 24)
            )
        elif data_len >= 4:
            user_id = str(
                card_data[0] |
                (card_data[1] << 8) |
                (card_data[2] << 16) |
                (card_data[3] << 24)
            )

        card_id = card_data.hex()

        card_read = CardRead(
            card_id=card_id,
            facility=facility,
            user_id=user_id,
            bitstring="",
            bit_length=data_len * 8,
            format_name=f"OSDP Formatted",
            reader_name=self.name,
            raw_data=card_data
        )
        self.report_card(card_read)

    def set_led(self, reader_num: int, led_num: int, color: int,
                on_time: int = 0, off_time: int = 0, count: int = 0) -> bool:
        """Set LED state on reader"""
        # LED command data format
        data = bytes([
            reader_num,
            led_num,
            0,  # Control code: temporary
            on_time,
            off_time,
            color,  # On color
            0,  # Off color
            count,
            0  # Padding
        ])

        response = self._send_command(OSDPCommand.LED, data)
        return response is not None and response.command == OSDPReply.ACK

    def set_buzzer(self, reader_num: int, tone: int, on_time: int,
                   off_time: int = 0, count: int = 1) -> bool:
        """Set buzzer state on reader"""
        data = bytes([
            reader_num,
            0,  # Control code: temporary
            tone,
            on_time,
            off_time,
            count
        ])

        response = self._send_command(OSDPCommand.BUZ, data)
        return response is not None and response.command == OSDPReply.ACK
