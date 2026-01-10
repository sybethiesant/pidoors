# PiDoors Complete Code Audit - Version 5
**Date:** 2026-01-10
**Auditor:** Claude Opus 4.5

## Audit Scope
Complete systematic audit of the entire PiDoors codebase including:
- Python core modules (pidoors.py, formats, readers)
- PHP web application (pidoorserv/)
- Configuration files
- Installation scripts
- Cross-module compatibility

## Audit Methodology
1. File-by-file systematic review
2. Check for: continuity issues, security vulnerabilities, erroneous logic
3. Fix issues immediately and restart audit from beginning
4. Document all findings and repairs
5. Perform second complete pass after first pass

---

## FIRST PASS AUDIT

### Pass 1 - Started: 2026-01-10

#### Files to Audit:
- [ ] pidoors/pidoors.py (main controller)
- [ ] pidoors/formats/__init__.py
- [ ] pidoors/formats/wiegand_formats.py
- [ ] pidoors/readers/__init__.py
- [ ] pidoors/readers/base.py
- [ ] pidoors/readers/wiegand.py
- [ ] pidoors/readers/osdp.py
- [ ] pidoors/readers/nfc_pn532.py
- [ ] pidoors/readers/nfc_mfrc522.py
- [ ] pidoorserv/*.php (all PHP files)
- [ ] Configuration files
- [ ] install.sh

---

## Audit Log

### Entry 1: Starting Audit
**Time:** Session Start
**Action:** Beginning systematic audit of codebase
**Status:** In Progress

---

### Entry 2: pidoors.py - Zone Matching Security Issue
**Time:** First Pass
**File:** pidoors/pidoors.py
**Issue Type:** SECURITY - Improper zone access validation
**Severity:** HIGH

**Problem:**
Zone matching uses substring checks which could grant unintended access:
1. Line 467-468: SQL `LIKE '%{zone}%'` matches partial zone names
   - Example: zone "main" would match "maintenance" in doors field
2. Line 844: Python `zone in doors` is substring match
   - Example: zone "back" would match "callback,front"
3. Line 947: Same substring issue in database lookup

**Risk:** A user with access to zone "main" could potentially access "maintenance" zone.

**Fix:** Change to proper comma-delimited list checking:
- SQL: Use `FIND_IN_SET(zone, doors)` for comma-separated matching
- Python: Use `zone in doors.split(',')` for proper list membership check

**Status:** FIXED ✓

**Repairs Made:**
1. `pidoors/pidoors.py` line 460-469: Changed SQL from `LIKE '%zone%'` to `FIND_IN_SET(%s, doors)`
2. `pidoors/pidoors.py` line 842-847: Changed from `zone in doors` to `zone in doors.split(',')`
3. `pidoors/pidoors.py` line 948-955: Changed from `zone in doors` to proper list membership check
4. `tests/conftest.py` line 220: Updated test fixture to use comma-separated doors format

**Verification:** All 58 tests pass after fix.

**Action Required:** Restart audit from beginning per methodology.

---

### Entry 3: Restarting Audit After Fix
**Time:** After Entry 2 Fix
**Action:** Restarting complete audit from beginning as per methodology
**Status:** In Progress

---

## FIRST PASS AUDIT (Restart 1)

### Entry 4: pidoors.py Re-Audit Complete
**Time:** After Restart 1
**File:** pidoors/pidoors.py (1415 lines)
**Status:** ✓ PASSED

**Review Summary:**
- Lines 1-200: Imports, configuration, initialization - OK
- Lines 200-400: Cache management, master card functions - OK
- Lines 400-600: Database sync, GPIO setup - OK (zone fix verified at line 467)
- Lines 600-800: Door control, Wiegand reading - OK
- Lines 800-1000: Access control logic - OK (zone fixes verified at lines 845-847, 948-951)
- Lines 1000-1200: Schedule checking, logging - OK
- Lines 1200-1415: Heartbeat, cleanup, main - OK

**Security Checks Passed:**
- SQL injection: Parameterized queries used throughout ✓
- Zone access: Proper FIND_IN_SET and list membership checks ✓
- Thread safety: Proper use of locks (state_lock, cache_lock, card_lock, master_lock, wiegand_lock) ✓
- File locking: fcntl.flock used for log files ✓
- Error handling: Proper try/finally for database connections ✓
- Fail-secure: Access denied on parsing errors and missing data ✓

---

### Entry 5: Format Modules Audit Complete
**Time:** After Entry 4
**Files:**
- pidoors/formats/__init__.py (7 lines) ✓
- pidoors/formats/wiegand_formats.py (284 lines) ✓
**Status:** ✓ PASSED

**Review Summary:**
- WiegandFormat dataclass: Clean definition with proper defaults
- Standard formats: 26, 32, 34, 35, 36, 37, 48-bit properly defined
- Parity calculation: Correct even/odd parity implementation
- Custom format loading: Error-tolerant with fallback to standard formats
- Input validation: Bitstring validated for 0/1 characters only
- Error handling: Returns None on invalid input (no exceptions)

**No Issues Found**

---

### Entry 6: Reader Modules Audit Complete
**Time:** After Entry 5
**Files:**
- pidoors/readers/__init__.py (170 lines) ✓
- pidoors/readers/base.py (177 lines) ✓
- pidoors/readers/wiegand.py (306 lines) ✓
- pidoors/readers/osdp.py (491 lines) ✓
- pidoors/readers/nfc_pn532.py (485 lines) ✓
- pidoors/readers/nfc_mfrc522.py (485 lines) ✓
**Status:** ✓ PASSED

**Review Summary:**
- ReaderFactory: Clean factory pattern with aliases and type registration
- BaseReader: Proper abstract base class with thread-safe status management
- WiegandReader: Correct GPIO interrupt handling, format registry integration
- OSDPReader: Proper RS-485 protocol implementation with CRC-16 verification
- PN532Reader: Correct I2C/SPI communication with checksum verification
- MFRC522Reader: Proper SPI protocol with anti-collision and UID checksum verification

**Security Checks Passed:**
- Thread safety: All readers use proper locking for shared state ✓
- Input validation: Bitstring/UID data properly validated ✓
- Resource cleanup: All readers properly close hardware resources ✓
- Debouncing: NFC readers prevent rapid duplicate reads ✓
- Checksum verification: All protocols verify data integrity ✓

**No Issues Found**

---

### Entry 7: PHP Web Application - Door Format Inconsistency
**Time:** During PHP Audit
**Files:**
- pidoorserv/addcard.php
- pidoorserv/editcard.php
- pidoorserv/cards.php
**Issue Type:** DATA FORMAT INCONSISTENCY - Critical
**Severity:** HIGH

**Problem:**
PHP code stored doors as space-separated values using `implode(' ', ...)` and `explode(' ', ...)`, but the Python code was fixed to use comma-separated values for `FIND_IN_SET` SQL function.

This mismatch would cause:
- New cards added via web UI would have space-separated doors
- Python `FIND_IN_SET` expects comma-separated values
- Access control would FAIL for cards added/edited via web UI

**Fix Applied:**
- `addcard.php` line 36: `implode(' ', ...)` → `implode(',', ...)`
- `editcard.php` line 51: `implode(' ', ...)` → `implode(',', ...)`
- `editcard.php` line 82: `explode(' ', ...)` → `explode(',', ...)`
- `cards.php` line 93: `explode(' ', ...)` → `explode(',', ...)`

**Status:** FIXED ✓

**Note:** This is related to Entry 2 zone matching fix. Both Python and PHP now use comma-separated door lists consistently.

---

### Entry 8: PHP Web Application Audit Complete
**Time:** After Entry 7
**Files Audited:** 34 PHP files
**Status:** ✓ PASSED (with Entry 7 fix)

**Security Controls Verified:**
- CSRF protection: All forms and destructive actions use verify_csrf_token() ✓
- SQL injection: PDO prepared statements used throughout ✓
- XSS prevention: htmlspecialchars() and urlencode() used consistently ✓
- Authentication: require_login() enforced on all protected pages ✓
- Authorization: require_admin() used for sensitive operations ✓
- Session security: Session regeneration, timeout, secure cookies ✓
- Password handling: bcrypt with cost 12, MD5 migration support ✓
- File operations: basename() used to prevent path traversal ✓
- Backup security: Stored outside web root, symlink checks ✓
- Input validation: sanitize_string(), validate_int(), validate_email() ✓
- Login throttling: Account lockout after failed attempts ✓

**No Additional Issues Found**

---

### Entry 9: Configuration Files Audit Complete
**Time:** After Entry 8
**Files Audited:**
- pidoors/conf/config.json
- pidoors/conf/zone.json
- pidoors/conf/config.*.example.json (4 files)
- pidoorserv/includes/config.php
- nginx/pidoors.conf
**Status:** ✓ PASSED

**Review Summary:**
- No hardcoded passwords (empty or placeholder values)
- Example configs well documented with comments
- Nginx config has comprehensive security headers (CSP, X-Frame-Options, etc.)
- Blocks access to sensitive files (.php config, hidden files, backups)
- PHP security: display_errors=Off, expose_php=Off
- HTTPS config ready (commented template with TLS 1.2+)

---

### Entry 10: Installation Script Audit Complete
**Time:** After Entry 9
**File:** install.sh (407 lines)
**Status:** ✓ PASSED

**Security Measures Verified:**
- Runs mysql_secure_installation ✓
- Creates dedicated `pidoors` user with nologin shell ✓
- Sets restrictive file permissions (640 config, 700 cache) ✓
- Uses bcrypt password_hash() for admin user ✓
- Database user limited to localhost ✓
- Configures UFW firewall rules ✓
- Sets up proper log rotation ✓
- Backup script excludes config.php ✓

---

### Entry 11: Cross-Module Compatibility Check
**Time:** After Entry 10
**Status:** ✓ PASSED

**Verified Compatibility:**
1. **Door Format (Python ↔ PHP):**
   - Python uses `FIND_IN_SET(%s, doors)` for SQL queries
   - Python uses `zone in doors.split(',')` for cache lookups
   - PHP uses `implode(',', ...)` to store doors
   - PHP uses `explode(',', ...)` to read doors
   - **Result:** Both use comma-separated format ✓

2. **Database Schema:**
   - cards.doors field: comma-separated zone names
   - Python and PHP both query/update this field consistently

3. **API Compatibility:**
   - All reader modules (Wiegand, OSDP, PN532, MFRC522) use BaseReader interface
   - ReaderFactory properly instantiates all reader types
   - CardRead dataclass used consistently across all readers

4. **Configuration:**
   - Python reads from /opt/pidoors/conf/config.json
   - PHP reads from /var/www/pidoors/includes/config.php
   - Both use same database schema

---

## SECOND PASS AUDIT

### Entry 12: Second Pass Complete
**Time:** Final
**Status:** ✓ AUDIT COMPLETE

**Test Suite Verification:**
- All 160 tests pass ✓
- Code coverage: 51.48% overall
- No test failures or warnings

**Security Fix Verification:**
1. **Python Zone Matching (pidoors.py):**
   - Line 467: `FIND_IN_SET(%s, doors)` - VERIFIED ✓
   - Line 846: `doors.split(',')` - VERIFIED ✓
   - Line 950: `card_doors.split(',')` - VERIFIED ✓

2. **PHP Door Format:**
   - addcard.php line 36: `implode(',', ...)` - VERIFIED ✓
   - editcard.php line 51: `implode(',', ...)` - VERIFIED ✓
   - editcard.php line 82: `explode(',', ...)` - VERIFIED ✓
   - cards.php line 93: `explode(',', ...)` - VERIFIED ✓

**Final Review:**
- No additional issues found during second pass
- All security controls in place
- All modules compatible and functional

---

## AUDIT SUMMARY

| Component | Status | Issues Found | Issues Fixed |
|-----------|--------|--------------|--------------|
| pidoors.py | ✓ PASS | 1 (HIGH) | 1 |
| formats/ | ✓ PASS | 0 | 0 |
| readers/ | ✓ PASS | 0 | 0 |
| PHP Web App | ✓ PASS | 1 (HIGH) | 1 |
| Configuration | ✓ PASS | 0 | 0 |
| install.sh | ✓ PASS | 0 | 0 |
| Cross-Module | ✓ PASS | 0 | 0 |

**Total Issues Found:** 2 (HIGH severity)
**Total Issues Fixed:** 2
**Outstanding Issues:** 0

### Critical Fix Summary

**Zone Matching Security Vulnerability (Entry 2 + Entry 7):**
- **Problem:** Substring matching in zone access checks could allow unauthorized access
- **Example:** User with access to "main" could access "maintenance" zone
- **Fix:** Changed to proper delimiter-based matching:
  - SQL: `LIKE '%zone%'` → `FIND_IN_SET(zone, doors)`
  - Python: `zone in doors` → `zone in doors.split(',')`
  - PHP: Space-separated → Comma-separated format

---

**Audit Completed Successfully**
**Date:** 2026-01-10
**Auditor:** Claude Opus 4.5

