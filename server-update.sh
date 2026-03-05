#!/bin/bash
#
# PiDoors Server Update Script
# Updates the web interface and runs database migrations.
# Must run as root.
#
# Usage: sudo ./server-update.sh [--db-pass PASSWORD]
#

set -euo pipefail

REPO="sybethiesant/pidoors"
WEB_ROOT="/var/www/pidoors"
BACKUP_DIR="/var/backups/pidoors"
TMPDIR=""

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
fail() { echo -e "  ${RED}✗${NC} $1"; }
warn() { echo -e "  ${YELLOW}!${NC} $1"; }
info() { echo -e "  ${BLUE}→${NC} $1"; }

cleanup() {
    if [ -n "$TMPDIR" ] && [ -d "$TMPDIR" ]; then
        rm -rf "$TMPDIR"
    fi
}
trap cleanup EXIT

# ──────────────────────────────────────────────
# Parse arguments
# ──────────────────────────────────────────────

DB_PASS_ARG=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --db-pass)
            DB_PASS_ARG="$2"
            shift 2
            ;;
        *)
            fail "Unknown argument: $1"
            echo "  Usage: sudo ./server-update.sh [--db-pass PASSWORD]"
            exit 1
            ;;
    esac
done

# ──────────────────────────────────────────────
# Pre-flight checks
# ──────────────────────────────────────────────

echo
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  PiDoors Server Update${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo

if [ "$(id -u)" -ne 0 ]; then
    fail "This script must be run as root: ${BOLD}sudo ./server-update.sh${NC}"
    exit 1
fi
ok "Running as root"

if [ ! -d "$WEB_ROOT" ]; then
    fail "Web root not found at $WEB_ROOT"
    fail "Is PiDoors installed? Run install.sh first."
    exit 1
fi
ok "Web root exists: $WEB_ROOT"

# Read current version
CURRENT_VERSION="unknown"
if [ -f "$WEB_ROOT/VERSION" ]; then
    CURRENT_VERSION=$(cat "$WEB_ROOT/VERSION" | tr -d '[:space:]')
fi
info "Current version: $CURRENT_VERSION"

# ──────────────────────────────────────────────
# Fetch latest release
# ──────────────────────────────────────────────

info "Checking latest release on GitHub..."
LATEST_TAG=$(curl -sf "https://api.github.com/repos/$REPO/releases/latest" | python3 -c "import sys,json; print(json.load(sys.stdin)['tag_name'])" 2>/dev/null) || {
    fail "Could not reach GitHub API. Check internet connection."
    exit 1
}

LATEST_VERSION="${LATEST_TAG#v}"
ok "Latest version: $LATEST_VERSION"

if [ "$CURRENT_VERSION" = "$LATEST_VERSION" ]; then
    echo
    warn "Already running version $CURRENT_VERSION"
    read -p "  Update anyway? (y/N) " -n 1 -r
    echo
    [[ ! $REPLY =~ ^[Yy]$ ]] && { echo "  Aborted."; exit 0; }
fi

# ──────────────────────────────────────────────
# Download and extract
# ──────────────────────────────────────────────

TMPDIR=$(mktemp -d /tmp/pidoors-server-update-XXX)
TARBALL="$TMPDIR/release.tar.gz"

info "Downloading release $LATEST_TAG..."
curl -sfL "https://github.com/$REPO/releases/download/$LATEST_TAG/$LATEST_TAG.tar.gz" -o "$TARBALL" 2>/dev/null || \
curl -sfL "https://github.com/$REPO/archive/refs/tags/$LATEST_TAG.tar.gz" -o "$TARBALL" 2>/dev/null || {
    fail "Failed to download release $LATEST_TAG"
    exit 1
}

if [ ! -s "$TARBALL" ]; then
    fail "Downloaded tarball is empty"
    exit 1
fi
ok "Downloaded"

info "Extracting..."
tar xzf "$TARBALL" -C "$TMPDIR" || {
    fail "Failed to extract tarball"
    exit 1
}

# Find extracted directory
EXTRACTED=$(find "$TMPDIR" -maxdepth 1 -type d -name "pidoors*" | head -1)
if [ -z "$EXTRACTED" ]; then
    fail "Could not find extracted directory"
    exit 1
fi

# ──────────────────────────────────────────────
# Pre-flight: verify archive contents
# ──────────────────────────────────────────────

if [ ! -d "$EXTRACTED/pidoorserv" ]; then
    fail "Release archive missing pidoorserv/ directory"
    exit 1
fi

if [ ! -f "$EXTRACTED/VERSION" ]; then
    fail "Release archive missing VERSION file"
    exit 1
fi
ok "Archive contents verified"

# ──────────────────────────────────────────────
# Copy web files (skip config.php)
# ──────────────────────────────────────────────

info "Updating web files..."
COPIED=0
SKIPPED=0

# Use rsync if available, otherwise cp with exclusion
if command -v rsync > /dev/null 2>&1; then
    rsync -a --delete --exclude='includes/config.php' "$EXTRACTED/pidoorserv/" "$WEB_ROOT/"
    ok "Web files updated (rsync, stale files removed)"
else
    # Manual copy, preserving config.php
    if [ -f "$WEB_ROOT/includes/config.php" ]; then
        cp "$WEB_ROOT/includes/config.php" "$TMPDIR/config.php.bak"
    fi

    cp -r "$EXTRACTED/pidoorserv/"* "$WEB_ROOT/"

    if [ -f "$TMPDIR/config.php.bak" ]; then
        cp "$TMPDIR/config.php.bak" "$WEB_ROOT/includes/config.php"
    fi
    # Remove files no longer in the release
    STALE=0
    find "$WEB_ROOT" -type f | while read -r f; do
        rel="${f#$WEB_ROOT/}"
        case "$rel" in
            includes/config.php|.docker*|.*) continue ;;
        esac
        if [ ! -f "$EXTRACTED/pidoorserv/$rel" ]; then
            rm -f "$f" && STALE=$((STALE + 1))
        fi
    done
    find "$WEB_ROOT" -mindepth 1 -type d -empty -delete 2>/dev/null || true
    ok "Web files updated (cp, stale files removed)"
