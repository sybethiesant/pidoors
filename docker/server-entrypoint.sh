#!/bin/bash
#
# PiDoors Server Entrypoint — replicates install.sh server path
# Runs MariaDB + PHP-FPM + Nginx all in one container, just like
# a real Raspberry Pi running install.sh option 1 (or 3).
#
# Source files are bind-mounted at /src/ (read-only) and copied
# into their install paths at startup, so code changes are picked
# up on container restart.
#

set -e

DB_USER="pidoors"
DB_PASS="pidoors_pass"
DB_ROOT_PASS="pidoors_root_pass"
ADMIN_EMAIL="admin@pidoors.local"
ADMIN_PASS="PiDoors2024!"
WEB_ROOT="/var/www/pidoors"
WEB_UI_ROOT="/var/www/pidoors-ui"
# TLS certs stored inside the DB volume so they persist across rebuilds
CERT_DIR="/var/lib/mysql/ssl"
MARKER="/var/lib/mysql/.pidoors-initialized"

log() { echo "[server] $1"; }

# ──────────────────────────────────────────────
# Detect PHP version (mirrors install.sh line 168)
# ──────────────────────────────────────────────
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
PHP_FPM_SOCK="/var/run/php/php${PHP_VERSION}-fpm.sock"
log "PHP version: $PHP_VERSION"

# ──────────────────────────────────────────────
# Start MariaDB (mirrors install.sh lines 172–178)
# ──────────────────────────────────────────────
log "Starting MariaDB..."

# Ensure data directory exists with correct ownership
mkdir -p /var/run/mysqld
chown mysql:mysql /var/run/mysqld

# First-run: initialize data directory if empty
if [ ! -d "/var/lib/mysql/mysql" ]; then
    log "Initializing MariaDB data directory..."
    mysql_install_db --user=mysql --datadir=/var/lib/mysql > /dev/null 2>&1
fi

# Start MariaDB in background
mysqld_safe --skip-syslog &
MARIADB_PID=$!

# Wait for MariaDB to be ready
log "Waiting for MariaDB to accept connections..."
for i in $(seq 1 60); do
    if mysqladmin ping --silent 2>/dev/null; then
        break
    fi
    sleep 1
done

if ! mysqladmin ping --silent 2>/dev/null; then
    log "ERROR: MariaDB failed to start"
    exit 1
fi
log "MariaDB is ready"

# ──────────────────────────────────────────────
# First-run: Database setup
# (mirrors install.sh lines 211–405)
# ──────────────────────────────────────────────

if [ ! -f "$MARKER" ]; then
    log "First run — setting up databases..."

    # ── Set root password (mirrors install.sh line 319–327) ──
    mysql -u root <<EOF
ALTER USER 'root'@'localhost' IDENTIFIED BY '$DB_ROOT_PASS';
FLUSH PRIVILEGES;
EOF

    # ── Create databases and user (mirrors install.sh lines 336–346) ──
    log "Creating databases and user..."
    MYSQL_PWD="$DB_ROOT_PASS" mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS users;
