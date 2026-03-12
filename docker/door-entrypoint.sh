#!/bin/bash
#
# PiDoors Door Controller Entrypoint — replicates install.sh door controller path
# Sets up /opt/pidoors with config, TLS, and runs pidoors.py with mock GPIO.
#
# Source files are bind-mounted at /src/ (read-only) and copied
# into /opt/pidoors at startup, so code changes are picked up
# on container restart.
#

set -e

INSTALL_DIR="/opt/pidoors"
DOOR_NAME="docker_door"
DB_HOST="server"
DB_USER="pidoors"
DB_PASS="pidoors_pass"
DB_NAME="access"
LISTEN_PORT=8443

log() { echo "[door] $1"; }

# Ensure directories exist (defensive — Dockerfile creates them,
# but brace expansion may fail in some Docker build shells)
mkdir -p "$INSTALL_DIR/conf" "$INSTALL_DIR/cache" "$INSTALL_DIR/readers" "$INSTALL_DIR/formats"

# ──────────────────────────────────────────────
# Wait for server's MariaDB to be ready
# (mirrors install.sh verification, lines 900–941)
# ──────────────────────────────────────────────
log "Waiting for database on $DB_HOST..."
until "$INSTALL_DIR/venv/bin/python3" -c "
import pymysql, sys
try:
    pymysql.connect(host='$DB_HOST', user='$DB_USER', password='$DB_PASS',
                    database='$DB_NAME', connect_timeout=3, ssl_disabled=True)
    sys.exit(0)
except Exception:
    sys.exit(1)
" 2>/dev/null; do
    sleep 2
done
log "Database is ready"