fi

# Copy VERSION file
cp "$EXTRACTED/VERSION" "$WEB_ROOT/VERSION"
ok "VERSION file updated"

# Copy database migration if present
if [ -f "$EXTRACTED/database_migration.sql" ]; then
    cp "$EXTRACTED/database_migration.sql" "$WEB_ROOT/database_migration.sql"
fi

# ──────────────────────────────────────────────
# Run database migration
# ──────────────────────────────────────────────

MIGRATION_SQL=""
if [ -f "$EXTRACTED/database_migration.sql" ]; then
    MIGRATION_SQL="$EXTRACTED/database_migration.sql"
fi

DB_PASS=""
# Try --db-pass argument first
if [ -n "$DB_PASS_ARG" ]; then
    DB_PASS="$DB_PASS_ARG"
# Try reading from config.php
elif [ -f "$WEB_ROOT/includes/config.php" ]; then
    DB_PASS=$(php -r "
        \$cfg = include '$WEB_ROOT/includes/config.php';
        if (is_array(\$cfg) && isset(\$cfg['sqlpass'])) {
            echo \$cfg['sqlpass'];
        }
    " 2>/dev/null) || true
fi

if [ -n "$MIGRATION_SQL" ] && [ -n "$DB_PASS" ]; then
    info "Running database migration..."
    if MYSQL_PWD="$DB_PASS" mysql -u pidoors access < "$MIGRATION_SQL" 2>/dev/null; then
        ok "Database migration completed"
    else
        warn "Database migration had errors (non-fatal for upgrades)"
    fi

    # Also run the users DB portion if it switches contexts (it does via USE users)
    # The migration script handles both databases internally, so one run is enough.
elif [ -n "$MIGRATION_SQL" ]; then
    warn "Could not determine database password"
    warn "Run manually: mysql -u pidoors -p access < $WEB_ROOT/database_migration.sql"
else
    warn "No database_migration.sql found in release"
fi

# ──────────────────────────────────────────────
# Fix ownership and permissions
# ──────────────────────────────────────────────

info "Setting ownership..."
chown -R www-data:www-data "$WEB_ROOT"
chmod -R 755 "$WEB_ROOT"

# Protect config file
if [ -f "$WEB_ROOT/includes/config.php" ]; then
    chmod 640 "$WEB_ROOT/includes/config.php"
    chown www-data:www-data "$WEB_ROOT/includes/config.php"
fi
ok "Ownership and permissions set"

# ──────────────────────────────────────────────
# Ensure backup directory exists
# ──────────────────────────────────────────────

if [ ! -d "$BACKUP_DIR" ]; then
    mkdir -p "$BACKUP_DIR"
    chown www-data:www-data "$BACKUP_DIR"
    chmod 750 "$BACKUP_DIR"
    ok "Backup directory created: $BACKUP_DIR"
else
    ok "Backup directory exists: $BACKUP_DIR"
fi

# ──────────────────────────────────────────────
# Reload nginx
# ──────────────────────────────────────────────

if systemctl is-active --quiet nginx; then
    nginx -t > /dev/null 2>&1 && systemctl reload nginx
    ok "Nginx reloaded"
else
    warn "Nginx is not running"
fi

# ──────────────────────────────────────────────
# Done
# ──────────────────────────────────────────────

NEW_VERSION=$(cat "$WEB_ROOT/VERSION" | tr -d '[:space:]')

echo
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  Update Complete!${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo
echo -e "  ${BOLD}Previous version:${NC}  $CURRENT_VERSION"
echo -e "  ${BOLD}New version:${NC}       $NEW_VERSION"
echo -e "  ${BOLD}Web root:${NC}          $WEB_ROOT"
echo
