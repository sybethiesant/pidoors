#!/bin/bash
#
# PiDoors Access Control System - Installation Script
# Installs and configures the web server, door controller, or both
#
# Usage: sudo ./install.sh
#

set -e

# ──────────────────────────────────────────────
# Colors and helpers
# ──────────────────────────────────────────────

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

INSTALL_DIR="/opt/pidoors"
WEB_ROOT="/var/www/pidoors"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VERSION="2.4.1"

ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
fail() { echo -e "  ${RED}✗${NC} $1"; }
warn() { echo -e "  ${YELLOW}!${NC} $1"; }
info() { echo -e "  ${BLUE}→${NC} $1"; }

step() {
    echo
    echo -e "${BLUE}─── $1 ───${NC}"
    echo
}

prompt() {
    local var_name="$1" prompt_text="$2" default="$3"
    local value
    if [ -n "$default" ]; then
        read -p "  $prompt_text [$default]: " value
        value="${value:-$default}"
    else
        while [ -z "$value" ]; do
            read -p "  $prompt_text: " value
            [ -z "$value" ] && echo -e "  ${RED}This field is required.${NC}"
        done
    fi
    eval "$var_name='$value'"
}

prompt_secret() {
    local var_name="$1" prompt_text="$2"
    local value
    while [ -z "$value" ]; do
        read -s -p "  $prompt_text: " value
        echo
        [ -z "$value" ] && echo -e "  ${RED}This field is required.${NC}"
    done
    eval "$var_name='$value'"
}

# ──────────────────────────────────────────────
# Banner
# ──────────────────────────────────────────────

clear
echo
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  PiDoors Access Control System${NC}"
echo -e "${GREEN}  Installation Script v${VERSION}${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo

# ──────────────────────────────────────────────
# Pre-flight checks
# ──────────────────────────────────────────────

step "Pre-flight checks"

# Root check
if [ "$EUID" -ne 0 ]; then
    fail "This script must be run as root: ${BOLD}sudo ./install.sh${NC}"
    exit 1
fi
ok "Running as root"

# Raspberry Pi check
if grep -q "Raspberry Pi\|BCM" /proc/cpuinfo 2>/dev/null; then
    ok "Running on Raspberry Pi"
    IS_PI=true
else
    warn "This does not appear to be a Raspberry Pi"
    info "GPIO features will not be available"
    IS_PI=false
fi

# OS check
if [ -f /etc/os-release ]; then
    . /etc/os-release
    ok "OS: $PRETTY_NAME"
else
    warn "Could not detect OS"
fi

# ──────────────────────────────────────────────
# Installation type
# ──────────────────────────────────────────────

step "Installation type"

echo "  What would you like to install?"
echo
echo "    ${BOLD}1) Server${NC}          - Web interface + Database"
echo "                       Install on the central Pi that hosts the dashboard"
echo
echo "    ${BOLD}2) Door Controller${NC} - GPIO + Card reader"
echo "                       Install on each Pi that controls a door"
echo
echo "    ${BOLD}3) Both${NC}            - Server + Door Controller on one Pi"
echo "                       For single-door setups or testing"
echo
read -p "  Enter choice [1-3]: " INSTALL_TYPE

case $INSTALL_TYPE in
    1) INSTALL_SERVER=true;  INSTALL_DOOR=false ;;
    2) INSTALL_SERVER=false; INSTALL_DOOR=true  ;;
    3) INSTALL_SERVER=true;  INSTALL_DOOR=true  ;;
    *) fail "Invalid choice"; exit 1 ;;
esac

# ──────────────────────────────────────────────
# System packages
# ──────────────────────────────────────────────

step "Installing system packages"

info "Updating package lists..."
apt-get update -qq
ok "Package lists updated"

info "Installing common dependencies..."
apt-get install -y -qq git python3 python3-pip python3-dev python3-venv curl > /dev/null 2>&1
ok "Common packages installed"

