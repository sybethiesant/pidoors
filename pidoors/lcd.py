"""
Character LCD (HD44780) driver for the PiDoors door controller.

Drives a 16x2 / 20x4 style character LCD at the door to show status,
"Access granted" / "Access denied", and keypad PIN entry progress.

Two wiring types are supported, chosen by ``lcd_config.type``:

  "i2c"  - LCD with the common PCF8574 I2C backpack (4 wires: 5V, GND, SDA, SCL).
           Config: i2c_bus (default 1), i2c_address (default 0x27; 0x3F is the
           other common one), cols, rows.

  "gpio" - LCD wired directly to GPIO in 4-bit mode (RS, E, D4..D7, optional
           backlight). Config: pins.rs, pins.e, pins.d4, pins.d5, pins.d6,
           pins.d7, pins.backlight (optional), cols, rows. All BCM numbers.

The controller only ever calls ``create_lcd()``, ``write_lines()``, ``clear()``
and ``close()``. Hardware errors are swallowed after initialisation so a flaky
display can never take the access-control loop down with it.
"""

import threading
import time

try:
    import RPi.GPIO as GPIO
    GPIO_AVAILABLE = True
except ImportError:  # pragma: no cover - dev box
    GPIO_AVAILABLE = False

try:
    import smbus2
    SMBUS_AVAILABLE = True
except ImportError:  # pragma: no cover - dev box
    SMBUS_AVAILABLE = False


# HD44780 commands
_CLEAR = 0x01
_HOME = 0x02
_ENTRY_MODE = 0x06        # cursor moves right, no display shift
_DISPLAY_OFF = 0x08
_DISPLAY_ON = 0x0C        # display on, cursor off, blink off
_FUNCTION_SET = 0x28      # 4-bit bus, 2 lines, 5x8 font
_SET_DDRAM = 0x80

# PCF8574 backpack bit layout (the near-universal "YwRobot"/"SunFounder" wiring)
_I2C_RS = 0x01
_I2C_RW = 0x02
_I2C_EN = 0x04
_I2C_BACKLIGHT = 0x08


class _HD44780:
    """Transport-agnostic HD44780 logic. Subclasses implement _write_nibble()."""

    def __init__(self, cols, rows):
        self.cols = max(8, min(int(cols or 16), 40))
        self.rows = max(1, min(int(rows or 2), 4))
        # DDRAM start address of each row. Rows 3/4 continue on from rows 1/2.
        self._row_offsets = [0x00, 0x40, 0x00 + self.cols, 0x40 + self.cols]
        self._lock = threading.Lock()

    # -- transport hooks -------------------------------------------------
    def _write_nibble(self, nibble, is_data):
        raise NotImplementedError

    def _backlight(self, on):
        pass

    def _release(self):
        pass

    # -- protocol ----------------------------------------------------------
    def _write_byte(self, value, is_data):
        self._write_nibble((value >> 4) & 0x0F, is_data)
        self._write_nibble(value & 0x0F, is_data)

    def _command(self, value):
        self._write_byte(value, False)
        if value in (_CLEAR, _HOME):
            time.sleep(0.002)  # these two need >1.52ms
        else:
            time.sleep(0.00005)

    def _init_display(self):
        time.sleep(0.05)  # power-on settle
        # Force 8-bit mode three times, then switch to 4-bit (datasheet init)
        for _ in range(3):
            self._write_nibble(0x03, False)
            time.sleep(0.005)
        self._write_nibble(0x02, False)
        time.sleep(0.001)
        self._command(_FUNCTION_SET)
        self._command(_DISPLAY_OFF)
        self._command(_CLEAR)
        self._command(_ENTRY_MODE)
        self._command(_DISPLAY_ON)
        self._backlight(True)

    # -- public API --------------------------------------------------------
    def write_lines(self, lines):
        """Replace the whole display with the given lines (list of str).
        Each line is padded/truncated to the display width so stale text never
        lingers. Extra lines beyond the display's rows are dropped."""
        with self._lock:
            for row in range(self.rows):
                text = lines[row] if row < len(lines) else ""
                text = str(text or "")
                text = text.encode("ascii", "replace").decode("ascii")
                text = text[: self.cols].ljust(self.cols)
                self._command(_SET_DDRAM | self._row_offsets[row])
                for ch in text:
                    self._write_byte(ord(ch), True)

    def clear(self):
        with self._lock:
            self._command(_CLEAR)

    def close(self):
        with self._lock:
            try:
                self._command(_CLEAR)
                self._backlight(False)
            except Exception:
                pass
            self._release()


