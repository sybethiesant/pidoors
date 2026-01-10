# PiDoors - Open Source Access Control System

![License](https://img.shields.io/badge/license-Open%20Source-blue)
![Platform](https://img.shields.io/badge/platform-Raspberry%20Pi-red)
![Version](https://img.shields.io/badge/version-2.2-green)
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

### Automated Installation (Recommended)

```bash
# Download PiDoors
git clone https://github.com/yourusername/pidoors.git
cd pidoors

# Run installer
sudo ./install.sh
```

The installer will guide you through:
- Server or door controller setup
- Database configuration
- Web interface installation
- Service activation

Access the web interface at `http://your-pi-ip/`

### Manual Installation

See the [Installation Guide](INSTALLATION_GUIDE.md) for detailed step-by-step instructions.

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
    'db' => [
        'host' => 'localhost',
        'username' => 'pidoors',
        'password' => 'your_secure_password',
    ],
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
| IN           | GPIO 17      |
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
 GPIO17 (11) (12) GPIO18    <- Relay (GPIO17)
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

**Current Version: 2.2** - Production Ready

**Future Enhancements** (community contributions welcome):
- Mobile app (iOS/Android)
- Bluetooth Low Energy (BLE) readers
- Biometric integration (fingerprint, face)
- Cloud backup integration
- Multi-site management dashboard

---

## Changelog

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
- **Bug Reports**: [GitHub Issues](https://github.com/yourusername/pidoors/issues)
- **Feature Requests**: [GitHub Issues](https://github.com/yourusername/pidoors/issues)

---

**Built for the open source community**
