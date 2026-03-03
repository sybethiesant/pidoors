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
TMPDIR=""

log() {
    echo "[pidoors-update] $1"
    logger -t pidoors-update "$1"
}

update_db_status() {
    local status="$1"
    local version="${2:-}"
    local config_file="$INSTALL_DIR/conf/config.json"

    if [ ! -f "$config_file" ] || [ -z "$ZONE" ]; then
        return
    fi

    # Pass values via environment variables to avoid shell/quote injection issues
    PIDOORS_CONFIG="$config_file" PIDOORS_ZONE="$ZONE" \
    PIDOORS_STATUS="$status" PIDOORS_VERSION="$version" \
    python3 -c "
import os, json, pymysql
cfg = json.load(open(os.environ['PIDOORS_CONFIG']))
zc = cfg[os.environ['PIDOORS_ZONE']]
db = pymysql.connect(host=zc['sqladdr'], user=zc['sqluser'], password=zc['sqlpass'], database=zc['sqldb'], connect_timeout=5)
c = db.cursor()
version = os.environ.get('PIDOORS_VERSION', '')
if version:
    c.execute('UPDATE doors SET update_requested=0, update_status=%s, update_status_time=NOW(), controller_version=%s WHERE name=%s',
              (os.environ['PIDOORS_STATUS'], version, os.environ['PIDOORS_ZONE']))
else:
    c.execute('UPDATE doors SET update_requested=0, update_status=%s, update_status_time=NOW() WHERE name=%s',
              (os.environ['PIDOORS_STATUS'], os.environ['PIDOORS_ZONE']))
db.commit()
db.close()
" 2>/dev/null || log "Warning: failed to update DB status"
}

cleanup() {
    if [ -n "$TMPDIR" ] && [ -d "$TMPDIR" ]; then
        rm -rf "$TMPDIR"
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

# Fetch latest release tag from GitHub
log "Checking latest release..."
LATEST_TAG=$(curl -sf "https://api.github.com/repos/$REPO/releases/latest" | python3 -c "import sys,json; print(json.load(sys.stdin)['tag_name'])" 2>/dev/null) || {
    log "Error: failed to fetch latest release from GitHub"
    update_db_status "failed"
    exit 1
}

# Strip leading 'v' if present
LATEST_VERSION="${LATEST_TAG#v}"
log "Latest version: $LATEST_VERSION"

# Download tarball
TMPDIR=$(mktemp -d /tmp/pidoors-update-XXX)
TARBALL="$TMPDIR/release.tar.gz"
log "Downloading release tarball..."
curl -sfL "https://github.com/$REPO/releases/download/$LATEST_TAG/$LATEST_TAG.tar.gz" -o "$TARBALL" 2>/dev/null || \
curl -sfL "https://github.com/$REPO/archive/refs/tags/$LATEST_TAG.tar.gz" -o "$TARBALL" 2>/dev/null || {
    log "Error: failed to download release"
    update_db_status "failed"
    exit 1
}

# Extract
log "Extracting..."
tar xzf "$TARBALL" -C "$TMPDIR"

# Find the extracted directory (could be pidoors-X.Y.Z or pidoors-vX.Y.Z)
EXTRACTED=$(find "$TMPDIR" -maxdepth 1 -type d -name "pidoors*" | head -1)
if [ -z "$EXTRACTED" ]; then
    log "Error: could not find extracted directory"
    update_db_status "failed"
    exit 1
fi

# Stop the service
log "Stopping pidoors service..."
systemctl stop pidoors || true

# Copy updated files
log "Installing updated files..."
[ -f "$EXTRACTED/pidoors/pidoors.py" ] && cp "$EXTRACTED/pidoors/pidoors.py" "$INSTALL_DIR/"
[ -f "$EXTRACTED/VERSION" ] && cp "$EXTRACTED/VERSION" "$INSTALL_DIR/"
[ -f "$EXTRACTED/pidoors/pidoors-update.sh" ] && cp "$EXTRACTED/pidoors/pidoors-update.sh" "$INSTALL_DIR/" && chmod +x "$INSTALL_DIR/pidoors-update.sh"
[ -d "$EXTRACTED/pidoors/formats" ] && cp -r "$EXTRACTED/pidoors/formats/"* "$INSTALL_DIR/formats/" 2>/dev/null || true
[ -d "$EXTRACTED/pidoors/readers" ] && cp -r "$EXTRACTED/pidoors/readers/"* "$INSTALL_DIR/readers/" 2>/dev/null || true

# Fix ownership
chown -R pidoors:pidoors "$INSTALL_DIR"
chmod +x "$INSTALL_DIR/pidoors.py"

# Read new version
NEW_VERSION="$LATEST_VERSION"
if [ -f "$INSTALL_DIR/VERSION" ]; then
    NEW_VERSION=$(cat "$INSTALL_DIR/VERSION" | tr -d '[:space:]')
fi

# Update database
log "Updating database status..."
update_db_status "success" "$NEW_VERSION"

# Start the service
log "Starting pidoors service..."
systemctl start pidoors

log "Update complete: version $NEW_VERSION"