# ============================================================
# SERVER INSTALLATION
# ============================================================

if [ "$INSTALL_SERVER" = true ]; then

    step "Server: Web server + Database"

    # Install packages
    info "Installing Nginx, PHP-FPM, and MariaDB..."
    apt-get install -y -qq nginx php-fpm php-mysql php-cli php-mbstring php-curl php-json mariadb-server > /dev/null 2>&1
    ok "Server packages installed"

    # Detect PHP version
    PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
    ok "PHP version: $PHP_VERSION"

    # Enable services
    systemctl enable nginx > /dev/null 2>&1
    systemctl start nginx
    systemctl enable "php${PHP_VERSION}-fpm" > /dev/null 2>&1
    systemctl start "php${PHP_VERSION}-fpm"
    systemctl enable mariadb > /dev/null 2>&1
    systemctl start mariadb
    ok "Services enabled and started"

    # ── Database setup ──

    step "Server: Database setup"

    echo "  MariaDB needs to be secured."
    echo "  If you have already secured it, you can skip this step."
    echo
    read -p "  Run secure installation? (Y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Nn]$ ]]; then
        if command -v mariadb-secure-installation > /dev/null 2>&1; then
            mariadb-secure-installation
        elif command -v mysql_secure_installation > /dev/null 2>&1; then
            mysql_secure_installation
        else
            warn "Neither mariadb-secure-installation nor mysql_secure_installation found"
            warn "Please secure MariaDB manually after installation"
        fi
    fi

    # Allow remote connections from door controllers
    MARIADB_CNF="/etc/mysql/mariadb.conf.d/50-server.cnf"
    if [ -f "$MARIADB_CNF" ]; then
        if grep -q "^bind-address\s*=\s*127.0.0.1" "$MARIADB_CNF"; then
            info "Configuring MariaDB to accept remote connections..."
            sed -i 's/^bind-address\s*=\s*127.0.0.1/bind-address = 0.0.0.0/' "$MARIADB_CNF"
            systemctl restart mariadb
            ok "MariaDB bind-address set to 0.0.0.0 (allows door controllers to connect)"
        fi
    fi

    echo
    prompt_secret MYSQL_ROOT_PASS "MySQL root password"

    # Test root connection
    if mysql -u root -p"$MYSQL_ROOT_PASS" -e "SELECT 1" > /dev/null 2>&1; then
        ok "MySQL root connection verified"
    else
        fail "Cannot connect to MySQL as root. Check password."
        exit 1
    fi

    echo
    echo "  Create a password for the PiDoors database user."
    echo "  ${YELLOW}Save this password - you will need it when setting up door controllers.${NC}"
    echo
    prompt_secret DB_PASS "New PiDoors database password"

    info "Creating databases and user..."
    mysql -u root -p"$MYSQL_ROOT_PASS" <<EOF
