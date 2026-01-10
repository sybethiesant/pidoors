"""
Wiegand Card Format Definitions and Validation
PiDoors Access Control System

Supports standard formats: 26, 32, 34, 35, 36, 37, 48-bit
Also supports custom format definitions via formats.json
"""

from dataclasses import dataclass, field
from typing import Dict, Optional, Tuple, List
import json
import os


@dataclass
class WiegandFormat:
    """Definition of a Wiegand card format"""
    bit_length: int
    name: str
    facility_start: int          # Bit position where facility code starts
    facility_end: int            # Bit position where facility code ends (inclusive)
    user_id_start: int           # Bit position where user ID starts
    user_id_end: int             # Bit position where user ID ends (inclusive)
    parity_even_bits: List[int] = field(default_factory=list)  # Bits for even parity calc
    parity_odd_bits: List[int] = field(default_factory=list)   # Bits for odd parity calc
    parity_even_pos: int = 0     # Position of even parity bit
    parity_odd_pos: int = -1     # Position of odd parity bit (-1 = last bit)
    has_parity: bool = True      # Whether format uses parity
    description: str = ""

    def __post_init__(self):
        """Set default parity position if -1"""
        if self.parity_odd_pos == -1:
            self.parity_odd_pos = self.bit_length - 1


class FormatRegistry:
    """Registry of Wiegand card formats with validation"""

    # Standard Wiegand formats
    STANDARD_FORMATS: Dict[int, WiegandFormat] = {
        26: WiegandFormat(
            bit_length=26,
            name="Standard 26-bit (H10301)",
            facility_start=1,
            facility_end=8,
            user_id_start=9,
            user_id_end=24,
            parity_even_bits=list(range(1, 13)),
            parity_odd_bits=list(range(13, 25)),
            parity_even_pos=0,
            parity_odd_pos=25,
            description="Most common access control format. 8-bit facility (0-255), 16-bit user ID (0-65535)"
        ),
        32: WiegandFormat(
            bit_length=32,
            name="32-bit (No Parity)",
            facility_start=0,
            facility_end=15,
            user_id_start=16,
            user_id_end=31,
            has_parity=False,
            description="Raw 32-bit format without parity. 16-bit facility, 16-bit user ID"
        ),
        34: WiegandFormat(
            bit_length=34,
            name="34-bit (H10306)",
            facility_start=1,
            facility_end=16,
            user_id_start=17,
            user_id_end=32,
            parity_even_bits=list(range(1, 17)),
            parity_odd_bits=list(range(17, 33)),
            parity_even_pos=0,
            parity_odd_pos=33,
            description="Extended format. 16-bit facility (0-65535), 16-bit user ID (0-65535)"
        ),
        35: WiegandFormat(
            bit_length=35,
            name="35-bit Corporate 1000",
            facility_start=2,
            facility_end=13,
            user_id_start=14,
            user_id_end=33,
            parity_even_bits=list(range(2, 18)),
            parity_odd_bits=list(range(18, 34)),
            parity_even_pos=0,
            parity_odd_pos=34,
            description="HID Corporate 1000. 12-bit company (0-4095), 20-bit user ID (0-1048575)"
        ),
        36: WiegandFormat(
            bit_length=36,
            name="36-bit Simplex",
            facility_start=1,
            facility_end=14,
            user_id_start=15,
            user_id_end=34,
            parity_even_bits=list(range(1, 18)),
            parity_odd_bits=list(range(18, 35)),
            parity_even_pos=0,
            parity_odd_pos=35,
            description="Simplex format. 14-bit facility, 20-bit user ID"
        ),
        37: WiegandFormat(
            bit_length=37,
            name="37-bit (H10304)",
            facility_start=1,
            facility_end=16,
            user_id_start=17,
            user_id_end=35,
            parity_even_bits=list(range(1, 19)),
            parity_odd_bits=list(range(19, 37)),
            parity_even_pos=0,
            parity_odd_pos=36,
            description="HID 37-bit format. 16-bit facility, 19-bit user ID (0-524287)"
        ),
        48: WiegandFormat(
            bit_length=48,
            name="48-bit Extended",
            facility_start=1,
            facility_end=22,
            user_id_start=23,
            user_id_end=46,
            parity_even_bits=list(range(1, 24)),
            parity_odd_bits=list(range(24, 47)),
            parity_even_pos=0,
            parity_odd_pos=47,
            description="Extended 48-bit. 22-bit facility, 24-bit user ID"
        ),
    }

    def __init__(self, custom_formats_file: Optional[str] = None):
        """
        Initialize format registry with standard and optional custom formats.

        Args:
            custom_formats_file: Path to JSON file with custom format definitions
        """
        self.formats: Dict[int, WiegandFormat] = dict(self.STANDARD_FORMATS)

        if custom_formats_file and os.path.exists(custom_formats_file):
            self._load_custom_formats(custom_formats_file)

    def _load_custom_formats(self, filepath: str) -> None:
        """Load custom format definitions from JSON file"""
        try:
            with open(filepath, 'r') as f:
                data = json.load(f)

            for fmt_data in data.get("formats", []):
                fmt = WiegandFormat(
                    bit_length=fmt_data["bit_length"],
                    name=fmt_data.get("name", f"Custom {fmt_data['bit_length']}-bit"),
                    facility_start=fmt_data["facility_start"],
                    facility_end=fmt_data["facility_end"],
                    user_id_start=fmt_data["user_id_start"],
                    user_id_end=fmt_data["user_id_end"],
                    parity_even_bits=fmt_data.get("parity_even_bits", []),
                    parity_odd_bits=fmt_data.get("parity_odd_bits", []),
                    parity_even_pos=fmt_data.get("parity_even_pos", 0),
                    parity_odd_pos=fmt_data.get("parity_odd_pos", -1),
                    has_parity=fmt_data.get("has_parity", True),
                    description=fmt_data.get("description", "")
                )
                self.formats[fmt.bit_length] = fmt

        except Exception as e:
            # Log error but don't fail - continue with standard formats
            print(f"Warning: Error loading custom formats from {filepath}: {e}")

    def get_format(self, bit_length: int) -> Optional[WiegandFormat]:
        """Get format definition by bit length"""
        return self.formats.get(bit_length)

    def get_supported_lengths(self) -> List[int]:
        """Get list of supported bit lengths"""
        return sorted(self.formats.keys())

    def validate(self, bitstring: str) -> Optional[Tuple[str, str, str]]:
        """
        Validate bitstring and extract card data.

        Args:
            bitstring: String of '0' and '1' characters

        Returns:
            Tuple of (card_id, facility, user_id) or None if invalid
        """
        bit_length = len(bitstring)
        fmt = self.get_format(bit_length)

        if not fmt:
            return None

        # Validate bitstring contains only 0s and 1s
        if not all(c in '01' for c in bitstring):
            return None

        # Check parity if format uses it
        if fmt.has_parity and fmt.parity_even_bits and fmt.parity_odd_bits:
            if not self._validate_parity(bitstring, fmt):
                return None

        # Extract facility and user ID
        try:
            facility = int(bitstring[fmt.facility_start:fmt.facility_end + 1], 2)
            user_id = int(bitstring[fmt.user_id_start:fmt.user_id_end + 1], 2)

            # Generate hex card ID from full bitstring
            hex_width = (bit_length + 3) // 4  # Round up to full hex digits
            card_id = f"{int(bitstring, 2):0{hex_width}x}"

            return (card_id, str(facility), str(user_id))

        except (ValueError, IndexError):
            return None

    def _validate_parity(self, bitstring: str, fmt: WiegandFormat) -> bool:
        """
        Validate even and odd parity bits.

        Even parity: XOR of specified bits should equal parity bit (result is 0)
        Odd parity: XOR of specified bits + 1 should equal parity bit (result is 0)
        """
        try:
            # Get actual parity bits from bitstring
            even_parity = int(bitstring[fmt.parity_even_pos])
            odd_parity = int(bitstring[fmt.parity_odd_pos])

            # Calculate expected even parity (XOR of all even parity bits)
            calc_even = 0
            for i in fmt.parity_even_bits:
                if i < len(bitstring):
                    calc_even ^= int(bitstring[i])

            # Calculate expected odd parity (XOR of all odd parity bits, inverted)
            calc_odd = 1  # Start with 1 for odd parity
            for i in fmt.parity_odd_bits:
                if i < len(bitstring):
                    calc_odd ^= int(bitstring[i])

            return even_parity == calc_even and odd_parity == calc_odd

        except (ValueError, IndexError):
            return False

    def format_info(self, bit_length: int) -> str:
        """Get human-readable format information"""
        fmt = self.get_format(bit_length)
        if not fmt:
            return f"Unknown {bit_length}-bit format"

        facility_bits = fmt.facility_end - fmt.facility_start + 1
        user_bits = fmt.user_id_end - fmt.user_id_start + 1
        max_facility = (1 << facility_bits) - 1
        max_user = (1 << user_bits) - 1

        return (
            f"{fmt.name}\n"
            f"  Facility: {facility_bits} bits (0-{max_facility})\n"
            f"  User ID: {user_bits} bits (0-{max_user})\n"
            f"  Parity: {'Yes' if fmt.has_parity else 'No'}\n"
            f"  {fmt.description}"
        )


# Default registry instance
_default_registry: Optional[FormatRegistry] = None


def get_default_registry() -> FormatRegistry:
    """Get or create the default format registry"""
    global _default_registry
    if _default_registry is None:
        _default_registry = FormatRegistry()
    return _default_registry


def init_registry(custom_formats_file: Optional[str] = None) -> FormatRegistry:
    """Initialize the default registry with optional custom formats"""
    global _default_registry
    _default_registry = FormatRegistry(custom_formats_file)
    return _default_registry