CREATE DATABASE IF NOT EXISTS access;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON users.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON access.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON users.* TO '$DB_USER'@'%';
GRANT ALL PRIVILEGES ON access.* TO '$DB_USER'@'%';
FLUSH PRIVILEGES;
EOF

    # ── Create users table schema (mirrors install.sh lines 351–378) ──
    log "Creating users table schema..."
    MYSQL_PWD="$DB_ROOT_PASS" mysql -u root users <<EOF
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

    # ── Run access database migration (mirrors install.sh lines 381–405) ──
    log "Running access database migration..."
    MYSQL_PWD="$DB_ROOT_PASS" mysql -u root access < /src/database_migration.sql

    # Verify critical tables
    TABLES_OK=$(MYSQL_PWD="$DB_ROOT_PASS" mysql -u root -N -e \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='access' AND table_name IN ('cards','doors','logs','settings')" 2>/dev/null)
    if [ "$TABLES_OK" -lt 4 ] 2>/dev/null; then
        log "ERROR: Migration ran but critical tables are missing (expected 4, found ${TABLES_OK:-0})"
        exit 1
    fi
    log "Tables created and verified ($TABLES_OK core tables)"

    # ── Admin user (mirrors install.sh lines 504–514) ──
    log "Creating admin user..."
    ADMIN_EMAIL_ENV="$ADMIN_EMAIL" ADMIN_PASS_ENV="$ADMIN_PASS" DB_PASS_ENV="$DB_PASS" \
    php -r '
        $email = getenv("ADMIN_EMAIL_ENV");
        $pass  = getenv("ADMIN_PASS_ENV");
        $dbpass = getenv("DB_PASS_ENV");
        $hash  = password_hash($pass, PASSWORD_BCRYPT, ["cost" => 12]);
        $pdo   = new PDO("mysql:host=localhost;dbname=users", "pidoors", $dbpass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt  = $pdo->prepare("INSERT INTO users (user_name, user_email, user_pass, admin, active) VALUES (\"Admin\", ?, ?, 1, 1) ON DUPLICATE KEY UPDATE user_pass=VALUES(user_pass)");
        $stmt->execute([$email, $hash]);
    '

    # ── Example data (mirrors install.sh lines 524–554) ──
    log "Installing example data..."
    EXAMPLE_PASS=$(php -r 'echo password_hash("password123", PASSWORD_BCRYPT, ["cost" => 12]);')

    MYSQL_PWD="$DB_PASS" mysql -u "$DB_USER" users <<EOF
INSERT IGNORE INTO users (user_name, user_email, user_pass, first_name, last_name, department, company, job_title, admin, active)
VALUES ('jsmith', 'jsmith@example.com', '$EXAMPLE_PASS', 'John', 'Smith', 'Engineering', 'Acme Corp', 'Engineer', 0, 1);
EOF

    MYSQL_PWD="$DB_PASS" mysql -u "$DB_USER" access <<EOF
INSERT IGNORE INTO doors (name, location, description, reader_type, unlock_duration, status)
VALUES ('front_door', 'Main Entrance', 'Front door with card reader', 'wiegand', 5, 'unknown');

INSERT IGNORE INTO cards (card_id, user_id, facility, firstname, lastname, department, group_id, active)
VALUES ('12345678', 'EMP001', '100', 'Jane', 'Doe', 'Security', 1, 1);

INSERT IGNORE INTO cards (card_id, user_id, facility, firstname, lastname, department, group_id, schedule_id, active)
VALUES ('87654321', 'EMP002', '100', 'Bob', 'Wilson', 'Engineering', 2, 2, 1);

INSERT IGNORE INTO master_cards (card_id, user_id, facility, description, active)
VALUES ('12345678', 'EMP001', '100', 'Jane Doe', 1);

INSERT IGNORE INTO holidays (name, date, recurring, access_denied)
VALUES ('New Year''s Day', '2026-01-01', 1, 1);
EOF

    touch "$MARKER"
    log "First-run DB setup complete"
else
    log "Database already initialized, skipping DB setup"
fi

# ──────────────────────────────────────────────
# TLS Certificates — generated if missing (survive in db_data volume)
# (mirrors install.sh lines 213–314)
# ──────────────────────────────────────────────

NEED_MARIADB_RESTART=false

# ── MariaDB TLS (mirrors install.sh lines 213–258) ──
if [ ! -f "$CERT_DIR/ca.pem" ]; then
    log "Generating MariaDB TLS certificates..."
    mkdir -p "$CERT_DIR"

    # Generate CA key and cert
    openssl genrsa 2048 > "$CERT_DIR/ca-key.pem" 2>/dev/null
    openssl req -new -x509 -nodes -days 3650 \
        -key "$CERT_DIR/ca-key.pem" \
        -out "$CERT_DIR/ca.pem" \
        -subj "/CN=PiDoors CA" 2>/dev/null

    # Generate server key and cert signed by CA
    openssl genrsa 2048 > "$CERT_DIR/server-key.pem" 2>/dev/null
    openssl req -new -key "$CERT_DIR/server-key.pem" \
        -out "$CERT_DIR/server-req.pem" \
        -subj "/CN=PiDoors DB Server" 2>/dev/null
    openssl x509 -req -days 3650 \
        -in "$CERT_DIR/server-req.pem" \
        -CA "$CERT_DIR/ca.pem" \
        -CAkey "$CERT_DIR/ca-key.pem" \
        -CAcreateserial \
        -out "$CERT_DIR/server-cert.pem" 2>/dev/null

    # www-data needs read access to ca-key.pem for cert signing API,
    # and write access to the directory for the ca.srl serial file
    chown mysql:www-data "$CERT_DIR"
    chmod 770 "$CERT_DIR"
    chown mysql:mysql "$CERT_DIR"/server-*.pem
    chown mysql:www-data "$CERT_DIR"/ca-key.pem "$CERT_DIR"/ca.pem
    chmod 600 "$CERT_DIR"/server-key.pem
    chmod 640 "$CERT_DIR"/ca-key.pem
    chmod 644 "$CERT_DIR/ca.pem" "$CERT_DIR/server-cert.pem"
    rm -f "$CERT_DIR/server-req.pem" "$CERT_DIR/ca.srl"
    log "MariaDB TLS certificates generated"
    NEED_MARIADB_RESTART=true
fi

# Ensure CA key is always readable by www-data (fix existing volumes)
if [ -f "$CERT_DIR/ca-key.pem" ]; then
    chown mysql:www-data "$CERT_DIR" "$CERT_DIR"/ca-key.pem "$CERT_DIR"/ca.pem 2>/dev/null || true
    chmod 770 "$CERT_DIR" 2>/dev/null || true
    chmod 640 "$CERT_DIR"/ca-key.pem 2>/dev/null || true
fi

# Configure MariaDB TLS + remote access (only on first setup)
MARIADB_CNF="/etc/mysql/mariadb.conf.d/50-server.cnf"
if [ -f "$MARIADB_CNF" ] && ! grep -q "ssl-ca" "$MARIADB_CNF"; then
    sed -i "/^\[mysqld\]/a ssl-ca = ${CERT_DIR}/ca.pem\nssl-cert = ${CERT_DIR}/server-cert.pem\nssl-key = ${CERT_DIR}/server-key.pem" "$MARIADB_CNF"
    NEED_MARIADB_RESTART=true
fi
if [ -f "$MARIADB_CNF" ] && grep -q "^bind-address\s*=\s*127.0.0.1" "$MARIADB_CNF"; then
    sed -i 's/^bind-address\s*=\s*127.0.0.1/bind-address = 0.0.0.0/' "$MARIADB_CNF"
    NEED_MARIADB_RESTART=true
fi

if [ "$NEED_MARIADB_RESTART" = true ]; then
    log "Restarting MariaDB with TLS and remote access..."
    mysqladmin -u root -p"$DB_ROOT_PASS" shutdown 2>/dev/null || true
    sleep 2
    mysqld_safe --skip-syslog &
    for i in $(seq 1 30); do
        mysqladmin ping --silent 2>/dev/null && break
        sleep 1
    done
    log "MariaDB restarted"
fi

# ── Nginx TLS (mirrors install.sh lines 264–314) ──
# Stored in volume; symlinked to where Nginx expects them
if [ ! -f "$CERT_DIR/nginx.key" ]; then
    log "Generating Nginx TLS certificate..."
    openssl genrsa 2048 > "$CERT_DIR/nginx.key" 2>/dev/null
    openssl req -new -key "$CERT_DIR/nginx.key" \
        -out /tmp/pidoors-nginx.csr \
        -subj "/CN=PiDoors Web" 2>/dev/null

    cat > /tmp/pidoors-nginx-ext.cnf <<SANEOF
subjectAltName = DNS:pidoors,DNS:pidoors.local,DNS:localhost,DNS:server,IP:127.0.0.1
SANEOF

    openssl x509 -req -days 3650 \
        -in /tmp/pidoors-nginx.csr \
        -CA "$CERT_DIR/ca.pem" \
        -CAkey "$CERT_DIR/ca-key.pem" \
        -CAcreateserial \
        -out "$CERT_DIR/nginx.crt" \
        -extfile /tmp/pidoors-nginx-ext.cnf 2>/dev/null

    rm -f /tmp/pidoors-nginx.csr /tmp/pidoors-nginx-ext.cnf "$CERT_DIR/ca.srl"
    chmod 600 "$CERT_DIR/nginx.key"
    chmod 644 "$CERT_DIR/nginx.crt"
    log "Nginx TLS certificate generated"
fi

# Symlink certs to standard paths Nginx config expects
mkdir -p /etc/ssl/private /etc/ssl/certs
ln -sf "$CERT_DIR/nginx.key" /etc/ssl/private/pidoors.key
ln -sf "$CERT_DIR/nginx.crt" /etc/ssl/certs/pidoors.crt

# Symlink CA certs to /etc/mysql/ssl/ — api.php cert signing endpoint expects them here
# (on bare-metal installs, install.sh creates certs directly at /etc/mysql/ssl/)
ln -sfn "$CERT_DIR" /etc/mysql/ssl

# ──────────────────────────────────────────────
# Deploy web files (every startup, mirrors install.sh lines 411–421)
# ──────────────────────────────────────────────
log "Deploying web files..."
cp -r /src/pidoorserv/* "$WEB_ROOT/"
[ -f /src/VERSION ] && cp /src/VERSION "$WEB_ROOT/"

# Copy CA cert to web root for door controllers (mirrors install.sh lines 417–420)
if [ -f "$CERT_DIR/ca.pem" ]; then
    cp "$CERT_DIR/ca.pem" "$WEB_ROOT/ca.pem"
    chmod 644 "$WEB_ROOT/ca.pem"
fi

# ── Configure PHP (mirrors install.sh lines 469–478) ──
# Always regenerate from template — repo may ship a config.php with empty password
cp "$WEB_ROOT/includes/config.php.example" "$WEB_ROOT/includes/config.php"
sed -i "s/'sqlpass' => ''/'sqlpass' => '$DB_PASS'/g" "$WEB_ROOT/includes/config.php"
sed -i "s|'url' => 'http://localhost'|'url' => 'https://localhost'|g" "$WEB_ROOT/includes/config.php"
chmod 640 "$WEB_ROOT/includes/config.php"
chown www-data:www-data "$WEB_ROOT/includes/config.php"
chown -R www-data:www-data "$WEB_ROOT"

# ── Build React UI (mirrors install.sh lines 436–466) ──
if [ -d /src/pidoors-ui ] && [ -f /src/pidoors-ui/package.json ]; then
    # Only rebuild if source is newer than last build
    if [ ! -f "$WEB_UI_ROOT/.build-stamp" ] || \
       [ /src/pidoors-ui/package.json -nt "$WEB_UI_ROOT/.build-stamp" ] || \
       [ /src/pidoors-ui/src -nt "$WEB_UI_ROOT/.build-stamp" ] 2>/dev/null; then
        log "Building React UI..."
        BUILD_DIR=$(mktemp -d /tmp/pidoors-ui-build-XXXXXX)
        cp -r /src/pidoors-ui/* "$BUILD_DIR/"
        [ -f /src/pidoors-ui/.env ] && cp /src/pidoors-ui/.env "$BUILD_DIR/"

        if (cd "$BUILD_DIR" && npm install --production=false --loglevel=error) > /dev/null 2>&1 && \
           (cd "$BUILD_DIR" && npm run build) > /dev/null 2>&1; then
            mkdir -p "$WEB_UI_ROOT"
            rm -rf "${WEB_UI_ROOT:?}"/*
            cp -r "$BUILD_DIR/dist/"* "$WEB_UI_ROOT/"
            touch "$WEB_UI_ROOT/.build-stamp"
            log "React UI built and deployed"
        else
            log "WARNING: React UI build failed — UI may not be available"
        fi
        rm -rf "$BUILD_DIR"
    else
        log "React UI unchanged, skipping build"
    fi
    chown -R www-data:www-data "$WEB_UI_ROOT"
else
    log "WARNING: pidoors-ui source not found, skipping React build"
fi

# ── Download vendor CSS/JS (mirrors install.sh lines 424–432) ──
if [ ! -f "$WEB_ROOT/js/jquery-3.5.1.js" ]; then
    log "Downloading front-end libraries..."
    mkdir -p "$WEB_ROOT/css" "$WEB_ROOT/js"
    curl -sL "https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" -o "$WEB_ROOT/css/bootstrap.min.css" || true
    curl -sL "https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" -o "$WEB_ROOT/css/dataTables.bootstrap5.min.css" || true
    curl -sL "https://code.jquery.com/jquery-3.5.1.min.js" -o "$WEB_ROOT/js/jquery-3.5.1.js" || true
    curl -sL "https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" -o "$WEB_ROOT/js/bootstrap.bundle.min.js" || true
    curl -sL "https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js" -o "$WEB_ROOT/js/jquery.dataTables.min.js" || true
    curl -sL "https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js" -o "$WEB_ROOT/js/dataTables.bootstrap5.min.js" || true
    curl -sL "https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" -o "$WEB_ROOT/js/Chart.min.js" || true
    log "Front-end libraries downloaded"
fi

# ──────────────────────────────────────────────
# Configure Nginx (mirrors install.sh lines 484–493)
# Uses the real nginx/pidoors.conf template with PHP socket substitution
# ──────────────────────────────────────────────
log "Configuring Nginx..."
if [ -f /src/nginx/pidoors.conf ]; then
    sed "s|unix:/var/run/php/php-fpm.sock|unix:${PHP_FPM_SOCK}|g" \
        /src/nginx/pidoors.conf > /etc/nginx/sites-available/pidoors
fi
rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/pidoors /etc/nginx/sites-enabled/pidoors

# Ensure PHP-FPM run directory exists
mkdir -p /var/run/php

# ──────────────────────────────────────────────
# Start PHP-FPM (mirrors install.sh lines 174–175)
# ──────────────────────────────────────────────
log "Starting PHP-FPM ${PHP_VERSION}..."
php-fpm${PHP_VERSION} -D

# Wait for socket
for i in $(seq 1 15); do
    [ -S "$PHP_FPM_SOCK" ] && break
    sleep 1
done

if [ ! -S "$PHP_FPM_SOCK" ]; then
    log "ERROR: PHP-FPM socket not found at $PHP_FPM_SOCK"
    exit 1
fi
log "PHP-FPM ready"

# ──────────────────────────────────────────────
# Start Nginx (mirrors install.sh lines 172–173)
# ──────────────────────────────────────────────
log "Testing Nginx config..."
nginx -t 2>&1 || { log "ERROR: Nginx config test failed"; exit 1; }
log "Starting Nginx..."
nginx -g "daemon off;" &
NGINX_PID=$!

log "================================================"
log "PiDoors server is running"
log "  HTTPS: https://localhost:443"
log "  HTTP:  http://localhost:80 (redirects to HTTPS)"
log "  Admin: $ADMIN_EMAIL / $ADMIN_PASS"
log "================================================"

# ──────────────────────────────────────────────
# Monitor processes — exit if any critical process dies
# ──────────────────────────────────────────────
while true; do
    # Check MariaDB
    if ! mysqladmin ping --silent -u root -p"$DB_ROOT_PASS" 2>/dev/null; then
        log "ERROR: MariaDB died, exiting"
        exit 1
    fi
    # Check Nginx
    if ! kill -0 "$NGINX_PID" 2>/dev/null; then
        log "ERROR: Nginx died, exiting"
        exit 1
    fi
    # Check PHP-FPM socket
    if [ ! -S "$PHP_FPM_SOCK" ]; then
        log "ERROR: PHP-FPM died, exiting"
        exit 1
    fi
    sleep 10
done
