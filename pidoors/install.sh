#!/bin/bash
#
# PiDoors Access Control System - Installation Script
# This script installs and configures PiDoors on a Raspberry Pi
# Web Server: Nginx with PHP-FPM
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${GREEN}======================================${NC}"
echo -e "${GREEN}PiDoors Installation Script${NC}"
echo -e "${GREEN}Version 2.2.3 - Multi-Reader Edition${NC}"
echo -e "${GREEN}======================================${NC}"
echo

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Please run as root (sudo ./install.sh)${NC}"
    exit 1
fi

# Check if running on Raspberry Pi
if ! grep -q "Raspberry Pi" /proc/cpuinfo 2>/dev/null && ! grep -q "BCM" /proc/cpuinfo 2>/dev/null; then
    echo -e "${YELLOW}Warning: This doesn't appear to be a Raspberry Pi${NC}"
    read -p "Continue anyway? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# Detect installation type
echo "Select installation type:"
echo "1) Server (Web interface + Database)"
echo "2) Door Controller (GPIO + Card reader)"
echo "3) Full (Server + Door Controller)"
read -p "Enter choice [1-3]: " INSTALL_TYPE

case $INSTALL_TYPE in
    1) INSTALL_SERVER=true; INSTALL_DOOR=false ;;
    2) INSTALL_SERVER=false; INSTALL_DOOR=true ;;
    3) INSTALL_SERVER=true; INSTALL_DOOR=true ;;
    *) echo -e "${RED}Invalid choice${NC}"; exit 1 ;;
esac

# Update system
echo -e "\n${GREEN}[1/8] Updating system packages...${NC}"
apt-get update
apt-get upgrade -y

# Install common dependencies
echo -e "\n${GREEN}[2/8] Installing common dependencies...${NC}"
apt-get install -y git python3 python3-pip python3-dev python3-venv curl

# Install server components
if [ "$INSTALL_SERVER" = true ]; then
    echo -e "\n${GREEN}[3/8] Installing web server components (Nginx + PHP-FPM)...${NC}"

    # Install Nginx, PHP-FPM, and MariaDB
    apt-get install -y nginx php-fpm php-mysql php-cli php-mbstring php-curl php-json mariadb-server

    # Detect PHP version for FPM socket
    PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
    echo -e "${BLUE}Detected PHP version: ${PHP_VERSION}${NC}"

    # Enable and start Nginx
    systemctl enable nginx
    systemctl start nginx

    # Enable and start PHP-FPM
    systemctl enable php${PHP_VERSION}-fpm
    systemctl start php${PHP_VERSION}-fpm

    # Secure MariaDB installation
    echo -e "\n${YELLOW}Securing MariaDB installation...${NC}"
    mysql_secure_installation

    # Create databases
    echo -e "\n${GREEN}Creating databases...${NC}"
    read -p "Enter MySQL root password: " -s MYSQL_ROOT_PASS
    echo
    read -p "Enter new PiDoors database password: " -s DB_PASS
    echo

    mysql -u root -p"$MYSQL_ROOT_PASS" <<EOF
CREATE DATABASE IF NOT EXISTS users;
CREATE DATABASE IF NOT EXISTS access;
CREATE USER IF NOT EXISTS 'pidoors'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON users.* TO 'pidoors'@'localhost';
GRANT ALL PRIVILEGES ON access.* TO 'pidoors'@'localhost';
FLUSH PRIVILEGES;
EOF

    # Create users table schema
    echo -e "${GREEN}Creating users table...${NC}"
    mysql -u root -p"$MYSQL_ROOT_PASS" users <<EOF
