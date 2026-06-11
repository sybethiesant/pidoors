#!/bin/bash
#
# PiDoors Server Update Script
# Updates the web interface and runs database migrations.
# Must run as root.
#
# Usage: sudo ./server-update.sh
#
# The database password is normally read from the web config
# ($WEB_ROOT/includes/config.php). If it cannot be read, set the
# PIDOORS_DB_PASS environment variable, or you will be prompted
# interactively. Passing the password on the command line is NOT
# supported — argv is visible to other users via `ps` and shell history.
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

# ──────────────────────────────────────────────
# Supply-chain integrity: verify a downloaded release tarball against the
# published <tarball>.sha256 asset BEFORE extracting/deploying it.
#
# This is the shared verification hook used by both updaters (the controller
# updater, pidoors-update.sh, calls the equivalent). It FAILS SECURE: if the
# checksum asset cannot be downloaded, or the tooling is missing, or the sum
# does not match, we abort and deploy nothing.
#
# Args: $1 = path to downloaded tarball, $2 = URL of the .sha256 asset
verify_tarball_checksum() {
    local tarball="$1"
    local sum_url="$2"
    local sum_file="${tarball}.sha256"

    info "Verifying release checksum..."

    # Download the published checksum asset.
    if ! curl -sfL --connect-timeout 15 --max-time 30 "$sum_url" -o "$sum_file" 2>/dev/null; then
        fail "Could not download checksum ($sum_url)."
        fail "Refusing to deploy an unverified release."
        return 1
    fi
    if [ ! -s "$sum_file" ]; then
        fail "Downloaded checksum file is empty. Refusing to deploy."
        return 1
    fi

    # Pick an available checksum verifier.
    local verifier=""
    if command -v sha256sum > /dev/null 2>&1; then
        verifier="sha256sum"
    elif command -v shasum > /dev/null 2>&1; then
        verifier="shasum -a 256"
    else
        fail "No sha256sum/shasum available to verify the release. Refusing to deploy."
        return 1
    fi

    # The published sum references the tarball by basename. Verify in the
    # tarball's own directory so the recorded name resolves.
    local tdir tbase
    tdir="$(dirname "$tarball")"
    tbase="$(basename "$tarball")"

    # Normalize the sum file to reference the exact basename we downloaded
    # (build-release.sh writes the release tarball name, e.g. vX.Y.Z.tar.gz,
    # which may differ from our local "release.tar.gz"). Extract just the
    # hex digest and re-pair it with our local basename.
    local expected
    expected="$(awk '{print $1}' "$sum_file" | head -1)"
    if ! echo "$expected" | grep -qE '^[0-9a-fA-F]{64}$'; then
        fail "Checksum file is malformed. Refusing to deploy."
        return 1
    fi
    printf '%s  %s\n' "$expected" "$tbase" > "$sum_file"

    if ( cd "$tdir" && $verifier -c "$(basename "$sum_file")" ) > /dev/null 2>&1; then
        ok "Checksum verified"
        return 0
    fi

    fail "Checksum MISMATCH — release tarball failed integrity verification."
    fail "The download may be corrupt or tampered with. Aborting."
    return 1
}

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

# NOTE: --db-pass on argv was removed — a password on the command line is
# visible to any local user via `ps` and is recorded in shell history.
# The DB password now comes from (in order): the web config, the
# PIDOORS_DB_PASS env var, or an interactive prompt.
while [[ $# -gt 0 ]]; do
    case "$1" in
        --db-pass|--db-pass=*)
            fail "--db-pass is no longer accepted (it leaks via ps/history)."
            echo "  Set the PIDOORS_DB_PASS environment variable instead, e.g.:"
            echo "    sudo PIDOORS_DB_PASS=secret ./server-update.sh"
            echo "  or simply run the script and enter it when prompted."
            exit 1
            ;;
        *)
            fail "Unknown argument: $1"
            echo "  Usage: sudo ./server-update.sh"
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
LATEST_TAG=$(curl -sf --connect-timeout 15 --max-time 30 "https://api.github.com/repos/$REPO/releases/latest" | python3 -c "import sys,json; print(json.load(sys.stdin)['tag_name'])" 2>/dev/null) || {
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
# Only the published release ASSET (releases/download/...) has a matching
# .sha256 asset we can verify against. The GitHub auto-generated source
# archive (archive/refs/tags/...) is NOT covered by our published checksum,
# so we must NOT silently fall back to it and then skip verification —
# that would defeat the supply-chain check. Track the source explicitly.
ASSET_URL="https://github.com/$REPO/releases/download/$LATEST_TAG/$LATEST_TAG.tar.gz"
SHA256_URL="${ASSET_URL}.sha256"

if ! curl -sfL --connect-timeout 15 --max-time 120 "$ASSET_URL" -o "$TARBALL" 2>/dev/null; then
    fail "Failed to download release asset $LATEST_TAG"
    fail "A signed release asset with a published .sha256 is required."
    exit 1
fi

if [ ! -s "$TARBALL" ]; then
    fail "Downloaded tarball is empty"
    exit 1
fi
ok "Downloaded"

# Verify the tarball against its published checksum BEFORE extracting.
# Fails secure: any download/tooling/mismatch error aborts the update.
verify_tarball_checksum "$TARBALL" "$SHA256_URL" || exit 1

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
    rsync -a --delete --exclude='includes/config.php' --exclude='ca.pem' "$EXTRACTED/pidoorserv/" "$WEB_ROOT/"
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

# Upgrade nginx config if: legacy PHP-only layout, or missing known fixes
if [ -f /etc/nginx/sites-available/pidoors ] && [ -f "$EXTRACTED/nginx/pidoors.conf" ]; then
    NEEDS_UPGRADE=false
    if ! grep -q "pidoors-ui" /etc/nginx/sites-available/pidoors 2>/dev/null; then
        NEEDS_UPGRADE=true
        info "Upgrading Nginx config: legacy PHP-only layout detected"
    elif ! grep -q "location = /index.html" /etc/nginx/sites-available/pidoors 2>/dev/null; then
        NEEDS_UPGRADE=true
        info "Upgrading Nginx config: missing index.html no-cache block"
    fi
    if [ "$NEEDS_UPGRADE" = true ]; then
        PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || echo "")
        if [ -n "$PHP_VERSION" ]; then
            sed "s|unix:/var/run/php/php-fpm.sock|unix:/var/run/php/php${PHP_VERSION}-fpm.sock|g" \
                "$EXTRACTED/nginx/pidoors.conf" > /etc/nginx/sites-available/pidoors
        else
            cp "$EXTRACTED/nginx/pidoors.conf" /etc/nginx/sites-available/pidoors
        fi
        ok "Nginx config upgraded"
    fi
