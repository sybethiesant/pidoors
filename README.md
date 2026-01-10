# PiDoors Access Control System

A professional-grade, Wiegand-based access control system built on Raspberry Pi. PiDoors provides industrial/retail-standard features including time-based access schedules, offline operation capability, comprehensive audit logging, and a modern web management interface.

## Features

### Core Access Control
- **Wiegand Protocol Support**: 26-bit, 34-bit, and 37-bit formats
- **Offline Operation**: 24-hour local caching for continued operation during server outages
- **Time-based Schedules**: Define access windows by day/time
- **Access Groups**: Organize users into groups with shared permissions
- **Holiday Support**: Restrict or allow access on specific dates
- **Card Validity Dates**: Set expiration dates for temporary access
- **PIN Code Support**: Optional secondary authentication

### Door Controller Features
- **Real-time Monitoring**: Door sensor and REX (Request to Exit) button support
- **Security Alerts**: Door held open, forced entry detection
- **Remote Control**: Lock/unlock doors from web interface
- **Health Monitoring**: Automatic heartbeat to server
- **Auto-reconnect**: Automatic database reconnection on connection loss
- **Master Cards**: Database-configurable master access cards

### Web Management Interface
- **Modern Dashboard**: Real-time statistics and access charts
- **Card Management**: Add, edit, delete, and import cards (CSV bulk upload)
- **Door Management**: Configure and monitor all door controllers
- **Access Logs**: Searchable logs with filtering and CSV export
- **Schedule Management**: Create and assign time-based access schedules
- **Group Management**: Organize cards into access groups
- **Holiday Calendar**: Manage holiday access restrictions
- **User Administration**: Multi-user support with role-based access
- **Audit Logging**: Complete security event tracking
- **System Settings**: Centralized configuration
- **Backup/Restore**: Database backup functionality

### Security Features
- **Bcrypt Password Hashing**: Industry-standard password security
- **CSRF Protection**: All forms protected against cross-site request forgery
- **SQL Injection Prevention**: PDO prepared statements throughout
- **Session Security**: Secure session handling with timeout and regeneration
- **Input Validation**: Comprehensive sanitization of all user inputs
- **Audit Trail**: Complete logging of security events
- **Password Requirements**: Enforced password strength policies

### Notifications & Reporting
- **Email Alerts**: Access denied, door status changes, security events
- **Daily Reports**: Automated summary emails
- **Custom Reports**: Daily, hourly, by-door, by-user statistics
- **CSV Export**: All reports exportable to CSV format
- **Real-time Alerts**: Immediate notification of security events

## System Architecture

PiDoors uses a distributed architecture:
- **1 Server Pi**: Runs web interface, MySQL database, and central management
- **N Door Controller Pis**: One Raspberry Pi at each access point with Wiegand reader

Door controllers cache access permissions locally for 24 hours, ensuring continued operation even if the network connection to the server is lost.

## Hardware Requirements

### Server Pi
- Raspberry Pi 3B+ or newer
- 16GB+ SD card
- Ethernet connection (recommended)
- Sufficient power supply (2.5A+)

### Door Controller Pi
- Raspberry Pi Zero W or newer
- 8GB+ SD card
- Wiegand card reader (26/34/37-bit)
- 12V electric strike or magnetic lock
- 12V relay module
- Door sensor (optional but recommended)
- REX button (optional)
- GPIO connections as per PCB files

### PCB Files
Custom PCB Gerber files are included in `pidoorspcb/` directory for easier installation.

## Installation

### Quick Install

For automated installation on Raspberry Pi OS:

```bash
git clone https://github.com/sybethiesant/pidoors.git
cd pidoors
sudo ./install.sh
```

The installer will guide you through:
1. Installation type selection (Server/Door Controller/Full)
2. System package installation
3. Database setup (for server)
4. Web interface configuration
5. Door controller configuration
6. Automatic backup scheduling

### Manual Installation

#### Server Installation

1. **Install dependencies**:
```bash
sudo apt-get update
sudo apt-get install apache2 php php-mysql mariadb-server
```

2. **Configure database**:
```bash
sudo mysql_secure_installation
sudo mysql -u root -p
```

```sql
CREATE DATABASE users;
CREATE DATABASE access;
CREATE USER 'pidoors'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON users.* TO 'pidoors'@'localhost';
GRANT ALL PRIVILEGES ON access.* TO 'pidoors'@'localhost';
FLUSH PRIVILEGES;
```