CREATE TABLE IF NOT EXISTS \`users\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`user_name\` varchar(100) NOT NULL,
  \`user_email\` varchar(255) NOT NULL,
  \`user_pass\` varchar(255) NOT NULL,
  \`admin\` tinyint(1) NOT NULL DEFAULT 0,
  \`active\` tinyint(1) NOT NULL DEFAULT 1,
  \`created_at\` datetime DEFAULT CURRENT_TIMESTAMP,
  \`last_login\` datetime DEFAULT NULL,
  PRIMARY KEY (\`id\`),
  UNIQUE KEY \`user_email\` (\`user_email\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS \`audit_logs\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`event_type\` varchar(50) NOT NULL,
  \`user_id\` int(11) DEFAULT NULL,
  \`ip_address\` varchar(45) DEFAULT NULL,
  \`user_agent\` varchar(255) DEFAULT NULL,
  \`details\` text,
  \`created_at\` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (\`id\`),
  KEY \`event_type\` (\`event_type\`),
  KEY \`user_id\` (\`user_id\`),
  KEY \`created_at\` (\`created_at\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
EOF

    # Import access database schema
    if [ -f "database_migration.sql" ]; then
        echo -e "${GREEN}Importing access database schema...${NC}"
        mysql -u root -p"$MYSQL_ROOT_PASS" access < database_migration.sql
    fi

    # Copy web files
    echo -e "\n${GREEN}Installing web interface...${NC}"
    mkdir -p /var/www/pidoors
    cp -r pidoorserv/* /var/www/pidoors/
    chown -R www-data:www-data /var/www/pidoors
    chmod -R 755 /var/www/pidoors

    # Create configuration from example
    if [ -f "/var/www/pidoors/includes/config.php.example" ]; then
        cp /var/www/pidoors/includes/config.php.example /var/www/pidoors/includes/config.php
        # Update database password and URL in config
        sed -i "s/'sqlpass' => ''/'sqlpass' => '$DB_PASS'/g" /var/www/pidoors/includes/config.php
        SERVER_IP=$(hostname -I | awk '{print $1}')
        sed -i "s|'url' => 'http://localhost'|'url' => 'http://$SERVER_IP'|g" /var/www/pidoors/includes/config.php
        chmod 640 /var/www/pidoors/includes/config.php
        chown www-data:www-data /var/www/pidoors/includes/config.php
    fi

    # Install Nginx configuration
    echo -e "${GREEN}Configuring Nginx...${NC}"

    # Update PHP-FPM socket path in nginx config based on detected version
    sed "s|unix:/var/run/php/php-fpm.sock|unix:/var/run/php/php${PHP_VERSION}-fpm.sock|g" \
        nginx/pidoors.conf > /etc/nginx/sites-available/pidoors

    # Disable default site and enable PiDoors
    rm -f /etc/nginx/sites-enabled/default
    ln -sf /etc/nginx/sites-available/pidoors /etc/nginx/sites-enabled/pidoors

    # Test Nginx configuration
    nginx -t

    # Reload Nginx
    systemctl reload nginx

    echo -e "${GREEN}Web interface installed at http://$(hostname -I | awk '{print $1}')/${NC}"
else
    echo -e "\n${YELLOW}[3/8] Skipping web server installation${NC}"
fi

# Install door controller components
if [ "$INSTALL_DOOR" = true ]; then
    echo -e "\n${GREEN}[4/8] Installing door controller components...${NC}"

    # Create Python virtual environment for door controller
    echo -e "${GREEN}Setting up Python virtual environment...${NC}"
    python3 -m venv /opt/pidoors/venv --system-site-packages
    /opt/pidoors/venv/bin/pip install --upgrade pip

    # Install Python dependencies inside the venv
    # Core: database and GPIO
    # Readers: pyserial (OSDP), smbus2 (PN532 I2C), spidev (PN532/MFRC522 SPI)
    /opt/pidoors/venv/bin/pip install pymysql RPi.GPIO pyserial smbus2 spidev

    # Create pidoors user
    if ! id -u pidoors > /dev/null 2>&1; then
        useradd -r -s /bin/false -G gpio pidoors
        echo -e "${GREEN}Created pidoors user${NC}"
    fi

    # Create installation directory
    mkdir -p /opt/pidoors/conf
    mkdir -p /opt/pidoors/cache
    mkdir -p /opt/pidoors/readers
    mkdir -p /opt/pidoors/formats

    # Copy main controller
    cp pidoors/pidoors.py /opt/pidoors/

    # Copy reader modules
    cp -r pidoors/readers/* /opt/pidoors/readers/
    cp -r pidoors/formats/* /opt/pidoors/formats/

    # Copy configuration template
    cp pidoors/conf/config.json.example /opt/pidoors/conf/config.json

    # Set permissions
    chown -R pidoors:pidoors /opt/pidoors
    chmod +x /opt/pidoors/pidoors.py
    chmod 700 /opt/pidoors/cache

    # Configure door controller
    echo -e "\n${YELLOW}Configuring door controller...${NC}"
    read -p "Enter database server IP address [localhost]: " DB_HOST
    DB_HOST=${DB_HOST:-localhost}
    read -p "Enter database password: " -s DB_PASS_DOOR
    echo
    read -p "Enter door name/location: " DOOR_NAME

    # Select reader type
    echo -e "\n${YELLOW}Select card reader type:${NC}"
    echo "1) Wiegand (GPIO) - Most common"
    echo "2) OSDP (RS-485) - Encrypted"
    echo "3) NFC PN532 (I2C)"
    echo "4) NFC MFRC522 (SPI)"
    read -p "Enter choice [1-4]: " READER_TYPE

    case $READER_TYPE in
        2)
            READER_CONFIG='"reader_type": "osdp",
        "serial_port": "/dev/serial0",
        "baud_rate": 115200,
        "address": 0,'
            ;;
        3)
            READER_CONFIG='"reader_type": "nfc_pn532",
        "interface": "i2c",
        "i2c_address": 36,
        "i2c_bus": 1,'
            ;;
        4)
            READER_CONFIG='"reader_type": "nfc_mfrc522",
        "spi_bus": 0,
        "spi_device": 0,
        "reset_pin": 25,'
            ;;
        *)
            READER_CONFIG='"reader_type": "wiegand",
        "d0": 24,
        "d1": 23,'
            ;;
    esac

    # Update configuration file
    cat > /opt/pidoors/conf/config.json <<DOORCONF
{
    "$DOOR_NAME": {
        $READER_CONFIG
        "unlock_value": 1,
        "open_delay": 3,
        "latch_gpio": 18,
        "sqladdr": "$DB_HOST",
        "sqluser": "pidoors",
        "sqlpass": "$DB_PASS_DOOR",
        "sqldb": "access"
    }
}
DOORCONF

    # Create zone configuration
    cat > /opt/pidoors/conf/zone.json <<ZONECONF
{
    "zone": "$DOOR_NAME"
}
ZONECONF

    chown pidoors:pidoors /opt/pidoors/conf/*.json
    chmod 600 /opt/pidoors/conf/config.json

    # Install systemd service
    cp pidoors/pidoors.service /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable pidoors.service

    echo -e "${GREEN}Door controller installed. Start with: systemctl start pidoors${NC}"
else
    echo -e "\n${YELLOW}[4/8] Skipping door controller installation${NC}"
fi

# Configure firewall
echo -e "\n${GREEN}[5/8] Configuring firewall...${NC}"
if command -v ufw > /dev/null; then
    if [ "$INSTALL_SERVER" = true ]; then
        ufw allow 80/tcp
        ufw allow 443/tcp
        ufw allow 3306/tcp # MySQL (only if needed remotely)
    fi
    echo -e "${GREEN}Firewall rules added${NC}"
else
    echo -e "${YELLOW}UFW not installed, skipping firewall configuration${NC}"
fi

# Set up log rotation
echo -e "\n${GREEN}[6/8] Configuring log rotation...${NC}"
cat > /etc/logrotate.d/pidoors <<LOGROTATE
/var/log/pidoors.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 0640 pidoors pidoors
}

/var/log/nginx/pidoors_*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    sharedscripts
    postrotate
        [ -f /var/run/nginx.pid ] && kill -USR1 \$(cat /var/run/nginx.pid)
    endscript
}
LOGROTATE

# Create backup script
echo -e "\n${GREEN}[7/8] Creating backup script...${NC}"
if [ "$INSTALL_SERVER" = true ]; then
    mkdir -p /var/backups/pidoors
    chown www-data:www-data /var/backups/pidoors
    chmod 750 /var/backups/pidoors

    cat > /usr/local/bin/pidoors-backup.sh <<'BACKUP'
#!/bin/bash
# PiDoors Backup Script

BACKUP_DIR="/var/backups/pidoors"
DATE=$(date +%Y%m%d_%H%M%S)

mkdir -p "$BACKUP_DIR"

# Backup databases
mysqldump -u pidoors -p"$1" users > "$BACKUP_DIR/users_$DATE.sql"
mysqldump -u pidoors -p"$1" access > "$BACKUP_DIR/access_$DATE.sql"

# Backup web files (excluding sensitive config)
tar --exclude='config.php' -czf "$BACKUP_DIR/web_$DATE.tar.gz" /var/www/pidoors

# Keep only last 30 days of backups
find "$BACKUP_DIR" -name "*.sql" -mtime +30 -delete
find "$BACKUP_DIR" -name "*.tar.gz" -mtime +30 -delete

echo "Backup completed: $DATE"
BACKUP

    chmod +x /usr/local/bin/pidoors-backup.sh

    # Add to crontab (user needs to add password or use .my.cnf)
    echo -e "${YELLOW}Note: Configure backup password in /root/.my.cnf or update crontab${NC}"
    echo -e "${YELLOW}Example crontab entry: 0 2 * * * /usr/local/bin/pidoors-backup.sh${NC}"
fi

# Final setup
echo -e "\n${GREEN}[8/8] Final configuration...${NC}"

if [ "$INSTALL_SERVER" = true ]; then
    # Create default admin user
    read -p "Create default admin user? (y/N) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        read -p "Admin email: " ADMIN_EMAIL
        read -p "Admin password: " -s ADMIN_PASS
        echo

        # Hash password with PHP
        HASHED_PASS=$(php -r "echo password_hash('$ADMIN_PASS', PASSWORD_BCRYPT);")

        mysql -u pidoors -p"$DB_PASS" users <<EOF
INSERT INTO users (user_name, user_email, user_pass, admin, active)
VALUES ('Admin', '$ADMIN_EMAIL', '$HASHED_PASS', 1, 1)
ON DUPLICATE KEY UPDATE user_pass='$HASHED_PASS';
EOF

        echo -e "${GREEN}Admin user created${NC}"
    fi
fi

# Summary
echo
echo -e "${GREEN}======================================${NC}"
echo -e "${GREEN}Installation Complete!${NC}"
echo -e "${GREEN}======================================${NC}"
echo

if [ "$INSTALL_SERVER" = true ]; then
    echo -e "Web Interface: ${GREEN}http://$(hostname -I | awk '{print $1}')/${NC}"
    echo -e "Web Server: ${GREEN}Nginx with PHP-FPM${NC}"
    echo -e "Database: ${GREEN}MariaDB${NC}"
    echo -e "Backup Script: ${GREEN}/usr/local/bin/pidoors-backup.sh${NC}"
    echo -e "Nginx Config: ${GREEN}/etc/nginx/sites-available/pidoors${NC}"
    echo -e "Web Root: ${GREEN}/var/www/pidoors/${NC}"
fi

if [ "$INSTALL_DOOR" = true ]; then
    echo -e "Door Controller: ${GREEN}/opt/pidoors/pidoors.py${NC}"
    echo -e "Configuration: ${GREEN}/opt/pidoors/conf/config.json${NC}"
    echo -e "Service: ${GREEN}systemctl start pidoors${NC}"
    echo -e "Logs: ${GREEN}journalctl -u pidoors -f${NC}"
fi

echo
echo -e "${YELLOW}Next steps:${NC}"
if [ "$INSTALL_SERVER" = true ]; then
    echo "1. Log in to the web interface"
    echo "2. Configure doors in the Doors page"
    echo "3. Add cards in the Cards page"
    echo "4. Set up access schedules and groups"
    echo "5. Configure email notifications in Settings"
    echo "6. Enable HTTPS for production (see nginx/pidoors.conf)"
fi
if [ "$INSTALL_DOOR" = true ]; then
    echo "1. Verify reader connections:"
    echo "   - Wiegand: DATA0=GPIO24, DATA1=GPIO23"
    echo "   - OSDP: RS-485 adapter on /dev/serial0"
    echo "   - PN532: I2C on bus 1, address 0x24"
    echo "   - MFRC522: SPI bus 0, device 0, reset=GPIO25"
    echo "2. Start the service: systemctl start pidoors"
    echo "3. Monitor logs: journalctl -u pidoors -f"
fi

echo
echo -e "${GREEN}Installation log saved to: /var/log/pidoors-install.log${NC}"
echo
