# PiDoors - Open Source Access Control System

![License](https://img.shields.io/badge/license-Open%20Source-blue)
![Platform](https://img.shields.io/badge/platform-Raspberry%20Pi-red)
![Version](https://img.shields.io/badge/version-2.2.1-green)
![Status](https://img.shields.io/badge/status-Production%20Ready-brightgreen)

**Professional-grade physical access control powered by Raspberry Pi**

---

## Overview

PiDoors is a complete, industrial-grade access control system built on Raspberry Pi hardware. It provides enterprise-level security features while remaining affordable and open source. Designed for small businesses, makerspaces, office buildings, or anyone needing professional access control.

**Key Benefits:**
- **Cost-Effective**: 10x cheaper than commercial systems (~$100-150 per door vs $500-2000)
- **Secure**: Enterprise-grade encryption, bcrypt passwords, SQL injection protection, CSRF tokens
- **Open Source**: Full control over your security system
- **Modern Interface**: Responsive Bootstrap 5 web dashboard
- **Offline Capable**: 24-hour local caching keeps doors working during network outages
- **Extensible**: Easy to customize and integrate

---

## Features

### Access Control
- **Multi-format Wiegand**: 26, 32, 34, 35, 36, 37, 48-bit with auto-detection
- **OSDP Readers**: RS-485 encrypted readers with AES-128 Secure Channel
- **NFC/RFID**: PN532 (I2C/SPI) and MFRC522 (SPI) support
- Time-based access schedules
- Access groups and permissions
- Holiday calendar support
- Card validity date ranges
- PIN code authentication
- Anti-passback protection
- Persistent master cards (never expire for emergency access)

### Management
- Modern web interface
- Real-time dashboard with analytics
- Multi-user administration
- CSV bulk import/export
- Comprehensive reporting
- Email notifications
- Complete audit trail
- Remote door control

### Reliability
- 24-hour offline operation
- Automatic failover
- Health monitoring
- Auto-reconnection
- Automated backups
- Service redundancy

### Security
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
2. Installs dependencies (Nginx, PHP-FPM, MariaDB, Python libraries)
3. Creates the `users` and `access` databases with all required tables
4. Imports the full database schema (base tables + migration extensions)
5. Deploys the web interface to `/var/www/pidoors/`
6. Configures Nginx with security headers
7. Prompts you to create an admin account (email + password)
8. Sets up log rotation and backup scripts

#### After installation

1. Open `http://your-pi-ip/` in a browser
2. Log in with the admin email and password you set during install
3. Navigate to **Doors** to register your door controllers
4. Navigate to **Cards** to add access cards
5. Set up **Schedules** and **Access Groups** as needed

### Manual Installation

If you prefer to install manually or need to troubleshoot:

#### 1. Install system packages
```bash
sudo apt-get update && sudo apt-get install -y \
  nginx php-fpm php-mysql php-cli php-mbstring mariadb-server \
  python3 python3-pip git
```

#### 2. Create databases
```bash
sudo mysql_secure_installation

sudo mysql -u root -p <<'SQL'
CREATE DATABASE IF NOT EXISTS users;
CREATE DATABASE IF NOT EXISTS access;
CREATE USER IF NOT EXISTS 'pidoors'@'localhost' IDENTIFIED BY 'YOUR_PASSWORD';
GRANT ALL PRIVILEGES ON users.* TO 'pidoors'@'localhost';
GRANT ALL PRIVILEGES ON access.* TO 'pidoors'@'localhost';
FLUSH PRIVILEGES;
SQL
```

#### 3. Import schemas
```bash
# Import the access database schema (creates all tables including base tables)
sudo mysql -u root -p access < database_migration.sql

# Create the users table (in the users database)
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
```

#### 4. Create your admin user
```bash
# Generate a bcrypt hash for your password
HASH=$(php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_BCRYPT);")

sudo mysql -u root -p users -e \
  "INSERT INTO users (user_name, user_email, user_pass, admin, active) \
   VALUES ('Admin', 'admin@example.com', '$HASH', 1, 1);"
```

#### 5. Deploy the web interface
```bash
sudo mkdir -p /var/www/pidoors
sudo cp -r pidoorserv/* /var/www/pidoors/
sudo cp /var/www/pidoors/includes/config.php.example /var/www/pidoors/includes/config.php
sudo nano /var/www/pidoors/includes/config.php   # Set your database password
sudo chown -R www-data:www-data /var/www/pidoors
sudo chmod 640 /var/www/pidoors/includes/config.php
```

#### 6. Configure Nginx
```bash
sudo cp nginx/pidoors.conf /etc/nginx/sites-available/pidoors
sudo ln -sf /etc/nginx/sites-available/pidoors /etc/nginx/sites-enabled/pidoors
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

See the [Installation Guide](INSTALLATION_GUIDE.md) for additional details.

---

## System Architecture

```
                        WEB INTERFACE
     Dashboard | Cards | Doors | Logs | Reports | Settings
                            |
            +---------------+---------------+
            |                               |
     SERVER RASPBERRY PI              DOOR CONTROLLERS
    +------------------+             +----------------+
    |  Nginx + PHP-FPM |             |  Door Pi #1    |
    |  MariaDB         |<--Network-->|  24hr Cache    |
    |  Backups & Logs  |             |  Card Reader   |
    +------------------+             |  Electric Lock |
                                     +----------------+
                                            |
                                     +----------------+
                                     |  Door Pi #N    |
                                     |  24hr Cache    |
                                     |  Card Reader   |
                                     |  Electric Lock |
                                     +----------------+
```

**One server Pi** runs the web interface and database.
**N door Pis** control individual access points with 24-hour local caching.

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
    'url' => 'http://your-pi-ip',
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

3. Set your values:
```json
{
    "connex1": {
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

Full wiring diagrams available in [Installation Guide](INSTALLATION_GUIDE.md#wiring).

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
card_id,user_id,firstname,lastname
12345678,EMP001,John,Smith
87654321,EMP002,Jane,Doe
```

Upload at **Cards** > **Import Cards**

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
```bash
cd ~/pidoors
git pull
sudo cp -r pidoorserv/* /var/www/pidoors/
sudo systemctl restart nginx
sudo systemctl restart pidoors  # On door controllers
```

### Database Migrations

When upgrading, check if database migrations are required:

**v2.2.1 Migration (Required if upgrading from v2.2 or earlier):**

This migration converts door assignments from space-separated to comma-separated format for improved security.

```bash
# Preview changes (safe - no modifications)
python3 migrations/migrate_doors_format.py --dry-run

# Apply changes (will prompt for confirmation)
python3 migrations/migrate_doors_format.py
```

Or via SQL:
```bash
# Backup first!
mysqldump -u pidoors -p access > backup_$(date +%Y%m%d).sql

# Run migration
mysql -u pidoors -p access < migrations/migrate_doors_format.sql
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
- Bcrypt password hashing with automatic MD5 upgrade
- PDO prepared statements (no SQL injection)
- CSRF token protection on all forms
- Secure session handling with timeout
- Comprehensive input validation
- Complete audit logging
- Login rate limiting (5 attempts, 15-minute lockout)

### Reporting Vulnerabilities
Please report security issues to the repository owner directly, not via public issues.

See [SECURITY_NOTICE.md](SECURITY_NOTICE.md) for security best practices.

---

## File Structure

```
pidoors/
├── pidoorserv/           # Web server application
│   ├── includes/         # Core PHP includes
│   │   ├── config.php.example
│   │   ├── security.php
│   │   └── header.php
│   ├── users/            # User management
│   ├── database/         # Database connection
│   ├── css/              # Stylesheets
│   └── js/               # JavaScript
├── pidoors/              # Door controller
│   ├── pidoors.py        # Main daemon
│   ├── pidoors.service   # Systemd service
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
├── pidoorspcb/           # PCB design files (KiCAD)
├── install.sh            # Installation script
├── database_migration.sql
└── README.md
```

---

## Documentation

| Document | Description |
|----------|-------------|
| [Installation Guide](INSTALLATION_GUIDE.md) | Complete beginner-friendly setup |
| [Security Notice](SECURITY_NOTICE.md) | Security best practices |
| [Security Audit](SECURITY_AUDIT_REPORT.md) | Full security audit report |
| [Project Log](PROJECT_LOG.md) | Development history |

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

**Current Version: 2.2.1** - Production Ready

**Future Enhancements** (community contributions welcome):
- Mobile app (iOS/Android)
- Bluetooth Low Energy (BLE) readers
- Biometric integration (fingerprint, face)
- Cloud backup integration
- Multi-site management dashboard

---

## Changelog

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

- **Documentation**: [Installation Guide](INSTALLATION_GUIDE.md)
- **Bug Reports**: [GitHub Issues](https://github.com/sybethiesant/pidoors/issues)
- **Feature Requests**: [GitHub Issues](https://github.com/sybethiesant/pidoors/issues)

---

**Built for the open source community**
