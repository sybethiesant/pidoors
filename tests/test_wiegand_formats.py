"""
Tests for Wiegand Format Registry
pidoors/formats/wiegand_formats.py
"""

import pytest
import sys
import os

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'pidoors'))

from formats.wiegand_formats import (
    WiegandFormat,
    FormatRegistry,
    get_default_registry,
    init_registry
)


class TestWiegandFormat:
    """Tests for WiegandFormat dataclass"""

    def test_format_creation(self):
        """Test creating a WiegandFormat instance"""
        fmt = WiegandFormat(
            bit_length=26,
            name="Test Format",
            facility_start=1,
            facility_end=8,
            user_id_start=9,
            user_id_end=24
        )
        assert fmt.bit_length == 26
        assert fmt.name == "Test Format"
        assert fmt.has_parity == True  # Default

    def test_format_parity_pos_default(self):
        """Test that odd parity position defaults to last bit"""
        fmt = WiegandFormat(
            bit_length=26,
            name="Test",
            facility_start=1,
            facility_end=8,
            user_id_start=9,
            user_id_end=24,
            parity_odd_pos=-1
        )
        assert fmt.parity_odd_pos == 25  # bit_length - 1

    def test_format_no_parity(self):
        """Test format without parity"""
        fmt = WiegandFormat(
            bit_length=32,
            name="No Parity",
            facility_start=0,
            facility_end=15,
            user_id_start=16,
            user_id_end=31,
            has_parity=False
        )
        assert fmt.has_parity == False


class TestFormatRegistry:
    """Tests for FormatRegistry"""

    def test_registry_initialization(self):
        """Test registry initializes with standard formats"""
        registry = FormatRegistry()
        supported = registry.get_supported_lengths()

        assert 26 in supported
        assert 34 in supported
        assert 37 in supported

    def test_get_format_exists(self):
        """Test getting an existing format"""
        registry = FormatRegistry()
        fmt = registry.get_format(26)

        assert fmt is not None
        assert fmt.bit_length == 26
        assert fmt.name == "Standard 26-bit (H10301)"

    def test_get_format_not_exists(self):
        """Test getting a non-existent format"""
        registry = FormatRegistry()
        fmt = registry.get_format(99)

        assert fmt is None

    def test_validate_invalid_chars(self):
        """Test validation rejects non-binary characters"""
        registry = FormatRegistry()
        result = registry.validate("0123456789abcdef0123456789")

        assert result is None

    def test_validate_unsupported_length(self):
        """Test validation rejects unsupported lengths"""
        registry = FormatRegistry()
        result = registry.validate("0" * 99)

        assert result is None

    def test_format_info(self):
        """Test getting format information string"""
        registry = FormatRegistry()
        info = registry.format_info(26)

        assert "26-bit" in info or "Standard" in info
        assert "Facility" in info
        assert "User ID" in info

    def test_format_info_unknown(self):
        """Test format info for unknown format"""
        registry = FormatRegistry()
        info = registry.format_info(99)

        assert "Unknown" in info


class TestFormatValidation:
    """Tests for card validation with parity checking"""

    def test_validate_26bit_valid(self, valid_26bit_card):
        """Test validating a valid 26-bit card"""
        registry = FormatRegistry()
        result = registry.validate(valid_26bit_card['bitstring'])

        assert result is not None
        card_id, facility, user_id = result
        assert facility == valid_26bit_card['facility']
        assert user_id == valid_26bit_card['user_id']

    def test_validate_26bit_bad_parity(self, valid_26bit_card):
        """Test that bad parity is detected"""
        registry = FormatRegistry()
        # Flip the first parity bit
        bad_bitstring = ('1' if valid_26bit_card['bitstring'][0] == '0' else '0') + \
                        valid_26bit_card['bitstring'][1:]
        result = registry.validate(bad_bitstring)

        assert result is None

    def test_validate_34bit_valid(self, valid_34bit_card):
        """Test validating a valid 34-bit card"""
        registry = FormatRegistry()
        result = registry.validate(valid_34bit_card['bitstring'])

        assert result is not None
        card_id, facility, user_id = result
        assert facility == valid_34bit_card['facility']
        assert user_id == valid_34bit_card['user_id']

    def test_validate_32bit_no_parity(self):
        """Test 32-bit format without parity validation"""
        registry = FormatRegistry()
        # 32-bit format has no parity
        bitstring = '0' * 32
        result = registry.validate(bitstring)

        assert result is not None
        card_id, facility, user_id = result
        assert facility == '0'
        assert user_id == '0'

    def test_card_id_hex_format(self, valid_26bit_card):
        """Test that card_id is returned as hex"""
        registry = FormatRegistry()
        result = registry.validate(valid_26bit_card['bitstring'])

        assert result is not None
        card_id, _, _ = result
        # Should be valid hex string
        int(card_id, 16)  # Will raise if not valid hex


class TestDefaultRegistry:
    """Tests for default registry singleton"""

    def test_get_default_registry(self):
        """Test getting default registry"""
        registry = get_default_registry()
        assert registry is not None
        assert isinstance(registry, FormatRegistry)

    def test_default_registry_singleton(self):
        """Test that default registry is a singleton"""
        registry1 = get_default_registry()
        registry2 = get_default_registry()
        # Note: May not be exact same instance due to module reloading in tests
        assert registry1.get_supported_lengths() == registry2.get_supported_lengths()

    def test_init_registry(self):
        """Test initializing registry"""
        registry = init_registry()
        assert registry is not None
        assert 26 in registry.get_supported_lengths()


class TestStandardFormats:
    """Tests for standard Wiegand format definitions"""

    @pytest.mark.parametrize("bit_length,expected_name", [
        (26, "Standard 26-bit (H10301)"),
        (32, "32-bit (No Parity)"),
        (34, "34-bit (H10306)"),
        (35, "35-bit Corporate 1000"),
        (36, "36-bit Simplex"),
        (37, "37-bit (H10304)"),
        (48, "48-bit Extended"),
    ])
    def test_standard_format_names(self, bit_length, expected_name):
        """Test standard format names are correct"""
        registry = FormatRegistry()
        fmt = registry.get_format(bit_length)

        assert fmt is not None
        assert fmt.name == expected_name

    def test_26bit_format_details(self):
        """Test 26-bit format has correct bit positions"""
        registry = FormatRegistry()
        fmt = registry.get_format(26)

        assert fmt.facility_start == 1
        assert fmt.facility_end == 8
        assert fmt.user_id_start == 9
        assert fmt.user_id_end == 24
        assert fmt.parity_even_pos == 0
        assert fmt.parity_odd_pos == 25

    def test_34bit_format_details(self):
        """Test 34-bit format has correct bit positions"""
        registry = FormatRegistry()
        fmt = registry.get_format(34)

        assert fmt.facility_start == 1
        assert fmt.facility_end == 16
        assert fmt.user_id_start == 17
        assert fmt.user_id_end == 32
        assert fmt.parity_even_pos == 0
        assert fmt.parity_odd_pos == 33
