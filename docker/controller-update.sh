#!/bin/bash
#
# PiDoors Controller Self-Update (Docker version)
# Replaces pidoors-update.sh inside the Docker container.
#
# Called by pidoors.py when the server requests an update.
# Downloads latest release from GitHub, updates files in-place,
# then exits — Docker auto-restarts the container with new code.
#
# Usage: /opt/pidoors/pidoors-update.sh <zone_name>
#

set -euo pipefail

ZONE="${1:-}"
INSTALL_DIR="/opt/pidoors"
REPO="sybethiesant/pidoors"
TMPDIR="${2:-}"  # May be passed by self-update re-exec

log() {
    echo "[pidoors-update] $1"
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
db = pymysql.connect(host=zc['sqladdr'], user=zc['sqluser'], password=zc['sqlpass'],
                     database=zc['sqldb'], connect_timeout=5, ssl_disabled=True)
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
    LATEST_TAG=$(curl -sf "https://api.github.com/repos/$REPO/releases/latest" \
        | python3 -c "import sys,json; print(json.load(sys.stdin)['tag_name'])" 2>/dev/null) || {
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
        fail "Failed to download release $LATEST_TAG."
    }

    if [ ! -s "$TARBALL" ]; then
        fail "Downloaded tarball is empty."
    fi

    # Extract
    log "Extracting..."
    tar xzf "$TARBALL" -C "$TMPDIR" || {
        fail "Failed to extract tarball."
    }

    EXTRACTED=$(find "$TMPDIR" -mindepth 1 -maxdepth 1 -type d | head -1)
    if [ -z "$EXTRACTED" ]; then
        fail "Could not find extracted directory."
    fi
fi

# Pre-flight checks
SRC_DIR="$EXTRACTED/pidoors"
[ -d "$SRC_DIR" ] || fail "Release missing pidoors/ directory."
[ -f "$SRC_DIR/pidoors.py" ] || fail "Release missing pidoors.py."
[ -f "$EXTRACTED/VERSION" ] || fail "Release missing VERSION file."

log "Pre-flight checks passed."

# --- Self-update: replace this script with the new version FIRST ---
DOCKER_UPDATE_SRC="$EXTRACTED/docker/controller-update.sh"
if [ -f "$DOCKER_UPDATE_SRC" ]; then
    if ! cmp -s "$DOCKER_UPDATE_SRC" "$INSTALL_DIR/pidoors-update.sh" 2>/dev/null; then
        log "Update script has changed — self-updating and re-executing..."
        cp "$DOCKER_UPDATE_SRC" "$INSTALL_DIR/pidoors-update.sh"
        chmod +x "$INSTALL_DIR/pidoors-update.sh"
        exec "$INSTALL_DIR/pidoors-update.sh" "$ZONE" "$TMPDIR"
    fi
fi

# Update files
COPIED=0
FAILED=0

copy_file() {
    local src="$1" dest="$2"
    if cp "$src" "$dest" 2>/dev/null; then
        COPIED=$((COPIED + 1))
    else
        FAILED=$((FAILED + 1))
        log "Failed to copy: $src -> $dest"
    fi
}

# Core files
copy_file "$SRC_DIR/pidoors.py" "$INSTALL_DIR/pidoors.py"

# Formats directory
if [ -d "$SRC_DIR/formats" ]; then
    mkdir -p "$INSTALL_DIR/formats"
    for f in "$SRC_DIR/formats/"*; do
        [ -f "$f" ] && copy_file "$f" "$INSTALL_DIR/formats/$(basename "$f")"
    done
fi

# Readers directory
if [ -d "$SRC_DIR/readers" ]; then
    mkdir -p "$INSTALL_DIR/readers"
    for f in "$SRC_DIR/readers/"*; do
        [ -f "$f" ] && copy_file "$f" "$INSTALL_DIR/readers/$(basename "$f")"
    done
fi

if [ "$FAILED" -gt 0 ]; then
    fail "$FAILED file(s) failed to copy ($COPIED succeeded)."
fi

# Update VERSION last (signals success)
copy_file "$EXTRACTED/VERSION" "$INSTALL_DIR/VERSION"

NEW_VERSION=$(cat "$INSTALL_DIR/VERSION" | tr -d '[:space:]')

# Report success to DB
log "Updating database status..."
update_db_status "success" "$COPIED files updated" "$NEW_VERSION"

log "Update complete: version $NEW_VERSION ($COPIED files copied)"
log "Restarting controller..."

# Kill the controller process — Docker will auto-restart the container
# PID 1 is the entrypoint/python process; killing it triggers container restart
kill 1 2>/dev/null || true
