# PiDoors - Open Source Access Control System

![License](https://img.shields.io/badge/license-Open%20Source-blue)
![Platform](https://img.shields.io/badge/platform-Raspberry%20Pi-red)
![Version](https://img.shields.io/badge/version-3.1.3-green)
![Status](https://img.shields.io/badge/status-Production%20Ready-brightgreen)

**Professional-grade physical access control powered by Raspberry Pi**

---

## Overview

PiDoors is a complete, industrial-grade access control system built on Raspberry Pi hardware. It provides enterprise-level security features while remaining affordable and open source. Designed for small businesses, makerspaces, office buildings, or anyone needing professional access control.

**Key Benefits:**
- **Cost-Effective**: 10x cheaper than commercial systems (~$100-150 per door vs $500-2000)
- **Secure**: TLS database encryption, bcrypt passwords, SQL injection protection, CSRF tokens
- **Open Source**: Full control over your security system
- **Modern Interface**: React SPA with TailwindCSS — fast, responsive single-page application
- **Offline Capable**: 24-hour local caching keeps doors working during network outages
- **Extensible**: Easy to customize and integrate

---

## Features

### Access Control
- **Multi-format Wiegand**: 26, 32, 34, 35, 36, 37, 48-bit with auto-detection
- **OSDP Readers**: RS-485 encrypted readers (planned — reader module included, not yet integrated)
- **NFC/RFID**: PN532 and MFRC522 support (planned — reader modules included, not yet integrated)
- Time-based access schedules
- Access groups and permissions
- Holiday calendar support
- Card validity date ranges
- Persistent master cards (never expire for emergency access)
- **Master card toggle** in web UI — promote any card to master with a checkbox

### Management
- Modern React SPA with REST API backend
- Login by username or email
- Real-time dashboard with analytics
- Multi-user administration with extended profiles (name, department, company, etc.)
- Extended cardholder details (email, phone, department, employee ID, company, title)
- CSV bulk import/export with all cardholder fields (including master card flag)
- Comprehensive reporting
- Email notifications
- Complete audit trail
- Remote door control
- Instant offline detection via server-initiated polling (no stale heartbeat data)
- Door auto-registration from client heartbeat

### Updates
- One-click server updates from the web UI
- Remote controller updates via heartbeat signaling
- Pre-flight checks prevent partial updates
- Actionable error messages on failure
- Version tracking across all doors and server

### Reliability
- 24-hour offline operation
- Automatic failover
- Health monitoring
- Auto-reconnection
- Automated backups
- Service redundancy

### Security
- **TLS database encryption** — controller-to-server connections encrypted automatically
- Bcrypt password hashing (cost 12)
- PDO prepared statements (SQL injection proof)
- CSRF protection on all forms
- Secure session management
- Input validation and sanitization
- Security event logging
- Rate limiting on login

---

## Quick Start

### Prerequisites

- Raspberry Pi 3B+ or newer (Pi 4 recommended for server)
- Raspberry Pi OS (Debian-based, 64-bit recommended)
- Internet connection for initial setup
- Node.js and npm (installed automatically by `install.sh`)
- For door controllers: card reader hardware and relay module

### Automated Installation (Recommended)

```bash
# Download PiDoors
git clone https://github.com/sybethiesant/pidoors.git
cd pidoors

# Run installer as root
sudo ./install.sh
```

The installer presents three installation modes:

| Mode | Use Case |
|------|----------|
| **1) Server** | Web interface + MariaDB database (run on your central Pi) |
| **2) Door Controller** | GPIO + card reader daemon (run on each door Pi) |
| **3) Full** | Both server and controller on one Pi (small deployments) |

#### What the installer does

1. Updates system packages
2. Installs dependencies (Nginx, PHP-FPM, MariaDB, Node.js, Python libraries)
3. Generates TLS certificates and enables encrypted database connections
4. Creates the `users` and `access` databases with all required tables
5. Imports the full database schema (base tables + migration extensions)
6. Deploys the PHP API to `/var/www/pidoors/`
7. Builds the React SPA and deploys to `/var/www/pidoors-ui/`
8. Configures Nginx to serve the SPA with `/api/*` routed to PHP-FPM
9. Prompts you to create an admin account (username, email + password)
10. Sets up log rotation and backup scripts

#### After installation

1. Open `https://your-pi-ip/` in a browser
2. Log in with the email (or username `Admin`) and password you set during install
3. Navigate to **Doors** to see your door controllers as they come online
4. Navigate to **Cards** to add access cards
5. Set up **Schedules** and **Access Groups** as needed

### Manual Installation

If you prefer to install manually or need to troubleshoot:

#### 1. Install system packages
```bash
sudo apt-get update && sudo apt-get install -y \
  nginx php-fpm php-mysql php-cli php-mbstring php-curl php-json \
  mariadb-server nodejs npm \
  python3 python3-pip python3-dev python3-venv git curl
```

#### 2. Create databases
```bash
sudo mysql_secure_installation

sudo mysql -u root -p <<'SQL'
CREATE DATABASE IF NOT EXISTS users;
CREATE DATABASE IF NOT EXISTS access;
CREATE USER IF NOT EXISTS 'pidoors'@'localhost' IDENTIFIED BY 'YOUR_PASSWORD';
CREATE USER IF NOT EXISTS 'pidoors'@'%' IDENTIFIED BY 'YOUR_PASSWORD';
GRANT ALL PRIVILEGES ON users.* TO 'pidoors'@'localhost';
GRANT ALL PRIVILEGES ON access.* TO 'pidoors'@'localhost';
GRANT ALL PRIVILEGES ON users.* TO 'pidoors'@'%';
GRANT ALL PRIVILEGES ON access.* TO 'pidoors'@'%';
FLUSH PRIVILEGES;
SQL
```

> **Note:** The `'pidoors'@'%'` user allows door controllers on other Pis to connect remotely. You also need to set `bind-address = 0.0.0.0` in `/etc/mysql/mariadb.conf.d/50-server.cnf` and restart MariaDB.

#### 3. Import schemas
```bash
# Create the users table and audit_logs table in the users database
sudo mysql -u root -p users <<'SQL'
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_name` varchar(100) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `user_pass` varchar(255) NOT NULL,
  `admin` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_email` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_type` varchar(50) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `details` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `event_type` (`event_type`),
  KEY `user_id` (`user_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL

# Import the access database schema and migration extensions
sudo mysql -u root -p access < database_migration.sql
```

The migration script creates all access tables, adds extended columns, and also switches to the `users` database to add profile columns. It is safe to re-run on existing installations.

#### 4. Create your admin user
```bash
# Generate a bcrypt hash for your password
HASH=$(php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_BCRYPT);")

sudo mysql -u root -p users -e \
  "INSERT INTO users (user_name, user_email, user_pass, admin, active) \
   VALUES ('Admin', 'admin@example.com', '$HASH', 1, 1);"
```

#### 5. Deploy the PHP API
```bash
sudo mkdir -p /var/www/pidoors
sudo cp -r pidoorserv/* /var/www/pidoors/
sudo cp VERSION /var/www/pidoors/
sudo cp /var/www/pidoors/includes/config.php.example /var/www/pidoors/includes/config.php
sudo nano /var/www/pidoors/includes/config.php    # Set your database password and server IP
sudo chown -R www-data:www-data /var/www/pidoors
sudo chmod 640 /var/www/pidoors/includes/config.php
```

#### 6. Build and deploy the React SPA
```bash
sudo apt-get install -y nodejs npm
cd pidoors-ui
npm install && npm run build
sudo mkdir -p /var/www/pidoors-ui
sudo cp -r dist/* /var/www/pidoors-ui/
sudo chown -R www-data:www-data /var/www/pidoors-ui
cd ..
```

#### 7. Configure Nginx
```bash
# Detect your PHP version
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")

# Install config with correct PHP socket path
sudo sed "s|php-fpm.sock|php${PHP_VERSION}-fpm.sock|g" \
  nginx/pidoors.conf > /etc/nginx/sites-available/pidoors
sudo ln -sf /etc/nginx/sites-available/pidoors /etc/nginx/sites-enabled/pidoors
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

See the [Installation Guide](pidoors/INSTALLATION_GUIDE.md) for additional details.

---

## System Architecture

```
                    REACT SPA (Browser)
     Dashboard | Cards | Doors | Logs | Reports | Settings
                          |
                     /api/* REST
                          |
     SERVER RASPBERRY PI
    +--------------------------+
    |  Nginx                   |
    |  ├─ /         → React   |
    |  └─ /api/*    → PHP-FPM |
    |  MariaDB (TLS)           |
    |  Backups & Logs          |
    +--------------------------+
          |                  |
     HTTPS Push         TLS/TCP DB
     (instant)          (sync/poll)
          |                  |
    +----------------+  +----------------+
    |  Door Pi #1    |  |  Door Pi #N    |
    |  Push Listener |  |  Push Listener |
    |  24hr Cache    |  |  24hr Cache    |
    |  Card Reader   |  |  Card Reader   |
    |  Electric Lock |  |  Electric Lock |
    +----------------+  +----------------+
```

**One server Pi** runs the React SPA, PHP API, and database.
**N door Pis** control individual access points with 24-hour local caching.
The server pushes commands instantly via HTTPS, with database polling as fallback.
The server pings controllers on each page load for instant status. Heartbeat runs every 5 minutes as a safety net.

---

## Hardware Requirements

### Server (1 per system)
| Component | Requirement |
|-----------|-------------|
| Board | Raspberry Pi 3B+ or newer (4GB RAM recommended) |
| Storage | 16GB+ microSD card |
| Network | Ethernet recommended |
| Power | Official Raspberry Pi power supply |

### Door Controller (1 per door)
| Component | Requirement |
|-----------|-------------|
| Board | Raspberry Pi Zero W or newer |
| Storage | 8GB+ microSD card |
| Reader | See supported readers below |
| Lock | 12V electric strike or magnetic lock |
| Relay | Relay module for lock control |
| Optional | Door sensor, REX button |

**Supported Card Readers:**
| Type | Interface | Notes |
|------|-----------|-------|
| Wiegand (26/32/34/35/36/37/48-bit) | GPIO | Most common, auto-detection |
| OSDP v2 | RS-485 (UART) | Encrypted, requires USB-RS485 adapter |
| PN532 NFC | I2C or SPI | Mifare Classic, Ultralight, NTAG |
| MFRC522 NFC | SPI | Low-cost Mifare reader |

**Total cost per door: ~$100-150**

---

## Configuration

### Server Configuration

1. Copy the configuration template:
```bash
cp pidoorserv/includes/config.php.example pidoorserv/includes/config.php
```

2. Edit configuration:
```bash
nano pidoorserv/includes/config.php
```

3. Set your values:
```php
return [
    'sqladdr' => '127.0.0.1',
    'sqldb' => 'users',
    'sqldb2' => 'access',
    'sqluser' => 'pidoors',
    'sqlpass' => 'your_secure_password',
    'url' => 'https://your-pi-ip',
    // ... other settings
];
```

4. Secure the file:
```bash
chmod 640 pidoorserv/includes/config.php
```

### Door Controller Configuration

1. Copy the configuration template:
```bash
cp pidoors/conf/config.json.example pidoors/conf/config.json
```

2. Edit configuration:
```bash
nano pidoors/conf/config.json
```

3. Set your values (use your actual door name as the key):
```json
{
    "frontdoor": {
        "reader_type": "wiegand",
        "d0": 24,
        "d1": 23,
        "wiegand_format": "auto",
        "latch_gpio": 18,
        "open_delay": 5,
        "unlock_value": 1,
        "sqladdr": "SERVER_IP_ADDRESS",
        "sqluser": "pidoors",
        "sqlpass": "your_database_password",
        "sqldb": "access"
    }
}
```

---

## Wiring Guide

### Wiegand Reader to Raspberry Pi

| Wiegand Reader | Raspberry Pi |
|----------------|--------------|
| DATA0 (Green)  | GPIO 24      |
| DATA1 (White)  | GPIO 23      |
| GND (Black)    | GND (Pin 6)  |
| 5V+ (Red)      | 5V (Pin 2)   |

### Lock Relay Control

| Relay Module | Raspberry Pi |
|--------------|--------------|
| IN           | GPIO 18      |
| VCC          | 5V (Pin 4)   |
| GND          | GND (Pin 14) |

Connect lock to relay NO/COM terminals with 12V power supply.

### GPIO Pin Reference
```
    3V3  (1)  (2)  5V
  GPIO2  (3)  (4)  5V
  GPIO3  (5)  (6)  GND
  GPIO4  (7)  (8)  GPIO14
    GND  (9)  (10) GPIO15
 GPIO17 (11) (12) GPIO18    <- Relay (GPIO18)
 GPIO27 (13) (14) GND
 GPIO22 (15) (16) GPIO23    <- DATA1 (GPIO23)
    3V3 (17) (18) GPIO24    <- DATA0 (GPIO24)
```

Full wiring diagrams available in [Installation Guide](pidoors/INSTALLATION_GUIDE.md#wiring).

---

## Usage

### Adding a Card

**Via Web Interface:**
1. Navigate to **Cards** > **Add Card**
2. Enter card details (scan card at reader to get ID)
3. Assign access groups and schedules
4. Click **Add Card**

**Via CSV Import:**
```csv
card_id,user_id,firstname,lastname,email,department
12345678,EMP001,John,Smith,john@example.com,Engineering
87654321,EMP002,Jane,Doe,jane@example.com,Marketing
```

Upload at **Cards** > **Import CSV**. Optional columns: `email`, `phone`, `department`, `employee_id`, `company`, `title`, `notes`, `group_id`, `schedule_id`, `valid_from`, `valid_until`, `pin_code`.

### Creating Access Schedules

1. Go to **Schedules** > **Add Schedule**
2. Name the schedule (e.g., "Business Hours")
3. Set time windows for each day
4. Assign to cards or doors

### Monitoring Access

- **Dashboard**: Real-time statistics and charts
- **Access Logs**: Filter by date, door, user; export to CSV
- **Audit Log**: Track all administrative actions
- **Email Alerts**: Failed access attempts, door offline notifications

---

## Maintenance

### Automatic Backups
Backups run daily at 2 AM to `/var/backups/pidoors/`

### Manual Backup
```bash
sudo /usr/local/bin/pidoors-backup.sh
```

### Update PiDoors

**Via Web UI (Recommended):**
1. Go to **Updates** in the admin sidebar
2. Click **Check for Updates** to see the latest release
3. Click **Update Server** to update the web interface
4. Use the **Doors** page to push updates to door controllers

**Manual update:**
```bash
cd ~/pidoors
git pull
sudo cp -r pidoorserv/* /var/www/pidoors/
sudo systemctl restart nginx
sudo systemctl restart pidoors  # On door controllers
```

### Database Migrations

When upgrading, run the migration script to add any new columns:

```bash
# Backup first
mysqldump -u pidoors -p access > backup_access_$(date +%Y%m%d).sql
mysqldump -u pidoors -p users > backup_users_$(date +%Y%m%d).sql

# Run migration (safe to re-run, uses IF NOT EXISTS checks)
mysql -u root -p access < database_migration.sql
```

The migration script handles both the `access` and `users` databases automatically.

**v2.2.1 Migration (Required if upgrading from v2.2 or earlier):**

This migration converts door assignments from space-separated to comma-separated format:

```bash
python3 migrations/migrate_doors_format.py --dry-run   # Preview
python3 migrations/migrate_doors_format.py              # Apply
```

### View Logs
```bash
# Door controller logs
sudo journalctl -u pidoors -f

# Web server logs
sudo tail -f /var/log/nginx/pidoors_error.log
```

---

## Security

### Security Features
- TLS encryption for all database connections (auto-configured during install)
- Bcrypt password hashing with automatic MD5 upgrade
- PDO prepared statements (no SQL injection)
- CSRF token protection on all forms
- Secure session handling with timeout
- Comprehensive input validation
- Complete audit logging
- Login rate limiting (5 attempts, 15-minute lockout)

### Reporting Vulnerabilities
Please report security issues to the repository owner directly, not via public issues.

---

## File Structure

```
pidoors/
├── pidoorserv/           # PHP API backend
│   ├── api.php           # Unified REST API router
│   ├── includes/         # Core PHP includes
│   │   ├── config.php.example
│   │   ├── security.php
│   │   ├── header.php
│   │   ├── push.php      # Push communication & status polling
│   │   ├── notifications.php
│   │   └── smtp.php      # Lightweight SMTP sender
│   ├── cron/             # Scheduled tasks (notifications)
│   ├── users/            # User management
│   ├── database/         # Database connection
│   ├── css/              # Stylesheets (legacy)
│   └── js/               # JavaScript (legacy)
├── pidoors-ui/           # React SPA frontend
│   ├── src/
│   │   ├── pages/        # Dashboard, Cards, Doors, Settings, etc.
│   │   ├── components/   # Shared UI components
│   │   ├── contexts/     # Auth context
│   │   ├── api/          # REST API client
│   │   └── types/        # TypeScript type definitions
│   ├── package.json
│   └── vite.config.ts
├── pidoors/              # Door controller
│   ├── pidoors.py        # Main daemon
│   ├── pidoors.service   # Systemd service
│   ├── pidoors-update.sh # Self-update script (runs as root via sudo)
│   ├── readers/          # Card reader modules
│   │   ├── base.py       # Abstract base class
│   │   ├── wiegand.py    # Wiegand GPIO reader
│   │   ├── osdp.py       # OSDP RS-485 reader
│   │   ├── nfc_pn532.py  # PN532 NFC reader
│   │   └── nfc_mfrc522.py # MFRC522 NFC reader
│   ├── formats/          # Card format definitions
│   │   └── wiegand_formats.py
│   └── conf/             # Configuration
│       └── config.json.example
├── nginx/                # Nginx configuration
│   └── pidoors.conf
├── docker/               # Docker dev/test environment
│   ├── docker-compose.yml
│   ├── Dockerfile.server
│   ├── Dockerfile.door
│   ├── server-entrypoint.sh
│   ├── door-entrypoint.sh
│   ├── deploy.sh         # Deploy to remote Docker host
│   └── mock_gpio.py      # Mock GPIO for containerized door
├── pidoorspcb/           # PCB design files (KiCAD)
├── VERSION               # Current version number
├── install.sh            # Installation script
├── server-update.sh      # Server self-update script
├── database_migration.sql
└── README.md
```

---

## Documentation

| Document | Description |
|----------|-------------|
| [Installation Guide](pidoors/INSTALLATION_GUIDE.md) | Complete beginner-friendly setup |

---

## Contributing

Contributions welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

**Requirements:**
- Test on actual Raspberry Pi hardware
- Verify database migrations work
- Check security implications
- Update documentation

---

## Roadmap

**Current Version: 3.1.3** - Production Ready

**Future Enhancements** (community contributions welcome):
- Mobile app (iOS/Android)
- Bluetooth Low Energy (BLE) readers
- Biometric integration (fingerprint, face)
- Cloud backup integration
- Multi-site management dashboard

---

## Changelog

### Version 3.1.3 (March 2026)
- **Security**: CA key permissions fix — `ca-key.pem` now readable by www-data (`640 mysql:www-data`) so the `/api/certs/sign` endpoint can sign door controller TLS certificates on fresh installs
- **Fix**: Door controller cert signing was silently failing on all installs (CA key was `600 mysql:mysql`, PHP-FPM couldn't read it) — all doors fell back to self-signed certs, breaking push verification
- **UI**: Hide locked/unlocked and door sensor badges when a door is offline — stale state was misleading
- **UI**: Timezone setting is now a dropdown menu instead of free-text input
- **Docker**: Door container waits for server API before attempting cert signing (fixes race condition where both containers start simultaneously)
- **Docker**: Server container symlinks `/etc/mysql/ssl/` to persisted volume so cert signing API finds the CA
- **Docker**: Unbuffered Python output for door container logs

### Version 3.1.2 (March 2026)
- **Docker**: Complete rewrite — replaced 5-container setup with 2 minimal Debian Bookworm containers mirroring real Pi installs
- **Docker**: Server container runs MariaDB + PHP-FPM + Nginx in one container with TLS, React build, and full install.sh parity
- **Docker**: Door container runs pidoors.py with mock GPIO, CA-signed TLS certs, and database registration
- **Fix**: `install.sh` config.php URL now uses `https://` instead of `http://` to match HTTPS-enforced nginx
- **Security**: Nginx CSP header allows `'unsafe-inline'` for `script-src` to support Vite's module bootstrap

### Version 3.1.1 (March 2026)
- **Security**: IP-based login rate limiting — survives cookie clearing, uses `audit_logs` table
- **Security**: Timing-safe login — dummy `password_verify` prevents user enumeration; unified error messages leak no attempt counts or account status
- **Security**: Admin re-verified from database on every admin API call — demotion/deactivation takes immediate effect
- **Security**: HTTPS enabled with PiDoors CA-signed certificates, HSTS (`max-age=63072000`), Content-Security-Policy
- **Security**: Nginx API rate limiting (`limit_req_zone`, burst=20)
- **Security**: Controller push communication verifies TLS certificates against CA when available (graceful fallback for pre-CA installs)
- **Security**: Controller listener enforces TLS 1.2+ with strong cipher suite; timing-safe API key comparison via `hmac.compare_digest`
- **Security**: SMTP header injection prevention — CRLF stripped from From/To/Subject
- **Security**: `mysqldump` password passed via `MYSQL_PWD` env var instead of `-p` flag (not visible in `ps`)
- **Security**: Admin password during install passed via env vars instead of CLI args
- **Security**: LIMIT/OFFSET values bound as prepared statement parameters
- **Security**: Backup download `Content-Disposition` filename sanitized
- **Security**: Report type validated against whitelist
- **Security**: Session cookie `secure` flag respects `X-Forwarded-Proto` header
- **Security**: `mktemp` patterns use 6+ random characters (`XXXXXX`) across all scripts
- **Security**: Update script (`pidoors-update.sh`) owned by root, not pidoors user
- **Security**: Client IP logged in all push command reports
- **Feature**: `/api/certs/sign` endpoint — controllers request CA-signed TLS certificates during install/update
- **Feature**: `RequireAdmin` route guard in React SPA — non-admin users redirected from admin pages
- **Feature**: CSRF 403 auto-retry — stale tokens recovered transparently with one retry
- **Feature**: `password_needs_rehash` support — bcrypt cost upgrades on login

### Version 3.1.0 (March 2026)
- **Feature**: Server-initiated status polling — server pings all push-enabled controllers on every dashboard/doors page load for instant online/offline detection
- **Feature**: Parallel `curl_multi` polling with configurable `status_check_timeout` setting (default 2s)
- **Feature**: Single door view and ping endpoint now write full status fields (locked, held_open, version, door_open) to database
- **Change**: Controller heartbeat interval increased from 60s to 300s — heartbeat is now a safety net for cache sync, update checks, and auto-registration; status reporting handled by server-initiated pings
- **Fix**: Settings whitelist missing `default_listen_port`, `push_timeout`, `push_fallback_poll_interval` — push settings couldn't be saved from the UI
- **Fix**: Footer version showed "unknown" after login — login endpoint was missing `version` field in response
- **Database**: Added `status_check_timeout` setting

### Version 3.0.5 (March 2026)
- **Fix**: Card edit/delete returned "Card not found" — `card_id` is a hex string (Wiegand) but API was casting to `(int)`, breaking all lookups
- **Fix**: Card creation via API now sets `card_id` field (generates random hex if not provided), matching legacy PHP behavior
- **Fix**: `card_id` typed as string throughout frontend (was incorrectly `number`)

### Version 3.0.4 (March 2026)
- **Feature**: Add card setup from access logs — denied unknown cards show "Set Up Card" button linking to pre-filled edit form
- **Fix**: Door unlock/lock/hold status now updates instantly on dashboard after push command (was waiting up to 60s for controller poll)
- **Fix**: Gitignore release tarballs

### Version 3.0.0 (March 2026)
- **React SPA**: Complete frontend rewrite — legacy PHP pages replaced with a modern React + TypeScript + TailwindCSS single-page application
- **REST API**: New `api.php` unified router handles all SPA backend requests with full CSRF, auth, and input validation
- **Nginx rewrite**: Production config now serves React SPA from `/var/www/pidoors-ui` with `/api/*` routed to PHP-FPM
- **Install script**: Fresh installs now build the React SPA (Node.js + npm) and deploy it alongside the PHP API
- **Server update script**: Updates rebuild the React SPA automatically; auto-upgrades legacy nginx config to SPA layout
- **Feature**: Push-based controller communication — server sends commands directly to controllers via HTTPS for instant response (<100ms vs 3s polling)
- **Feature**: Controller HTTPS listener on port 8443 with Bearer token auth and self-signed TLS
- **Feature**: Automatic fallback to database polling if push is unavailable (mixed fleet support)
- **Feature**: Live controller ping from UI — on-demand status check with version, uptime, and connection info
- **Fix**: API route parsing now supports both `?route=` (Docker) and `PATH_INFO` (production nginx)
- **Fix**: Controller heartbeat now reports `api_key` to database — push self-heals if DB is restored
- **Fix**: Recurring holidays now cached for offline enforcement
- **Fix**: Door PUT endpoint validates `reader_type` against whitelist
- **Fix**: Profile API returns properly typed integer fields
- **Fix**: Stale `pidoors/database_migration.sql` synced — controller updates now get push columns
- **Fix**: Controller update script unbound variable crash on self-update re-exec path
- **Fix**: Install script React build errors now reported instead of silently swallowed
- **Cleanup**: Removed stale `pidoors/install.sh` duplicate (had eval injection bugs)

### Version 2.17.3 (March 2026)
- **Fix**: Dashboard AJAX refresh reverted action buttons back to padlock icons every 2 seconds

### Version 2.17.2 (March 2026)
- **UI**: Replaced padlock icon buttons with clear text labels — "Unlock", "Hold", "Unhold"
- **UI**: Action buttons use outlined style to distinguish from solid status badges

### Version 2.17.1 (March 2026)
- **Fix**: Controller update now runs database migrations automatically — v2.17.0 controller update broke doors because new code referenced `held_open`/`hold_requested` columns that didn't exist yet
- **Fix**: Root `database_migration.sql` was missing the `held_open`/`hold_requested` column migrations added in v2.17.0

### Version 2.17.0 (March 2026)
- **Feature**: Held-open door mode — master cards can triple-scan to hold a door permanently open, single scan to release
- **Feature**: Only master cards can toggle held-open state; regular cards never trigger it
- **Feature**: Dashboard and Doors page show orange "Held Open" badge when a door is held open
- **Feature**: Admin UI buttons to remotely hold open or release a door (via `hold_requested` column)
- **Feature**: Controller reports `held_open` state to database via heartbeat and command poll loop
- **Database**: Added `held_open` and `hold_requested` columns to `doors` table

### Version 2.6.4 (March 2026)
- **Fix**: "Failed to update door" self-heals — editdoor.php auto-adds missing columns and retries if the UPDATE fails due to missing schema
- **Fix**: Stale "updating" status on controllers — heartbeat now clears "updating" flag when controller is back online
- **Fix**: Auto-migration on version change — visiting the Updates page after a server update now auto-runs `database_migration.sql`
- **Fix**: Controller update script always restarts service — cleanup trap ensures pidoors service comes back online even if the update fails mid-way

### Version 2.6.3 (March 2026)
- **Fix**: "Failed to update door" when editing door settings — missing `unlock_requested` and `poll_interval` column migrations in `database_migration.sql` caused the UPDATE query in editdoor.php to fail on upgraded installs
- **Fix**: Door unlock duration stuck at 5 seconds despite UI changes — same root cause; the failed UPDATE prevented any door settings from being saved
- **Fix**: Remote unlock from web UI broken on upgraded installs — `unlock_requested` column didn't exist
- **Fix**: Controller command poll thread errors on upgraded installs — `poll_interval` column didn't exist, causing repeated query failures
- **Auto-migration**: Server update now automatically runs `database_migration.sql` after copying files, so database schema stays in sync with code

### Version 2.6.2 (March 2026)
- **Email notifications**: Working SMTP email system — door offline/online alerts, repeated access denial alerts, daily summary reports
- **SMTP settings UI**: Configure SMTP server, port, username, password, and from address directly in Settings with a "Send Test Email" button
- **Custom SMTP sender**: Lightweight ~130-line SMTP function supporting AUTH LOGIN, STARTTLS (587), implicit SSL (465), and plain relay (25) — no PHPMailer dependency
- **Notification cron**: `cron/notify.php` runs every 5 minutes to detect events and send notifications with deduplication via `notification_log` table
- **`mail()` fallback**: Falls back to PHP `mail()` when no SMTP server is configured (for systems with postfix/sendmail)
- **Self-healing TLS**: Controller database connections now auto-recover from certificate mismatches — on SSL error, fetches fresh `ca.pem` from server, falls back to plain if server has no TLS
- **TLS fallback in update script**: `pidoors-update.sh` no longer fails silently when TLS cert is stale — tries TLS first, falls back to plain
- **Install verification**: `install.sh` DB verification now reports whether connection is TLS or plain

### Version 2.6.1 (March 2026)
- **Fix**: Fresh install login always fails — `<<<` here-string added trailing newline to password before hashing, so `password_verify()` never matched
- **Fix**: Controller `CERTIFICATE_VERIFY_FAILED` — pymysql auto-negotiated SSL with server's self-signed cert; now explicitly disables SSL when no CA cert is present
- **Fix**: Controller `NO_CERTIFICATE_OR_CRL_FOUND` — empty/invalid ca.pem files are now detected and skipped instead of passed to SSL

### Version 2.6.0 (March 2026)
- **TLS Database Encryption**: All controller-to-server database connections can now be encrypted with TLS
- **Automatic cert generation**: Server install generates a self-signed CA and server certificate for MariaDB TLS
- **Automatic cert distribution**: Controller install downloads the CA cert from the server automatically
- **Centralized DB helper**: All 6 duplicate `pymysql.connect()` calls consolidated into `get_db_connection()` with optional TLS
- **PHP TLS support**: Web UI database connections support optional TLS via `sql_ssl_ca` config key
- **Backward compatible**: Existing installs without TLS certs continue to work unencrypted

### Version 2.5.20 (March 2026)
- **Remote Door Unlock**: Unlock doors remotely from the web UI with a new Unlock button on door cards
- **Command Poll Loop**: Lightweight fast-polling thread on controllers checks for remote commands every few seconds (configurable per-door)
- **Real-time lock state**: Controller now tracks and reports actual lock/unlock state instead of static config value
- **Fix**: Controller update trigger loop — `update_requested` flag was never cleared, causing updates to re-trigger every 60 seconds

### Version 2.5.19 (March 2026)
- **Fix**: Controller self-update script failed because `find` matched the temp directory itself instead of the extracted archive directory, causing "missing pidoors/ directory" error and update loop

### Version 2.5.18 (March 2026)
- **Upgrade Bootstrap** from 5.0.0-beta1 to 5.3.8 (bundled files and install.sh CDN URLs)
- Fix audit modal compatibility with older Bootstrap 5 versions

### Version 2.5.17 (March 2026)
- **Audit log detail modals**: Clicking any audit log row opens a modal showing full event details (event type, timestamp, user, IP, user agent, details)
- **Rich settings change logging**: Settings changes now log exactly which keys changed with old/new values
- **Card audit logging**: Card create, edit, and delete operations now log to the audit trail with full details
- **Enriched audit details**: Login, logout, profile updates, and user edits now include contextual information (usernames, changed fields, old/new values)
- Added missing event type labels in audit log (user_modified, profile_update, cards_export, cards_imported, report_export, server_update)
- **Example data**: Docker init-db.sql and install.sh now support optional example data (cards, doors, users, holidays)
- New `exportcards.php` for CSV card exports

### Version 2.5.16 (March 2026)
- **Fix**: Deleting a door (or any redirect action) caused white page — `header()` calls failed silently because HTML was already sent by `header.php`
- **Fix**: CSV exports (access logs, reports) were completely broken — HTML from `header.php` was prepended to CSV output
- Added `ob_start()` output buffering to `header.php`, fixing redirect and CSV issues across all 14 affected pages

### Version 2.5.15 (March 2026)
- **Stale file cleanup**: All three updaters (server-update.sh, web UI update, controller update) now remove orphaned files that were deleted from the project
- Server shell updater uses `rsync --delete` (with `cp` fallback cleanup)
- Web UI updater removes orphaned files after copying, reports count in success message
- Controller updater cleans orphaned files from `formats/` and `readers/` directories
- Protected files (`config.php`, `VERSION`, dotfiles, `conf/`, `cache/`) are never deleted

### Version 2.5.14 (March 2026)
- **Fix**: Export logs crash — `rowCount()` called on array instead of PDOStatement
- **Fix**: Cache structure mismatch — `load_cache()` now correctly handles flat cache format with backwards compatibility
- **Fix**: `try_database_lookup()` crashes with `NameError` when pymysql unavailable — added `MYSQL_AVAILABLE` guard
- **Fix**: Door unlock blocks GPIO callback thread — unlock now runs in a separate daemon thread
- **Security**: `install.sh` and `server-update.sh` use `MYSQL_PWD` env var instead of `-p` flag to avoid password exposure in process list
- **Security**: Admin password hashing in `install.sh` now uses stdin instead of command-line interpolation
- **Security**: Update temp directory created with `0700` permissions instead of `0755`, with mkdir failure check
- README version badge now tracks current version

### Version 2.5.4 (March 2026)
- **Hardened client updater**: Pre-flight checks verify archive contents before stopping the service; aborts cleanly if anything is wrong
- **Detailed error messages**: Update failures now include actionable details (e.g. "Could not reach GitHub API") visible on the Doors, Edit Door, and Updates pages
- **Service safety**: Controller service always restarts after a failed update so the door isn't left dead
- **Database**: Widened `update_status` column to hold detail messages

### Version 2.5.3 (March 2026)
- **Pre-flight writability check**: Server updater verifies all target files are writable before copying anything; aborts with a clear error listing problem files if not
- **Atomic version update**: VERSION file and database only updated after all file copies succeed, preventing version mismatch on partial updates

### Version 2.5.2 (March 2026)
- **Fix**: Server updater now validates file copy results — previously reported success even when copies silently failed

### Version 2.5.1 (March 2026)
- **Fix**: "Add Door" POST handler fired after failed "Request Update" POST on doors page
- **Fix**: Server updater PharData extraction wrapped in try/catch to handle corrupt archives gracefully
- **Fix**: Client update script passes DB credentials via environment variables to avoid shell injection with special characters in passwords

### Version 2.5.0 (March 2026)
- **Version tracking**: Server and controller versions displayed throughout the web UI
- **Server self-update**: Check for updates via GitHub API, one-click update from the Updates page (preserves config.php)
- **Controller self-update**: Set a target version in Settings, click "Request Update" on the Doors page; controllers download and install the update on the next heartbeat
- **Updates page**: Centralized view of server version, GitHub latest, and all controller versions with status badges
- **Settings**: Target controller version setting with outdated warnings on door cards
- **Footer**: Version number displayed on all pages
- **Install script**: Copies VERSION file and update script, adds sudoers entry for controller updates

### Version 2.4.2 (February 2026)
- **Fix**: Install script "dubious ownership" crash — `safe.directory` is now set before any git operations (#3)
- **Fix**: Install script recovery — re-running after a partial failure no longer crashes when the git remote is missing

### Version 2.4.1 (February 2026)
- **Fix**: Holidays page crash — column name mismatch (`no_access` vs `access_denied`)
- **Fix**: Groups page crash — missing `doors` column on `access_groups` table
- **Fix**: Log export crash — `rowCount()` called on array instead of `count()`
- **Fix**: `config.php.example` missing `site_name` and `notification_from` keys
- **Accuracy**: Removed undocumented PIN code and anti-passback feature claims
- **Accuracy**: Marked OSDP and NFC reader support as planned (modules exist, not yet integrated)
- **Cleanup**: Removed dead code (`addcard.php`, `adddoor.php`, legacy backup templates)
- Database migration adds `doors` column to `access_groups` for existing installs

### Version 2.4.0 (February 2026)
- **Master card web UI** — toggle any card as a master card from the cards list, edit form, or add modal
- **Master badge** on cards list shows which cards have master access
- **CSV import** supports optional `master` column (1/yes/true)
- **Master card warning** — edit/add forms display a warning about unrestricted access
- **Fix**: GPIO edge detection failure on Debian 12+ (Bookworm/Trixie) — uses `rpi-lgpio` instead of broken `RPi.GPIO`
- **Fix**: lgpio notification file creation fails — service now uses `RuntimeDirectory` for writable working directory
- **Install script** clones from GitHub and initializes git repo for easy `git pull` updates
- **Install script** auto-detects Debian version and installs correct GPIO library
- Card deletion now also cleans up `master_cards` table

### Version 2.3.0 (February 2026)
- **Login by username or email** — was email-only before
- **Extended user profiles** — first/last name, phone, department, employee ID, company, job title, notes
- **Extended cardholder fields** — email, phone, department, employee ID, company, title, notes on cards
- **Door name normalization** — web UI normalizes names to lowercase with underscores to match client convention
- **CSV import updated** — supports all new optional cardholder columns
- **Database migration** — `database_migration.sql` now handles both `users` and `access` databases
- Added UNIQUE index on `user_name` for safe username-based login

### Version 2.2.6 (February 2026)
- All add/edit forms converted to Bootstrap modals (cards, doors, groups, schedules, holidays, users)
- Door auto-registration via client heartbeat
- Comprehensive guided install script with validation and verification

### Version 2.2.5 (February 2026)
- **Fix**: Sidebar/content CSS overlap on responsive layouts
- **Fix**: DataTables column count error on logs page
- Rewrote sidebar/content layout for proper scaling

### Version 2.2.4 (February 2026)
- **Fix**: Web interface has no styling — vendor CSS/JS files (Bootstrap, jQuery, DataTables, Chart.js) were 0 bytes in the repository
- Populated all vendor library files in the repo so they work out of the box
- Installer now downloads fresh vendor assets during install as a safety net

### Version 2.2.3 (February 2026)
- **Fix**: Python pip install fails on Raspberry Pi OS Bookworm (PEP 668)
- **Fix**: Config password never set during install (sed matched wrong key)
- **Fix**: Web interface URL not set to server IP during install
- Uses Python virtual environment at `/opt/pidoors/venv/` for door controller
- Service file updated to use venv Python

### Version 2.2.2 (February 2026)
- **Fix**: Fresh install fails with `Table 'access.cards' doesn't exist` (Issue #2)
- **Fix**: Missing `users` table schema — users database was created empty
- **Fix**: Column name mismatches in edituser.php and profile.php (`is_admin`/`is_active`/`email`)
- **Fix**: Config key mismatches — PHP referenced nested keys that don't exist
- **Security**: Removed hardcoded MD5 password salt from source code
- **Security**: Renamed tracked config.php to config.php.example
- **Docs**: Expanded README with complete manual installation instructions

### Version 2.2.1 (January 2026)
- **Security fix**: Zone matching vulnerability that could allow unauthorized access
- **Database migration**: Door format changed from space-separated to comma-separated
- **Cross-module consistency**: Python and PHP now use identical door format
- **Audit**: Complete codebase security audit (see AUDIT_LOG_v5.md)

### Version 2.2 (January 2026)
- **Multi-reader support**: OSDP, NFC PN532, NFC MFRC522
- **Expanded Wiegand formats**: 26, 32, 34, 35, 36, 37, 48-bit with auto-detection
- **Persistent master cards**: Never expire locally for emergency access
- **Reader abstraction layer**: Modular architecture for easy extension
- **Format registry**: Custom Wiegand format definitions via JSON
- Web UI updates for reader type selection

### Version 2.1 (January 2026)
- Migrated from Apache to Nginx with PHP-FPM
- Fixed config.json credential exposure
- Updated deprecated PHP functions
- Improved documentation

### Version 2.0 (January 2026)
- Complete security overhaul
- 24-hour offline caching
- Modern Bootstrap 5 interface
- Time-based schedules and groups
- Email notifications
- Automated backups
- Multi-format Wiegand support

### Version 1.0 (Original)
- Basic Wiegand 26-bit support
- Simple web interface
- MySQL database

---

## License

This project is open source and available for free use, modification, and distribution.

---

## Support

- **Documentation**: [Installation Guide](pidoors/INSTALLATION_GUIDE.md)
- **Bug Reports**: [GitHub Issues](https://github.com/sybethiesant/pidoors/issues)
- **Feature Requests**: [GitHub Issues](https://github.com/sybethiesant/pidoors/issues)

---

**Built for the open source community**