class I2CLCD(_HD44780):
    """HD44780 behind a PCF8574 I2C backpack."""

    def __init__(self, cols, rows, bus=1, address=0x27):
        if not SMBUS_AVAILABLE:
            raise RuntimeError("smbus2 is not installed (pip install smbus2)")
        super().__init__(cols, rows)
        self._address = int(address)
        self._bl = _I2C_BACKLIGHT
        self._bus = smbus2.SMBus(int(bus))
        self._init_display()

    def _write_nibble(self, nibble, is_data):
        data = (nibble << 4) | self._bl | (_I2C_RS if is_data else 0)
        self._bus.write_byte(self._address, data | _I2C_EN)
        time.sleep(0.0000005)
        self._bus.write_byte(self._address, data & ~_I2C_EN)
        time.sleep(0.00005)

    def _backlight(self, on):
        self._bl = _I2C_BACKLIGHT if on else 0
        try:
            self._bus.write_byte(self._address, self._bl)
        except Exception:
            pass

    def _release(self):
        try:
            self._bus.close()
        except Exception:
            pass


class GPIOLCD(_HD44780):
    """HD44780 wired straight to GPIO in 4-bit mode."""

    def __init__(self, cols, rows, pins):
        if not GPIO_AVAILABLE:
            raise RuntimeError("RPi.GPIO is not available")
        super().__init__(cols, rows)
        required = ("rs", "e", "d4", "d5", "d6", "d7")
        try:
            self._pins = {k: int(pins[k]) for k in required}
        except (KeyError, TypeError, ValueError) as e:
            raise RuntimeError(f"LCD gpio wiring needs pins {', '.join(required)} ({e})")
        bl = pins.get("backlight")
        self._bl_pin = int(bl) if bl not in (None, "", 0) else None
        self._data_pins = [self._pins["d4"], self._pins["d5"], self._pins["d6"], self._pins["d7"]]

        for p in self._pins.values():
            GPIO.setup(p, GPIO.OUT)
            GPIO.output(p, 0)
        if self._bl_pin is not None:
            GPIO.setup(self._bl_pin, GPIO.OUT)
        self._init_display()

    def _write_nibble(self, nibble, is_data):
        GPIO.output(self._pins["rs"], 1 if is_data else 0)
        for i, p in enumerate(self._data_pins):
            GPIO.output(p, (nibble >> i) & 1)
        GPIO.output(self._pins["e"], 1)
        time.sleep(0.000001)
        GPIO.output(self._pins["e"], 0)
        time.sleep(0.00005)

    def _backlight(self, on):
        if self._bl_pin is not None:
            try:
                GPIO.output(self._bl_pin, 1 if on else 0)
            except Exception:
                pass

    def _release(self):
        # Leave the pins as plain outputs; the controller's GPIO.cleanup() on
        # exit releases them.
        pass

    def pins_in_use(self):
        pins = list(self._pins.values())
        if self._bl_pin is not None:
            pins.append(self._bl_pin)
        return pins


def create_lcd(cfg):
    """Build an LCD from a door's lcd_config dict.

    Returns an LCD instance, or None if the config is missing/disabled.
    Raises RuntimeError with a readable message when the config is invalid or
    the hardware can't be initialised, so the caller can log it once.
    """
    if not cfg or not cfg.get("enabled"):
        return None
    kind = (cfg.get("type") or "i2c").lower()
    cols = cfg.get("cols") or 16
    rows = cfg.get("rows") or 2
    try:
        if kind == "i2c":
            addr = cfg.get("i2c_address", 0x27)
            if isinstance(addr, str):
                addr = int(addr, 16) if addr.lower().startswith("0x") else int(addr)
            return I2CLCD(cols, rows, bus=cfg.get("i2c_bus", 1), address=addr)
        if kind == "gpio":
            return GPIOLCD(cols, rows, cfg.get("pins") or {})
    except RuntimeError:
        raise
    except Exception as e:
        raise RuntimeError(f"LCD ({kind}) init failed: {e}")
    raise RuntimeError(f"Unknown LCD type '{kind}' (expected 'i2c' or 'gpio')")