CREATE DATABASE IF NOT EXISTS users;
CREATE DATABASE IF NOT EXISTS access;
CREATE USER IF NOT EXISTS 'pidoors'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS 'pidoors'@'%' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON users.* TO 'pidoors'@'localhost';
GRANT ALL PRIVILEGES ON access.* TO 'pidoors'@'localhost';
GRANT ALL PRIVILEGES ON users.* TO 'pidoors'@'%';
GRANT ALL PRIVILEGES ON access.* TO 'pidoors'@'%';
FLUSH PRIVILEGES;
EOF
    ok "Databases and user created"

    # Create table schemas
    info "Creating table schemas..."
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

    if [ -f "$SCRIPT_DIR/database_migration.sql" ]; then
        mysql -u root -p"$MYSQL_ROOT_PASS" access < "$SCRIPT_DIR/database_migration.sql"
    fi
    ok "Table schemas created"

    # ── Web interface ──

    step "Server: Web interface"

    info "Installing web files..."
    mkdir -p "$WEB_ROOT"
    cp -r "$SCRIPT_DIR/pidoorserv/"* "$WEB_ROOT/"
    ok "Web files copied"

    # Download vendor assets
    info "Downloading front-end libraries..."
    curl -sL "https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta1/dist/css/bootstrap.min.css" -o "$WEB_ROOT/css/bootstrap.min.css"
    curl -sL "https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" -o "$WEB_ROOT/css/dataTables.bootstrap5.min.css"
    curl -sL "https://code.jquery.com/jquery-3.5.1.min.js" -o "$WEB_ROOT/js/jquery-3.5.1.js"
    curl -sL "https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta1/dist/js/bootstrap.bundle.min.js" -o "$WEB_ROOT/js/bootstrap.bundle.min.js"
    curl -sL "https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js" -o "$WEB_ROOT/js/jquery.dataTables.min.js"
    curl -sL "https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js" -o "$WEB_ROOT/js/dataTables.bootstrap5.min.js"
    curl -sL "https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" -o "$WEB_ROOT/js/Chart.min.js"
    ok "Front-end libraries downloaded"

    # Configure PHP
    if [ -f "$WEB_ROOT/includes/config.php.example" ]; then
        cp "$WEB_ROOT/includes/config.php.example" "$WEB_ROOT/includes/config.php"
        sed -i "s/'sqlpass' => ''/'sqlpass' => '$DB_PASS'/g" "$WEB_ROOT/includes/config.php"
        SERVER_IP=$(hostname -I | awk '{print $1}')
        sed -i "s|'url' => 'http://localhost'|'url' => 'http://$SERVER_IP'|g" "$WEB_ROOT/includes/config.php"
        chmod 640 "$WEB_ROOT/includes/config.php"
        chown www-data:www-data "$WEB_ROOT/includes/config.php"
        ok "Config file created"
    fi

    chown -R www-data:www-data "$WEB_ROOT"
    chmod -R 755 "$WEB_ROOT"

    # Configure Nginx
    info "Configuring Nginx..."
    if [ -f "$SCRIPT_DIR/nginx/pidoors.conf" ]; then
        sed "s|unix:/var/run/php/php-fpm.sock|unix:/var/run/php/php${PHP_VERSION}-fpm.sock|g" \
            "$SCRIPT_DIR/nginx/pidoors.conf" > /etc/nginx/sites-available/pidoors
    fi
    rm -f /etc/nginx/sites-enabled/default
    ln -sf /etc/nginx/sites-available/pidoors /etc/nginx/sites-enabled/pidoors
    nginx -t > /dev/null 2>&1
    systemctl reload nginx
    ok "Nginx configured"

    # ── Admin user ──

    step "Server: Admin account"

    echo "  Create the first admin user for the web interface."
    echo
    prompt ADMIN_EMAIL "Admin email"
    prompt_secret ADMIN_PASS "Admin password"

    HASHED_PASS=$(php -r "echo password_hash('$ADMIN_PASS', PASSWORD_BCRYPT, ['cost' => 12]);")

    mysql -u pidoors -p"$DB_PASS" users <<EOF
INSERT INTO users (user_name, user_email, user_pass, admin, active)
VALUES ('Admin', '$ADMIN_EMAIL', '$HASHED_PASS', 1, 1)
ON DUPLICATE KEY UPDATE user_pass='$HASHED_PASS';
EOF
    ok "Admin user created: $ADMIN_EMAIL"

    # Save the server IP for later use by door controller section
    SERVER_IP=$(hostname -I | awk '{print $1}')
fi

# ============================================================
# DOOR CONTROLLER INSTALLATION
# ============================================================

