#!/bin/bash
#
# PiDoors Controller Self-Update Script
# Called by pidoors.py via sudo when server requests an update.
# Must run as root (via sudoers entry).
#
# Usage: sudo /opt/pidoors/pidoors-update.sh <zone_name>
#

set -euo pipefail

ZONE="${1:-}"
INSTALL_DIR="/opt/pidoors"
REPO="sybethiesant/pidoors"
TMPDIR="${2:-}"  # May be passed by self-update re-exec

log() {
    echo "[pidoors-update] $1"
    logger -t pidoors-update "$1"
}

update_db_status() {
    local status="$1"
    local detail="${2:-}"
    local version="${3:-}"
    local config_file="$INSTALL_DIR/conf/config.json"

    if [ ! -f "$config_file" ] || [ -z "$ZONE" ]; then
        return
    fi

    PIDOORS_CONFIG="$config_file" PIDOORS_ZONE="$ZONE" \
    PIDOORS_STATUS="$status" PIDOORS_DETAIL="$detail" PIDOORS_VERSION="$version" \
    python3 -c "
import os, json, pymysql
cfg = json.load(open(os.environ['PIDOORS_CONFIG']))
zc = cfg[os.environ['PIDOORS_ZONE']]
ca_path = os.path.join(os.path.dirname(os.environ['PIDOORS_CONFIG']), 'ca.pem')
db = None
# Try TLS first, fall back to plain
for use_tls in [True, False]:
    try:
        kw = dict(host=zc['sqladdr'], user=zc['sqluser'], password=zc['sqlpass'],
                  database=zc['sqldb'], connect_timeout=5)
        if use_tls and os.path.isfile(ca_path) and os.path.getsize(ca_path) > 0:
            kw['ssl'] = {'ca': ca_path}
        else:
            kw['ssl_disabled'] = True
            if use_tls:
                continue  # No cert, skip straight to plain
        db = pymysql.connect(**kw)
        break
    except Exception as e:
        err = str(e).upper()
        if use_tls and ('SSL' in err or 'CERTIFICATE' in err or 'TLS' in err):
            continue  # TLS failed, try plain
        raise
if db is None:
    raise RuntimeError('Could not connect to database')
c = db.cursor()
version = os.environ.get('PIDOORS_VERSION', '')
detail = os.environ.get('PIDOORS_DETAIL', '')
status = os.environ['PIDOORS_STATUS']
if detail:
    status = status + ': ' + detail
if version:
    c.execute('UPDATE doors SET update_requested=0, update_status=%s, update_status_time=NOW(), controller_version=%s WHERE name=%s',
              (status, version, os.environ['PIDOORS_ZONE']))
else:
    c.execute('UPDATE doors SET update_requested=0, update_status=%s, update_status_time=NOW() WHERE name=%s',
              (status, os.environ['PIDOORS_ZONE']))
db.commit()
db.close()
" 2>/dev/null || log "Warning: failed to update DB status"
}

run_db_migration() {
    local migration_file="$1"
    local config_file="$INSTALL_DIR/conf/config.json"

    if [ ! -f "$migration_file" ]; then
        log "No migration file found, skipping database migration"
        return
    fi

    if [ ! -f "$config_file" ] || [ -z "$ZONE" ]; then
        log "Warning: cannot run migration — missing config or zone"
        return
    fi

    log "Running database migration..."

    PIDOORS_CONFIG="$config_file" PIDOORS_ZONE="$ZONE" \
    PIDOORS_MIGRATION="$migration_file" \
    python3 -c "
import os, json, pymysql

cfg = json.load(open(os.environ['PIDOORS_CONFIG']))
zc = cfg[os.environ['PIDOORS_ZONE']]
ca_path = os.path.join(os.path.dirname(os.environ['PIDOORS_CONFIG']), 'ca.pem')
db = None

# Try TLS first, fall back to plain
for use_tls in [True, False]:
    try:
        kw = dict(host=zc['sqladdr'], user=zc['sqluser'], password=zc['sqlpass'],
                  database=zc['sqldb'], connect_timeout=10)
        if use_tls and os.path.isfile(ca_path) and os.path.getsize(ca_path) > 0:
            kw['ssl'] = {'ca': ca_path}
        else:
            kw['ssl_disabled'] = True
            if use_tls:
                continue
        db = pymysql.connect(**kw)
        break
    except Exception as e:
        err = str(e).upper()
        if use_tls and ('SSL' in err or 'CERTIFICATE' in err or 'TLS' in err):
            continue
        raise
if db is None:
    raise RuntimeError('Could not connect to database')

# Read migration SQL and split into individual statements
with open(os.environ['PIDOORS_MIGRATION'], 'r') as f:
    sql = f.read()

c = db.cursor()
statements = []
current = []
for line in sql.split('\n'):
    stripped = line.strip()
    if stripped.startswith('--') or stripped == '':
        continue
    current.append(line)
    if stripped.endswith(';'):
        stmt = '\n'.join(current).strip()
        if stmt.endswith(';'):
            stmt = stmt[:-1].strip()
        if stmt:
            statements.append(stmt)
        current = []

errors = 0
for stmt in statements:
    try:
        c.execute(stmt)
    except Exception as e:
        # Migrations are idempotent — ignore duplicate column/table errors
        errors += 1

db.commit()
db.close()
print('Migration done: %d statements executed, %d skipped (already applied)' % (len(statements) - errors, errors))
" 2>&1 | while read -r line; do log "$line"; done

    log "Database migration step completed"
}