fi

# Ensure custom session save path exists (avoids Debian phpsessionclean cron)
if [ ! -d /var/lib/php/pidoors-sessions ]; then
    mkdir -p /var/lib/php/pidoors-sessions
    chown www-data:www-data /var/lib/php/pidoors-sessions
    chmod 700 /var/lib/php/pidoors-sessions
    ok "Created custom session save path"
fi

# Install nginx upgrade helper if missing (lets web UI updates sync nginx config)
if [ ! -f /usr/local/sbin/pidoors-nginx-upgrade ]; then
    info "Installing nginx upgrade helper..."
    cat > /usr/local/sbin/pidoors-nginx-upgrade <<'UPGRADESH'
#!/bin/bash
set -e
SRC="/var/www/pidoors/nginx/pidoors.conf"
DEST="/etc/nginx/sites-available/pidoors"
if [ ! -f "$SRC" ]; then exit 0; fi
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || echo "")
if [ -n "$PHP_VERSION" ]; then
    sed "s|unix:/var/run/php/php-fpm.sock|unix:/var/run/php/php${PHP_VERSION}-fpm.sock|g" "$SRC" > "$DEST"
else
    cp "$SRC" "$DEST"
fi
nginx -t > /dev/null 2>&1 && systemctl reload nginx
UPGRADESH
    chmod 755 /usr/local/sbin/pidoors-nginx-upgrade
    cat > /etc/sudoers.d/pidoors-nginx <<'SUDOEOF'
www-data ALL=(ALL) NOPASSWD: /usr/local/sbin/pidoors-nginx-upgrade
SUDOEOF
    chmod 440 /etc/sudoers.d/pidoors-nginx
    ok "Nginx upgrade helper installed"
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
# Take the password from the environment first (PIDOORS_DB_PASS) — this keeps
# it out of argv/ps/shell history. Falls back to config.php below, then to an
# interactive prompt just before the migration runs.
if [ -n "${PIDOORS_DB_PASS:-}" ]; then
    DB_PASS="$PIDOORS_DB_PASS"
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

# If we still have no password but a migration needs to run, prompt for it
# interactively (silently). This avoids ever taking it from argv. If stdin
# is not a TTY (non-interactive run), we fall through to the warn branch.
if [ -n "$MIGRATION_SQL" ] && [ -z "$DB_PASS" ] && [ -t 0 ]; then
    read -rs -p "  Enter database password for $DB_USER@$DB_HOST: " DB_PASS
    echo
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
# Restore ca.pem and fix SSL directory permissions
# ──────────────────────────────────────────────

# Ensure ca.pem exists in web root (controllers download it for TLS DB connections)
if [ ! -f "$WEB_ROOT/ca.pem" ] && [ -f /etc/mysql/ssl/ca.pem ]; then
    cp /etc/mysql/ssl/ca.pem "$WEB_ROOT/ca.pem"
    chown www-data:www-data "$WEB_ROOT/ca.pem"
    chmod 644 "$WEB_ROOT/ca.pem"
    ok "CA certificate restored to web root"
fi

# Fix SSL directory permissions (MariaDB package updates can reset ownership)
if [ -d /etc/mysql/ssl ]; then
    chown mysql:www-data /etc/mysql/ssl
    chmod 770 /etc/mysql/ssl
    chown mysql:www-data /etc/mysql/ssl/ca-key.pem /etc/mysql/ssl/ca.pem 2>/dev/null || true
    chmod 640 /etc/mysql/ssl/ca-key.pem 2>/dev/null || true
    chmod 644 /etc/mysql/ssl/ca.pem /etc/mysql/ssl/server-cert.pem 2>/dev/null || true
    ok "SSL directory permissions verified"
fi

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