# ──────────────────────────────────────────────
# Copy controller files (mirrors install.sh lines 731–741)
# ──────────────────────────────────────────────
log "Deploying controller files..."
cp /src/pidoors/pidoors.py "$INSTALL_DIR/"
[ -d /src/pidoors/readers ] && cp -r /src/pidoors/readers/* "$INSTALL_DIR/readers/" 2>/dev/null || true
[ -d /src/pidoors/formats ] && cp -r /src/pidoors/formats/* "$INSTALL_DIR/formats/" 2>/dev/null || true
[ -f /src/VERSION ] && cp /src/VERSION "$INSTALL_DIR/"
[ -f /src/pidoors/pidoors-update.sh ] && cp /src/pidoors/pidoors-update.sh "$INSTALL_DIR/" && chmod +x "$INSTALL_DIR/pidoors-update.sh"
chmod +x "$INSTALL_DIR/pidoors.py"

# ──────────────────────────────────────────────
# Generate TLS certificate for push listener
# (mirrors install.sh lines 749–772)
# ──────────────────────────────────────────────
if [ ! -f "$INSTALL_DIR/conf/listener.crt" ]; then
    log "Generating TLS certificate for push listener..."
    openssl genrsa 2048 > "$INSTALL_DIR/conf/listener.key" 2>/dev/null
    openssl req -new -key "$INSTALL_DIR/conf/listener.key" \
        -out /tmp/pidoors-controller.csr \
        -subj "/CN=$DOOR_NAME" 2>/dev/null

    # Try CA-signed via server API first (mirrors install.sh lines 757–771)
    CSR_PEM=$(cat /tmp/pidoors-controller.csr)
    SIGN_RESPONSE=$(curl -sf -k "https://$DB_HOST/api/certs/sign" \
        -H 'Content-Type: application/json' \
        -d "{\"db_user\":\"$DB_USER\",\"db_pass\":\"$DB_PASS\",\"csr\":$("$INSTALL_DIR/venv/bin/python3" -c "import sys,json; print(json.dumps(sys.stdin.read()))" <<< "$CSR_PEM"),\"door_name\":\"$DOOR_NAME\",\"door_ip\":\"$(hostname -i)\"}" \
        2>/dev/null) || SIGN_RESPONSE=""

    if echo "$SIGN_RESPONSE" | "$INSTALL_DIR/venv/bin/python3" -c "import sys,json; cert=json.load(sys.stdin)['cert']; open('$INSTALL_DIR/conf/listener.crt','w').write(cert)" 2>/dev/null; then
        log "TLS certificate signed by PiDoors CA"
    else
        # Fallback to self-signed
        openssl req -x509 -key "$INSTALL_DIR/conf/listener.key" \
            -in /tmp/pidoors-controller.csr \
            -out "$INSTALL_DIR/conf/listener.crt" \
            -days 3650 > /dev/null 2>&1
        log "TLS certificate generated (self-signed — CA signing unavailable)"
    fi
    rm -f /tmp/pidoors-controller.csr
fi

# ──────────────────────────────────────────────
# Download CA cert from server (mirrors install.sh lines 803–812)
# ──────────────────────────────────────────────
if [ ! -f "$INSTALL_DIR/conf/ca.pem" ]; then
    log "Downloading database TLS certificate..."
    if curl -sf -k "https://$DB_HOST/ca.pem" -o "$INSTALL_DIR/conf/ca.pem" 2>/dev/null || \
       curl -sf "http://$DB_HOST/ca.pem" -o "$INSTALL_DIR/conf/ca.pem" 2>/dev/null; then
        log "TLS certificate downloaded — database connections will be encrypted"
    else
        log "WARNING: Could not download CA certificate, connections will be unencrypted"
    fi
fi

# ──────────────────────────────────────────────
# Generate API key (mirrors install.sh line 744)
# ──────────────────────────────────────────────
API_KEY_FILE="$INSTALL_DIR/conf/.api_key"
if [ ! -f "$API_KEY_FILE" ]; then
    openssl rand -hex 32 > "$API_KEY_FILE"
    log "API key generated"
fi
API_KEY=$(cat "$API_KEY_FILE")

# ──────────────────────────────────────────────
# Write config.json (mirrors install.sh lines 775–790)
# ──────────────────────────────────────────────
log "Writing config.json..."
cat > "$INSTALL_DIR/conf/config.json" <<EOF
{
    "$DOOR_NAME": {
        "reader_type": "wiegand",
        "d0": 24,
        "d1": 23,
        "wiegand_format": "auto",
        "unlock_value": 1,
        "open_delay": 5,
        "latch_gpio": 18,
        "sqladdr": "$DB_HOST",
        "sqluser": "$DB_USER",
        "sqlpass": "$DB_PASS",
        "sqldb": "$DB_NAME",
        "api_key": "$API_KEY",
        "listen_port": $LISTEN_PORT
    }
}
EOF

# ──────────────────────────────────────────────
# Write zone.json (mirrors install.sh lines 794–799)
# ──────────────────────────────────────────────
cat > "$INSTALL_DIR/conf/zone.json" <<EOF
{
    "zone": "$DOOR_NAME"
}
EOF

# ──────────────────────────────────────────────
# Set permissions (mirrors install.sh lines 815–825)
# ──────────────────────────────────────────────
chmod 700 "$INSTALL_DIR/cache"
chmod 600 "$INSTALL_DIR/conf/config.json"
chmod 600 "$INSTALL_DIR/conf/listener.key" 2>/dev/null || true
chmod 644 "$INSTALL_DIR/conf/listener.crt" 2>/dev/null || true

# ──────────────────────────────────────────────
# Register door in database (mirrors install.sh lines 874–894)
# ──────────────────────────────────────────────
log "Registering door in database..."
"$INSTALL_DIR/venv/bin/python3" -c "
import pymysql, os
kw = dict(host='$DB_HOST', user='$DB_USER', password='$DB_PASS',
          database='$DB_NAME', connect_timeout=5)
ca_path = '$INSTALL_DIR/conf/ca.pem'
if os.path.isfile(ca_path) and os.path.getsize(ca_path) > 0:
    kw['ssl'] = {'ca': ca_path}
else:
    kw['ssl_disabled'] = True
db = pymysql.connect(**kw)
cur = db.cursor()
cur.execute('''
    INSERT IGNORE INTO doors (name, location, description)
    VALUES ('$DOOR_NAME', 'Docker Test', 'Simulated controller in Docker')
''')
cur.execute('UPDATE doors SET api_key = %s, listen_port = %s WHERE name = %s',
            ('$API_KEY', $LISTEN_PORT, '$DOOR_NAME'))
db.commit()
db.close()
print('[door] Door registered with push API key')
" 2>/dev/null || log "WARNING: Could not register door (will auto-register on first heartbeat)"

# ──────────────────────────────────────────────
# Launch pidoors.py with mock GPIO
# (mirrors install.sh systemd service, line 849)
# Uses mock_gpio.py instead of rpi-lgpio since there's no real GPIO
# ──────────────────────────────────────────────
log "================================================"
log "Starting door controller: $DOOR_NAME"
log "  DB host:    $DB_HOST"
log "  Reader:     wiegand (mock GPIO)"
log "  Listen:     :$LISTEN_PORT"
log "================================================"

cd "$INSTALL_DIR"
exec "$INSTALL_DIR/venv/bin/python3" -c "
# Import mock GPIO before pidoors.py tries to import RPi.GPIO
import sys
sys.path.insert(0, '/opt/pidoors')
import mock_gpio  # registers RPi.GPIO in sys.modules
exec(open('/opt/pidoors/pidoors.py').read())
"
