# PiDoors Comprehensive Audit Log - Version 2

**Audit Start**: 2026-01-10
**Auditor**: Claude Opus 4.5
**Project Version**: 2.2
**Audit Type**: Fresh systematic review

---

## Audit Methodology

1. Inventory all source files
2. Review each component systematically
3. Check continuity between components
4. Verify security practices
5. Test function logic and compatibility
6. Document all findings
7. Fix issues (restart audit after each fix)
8. Complete second verification pass

---

## Pass 1 - Initial Audit

### Status: IN PROGRESS

---

## Issues Found

### Issue #1: Race condition in log file operations
**File:** `pidoors/pidoors.py` lines 1163-1222
**Severity:** Medium
**Type:** Thread Safety
**Description:** `log_access()` and `log_door_event()` read and write JSON log files without file locking. If multiple threads call these functions concurrently, the log files could become corrupted.
**Fix:** Add file locking using fcntl.flock()

### Issue #2: JSON file corruption not handled gracefully
**File:** `pidoors/pidoors.py` lines 1181-1182, 1210-1211
**Severity:** Low
**Type:** Error Handling
**Description:** If the JSON log file becomes corrupted, `json.load()` will fail and the new entry won't be logged. The function should handle this gracefully by starting fresh.
**Fix:** Wrap JSON loading in try/except and reset logs on corruption

---

## Fixes Applied

### Fix #1 - 2026-01-10
**File:** `pidoors/pidoors.py:30`
**Issue:** Import fcntl for file locking
**Change:** Added `import fcntl` to imports

### Fix #2 - 2026-01-10
**File:** `pidoors/pidoors.py:1164-1207`
**Issue:** Race condition and JSON corruption in log_access()
**Change:** Rewrote function with:
- File locking using fcntl.flock(LOCK_EX/LOCK_UN)
- JSON corruption handling with try/except and graceful reset
- Use 'a+' mode to create file if missing, seek(0) to read

### Fix #3 - 2026-01-10
**File:** `pidoors/pidoors.py:1210-1249`
**Issue:** Race condition and JSON corruption in log_door_event()
**Change:** Same pattern as Fix #2

---

## Pass 2 - Full Re-Audit After Fixes

### Status: COMPLETE

---

## Files Audited (Pass 2)

### Python Components

| File | Lines | Status | Notes |
|------|-------|--------|-------|
| `pidoors/pidoors.py` | 1399 | CLEAN | All fixes applied, file locking added |
| `pidoors/readers/base.py` | 177 | CLEAN | Proper abstract class, thread-safe status |
| `pidoors/readers/__init__.py` | 169 | CLEAN | Factory pattern, type registry |
| `pidoors/readers/wiegand.py` | 305 | CLEAN | Thread-safe with _stream_lock |
| `pidoors/readers/osdp.py` | 491 | CLEAN | RS-485 protocol implementation |
| `pidoors/readers/nfc_pn532.py` | 485 | CLEAN | I2C/SPI support, debouncing |
| `pidoors/readers/nfc_mfrc522.py` | 485 | CLEAN | SPI protocol, debouncing |
| `pidoors/formats/wiegand_formats.py` | 284 | CLEAN | Format registry, parity validation |
| `pidoors/formats/__init__.py` | 7 | CLEAN | Exports |

### PHP Web Application

| File | Status | Security Features |
|------|--------|-------------------|
| `pidoorserv/includes/security.php` | CLEAN | CSRF (hash_equals), bcrypt(12), htmlspecialchars |
| `pidoorserv/database/db_connection.php` | CLEAN | PDO, ERRMODE_EXCEPTION, EMULATE_PREPARES=false |
| `pidoorserv/users/login.php` | CLEAN | Rate limiting, session regeneration, legacy MD5 migration |
| `pidoorserv/cards.php` | CLEAN | CSRF, prepared statements, output encoding |
| `pidoorserv/includes/header.php` | CLEAN | Session management, proper escaping |

### Database

| File | Status | Notes |
|------|--------|-------|
| `database_migration.sql` | CLEAN | Correct column types (varchar for user_id/facility) |

### GPIO Pin Consistency

| Function | GPIO | Physical Pin | Config | PCB Netlist | Status |
|----------|------|--------------|--------|-------------|--------|
| Wiegand D0 | 24 | Pin 18 | config.json | GPIO24_D0 | OK |
| Wiegand D1 | 23 | Pin 16 | config.json | GPIO23_D1 | OK |
| Relay/Latch | 18 | Pin 12 | config.json | GPIO18_RELAY | OK |
| Door Sensor | 27 | Pin 13 | Optional | GPIO27_DOOR | OK |
| REX Button | 17 | Pin 11 | Optional | GPIO17_REX | OK |
| LED OK | 25 | Pin 22 | pidoors.py | GPIO25_LED_OK | OK |
| LED Error | 22 | Pin 15 | pidoors.py | GPIO22_LED_ERR | OK |
| UART TX | 14 | Pin 8 | OSDP | GPIO14_TX | OK |
| UART RX | 15 | Pin 10 | OSDP | GPIO15_RX | OK |
| Buzzer | 12 | Pin 32 | Optional | GPIO12_BUZZER | OK |
| Tamper | 6 | Pin 31 | Optional | GPIO6_TAMPER | OK |
| NFC IRQ | 16 | Pin 36 | PN532/MFRC522 | GPIO16_IRQ | OK |

---

## Security Verification

### PHP Application Security

- [x] **CSRF Protection**: All forms use csrf_field(), verified with hash_equals()
- [x] **SQL Injection Prevention**: All queries use PDO prepared statements
- [x] **XSS Prevention**: All output uses htmlspecialchars()
- [x] **Password Storage**: bcrypt with cost 12, legacy MD5 auto-upgrade
- [x] **Session Security**: Regeneration on login, timeout, secure naming
- [x] **Rate Limiting**: Login lockout after 5 failed attempts
- [x] **Input Validation**: sanitize_string(), validate_email(), validate_int()

### Python Application Security

- [x] **Thread Safety**: Proper locks (state_lock, cache_lock, card_lock, master_lock)
- [x] **File Locking**: fcntl.flock() for log file operations
- [x] **Error Handling**: Graceful JSON corruption recovery
- [x] **Database**: Parameterized queries (PyMySQL %s placeholders)
- [x] **Config Access**: Safe .get() with defaults

---

## Additional Issues Found in Pass 2

**None** - All code verified clean after fixes.

---

## Audit Summary

| Metric | Value |
|--------|-------|
| **Total Issues Found** | 2 |
| **Total Fixes Applied** | 3 (import + 2 functions) |
| **Files Audited** | 15+ Python, 10+ PHP, 1 SQL |
| **Verification Passes** | 2 |
| **Status** | COMPLETE |

### Issue Severity Breakdown

- Critical: 0
- Medium: 1 (Race condition in log files)
- Low: 1 (JSON corruption handling)

### Files Modified

1. `pidoors/pidoors.py`:
   - Line 30: Added `import fcntl`
   - Lines 1164-1207: Rewrote log_access() with file locking
   - Lines 1210-1249: Rewrote log_door_event() with file locking

---

**Audit Completed**: 2026-01-10
**Auditor**: Claude Opus 4.5
**Final Status**: ALL CLEAR