if [ "$INSTALL_DOOR" = true ]; then

    step "Door Controller: Setup"

    if [ "$IS_PI" = false ]; then
        warn "GPIO will not work on this system"
        warn "Proceeding with install for testing only"
        echo
    fi

    # ── Door identity ──

    echo "  Give this door a short, unique name."
    echo "  Examples: frontdoor, warehouse_gate, office_main"
    echo "  ${YELLOW}Use lowercase, no spaces. Letters, numbers, underscores only.${NC}"
    echo
    prompt DOOR_NAME "Door name"

    # Normalize: lowercase, spaces to underscores, strip invalid chars
    DOOR_NAME=$(echo "$DOOR_NAME" | tr '[:upper:]' '[:lower:]' | tr ' ' '_' | tr -cd 'a-z0-9_')
    if [ -z "$DOOR_NAME" ]; then
        fail "Invalid door name"
        exit 1
    fi
    ok "Door name: ${BOLD}${DOOR_NAME}${NC}"

    # ── Database connection ──

    step "Door Controller: Database connection"

    if [ "$INSTALL_SERVER" = true ]; then
        # Same machine - use the already-set values
        DB_HOST="127.0.0.1"
        DB_USER="pidoors"
        DB_PASS_DOOR="$DB_PASS"
        DB_NAME="access"
        info "Using local database (same machine as server)"
        ok "Server: $DB_HOST"
    else
        echo "  Enter the IP address of the PiDoors server."
        echo "  This is the Pi running the web interface and database."
        echo
        prompt DB_HOST "Server IP address"
        prompt DB_USER "Database username" "pidoors"
        prompt_secret DB_PASS_DOOR "Database password"
        DB_NAME="access"
    fi

    # ── Card reader ──

    step "Door Controller: Card reader"

    echo "  Select the type of card reader connected to this door:"
    echo
    echo "    ${BOLD}1)${NC} Wiegand (GPIO)     - Most common, HID/generic readers"
    echo "    ${BOLD}2)${NC} OSDP (RS-485)      - Encrypted, modern readers"
    echo "    ${BOLD}3)${NC} NFC PN532 (I2C)    - NFC/RFID module"
    echo "    ${BOLD}4)${NC} NFC MFRC522 (SPI)  - NFC/RFID module"
    echo
    read -p "  Enter choice [1-4]: " READER_CHOICE

    case $READER_CHOICE in
        2)
            READER_TYPE="osdp"
            echo
            prompt SERIAL_PORT "Serial port" "/dev/serial0"
            prompt BAUD_RATE "Baud rate" "115200"
            prompt OSDP_ADDR "OSDP address" "0"
            READER_JSON="\"reader_type\": \"osdp\",
        \"serial_port\": \"$SERIAL_PORT\",
        \"baud_rate\": $BAUD_RATE,
        \"address\": $OSDP_ADDR,"
            ok "Reader: OSDP on $SERIAL_PORT"
            ;;
        3)
            READER_TYPE="nfc_pn532"
            echo
            prompt I2C_BUS "I2C bus" "1"
            prompt I2C_ADDR "I2C address (decimal)" "36"
            READER_JSON="\"reader_type\": \"nfc_pn532\",
        \"interface\": \"i2c\",
        \"i2c_address\": $I2C_ADDR,
        \"i2c_bus\": $I2C_BUS,"
            ok "Reader: NFC PN532 on I2C bus $I2C_BUS"
            ;;
        4)
            READER_TYPE="nfc_mfrc522"
            echo
            prompt SPI_BUS "SPI bus" "0"
            prompt SPI_DEV "SPI device" "0"
            prompt RESET_PIN "Reset GPIO pin" "25"
            READER_JSON="\"reader_type\": \"nfc_mfrc522\",
        \"spi_bus\": $SPI_BUS,
        \"spi_device\": $SPI_DEV,
        \"reset_pin\": $RESET_PIN,"
            ok "Reader: NFC MFRC522 on SPI $SPI_BUS:$SPI_DEV"
            ;;
        *)
            READER_TYPE="wiegand"
            echo
            prompt D0_PIN "DATA0 GPIO pin" "24"
            prompt D1_PIN "DATA1 GPIO pin" "23"
            READER_JSON="\"reader_type\": \"wiegand\",
        \"d0\": $D0_PIN,
        \"d1\": $D1_PIN,
        \"wiegand_format\": \"auto\","
            ok "Reader: Wiegand on GPIO $D0_PIN/$D1_PIN"
            ;;
    esac

    # ── Lock relay ──

    echo
    echo "  Configure the electric lock relay:"
    echo
    prompt LATCH_PIN "Lock relay GPIO pin" "18"
    prompt UNLOCK_SEC "Unlock duration (seconds)" "5"
    ok "Lock relay: GPIO $LATCH_PIN, ${UNLOCK_SEC}s unlock"

    # ── Confirm ──

    step "Door Controller: Confirm settings"

    echo -e "  ${BOLD}Door name:${NC}       $DOOR_NAME"
    echo -e "  ${BOLD}Server:${NC}          $DB_HOST"
    echo -e "  ${BOLD}Database:${NC}        $DB_NAME (user: $DB_USER)"
    echo -e "  ${BOLD}Reader type:${NC}     $READER_TYPE"
    echo -e "  ${BOLD}Lock GPIO:${NC}       $LATCH_PIN"
    echo -e "  ${BOLD}Unlock time:${NC}     ${UNLOCK_SEC}s"
    echo
    read -p "  Proceed? (Y/n) " -n 1 -r
    echo
    [[ $REPLY =~ ^[Nn]$ ]] && { echo "Aborted."; exit 0; }

    # ── Install files ──

    step "Door Controller: Installing"

    # Python venv
    info "Setting up Python environment..."
    mkdir -p "$INSTALL_DIR"/{conf,cache,readers,formats}
    python3 -m venv "$INSTALL_DIR/venv" --system-site-packages
    "$INSTALL_DIR/venv/bin/pip" install --upgrade pip -q
    "$INSTALL_DIR/venv/bin/pip" install pymysql pyserial smbus2 spidev -q 2>/dev/null || true

    # On Bookworm+ (Debian 12+), RPi.GPIO is broken for edge detection.
    # Use rpi-lgpio (drop-in replacement) from system packages instead.
    DEBIAN_VERSION=$(cat /etc/debian_version 2>/dev/null | cut -d. -f1)
    if [ -n "$DEBIAN_VERSION" ] && [ "$DEBIAN_VERSION" -ge 12 ] 2>/dev/null; then
        # Remove RPi.GPIO from venv if present so system rpi-lgpio takes over
        "$INSTALL_DIR/venv/bin/pip" uninstall RPi.GPIO -y -q 2>/dev/null || true
        # Ensure rpi-lgpio is installed at system level
        if ! python3 -c "import RPi.GPIO" 2>/dev/null; then
            apt-get install -y -qq python3-rpi-lgpio > /dev/null 2>&1 || \
            apt-get install -y -qq python3-rpi.gpio > /dev/null 2>&1 || \
            warn "Could not install GPIO library - install rpi-lgpio manually"
        fi
        ok "GPIO library: rpi-lgpio (Debian $DEBIAN_VERSION)"
    else
        "$INSTALL_DIR/venv/bin/pip" install RPi.GPIO -q 2>/dev/null || warn "RPi.GPIO not available (non-Pi system?)"
        ok "GPIO library: RPi.GPIO"
    fi
    ok "Python environment ready"

    # Create user
    if ! id -u pidoors > /dev/null 2>&1; then
        useradd -r -s /bin/false -G gpio pidoors
        ok "Created pidoors user"
    else
        ok "pidoors user exists"
    fi

    # Clone or update from git repo for easy future updates via git pull
    REPO_URL="https://github.com/sybethiesant/pidoors.git"
    if [ -d "$INSTALL_DIR/.git" ]; then
        info "Updating existing git repo..."
        git -C "$INSTALL_DIR" fetch origin
        git -C "$INSTALL_DIR" checkout origin/main -- pidoors/pidoors.py pidoors/readers pidoors/formats 2>/dev/null || true
        cp "$INSTALL_DIR/pidoors/pidoors.py" "$INSTALL_DIR/pidoors.py"
        [ -d "$INSTALL_DIR/pidoors/readers" ] && cp -r "$INSTALL_DIR/pidoors/readers/"* "$INSTALL_DIR/readers/" 2>/dev/null || true
        [ -d "$INSTALL_DIR/pidoors/formats" ] && cp -r "$INSTALL_DIR/pidoors/formats/"* "$INSTALL_DIR/formats/" 2>/dev/null || true
        rm -rf "$INSTALL_DIR/pidoors"
        ok "Controller files updated from git"
    else
        # Fresh install: clone into temp dir, copy source files, init local repo
        TMPCLONE="$(mktemp -d)"
        git clone --depth 1 "$REPO_URL" "$TMPCLONE" -q
        if [ -d "$TMPCLONE/pidoors" ]; then
            cp "$TMPCLONE/pidoors/pidoors.py" "$INSTALL_DIR/"
            [ -d "$TMPCLONE/pidoors/readers" ] && cp -r "$TMPCLONE/pidoors/readers/"* "$INSTALL_DIR/readers/" 2>/dev/null || true
            [ -d "$TMPCLONE/pidoors/formats" ] && cp -r "$TMPCLONE/pidoors/formats/"* "$INSTALL_DIR/formats/" 2>/dev/null || true
        else
            # Fallback to local source files
            if [ -d "$SCRIPT_DIR/pidoors/pidoors" ]; then
                DOOR_SRC="$SCRIPT_DIR/pidoors/pidoors"
            elif [ -d "$SCRIPT_DIR/pidoors" ]; then
                DOOR_SRC="$SCRIPT_DIR/pidoors"
            else
                fail "Cannot find pidoors source directory"
                rm -rf "$TMPCLONE"
                exit 1
            fi
            cp "$DOOR_SRC/pidoors.py" "$INSTALL_DIR/"
            [ -d "$DOOR_SRC/readers" ] && cp -r "$DOOR_SRC/readers/"* "$INSTALL_DIR/readers/" 2>/dev/null || true
            [ -d "$DOOR_SRC/formats" ] && cp -r "$DOOR_SRC/formats/"* "$INSTALL_DIR/formats/" 2>/dev/null || true
        fi
        rm -rf "$TMPCLONE"

        # Init git repo in install dir for future git pull updates
        git init -q "$INSTALL_DIR"
        git -C "$INSTALL_DIR" remote add origin "$REPO_URL"
        git config --global --add safe.directory "$INSTALL_DIR"
        ok "Controller files installed (git repo initialized for updates)"
    fi

    # Write config.json
    cat > "$INSTALL_DIR/conf/config.json" <<EOF
{
    "$DOOR_NAME": {
        $READER_JSON
        "unlock_value": 1,
        "open_delay": $UNLOCK_SEC,
        "latch_gpio": $LATCH_PIN,
        "sqladdr": "$DB_HOST",
        "sqluser": "$DB_USER",
        "sqlpass": "$DB_PASS_DOOR",
        "sqldb": "$DB_NAME"
    }
}
EOF
    ok "Config file written"

    # Write zone.json
    cat > "$INSTALL_DIR/conf/zone.json" <<EOF
{
    "zone": "$DOOR_NAME"
}
EOF
    ok "Zone file written"

    # Permissions
    chown -R pidoors:pidoors "$INSTALL_DIR"
    chmod +x "$INSTALL_DIR/pidoors.py"
    chmod 700 "$INSTALL_DIR/cache"
    chmod 600 "$INSTALL_DIR/conf/config.json"
    ok "Permissions set"

    # Systemd service
    cat > /etc/systemd/system/pidoors.service <<EOF