fail() {
    local message="$1"
    log "Error: $message"
    update_db_status "failed" "$message"
    # Always try to restart the service so the controller isn't left dead
    log "Restarting pidoors service..."
    systemctl start pidoors 2>/dev/null || true
    exit 1
}

cleanup() {
    if [ -n "$TMPDIR" ] && [ -d "$TMPDIR" ]; then
        rm -rf "$TMPDIR"
    fi
    # Always ensure the service is running when the script exits
    if ! systemctl is-active --quiet pidoors 2>/dev/null; then
        log "Ensuring pidoors service is running..."
        systemctl start pidoors 2>/dev/null || true
    fi
}
trap cleanup EXIT

if [ -z "$ZONE" ]; then
    log "Error: zone name required as first argument"
    exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
    log "Error: must run as root"
    exit 1
fi

log "Starting update for zone: $ZONE"

# If TMPDIR was passed (from self-update re-exec), reuse the already-downloaded release
if [ -n "$TMPDIR" ] && [ -d "$TMPDIR" ]; then
    log "Reusing pre-downloaded release from $TMPDIR"
    EXTRACTED=$(find "$TMPDIR" -mindepth 1 -maxdepth 1 -type d | head -1)
    if [ -z "$EXTRACTED" ]; then
        fail "Could not find extracted directory in pre-downloaded temp dir."
    fi
else
    # Fetch latest release tag from GitHub
    log "Checking latest release..."
    LATEST_TAG=$(curl -sf "https://api.github.com/repos/$REPO/releases/latest" | python3 -c "import sys,json; print(json.load(sys.stdin)['tag_name'])" 2>/dev/null) || {
        fail "Could not reach GitHub API. Check internet connection."
    }

    LATEST_VERSION="${LATEST_TAG#v}"
    log "Latest version: $LATEST_VERSION"

    # Download tarball
    TMPDIR=$(mktemp -d /tmp/pidoors-update-XXX)
    TARBALL="$TMPDIR/release.tar.gz"
    log "Downloading release tarball..."
    curl -sfL "https://github.com/$REPO/releases/download/$LATEST_TAG/$LATEST_TAG.tar.gz" -o "$TARBALL" 2>/dev/null || \
    curl -sfL "https://github.com/$REPO/archive/refs/tags/$LATEST_TAG.tar.gz" -o "$TARBALL" 2>/dev/null || {
        fail "Failed to download release $LATEST_TAG. Tag may not exist on GitHub."
    }

    if [ ! -s "$TARBALL" ]; then
        fail "Downloaded tarball is empty."
    fi

    # Extract
    log "Extracting..."
    tar xzf "$TARBALL" -C "$TMPDIR" || {
        fail "Failed to extract tarball. Download may be corrupt."
    }

    # Find the extracted directory (mindepth 1 to skip TMPDIR itself which also matches pidoors*)
    EXTRACTED=$(find "$TMPDIR" -mindepth 1 -maxdepth 1 -type d | head -1)
    if [ -z "$EXTRACTED" ]; then
        fail "Could not find extracted directory in tarball."
    fi
fi

