#!/bin/bash
#
# PiDoors Access Control System - Installation Script
# This script installs and configures PiDoors on a Raspberry Pi
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}======================================${NC}"
echo -e "${GREEN}PiDoors Installation Script${NC}"
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
apt-get install -y git python3 python3-pip python3-dev

# Install server components
if [ "$INSTALL_SERVER" = true ]; then
    echo -e "\n${GREEN}[3/8] Installing web server components...${NC}"
    apt-get install -y apache2 php php-mysql php-cli php-mbstring php-curl mariadb-server

    # Enable Apache modules
    a2enmod rewrite
    a2enmod ssl

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

    # Import database schema
    if [ -f "database_migration.sql" ]; then
        echo -e "${GREEN}Importing database schema...${NC}"
        mysql -u root -p"$MYSQL_ROOT_PASS" access < database_migration.sql
    fi

    # Copy web files
    echo -e "\n${GREEN}Installing web interface...${NC}"
    mkdir -p /var/www/pidoors
    cp -r pidoorserv/* /var/www/pidoors/
    chown -R www-data:www-data /var/www/pidoors
    chmod -R 755 /var/www/pidoors

    # Update database connection config
    echo -e "${YELLOW}Updating database configuration...${NC}"
    sed -i "s/'password' => ''/'password' => '$DB_PASS'/g" /var/www/pidoors/database/db_connection.php

    # Create Apache virtual host
    cat > /etc/apache2/sites-available/pidoors.conf <<VHOST
<VirtualHost *:80>
    ServerName pidoors.local
    DocumentRoot /var/www/pidoors

    <Directory /var/www/pidoors>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/pidoors_error.log
    CustomLog \${APACHE_LOG_DIR}/pidoors_access.log combined
</VirtualHost>
VHOST

    a2ensite pidoors.conf
    systemctl reload apache2

    echo -e "${GREEN}Web interface installed at http://$(hostname -I | awk '{print $1}')/pidoors${NC}"
else
    echo -e "\n${YELLOW}[3/8] Skipping web server installation${NC}"
fi

# Install door controller components
if [ "$INSTALL_DOOR" = true ]; then
    echo -e "\n${GREEN}[4/8] Installing door controller components...${NC}"

    # Install Python dependencies
    pip3 install mysql-connector-python RPi.GPIO

    # Create pidoors user
    if ! id -u pidoors > /dev/null 2>&1; then
        useradd -r -s /bin/false -G gpio pidoors
        echo -e "${GREEN}Created pidoors user${NC}"
    fi

    # Create installation directory
    mkdir -p /opt/pidoors
    cp pidoors/pidoors.py /opt/pidoors/
    chown -R pidoors:pidoors /opt/pidoors
    chmod +x /opt/pidoors/pidoors.py

    # Configure door controller
    echo -e "\n${YELLOW}Configuring door controller...${NC}"
    read -p "Enter database server IP address [localhost]: " DB_HOST
    DB_HOST=${DB_HOST:-localhost}
    read -p "Enter database password: " -s DB_PASS_DOOR
    echo
    read -p "Enter door name/location: " DOOR_NAME

    # Update configuration in pidoors.py
    sed -i "s/host='localhost'/host='$DB_HOST'/g" /opt/pidoors/pidoors.py
    sed -i "s/password=''/password='$DB_PASS_DOOR'/g" /opt/pidoors/pidoors.py
    sed -i "s/door_name = \"Door\"/door_name = \"$DOOR_NAME\"/g" /opt/pidoors/pidoors.py

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
LOGROTATE

# Create backup script
echo -e "\n${GREEN}[7/8] Creating backup script...${NC}"
if [ "$INSTALL_SERVER" = true ]; then
    cat > /usr/local/bin/pidoors-backup.sh <<'BACKUP'
#!/bin/bash
# PiDoors Backup Script

BACKUP_DIR="/var/backups/pidoors"
DATE=$(date +%Y%m%d_%H%M%S)

mkdir -p "$BACKUP_DIR"

# Backup databases
mysqldump -u pidoors -p users > "$BACKUP_DIR/users_$DATE.sql"
mysqldump -u pidoors -p access > "$BACKUP_DIR/access_$DATE.sql"

# Backup web files
tar -czf "$BACKUP_DIR/web_$DATE.tar.gz" /var/www/pidoors

# Keep only last 30 days of backups
find "$BACKUP_DIR" -name "*.sql" -mtime +30 -delete
find "$BACKUP_DIR" -name "*.tar.gz" -mtime +30 -delete

echo "Backup completed: $DATE"
BACKUP

    chmod +x /usr/local/bin/pidoors-backup.sh

    # Add to crontab
    (crontab -l 2>/dev/null; echo "0 2 * * * /usr/local/bin/pidoors-backup.sh") | crontab -
    echo -e "${GREEN}Daily backup scheduled at 2 AM${NC}"
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
INSERT INTO users (user_name, user_email, user_pass, user_admin, active)
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
    echo -e "Database: ${GREEN}MySQL/MariaDB${NC}"
    echo -e "Backup: ${GREEN}/usr/local/bin/pidoors-backup.sh${NC}"
fi

if [ "$INSTALL_DOOR" = true ]; then
    echo -e "Door Controller: ${GREEN}/opt/pidoors/pidoors.py${NC}"
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
fi
if [ "$INSTALL_DOOR" = true ]; then
    echo "1. Verify Wiegand reader connections (DATA0, DATA1)"
    echo "2. Start the service: systemctl start pidoors"
    echo "3. Monitor logs: journalctl -u pidoors -f"
fi

echo
echo -e "${GREEN}Installation log saved to: /var/log/pidoors-install.log${NC}"
echo