[Unit]
Description=PiDoors Access Control Service
After=network.target

[Service]
Type=simple
User=pidoors
Group=pidoors
RuntimeDirectory=pidoors
WorkingDirectory=/run/pidoors
ExecStart=/opt/pidoors/venv/bin/python3 /opt/pidoors/pidoors.py
Environment=PIDOORS_DIR=/opt/pidoors
Restart=always
RestartSec=10
SupplementaryGroups=gpio

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable pidoors.service > /dev/null 2>&1
    ok "Systemd service installed and enabled"

    # ── Verify database connection ──

    step "Door Controller: Verification"

    VERIFY_RESULT=$("$INSTALL_DIR/venv/bin/python3" -c "
import sys, json
try:
    import pymysql
    with open('$INSTALL_DIR/conf/config.json') as f:
        cfg = json.load(f)
    zc = cfg['$DOOR_NAME']
    db = pymysql.connect(host=zc['sqladdr'], user=zc['sqluser'], password=zc['sqlpass'], database=zc['sqldb'], connect_timeout=5)
    cursor = db.cursor()
    cursor.execute('SELECT name, status FROM doors WHERE name = %s', ('$DOOR_NAME',))
    row = cursor.fetchone()
    if row:
        print(f'EXISTS:{row[1]}')
    else:
        print('NOT_FOUND')
    db.close()
except Exception as e:
    print(f'ERROR:{e}')
" 2>&1)

    if [[ "$VERIFY_RESULT" == EXISTS:* ]]; then
        STATUS="${VERIFY_RESULT#EXISTS:}"
        ok "Database connection: working"
        ok "Door '$DOOR_NAME' found in database (status: $STATUS)"
    elif [[ "$VERIFY_RESULT" == "NOT_FOUND" ]]; then
        ok "Database connection: working"
        info "Door '$DOOR_NAME' will auto-register on first heartbeat"
    elif [[ "$VERIFY_RESULT" == ERROR:* ]]; then
        ERROR="${VERIFY_RESULT#ERROR:}"
        fail "Database connection failed: $ERROR"
        echo
        echo "  Common fixes:"
        echo "    - Check that the server IP ($DB_HOST) is correct"
        echo "    - Check the database password"
        echo "    - On the server, ensure MariaDB allows remote connections:"
        echo "      Edit /etc/mysql/mariadb.conf.d/50-server.cnf"
        echo "      Set: bind-address = 0.0.0.0"
        echo "      Then: sudo systemctl restart mariadb"
        echo "    - Ensure firewall allows port 3306 from this machine"
        echo
        warn "Fix the connection, then start the service"
    fi

fi

# ============================================================
# COMMON: Firewall, log rotation, backup
# ============================================================

step "System: Firewall and logging"

# Firewall
if command -v ufw > /dev/null 2>&1; then
    if [ "$INSTALL_SERVER" = true ]; then
        ufw allow 80/tcp > /dev/null 2>&1 || true
        ufw allow 443/tcp > /dev/null 2>&1 || true
        ufw allow 3306/tcp > /dev/null 2>&1 || true
        ok "Firewall rules added (HTTP, HTTPS, MySQL)"
    else
        ok "No firewall rules needed for door controller"
    fi
else
    info "UFW not installed, skipping firewall"
fi

# Log rotation
cat > /etc/logrotate.d/pidoors <<'LOGROTATE'
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
        [ -f /var/run/nginx.pid ] && kill -USR1 $(cat /var/run/nginx.pid)
    endscript
}
LOGROTATE
ok "Log rotation configured"