# --- Pre-flight: verify source files exist before stopping the service ---
SRC_DIR="$EXTRACTED/pidoors"
if [ ! -d "$SRC_DIR" ]; then
    fail "Release archive missing pidoors/ directory. Bad release?"
fi

if [ ! -f "$SRC_DIR/pidoors.py" ]; then
    fail "Release archive missing pidoors/pidoors.py. Bad release?"
fi

if [ ! -f "$EXTRACTED/VERSION" ]; then
    fail "Release archive missing VERSION file. Bad release?"
fi

log "Pre-flight checks passed."

# --- Self-update: replace this script with the new version FIRST ---
# This ensures any future changes to the update process take effect
# before the rest of the update runs.
if [ -f "$SRC_DIR/pidoors-update.sh" ]; then
    if ! cmp -s "$SRC_DIR/pidoors-update.sh" "$INSTALL_DIR/pidoors-update.sh" 2>/dev/null; then
        log "Update script has changed — self-updating and re-executing..."
        cp "$SRC_DIR/pidoors-update.sh" "$INSTALL_DIR/pidoors-update.sh"
        chmod +x "$INSTALL_DIR/pidoors-update.sh"
        chown pidoors:pidoors "$INSTALL_DIR/pidoors-update.sh"
        # Re-run the new script with the same arguments, passing the
        # already-downloaded temp dir to skip re-downloading
        exec "$INSTALL_DIR/pidoors-update.sh" "$ZONE" "$TMPDIR"
    fi
fi

# If we were re-executed with a pre-downloaded temp dir, skip the download
# (this variable is already set from the initial run)

# Stop the service
log "Stopping pidoors service..."
systemctl stop pidoors || true

# Copy updated files with verification
FAILED=0
COPIED=0

copy_file() {
    local src="$1"
    local dest="$2"
    if cp "$src" "$dest" 2>/dev/null; then
        COPIED=$((COPIED + 1))
    else
        FAILED=$((FAILED + 1))
        log "Failed to copy: $src -> $dest"
    fi
}

# Core files
copy_file "$SRC_DIR/pidoors.py" "$INSTALL_DIR/pidoors.py"

if [ -f "$SRC_DIR/pidoors-update.sh" ]; then
    copy_file "$SRC_DIR/pidoors-update.sh" "$INSTALL_DIR/pidoors-update.sh"
    chmod +x "$INSTALL_DIR/pidoors-update.sh" 2>/dev/null || true
fi

# Service file (picks up dependency fixes, security hardening changes)
if [ -f "$SRC_DIR/pidoors.service" ]; then
    copy_file "$SRC_DIR/pidoors.service" "/etc/systemd/system/pidoors.service"
    systemctl daemon-reload 2>/dev/null || true
fi

# Optional directories
if [ -d "$SRC_DIR/formats" ]; then
    mkdir -p "$INSTALL_DIR/formats"
    for f in "$SRC_DIR/formats/"*; do
        [ -f "$f" ] && copy_file "$f" "$INSTALL_DIR/formats/$(basename "$f")"
    done
fi

if [ -d "$SRC_DIR/readers" ]; then
    mkdir -p "$INSTALL_DIR/readers"
    for f in "$SRC_DIR/readers/"*; do
        [ -f "$f" ] && copy_file "$f" "$INSTALL_DIR/readers/$(basename "$f")"
    done
fi

# Database migration file (prefer pidoors/ copy, fall back to root)
if [ -f "$SRC_DIR/database_migration.sql" ]; then
    copy_file "$SRC_DIR/database_migration.sql" "$INSTALL_DIR/database_migration.sql"
elif [ -f "$EXTRACTED/database_migration.sql" ]; then
    copy_file "$EXTRACTED/database_migration.sql" "$INSTALL_DIR/database_migration.sql"
fi

# Check for failures before updating VERSION
if [ "$FAILED" -gt 0 ]; then
    # Fix ownership on whatever was copied, then restart
    chown -R pidoors:pidoors "$INSTALL_DIR"
    chmod +x "$INSTALL_DIR/pidoors.py" 2>/dev/null || true
    fail "$FAILED file(s) failed to copy ($COPIED succeeded). Check disk space and permissions."
fi

# All copies succeeded — now update VERSION
copy_file "$EXTRACTED/VERSION" "$INSTALL_DIR/VERSION"

# Fix ownership
chown -R pidoors:pidoors "$INSTALL_DIR"
chmod +x "$INSTALL_DIR/pidoors.py"

