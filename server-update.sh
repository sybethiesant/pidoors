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
    if [ -n "${UI_BUILD_DIR:-}" ] && [ -d "$UI_BUILD_DIR" ]; then
        rm -rf "$UI_BUILD_DIR"
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

TMPDIR=$(mktemp -d /tmp/pidoors-server-update-XXXXXX)
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
# Build and deploy React UI
# ──────────────────────────────────────────────

WEB_UI_ROOT="/var/www/pidoors-ui"
UI_DEPLOYED=false

# Try pre-built SPA dist first (from build-release.sh tarball)
UI_DIST=""
if [ -d "$EXTRACTED/pidoors-ui-dist" ] && [ -f "$EXTRACTED/pidoors-ui-dist/index.html" ]; then
    UI_DIST="$EXTRACTED/pidoors-ui-dist"
elif [ -d "$EXTRACTED/pidoorserv/pidoors-ui-dist" ] && [ -f "$EXTRACTED/pidoorserv/pidoors-ui-dist/index.html" ]; then
    UI_DIST="$EXTRACTED/pidoorserv/pidoors-ui-dist"
fi

if [ -n "$UI_DIST" ]; then
    info "Deploying pre-built React UI..."
    mkdir -p "$WEB_UI_ROOT"
    rm -rf "$WEB_UI_ROOT/"*
    cp -r "$UI_DIST/"* "$WEB_UI_ROOT/"
    chown -R www-data:www-data "$WEB_UI_ROOT"
    chmod -R 755 "$WEB_UI_ROOT"
    if [ -f "$WEB_UI_ROOT/index.html" ]; then
        ok "React UI deployed (pre-built)"
        UI_DEPLOYED=true
    else
        warn "Pre-built React UI copy failed"
    fi
fi

# Fallback: build from source if pre-built not available
if [ "$UI_DEPLOYED" = false ] && [ -d "$EXTRACTED/pidoors-ui" ] && [ -f "$EXTRACTED/pidoors-ui/package.json" ]; then
    if command -v node > /dev/null 2>&1; then
        info "Building React UI from source..."
        UI_BUILD_DIR=$(mktemp -d /tmp/pidoors-ui-build-XXXXXX)
        cp -r "$EXTRACTED/pidoors-ui/"* "$UI_BUILD_DIR/"
        [ -f "$EXTRACTED/pidoors-ui/.env" ] && cp "$EXTRACTED/pidoors-ui/.env" "$UI_BUILD_DIR/"

        if (cd "$UI_BUILD_DIR" && npm install --production=false --loglevel=error) > /dev/null 2>&1 && \
           (cd "$UI_BUILD_DIR" && npm run build) > /dev/null 2>&1; then
            mkdir -p "$WEB_UI_ROOT"
            rm -rf "$WEB_UI_ROOT/"*
            cp -r "$UI_BUILD_DIR/dist/"* "$WEB_UI_ROOT/"
            chown -R www-data:www-data "$WEB_UI_ROOT"
            chmod -R 755 "$WEB_UI_ROOT"
            ok "React UI built and deployed to $WEB_UI_ROOT"
        else
            warn "React UI build failed — the previous UI version is still in place"
        fi
        rm -rf "$UI_BUILD_DIR"
    else
        warn "Node.js is not installed and no pre-built React UI in release"
        warn "Install Node.js with: sudo apt-get install -y nodejs npm"
    fi
fi

# Upgrade nginx config if still using legacy PHP-only setup
if [ -f /etc/nginx/sites-available/pidoors ]; then
    if ! grep -q "pidoors-ui" /etc/nginx/sites-available/pidoors 2>/dev/null; then
        if [ -f "$EXTRACTED/nginx/pidoors.conf" ]; then
            info "Upgrading Nginx config to React SPA layout..."
            PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || echo "")
            if [ -n "$PHP_VERSION" ]; then
                sed "s|unix:/var/run/php/php-fpm.sock|unix:/var/run/php/php${PHP_VERSION}-fpm.sock|g" \
                    "$EXTRACTED/nginx/pidoors.conf" > /etc/nginx/sites-available/pidoors
            else
                cp "$EXTRACTED/nginx/pidoors.conf" /etc/nginx/sites-available/pidoors
            fi
            ok "Nginx config upgraded for React SPA"
        fi
    fi
fi

# ──────────────────────────────────────────────
# Run database migration
# ──────────────────────────────────────────────

MIGRATION_SQL=""
if [ -f "$EXTRACTED/database_migration.sql" ]; then
    MIGRATION_SQL="$EXTRACTED/database_migration.sql"
fi

DB_PASS=""
DB_USER="pidoors"
DB_HOST="localhost"
DB_NAME="access"
# Try --db-pass argument first
if [ -n "$DB_PASS_ARG" ]; then
    DB_PASS="$DB_PASS_ARG"
fi
# Read credentials from config.php
if [ -f "$WEB_ROOT/includes/config.php" ]; then
    if [ -z "$DB_PASS" ]; then
        DB_PASS=$(php -r "
            \$cfg = include '$WEB_ROOT/includes/config.php';
            if (is_array(\$cfg) && isset(\$cfg['sqlpass'])) echo \$cfg['sqlpass'];
        " 2>/dev/null) || true
    fi
    DB_USER=$(php -r "
        \$cfg = include '$WEB_ROOT/includes/config.php';
        if (is_array(\$cfg) && isset(\$cfg['sqluser'])) echo \$cfg['sqluser'];
    " 2>/dev/null) || DB_USER="pidoors"
    DB_HOST=$(php -r "
        \$cfg = include '$WEB_ROOT/includes/config.php';
        if (is_array(\$cfg) && isset(\$cfg['sqladdr'])) echo \$cfg['sqladdr'];
    " 2>/dev/null) || DB_HOST="localhost"
    DB_NAME=$(php -r "
        \$cfg = include '$WEB_ROOT/includes/config.php';
        if (is_array(\$cfg) && isset(\$cfg['sqldb2'])) echo \$cfg['sqldb2'];
    " 2>/dev/null) || DB_NAME="access"
fi

if [ -n "$MIGRATION_SQL" ] && [ -n "$DB_PASS" ]; then
    info "Running database migration..."
    if MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" < "$MIGRATION_SQL" 2>/dev/null; then
        ok "Database migration completed"
    else
        warn "Database migration had errors (non-fatal for upgrades)"
    fi
elif [ -n "$MIGRATION_SQL" ]; then
    warn "Could not determine database password"
    warn "Run manually: mysql -u $DB_USER -p $DB_NAME < $WEB_ROOT/database_migration.sql"
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