# Backup script (server only)
if [ "$INSTALL_SERVER" = true ]; then
    mkdir -p /var/backups/pidoors
    chown www-data:www-data /var/backups/pidoors
    chmod 750 /var/backups/pidoors

    cat > /usr/local/bin/pidoors-backup.sh <<'BACKUP'
#!/bin/bash
BACKUP_DIR="/var/backups/pidoors"
DATE=$(date +%Y%m%d_%H%M%S)
mkdir -p "$BACKUP_DIR"
mysqldump -u pidoors -p"$1" users > "$BACKUP_DIR/users_$DATE.sql"
mysqldump -u pidoors -p"$1" access > "$BACKUP_DIR/access_$DATE.sql"
tar --exclude='config.php' -czf "$BACKUP_DIR/web_$DATE.tar.gz" /var/www/pidoors
find "$BACKUP_DIR" -name "*.sql" -mtime +30 -delete
find "$BACKUP_DIR" -name "*.tar.gz" -mtime +30 -delete
echo "Backup completed: $DATE"
BACKUP
    chmod +x /usr/local/bin/pidoors-backup.sh
    ok "Backup script installed"
fi

# ============================================================
# Summary
# ============================================================

echo
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  Installation Complete!${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo

if [ "$INSTALL_SERVER" = true ]; then
    SERVER_IP=$(hostname -I | awk '{print $1}')
    echo -e "  ${BOLD}Web Interface${NC}"
    echo -e "    URL:        ${GREEN}http://${SERVER_IP}/${NC}"
    echo -e "    Login:      $ADMIN_EMAIL"
    echo -e "    Web root:   $WEB_ROOT"
    echo -e "    Nginx:      /etc/nginx/sites-available/pidoors"
    echo -e "    Backup:     /usr/local/bin/pidoors-backup.sh"
    echo
fi

if [ "$INSTALL_DOOR" = true ]; then
    echo -e "  ${BOLD}Door Controller${NC}"
    echo -e "    Door name:  ${GREEN}${DOOR_NAME}${NC}"
    echo -e "    Reader:     $READER_TYPE"
    echo -e "    Config:     $INSTALL_DIR/conf/config.json"
    echo -e "    Service:    pidoors.service"
    echo
fi

echo -e "  ${BOLD}Next steps:${NC}"
echo

if [ "$INSTALL_SERVER" = true ] && [ "$INSTALL_DOOR" = true ]; then
    echo "    1. Start the door controller:"
    echo "       sudo systemctl start pidoors"
    echo
    echo "    2. Log in to the web interface at http://${SERVER_IP}/"
    echo
    echo "    3. The door '${DOOR_NAME}' will auto-register within 60 seconds"
    echo "       Open Doors page to see it come online"
    echo
    echo "    4. Add cards in the Cards page with access to '${DOOR_NAME}'"
    echo
elif [ "$INSTALL_SERVER" = true ]; then
    echo "    1. Log in to the web interface at http://${SERVER_IP}/"
    echo
    echo "    2. On each door controller Pi, run:"
    echo "       sudo ./install.sh   (select option 2)"
    echo
    echo "    3. Doors will auto-register when controllers start"
    echo
    echo "    ${YELLOW}Save the database password - you need it for door controllers${NC}"
    echo
elif [ "$INSTALL_DOOR" = true ]; then
    echo "    1. Start the door controller:"
    echo "       sudo systemctl start pidoors"
    echo
    echo "    2. Watch the logs to confirm it connects:"
    echo "       sudo journalctl -u pidoors -f"
    echo
    echo "    3. Within 60 seconds, '${DOOR_NAME}' will appear in the web UI"
    echo "       Open the Doors page to see it come online"
    echo
    echo "    4. If the door doesn't appear, check:"
    echo "       - Is the server reachable? ping ${DB_HOST}"
    echo "       - Is the database password correct?"
    echo "       - Is MariaDB accepting remote connections?"
    echo
fi

echo -e "  ${BOLD}Useful commands:${NC}"
if [ "$INSTALL_DOOR" = true ]; then
    echo "    sudo systemctl start pidoors    # Start the door controller"
    echo "    sudo systemctl stop pidoors     # Stop the door controller"
    echo "    sudo systemctl status pidoors   # Check status"
    echo "    sudo journalctl -u pidoors -f   # Live logs"
fi
if [ "$INSTALL_SERVER" = true ]; then
    echo "    sudo systemctl status nginx     # Web server status"
    echo "    sudo nginx -t                   # Test nginx config"
fi
echo
