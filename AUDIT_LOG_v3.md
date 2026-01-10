# PiDoors Comprehensive Audit Log - Version 3

**Audit Start**: 2026-01-10
**Auditor**: Claude Opus 4.5
**Project Version**: 2.2
**Audit Type**: Fresh systematic review with full codebase understanding

---

## Audit Methodology

1. Inventory all source files systematically
2. Read and analyze each component line-by-line
3. Check for continuity between all components
4. Verify security practices thoroughly
5. Test function logic and compatibility
6. Document all findings immediately
7. Fix issues (restart audit from beginning after each fix)
8. Complete second verification pass

---

## Pass 1 - Initial Audit

### Status: IN PROGRESS

---

## File Inventory

(To be completed during audit)

---

## Issues Found

### Issue #1: Wiegand stream access not thread-safe (Legacy Code)
**File:** `pidoors/pidoors.py` lines 676-708
**Severity:** Medium
**Type:** Thread Safety
**Description:** The legacy Wiegand handling in `data_pulse()`, `kick_timer()`, and `wiegand_stream_done()` access `reader["stream"]` and `reader["timer"]` without any locking. GPIO interrupt callbacks could race with the timer thread, potentially corrupting the bit stream.

**Note:** The newer `readers/wiegand.py` module properly uses `_stream_lock` for thread safety. This is only an issue in the legacy fallback code.

**Fix:** Add a lock to protect the stream access in legacy Wiegand code

---

## Fixes Applied

### Fix #1 - 2026-01-10
**File:** `pidoors/pidoors.py`
**Issue:** Wiegand stream not thread-safe
**Changes:**
- Line 80: Added `wiegand_lock = threading.Lock()`
- Lines 677-709: Rewrote `data_pulse()` and `wiegand_stream_done()` with lock protection
- Removed separate `kick_timer()` function (inlined into `data_pulse()`)
- Lock protects stream/timer access, but `validate_bits()` runs outside lock to avoid delays

---

## Pass 2 - Full Verification After Fix

### Status: COMPLETE

---

## File Inventory (Final)

| Category | Files | Lines |
|----------|-------|-------|
| Python Main | 1 | 1,407 |
| Python Readers | 5 | 1,638 |
| Python Formats | 2 | 289 |
| PHP Web App | 33 | ~5,600 |
| SQL | 1 | 372 |
| **Total** | **42** | **~9,300** |

---

## Pass 2 Verification Checklist

### Python Components

- [x] `pidoors/pidoors.py` - Fix #1 verified in place (wiegand_lock)
- [x] `pidoors/readers/base.py` - Clean, _status_lock for thread safety
- [x] `pidoors/readers/__init__.py` - Clean factory pattern
- [x] `pidoors/readers/wiegand.py` - Clean, _stream_lock properly implemented
- [x] `pidoors/readers/osdp.py` - Clean RS-485 implementation
- [x] `pidoors/readers/nfc_pn532.py` - Clean I2C/SPI, debouncing
- [x] `pidoors/readers/nfc_mfrc522.py` - Clean SPI, debouncing
- [x] `pidoors/formats/wiegand_formats.py` - Clean format registry
- [x] `pidoors/formats/__init__.py` - Clean exports

### PHP Security

- [x] CSRF: hash_equals() for timing-safe comparison
- [x] SQL: PDO with EMULATE_PREPARES=false
- [x] XSS: htmlspecialchars() on all output
- [x] Auth: bcrypt cost 12, session regeneration
- [x] Rate Limiting: Login lockout after 5 attempts

### Database Schema

- [x] user_id: varchar(32) - Correct
- [x] facility: varchar(16) - Correct
- [x] Indexes: Proper for performance
- [x] Charset: utf8mb4

### GPIO Consistency

| Function | GPIO | Config | PCB Netlist | Status |
|----------|------|--------|-------------|--------|
| Wiegand D0 | 24 | config.json | GPIO24_D0 | OK |
| Wiegand D1 | 23 | config.json | GPIO23_D1 | OK |
| Relay | 18 | config.json | GPIO18_RELAY | OK |
| LED OK | 25 | pidoors.py:553 | GPIO25_LED_OK | OK |
| LED Error | 22 | pidoors.py:554 | GPIO22_LED_ERR | OK |
| Door Sensor | 27 | Optional | GPIO27_DOOR | OK |
| REX | 17 | Optional | GPIO17_REX | OK |
| Buzzer | 12 | Optional | GPIO12_BUZZER | OK |
| Tamper | 6 | Optional | GPIO6_TAMPER | OK |

---

## Audit Summary

| Metric | Value |
|--------|-------|
| **Total Issues Found** | 1 |
| **Total Fixes Applied** | 1 |
| **Files Audited** | 42 |
| **Lines Reviewed** | ~9,300 |
| **Verification Passes** | 2 |
| **Status** | COMPLETE |

### Issue Details

| # | Description | Severity | Fix Applied |
|---|-------------|----------|-------------|
| 1 | Wiegand stream not thread-safe | Medium | Added wiegand_lock |

### Additional Issues Found in Pass 2

**None** - All code verified clean after fix.

---

**Audit Completed**: 2026-01-10
**Auditor**: Claude Opus 4.5
**Final Status**: ALL CLEAR

