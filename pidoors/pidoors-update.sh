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
db = pymysql.connect(host=zc['sqladdr'], user=zc['sqluser'], password=zc['sqlpass'], database=zc['sqldb'], connect_timeout=5)
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

# Find the extracted directory
EXTRACTED=$(find "$TMPDIR" -maxdepth 1 -type d -name "pidoors*" | head -1)
if [ -z "$EXTRACTED" ]; then
    fail "Could not find extracted directory in tarball."
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

# Read new version
NEW_VERSION="$LATEST_VERSION"
if [ -f "$INSTALL_DIR/VERSION" ]; then
    NEW_VERSION=$(cat "$INSTALL_DIR/VERSION" | tr -d '[:space:]')
fi

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