# Remove orphaned files from managed directories
REMOVED=0
for dir in formats readers; do
    if [ -d "$INSTALL_DIR/$dir" ] && [ -d "$SRC_DIR/$dir" ]; then
        for f in "$INSTALL_DIR/$dir/"*; do
            [ -f "$f" ] || continue
            base="$(basename "$f")"
            if [ ! -f "$SRC_DIR/$dir/$base" ]; then
                rm -f "$f" && REMOVED=$((REMOVED + 1))
                log "Removed orphaned file: $dir/$base"
            fi
        done
    fi
done
if [ "$REMOVED" -gt 0 ]; then
    log "Cleaned up $REMOVED orphaned file(s)"
fi

# Read new version
NEW_VERSION="${LATEST_VERSION:-}"
if [ -f "$INSTALL_DIR/VERSION" ]; then
    NEW_VERSION=$(cat "$INSTALL_DIR/VERSION" | tr -d '[:space:]')
fi

# ── Upgrade path: generate push listener cert/key/api_key if missing ──
CONF_DIR="$INSTALL_DIR/conf"

if [ ! -f "$CONF_DIR/listener.crt" ] || [ ! -f "$CONF_DIR/listener.key" ]; then
    log "Generating TLS certificate for push listener..."
    openssl req -x509 -newkey rsa:2048 -nodes \
        -keyout "$CONF_DIR/listener.key" \
        -out "$CONF_DIR/listener.crt" \
        -days 3650 -subj "/CN=$ZONE" \
        > /dev/null 2>&1 && log "TLS certificate generated" || log "Warning: TLS cert generation failed"
    chown pidoors:pidoors "$CONF_DIR/listener.key" "$CONF_DIR/listener.crt" 2>/dev/null || true
    chmod 600 "$CONF_DIR/listener.key" 2>/dev/null || true
    chmod 644 "$CONF_DIR/listener.crt" 2>/dev/null || true
fi

# Add api_key and listen_port to config.json if missing
if [ -f "$CONF_DIR/config.json" ]; then
    python3 -c "
import json, os, subprocess
config_path = '$CONF_DIR/config.json'
zone = '$ZONE'
cfg = json.load(open(config_path))
zc = cfg.get(zone, {})
changed = False
if 'api_key' not in zc:
    zc['api_key'] = subprocess.check_output(['openssl', 'rand', '-hex', '32']).decode().strip()
    changed = True
if 'listen_port' not in zc:
    zc['listen_port'] = 8443
    changed = True
if changed:
    cfg[zone] = zc
    with open(config_path, 'w') as f:
        json.dump(cfg, f, indent=4)
    # Store API key in database
    try:
        import pymysql
        ca_path = os.path.join('$CONF_DIR', 'ca.pem')
        kw = dict(host=zc['sqladdr'], user=zc['sqluser'], password=zc['sqlpass'],
                  database=zc['sqldb'], connect_timeout=5)
        if os.path.isfile(ca_path) and os.path.getsize(ca_path) > 0:
            kw['ssl'] = {'ca': ca_path}
        else:
            kw['ssl_disabled'] = True
        db = pymysql.connect(**kw)
        c = db.cursor()
        c.execute('UPDATE doors SET api_key = %s, listen_port = %s WHERE name = %s',
                  (zc['api_key'], zc['listen_port'], zone))
        db.commit()
        db.close()
        print('Push API key registered in database')
    except Exception as e:
        print(f'Warning: could not register API key: {e}')
    print('Push config added to config.json')
else:
    print('Push config already present')
" 2>&1 | while read -r line; do log "$line"; done
fi

# Open firewall port if ufw is installed
if command -v ufw > /dev/null 2>&1; then
    ufw allow 8443/tcp > /dev/null 2>&1 && log "Firewall: port 8443/tcp opened" || true
fi

# Run database migration before marking success
run_db_migration "$INSTALL_DIR/database_migration.sql"

# Update database — only mark success after everything worked
log "Updating database status..."
update_db_status "success" "$COPIED files updated" "$NEW_VERSION"

# Start the service
log "Starting pidoors service..."
systemctl start pidoors || {
    update_db_status "failed" "Files updated but service failed to start" "$NEW_VERSION"
    log "Error: service failed to start after update"
    exit 1
}

log "Update complete: version $NEW_VERSION ($COPIED files copied)"
