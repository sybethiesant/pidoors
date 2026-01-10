# 🚪 PiDoors - Open Source Access Control System

<div align="center">

![License](https://img.shields.io/badge/license-Open%20Source-blue)
![Platform](https://img.shields.io/badge/platform-Raspberry%20Pi-red)
![Version](https://img.shields.io/badge/version-2.0-green)
![Status](https://img.shields.io/badge/status-Production%20Ready-brightgreen)

**Professional-grade access control powered by Raspberry Pi**

[Features](#features) • [Quick Start](#quick-start) • [Documentation](#documentation) • [Screenshots](#screenshots) • [Contributing](#contributing)

</div>

---

## 📋 Overview

PiDoors is a complete, industrial-grade access control system built on Raspberry Pi hardware. It provides enterprise-level security features while remaining affordable and open source. Perfect for small businesses, makerspaces, office buildings, or anyone needing professional access control.

### Why PiDoors?

- 💰 **Cost-Effective**: 10x cheaper than commercial systems
- 🔒 **Secure**: Enterprise-grade encryption and audit logging
- 🌐 **Open Source**: Full control over your security system
- 📱 **Modern Interface**: Beautiful, responsive web dashboard
- 🔌 **Offline Capable**: 24-hour local caching keeps doors working during network outages
- 🛠️ **Extensible**: Easy to customize and integrate

---

## ✨ Features

<table>
<tr>
<td width="50%">

### 🔐 Access Control
- ✅ Wiegand 26/34/37-bit card readers
- ✅ Time-based access schedules
- ✅ Access groups & permissions
- ✅ Holiday calendar support
- ✅ Card validity date ranges
- ✅ PIN code authentication
- ✅ Anti-passback protection
- ✅ Master card system

</td>
<td width="50%">

### 🖥️ Management
- ✅ Modern web interface
- ✅ Real-time dashboard & analytics
- ✅ Multi-user administration
- ✅ CSV bulk import/export
- ✅ Comprehensive reporting
- ✅ Email notifications
- ✅ Complete audit trail
- ✅ Remote door control

</td>
</tr>
<tr>
<td>

### 🏗️ Reliability
- ✅ 24-hour offline operation
- ✅ Automatic failover
- ✅ Health monitoring
- ✅ Auto-reconnection
- ✅ Automated backups
- ✅ Service redundancy

</td>
<td>

### 🔒 Security
- ✅ Bcrypt password hashing
- ✅ SQL injection protection
- ✅ CSRF protection
- ✅ Session security
- ✅ Input validation
- ✅ Security event logging

</td>
</tr>
</table>

---

## 🚀 Quick Start

### Option 1: Automated Installation (Recommended)

```bash
# Download PiDoors
git clone https://github.com/sybethiesant/pidoors.git
cd pidoors

# Run installer
sudo ./install.sh
```

The installer will guide you through:
- Server or door controller setup
- Database configuration
- Web interface installation
- Service activation

**That's it!** Access the web interface at `http://your-pi-ip/`

### Option 2: Step-by-Step Guide

New to Raspberry Pi? Check out our [**Complete Installation Guide**](INSTALLATION_GUIDE.md) with detailed instructions for beginners.

---

## 📸 Screenshots

<div align="center">

### Dashboard
![Dashboard](https://via.placeholder.com/800x400/0d6efd/ffffff?text=Real-time+Dashboard+with+Statistics+%26+Charts)

### Access Logs
![Logs](https://via.placeholder.com/800x400/28a745/ffffff?text=Comprehensive+Access+Logging+%26+Filtering)

### Card Management
![Cards](https://via.placeholder.com/800x400/17a2b8/ffffff?text=Easy+Card+%26+User+Management)

*Screenshots show the modern Bootstrap 5 interface*

</div>

---

## 🛠️ System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     WEB INTERFACE                           │
│  Dashboard │ Cards │ Doors │ Logs │ Reports │ Settings     │
└────────────────────────┬────────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────────┐
│                  SERVER RASPBERRY PI                        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
│  │  Apache +    │  │   MySQL      │  │   Backups    │     │
│  │     PHP      │  │  Database    │  │   & Logs     │     │
│  └──────────────┘  └──────────────┘  └──────────────┘     │
└────────────────────────┬────────────────────────────────────┘
                         │ Network
        ┌────────────────┼────────────────┐
        │                │                │
┌───────▼──────┐  ┌──────▼──────┐  ┌─────▼───────┐
│ DOOR PI #1   │  │ DOOR PI #2  │  │ DOOR PI #N  │
│              │  │             │  │             │
│ ┌──────────┐ │  │ ┌─────────┐ │  │ ┌─────────┐ │
│ │ 24hr     │ │  │ │ 24hr    │ │  │ │ 24hr    │ │
│ │ Cache    │ │  │ │ Cache   │ │  │ │ Cache   │ │
│ └──────────┘ │  │ └─────────┘ │  │ └─────────┘ │
│              │  │             │  │             │
│ Card Reader  │  │ Card Reader │  │ Card Reader │
│ Electric Lock│  │ Door Sensor │  │ REX Button  │
└──────────────┘  └─────────────┘  └─────────────┘
```

**One server Pi** runs the web interface and database
**N door Pis** control individual access points with 24-hour local caching

---

## 📚 Documentation

| Document | Description |
|----------|-------------|
| [**Installation Guide**](INSTALLATION_GUIDE.md) | Complete beginner-friendly setup instructions |
| [**Configuration**](#configuration) | How to configure the system |
| [**Hardware Setup**](#hardware-requirements) | Wiring diagrams and hardware specs |
| [**Troubleshooting**](INSTALLATION_GUIDE.md#troubleshooting) | Common issues and solutions |
| [**Security Guide**](SECURITY_NOTICE.md) | Security best practices |
| [**Project Log**](PROJECT_LOG.md) | Development history and completed features |

---

## 💻 Hardware Requirements

### Server Pi (1 per system)
- Raspberry Pi 3B+ or newer (4GB RAM recommended)
- 16GB+ microSD card
- Network connection (Ethernet recommended)
- Power supply

### Door Controller Pi (1 per door)
- Raspberry Pi Zero W or newer
- 8GB+ microSD card
- Wiegand card reader (26/34/37-bit)
- 12V electric strike or magnetic lock
- Relay module
- Optional: Door sensor, REX button
- Custom PCB available (see `pidoorspcb/`)

**Total cost per door: ~$100-150** (vs $500-2000 for commercial systems)

---

## ⚙️ Configuration

### First-Time Setup

1. **Copy the configuration template:**
```bash
cp pidoorserv/includes/config.php.example pidoorserv/includes/config.php
```

2. **Edit configuration:**
```bash
nano pidoorserv/includes/config.php
```

3. **Set your values:**
```php
return [
    'sqlpass' => 'your_secure_password',  // Database password
    'url' => 'http://your-pi-ip',        // Your server IP
    // ... other settings
];
```

4. **Secure the file:**
```bash
chmod 600 pidoorserv/includes/config.php
```

⚠️ **Never commit `config.php` to git!** It's already in `.gitignore`.

### Default Login

After installation, log in with:
- **Email:** Your configured admin email
- **Password:** Your configured admin password

**Change these immediately after first login!**

---

## 🔌 Wiring Guide

### Wiegand Reader to Raspberry Pi

```
Wiegand Reader          Raspberry Pi
──────────────          ────────────
DATA0 (Green)    ───►   GPIO 23
DATA1 (White)    ───►   GPIO 24
GND   (Black)    ───►   GND (Pin 6)
5V+   (Red)      ───►   5V  (Pin 2)
```

### Lock Control

```
Relay Module            Raspberry Pi
────────────            ────────────
IN               ───►   GPIO 17
VCC              ───►   5V (Pin 4)
GND              ───►   GND (Pin 14)
```

Connect lock to relay NO/COM terminals with 12V power supply.

**Full wiring diagrams in [Installation Guide](INSTALLATION_GUIDE.md#step-33-wire-the-wiegand-reader)**

---

## 📊 Usage Examples

### Adding a Card

**Via Web Interface:**
1. Navigate to **Cards** → **Add Card**
2. Enter card details
3. Click **Add Card**

**Via CSV Import:**
```csv
card_id,user_id,firstname,lastname
12345678,EMP001,John,Smith
87654321,EMP002,Jane,Doe
```

Upload at **Cards** → **Import Cards**

### Creating Access Schedules

```
Schedule: "Business Hours"
─────────────────────────
Monday    08:00 - 17:00
Tuesday   08:00 - 17:00
Wednesday 08:00 - 17:00
Thursday  08:00 - 17:00
Friday    08:00 - 17:00
Saturday  Closed
Sunday    Closed
```

Assign to cards or doors for time-based access control.

### Monitoring Access

**Real-time Dashboard:**
- Total access events
- Granted vs denied
- Door status
- Recent activity

**Access Logs:**
- Filter by date, door, user
- Export to CSV
- Search functionality

**Email Alerts:**
- Failed access attempts
- Door offline notifications
- Security events

---

## 🔧 Maintenance

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
sudo systemctl restart pidoors
sudo systemctl restart apache2
```

### View Logs

```bash
# Door controller logs
sudo journalctl -u pidoors -f

# Web server logs
sudo tail -f /var/log/apache2/pidoors_error.log
```

---

## 🤝 Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

**Testing Required:**
- Test on actual Raspberry Pi hardware
- Verify database migrations work
- Check security implications
- Update documentation

---

## 🛡️ Security

### Reporting Vulnerabilities

Please report security issues to the repository owner directly, not via public issues.

### Security Features

- ✅ **Passwords**: Bcrypt hashing with automatic MD5 upgrade
- ✅ **SQL**: PDO prepared statements (no SQL injection)
- ✅ **Forms**: CSRF token protection
- ✅ **Sessions**: Secure handling with timeout
- ✅ **Input**: Comprehensive validation & sanitization
- ✅ **Audit**: Complete security event logging

See [SECURITY_NOTICE.md](SECURITY_NOTICE.md) for more details.

---

## 📝 License

This project is open source and available for free use, modification, and distribution.

---

## 🙏 Credits

**Original Concept:** sybethiesant

**Version 2.0 Upgrade:** Complete industrial overhaul with enhanced security, offline capability, and professional features.

---

## 📞 Support

- 📖 **Documentation:** [Installation Guide](INSTALLATION_GUIDE.md)
- 🐛 **Bug Reports:** [GitHub Issues](https://github.com/sybethiesant/pidoors/issues)
- 💡 **Feature Requests:** [GitHub Issues](https://github.com/sybethiesant/pidoors/issues)
- 📧 **Contact:** Via GitHub

---

## 🗺️ Roadmap

**Current Version: 2.0** ✅ Production Ready

**Future Enhancements** (community contributions welcome):
- 📱 Mobile app (iOS/Android)
- 🔵 NFC/Bluetooth support
- 👤 Biometric integration (fingerprint, face)
- ☁️ Cloud backup integration
- 🤖 AI-powered analytics
- 🌐 Multi-site management dashboard

---

## ⭐ Star History

If you find PiDoors useful, please give it a star! It helps others discover the project.

---

## 📋 Changelog

### Version 2.0 (January 2026)
- ✅ Complete security overhaul
- ✅ 24-hour offline caching
- ✅ Modern Bootstrap 5 interface
- ✅ Time-based schedules & groups
- ✅ Email notifications
- ✅ Automated backups
- ✅ Multi-format Wiegand support
- ✅ Comprehensive documentation

### Version 1.0 (Original)
- Basic Wiegand 26-bit support
- Simple web interface
- MySQL database
- Basic logging

---

<div align="center">

**Built with ❤️ for the open source community**

[⬆ Back to Top](#-pidoors---open-source-access-control-system)

</div>