3. **Import schema**:
```bash
mysql -u pidoors -p access < database_migration.sql
```

4. **Copy web files**:
```bash
sudo cp -r pidoorserv /var/www/pidoors
sudo chown -R www-data:www-data /var/www/pidoors
```

5. **Configure Apache**:
Create `/etc/apache2/sites-available/pidoors.conf`:
```apache
<VirtualHost *:80>
    DocumentRoot /var/www/pidoors
    <Directory /var/www/pidoors>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

```bash
sudo a2ensite pidoors
sudo systemctl reload apache2
```

6. **Update database credentials** in `/var/www/pidoors/database/db_connection.php`

#### Door Controller Installation

1. **Install Python dependencies**:
```bash
sudo apt-get install python3 python3-pip
sudo pip3 install mysql-connector-python RPi.GPIO
```

2. **Copy and configure**:
```bash
sudo mkdir -p /opt/pidoors
sudo cp pidoors/pidoors.py /opt/pidoors/
```

3. **Edit configuration** in `/opt/pidoors/pidoors.py`:
   - Set database host, credentials
   - Set door name/location
   - Configure GPIO pins

4. **Install systemd service**:
```bash
sudo cp pidoors/pidoors.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable pidoors
sudo systemctl start pidoors
```

## Configuration

### Database Connection

Edit `pidoorserv/database/db_connection.php`:
```php
$pdo = new PDO("mysql:host=localhost;dbname=users", "pidoors", "your_password");
$pdo_access = new PDO("mysql:host=localhost;dbname=access", "pidoors", "your_password");
```

### Door Controller

Edit `/opt/pidoors/pidoors.py`:
```python
# Database configuration
db_config = {
    'host': 'server_ip_address',
    'user': 'pidoors',
    'password': 'your_password',
    'database': 'access'
}

# Door configuration
door_name = "Front Entrance"

# GPIO pins
DATA0_PIN = 23
DATA1_PIN = 24
LOCK_PIN = 17
SENSOR_PIN = 27
REX_PIN = 22
```

### Email Notifications

Configure in web interface under **Settings**:
- SMTP server settings
- Notification recipients
- Alert types to enable

## Usage

### First Login

1. Navigate to `http://your-pi-ip/`
2. Default credentials: `admin@fake.com` / `admin` (change immediately!)
3. Go to Settings and update:
   - System name
   - Email notifications
   - Password policies

### Adding Doors

1. Navigate to **Doors** page
2. Click **Add Door**
3. Enter:
   - Door name/location
   - IP address of door controller Pi
   - Schedule (optional)
   - Unlock duration (seconds)

### Adding Cards

**Single Card**:
1. Navigate to **Cards** page
2. Click **Add Card**
3. Enter card details (scan card to get Wiegand ID)

**Bulk Import**:
1. Prepare CSV file with columns: `card_id,user_id,firstname,lastname`
2. Navigate to **Import Cards**
3. Upload CSV file

### Creating Schedules

1. Navigate to **Schedules** page
2. Click **Add Schedule**
3. Define time windows for each day
4. Assign schedule to cards or doors

### Creating Access Groups

1. Navigate to **Access Groups** page
2. Click **Add Group**
3. Select which doors the group can access
4. Assign cards to the group

### Viewing Logs

1. Navigate to **Access Logs**
2. Use filters to search by:
   - Date range
   - Door location
   - User/Card ID
   - Granted/Denied status
3. Export to CSV for external analysis

### Backup & Restore

**Automatic Backup**:
Daily backups are scheduled automatically at 2 AM to `/var/backups/pidoors/`

**Manual Backup**:
```bash
sudo /usr/local/bin/pidoors-backup.sh
```

**Restore**:
```bash
mysql -u pidoors -p users < users_backup.sql
mysql -u pidoors -p access < access_backup.sql
```

## Monitoring

### Door Controller Logs

```bash
# View real-time logs
sudo journalctl -u pidoors -f

# View recent logs
sudo journalctl -u pidoors -n 100

# View logs from specific date
sudo journalctl -u pidoors --since "2026-01-09"
```

### Service Status

```bash
# Check service status
sudo systemctl status pidoors

# Restart service
sudo systemctl restart pidoors

# Stop service
sudo systemctl stop pidoors
```

### Web Server Logs

```bash
# Apache error log
sudo tail -f /var/log/apache2/pidoors_error.log

# Apache access log
sudo tail -f /var/log/apache2/pidoors_access.log
```

## Troubleshooting

### Door Controller Won't Start

1. Check service status: `sudo systemctl status pidoors`
2. Check logs: `sudo journalctl -u pidoors -n 50`
3. Verify database connectivity from controller Pi
4. Check GPIO permissions: user should be in `gpio` group

### Card Not Reading

1. Verify Wiegand reader connections (DATA0, DATA1, GND)
2. Check controller logs for card reads
3. Verify correct Wiegand format (26/34/37-bit)
4. Test with known-good card

### Access Denied

1. Check card is active in database
2. Verify card is within valid date range
3. Check access group permissions
4. Verify schedule allows access at current time
5. Check for holidays blocking access
6. Review access logs for deny reason

### Web Interface Not Loading

1. Check Apache status: `sudo systemctl status apache2`
2. Verify file permissions: `ls -la /var/www/pidoors`
3. Check Apache error log
4. Verify database connection settings

### Email Notifications Not Working

1. Verify SMTP settings in Settings page
2. Check PHP mail logs: `sudo tail -f /var/log/mail.log`
3. Test with a simple notification
4. Verify recipient email addresses are correct

## Security Best Practices

1. **Change default passwords immediately** after installation
2. **Use strong passwords** (12+ characters, mixed case, numbers, symbols)
3. **Enable HTTPS** for production deployments
4. **Restrict database access** to localhost or specific IPs
5. **Regular backups** - verify automated backups are working
6. **Update regularly** - keep Raspberry Pi OS and packages updated
7. **Monitor audit logs** - review security events regularly
8. **Limit user accounts** - only create necessary admin accounts
9. **Network security** - place on isolated VLAN if possible
10. **Physical security** - secure server Pi in locked cabinet

## API Documentation

### Remote Door Control

Door controllers expose a simple HTTP API for remote control:

```bash
# Unlock door remotely
curl http://door-controller-ip:8080/unlock

# Lock door
curl http://door-controller-ip:8080/lock

# Get status
curl http://door-controller-ip:8080/status
```

## Database Schema

### Key Tables

- **cards**: Card/user information and permissions
- **doors**: Door controller configuration
- **logs**: Access attempt history
- **access_schedules**: Time-based access schedules
- **access_groups**: Group permissions
- **holidays**: Holiday calendar
- **master_cards**: System master cards
- **users**: Web interface users
- **audit_logs**: Security event logging
- **settings**: System configuration

## Development

### File Structure

```
pidoors/
├── install.sh              # Automated installer
├── database_migration.sql  # Database schema
├── pidoors/                # Door controller
│   ├── pidoors.py         # Main controller script
│   └── pidoors.service    # Systemd service file
├── pidoorserv/            # Web interface
│   ├── index.php          # Dashboard
│   ├── cards.php          # Card management
│   ├── doors.php          # Door management
│   ├── logs.php           # Access logs
│   ├── schedules.php      # Schedule management
│   ├── groups.php         # Group management
│   ├── settings.php       # System settings
│   ├── users/             # User management
│   ├── includes/          # Shared code
│   │   ├── config.php    # Configuration
│   │   ├── security.php  # Security functions
│   │   ├── header.php    # Page header
│   │   └── footer.php    # Page footer
│   └── database/          # Database connection
└── pidoorspcb/            # PCB Gerber files
```

### Contributing

Contributions are welcome! Please:
1. Fork the repository
2. Create a feature branch
3. Test thoroughly on Raspberry Pi hardware
4. Submit pull request with detailed description

## License

This project is open source. Feel free to use, modify, and distribute.

## Credits

Original concept and development by sybethiesant.
Updated to industrial standards with enhanced security, offline capability, and comprehensive features.

## Support

For issues, questions, or feature requests, please open an issue on GitHub.

## Changelog

### Version 2.0 (2026-01-09)
- Complete security overhaul (bcrypt, CSRF, SQL injection prevention)
- Added 24-hour offline caching capability
- Implemented time-based schedules and access groups
- Added holiday support
- Enhanced web interface with modern Bootstrap 5 UI
- Comprehensive audit logging
- Email notification system
- CSV import/export functionality
- Automated backup system
- Systemd service integration
- Complete documentation and installation script
- Support for 34-bit and 37-bit Wiegand formats
- Real-time door monitoring and remote control
- Database-configurable master cards
- Multi-user administration with role-based access

### Version 1.0 (Original)
- Basic Wiegand 26-bit support
- Simple web interface
- MySQL card lookup
- Basic access logging
