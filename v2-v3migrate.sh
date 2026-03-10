#!/bin/bash
#
# PiDoors v2 → v3 Migration
# Switches nginx from the legacy PHP UI to the React SPA.
# Run once after upgrading from v2.x to v3.x via the web UI updater.
#
# Usage: sudo ./v2-v3migrate.sh
#

set -euo pipefail

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
fail() { echo -e "  ${RED}✗${NC} $1"; }
warn() { echo -e "  ${YELLOW}!${NC} $1"; }
info() { echo -e "  ${BLUE}→${NC} $1"; }

echo
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  PiDoors v2 → v3 Migration${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo

if [ "$(id -u)" -ne 0 ]; then
    fail "This script must be run as root: ${BOLD}sudo ./v2-v3migrate.sh${NC}"
    exit 1
fi

WEB_ROOT="/var/www/pidoors"
UI_ROOT="/var/www/pidoors-ui"
NGINX_CONF="/etc/nginx/sites-available/pidoors"

# ── Check current version ──

if [ ! -f "$WEB_ROOT/VERSION" ]; then
    fail "PiDoors not found at $WEB_ROOT"
    exit 1
fi

VERSION=$(cat "$WEB_ROOT/VERSION" | tr -d '[:space:]')
MAJOR=$(echo "$VERSION" | cut -d. -f1)

if [ "$MAJOR" -lt 3 ] 2>/dev/null; then
    fail "Server is still on v$VERSION. Update to v3.x first via the web UI, then run this script."
    exit 1
fi
ok "Server version: $VERSION"

# ── Check if already migrated ──

if [ -f "$NGINX_CONF" ] && grep -q "pidoors-ui" "$NGINX_CONF" 2>/dev/null; then
    ok "Nginx is already configured for the React SPA."
    echo
    warn "Nothing to do — your system is already on v3.x."
    echo
    exit 0
fi

# ── Deploy React SPA if not present ──

if [ ! -f "$UI_ROOT/index.html" ]; then
    info "React SPA not deployed yet. Building..."

    # Check for bundled pre-built dist
    if [ -d "$WEB_ROOT/pidoors-ui-dist" ] && [ -f "$WEB_ROOT/pidoors-ui-dist/index.html" ]; then
        mkdir -p "$UI_ROOT"
        cp -r "$WEB_ROOT/pidoors-ui-dist/"* "$UI_ROOT/"
        ok "Deployed pre-built React SPA"
    elif command -v node > /dev/null 2>&1; then
        # Try building from source in the release
        TMPDIR=$(mktemp -d /tmp/pidoors-ui-build-XXX)
        REPO="sybethiesant/pidoors"
        LATEST_TAG="v$VERSION"

        info "Downloading source to build SPA..."
        curl -sfL "https://github.com/$REPO/releases/download/$LATEST_TAG/$LATEST_TAG.tar.gz" -o "$TMPDIR/release.tar.gz" 2>/dev/null || \
        curl -sfL "https://github.com/$REPO/archive/refs/tags/$LATEST_TAG.tar.gz" -o "$TMPDIR/release.tar.gz" 2>/dev/null || {
            fail "Could not download release. Check internet connection."
            rm -rf "$TMPDIR"
            exit 1
        }

        tar xzf "$TMPDIR/release.tar.gz" -C "$TMPDIR"
        EXTRACTED=$(find "$TMPDIR" -maxdepth 1 -type d -name "pidoors*" | head -1)

        # Check for pre-built dist in the release
        if [ -d "$EXTRACTED/pidoors-ui-dist" ] && [ -f "$EXTRACTED/pidoors-ui-dist/index.html" ]; then
            mkdir -p "$UI_ROOT"
            cp -r "$EXTRACTED/pidoors-ui-dist/"* "$UI_ROOT/"
            ok "Deployed pre-built React SPA from release"
        elif [ -d "$EXTRACTED/pidoors-ui" ] && [ -f "$EXTRACTED/pidoors-ui/package.json" ]; then
            info "Building from source (this may take a minute)..."
            if (cd "$EXTRACTED/pidoors-ui" && npm install --loglevel=error && npm run build) > /dev/null 2>&1; then
                mkdir -p "$UI_ROOT"
                cp -r "$EXTRACTED/pidoors-ui/dist/"* "$UI_ROOT/"
                ok "Built and deployed React SPA"
            else
                fail "React SPA build failed."
                rm -rf "$TMPDIR"
                exit 1
            fi
        else
            fail "No SPA source or dist found in release."
            rm -rf "$TMPDIR"
            exit 1
        fi
        rm -rf "$TMPDIR"
    else
        # No node, no bundled dist — install node and try again
        info "Installing Node.js..."
        apt-get install -y -qq nodejs npm > /dev/null 2>&1 || {
            fail "Could not install Node.js. Install manually: sudo apt-get install nodejs npm"
            exit 1
        }
        ok "Node.js installed"
        warn "Re-run this script to build the React SPA."
        exit 0
    fi

    chown -R www-data:www-data "$UI_ROOT"
    chmod -R 755 "$UI_ROOT"
fi
ok "React SPA is at $UI_ROOT"

# ── Update nginx config ──

info "Updating nginx configuration..."

# Detect PHP-FPM socket path
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || echo "")
SOCKET="unix:/var/run/php/php-fpm.sock"
if [ -n "$PHP_VERSION" ] && [ -S "/var/run/php/php${PHP_VERSION}-fpm.sock" ]; then
    SOCKET="unix:/var/run/php/php${PHP_VERSION}-fpm.sock"
fi

# Backup old config
if [ -f "$NGINX_CONF" ]; then
    cp "$NGINX_CONF" "${NGINX_CONF}.v2.bak"
    ok "Old config backed up to ${NGINX_CONF}.v2.bak"
fi

# Write new config
cat > "$NGINX_CONF" << 'NGINX_EOF'
server {
    listen 80;
    listen [::]:80;
    server_name pidoors.local _;

    root /var/www/pidoors-ui;
    index index.html;

    server_tokens off;

    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    access_log /var/log/nginx/pidoors_access.log;
    error_log /var/log/nginx/pidoors_error.log;

    location ~ ^/api/(.*)$ {
        fastcgi_pass __SOCKET__;
        fastcgi_index api.php;
        fastcgi_param SCRIPT_FILENAME /var/www/pidoors/api.php;
        fastcgi_param PATH_INFO /$1;
        fastcgi_param QUERY_STRING $query_string;
        include fastcgi_params;
        fastcgi_param PHP_VALUE "display_errors=Off
log_errors=On
expose_php=Off";
    }

    location = /ca.pem {
        alias /var/www/pidoors/ca.pem;
    }

    location ~ /\. {
        deny all;
        return 404;
    }

    location ~ ^/includes/ {
        deny all;
        return 404;
    }

    location ~* \.(?:js|css|woff2?|ttf|eot|svg|png|jpg|jpeg|gif|ico|webp)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ /index.html;
    }
}
NGINX_EOF

# Replace socket placeholder
sed -i "s|__SOCKET__|${SOCKET}|g" "$NGINX_CONF"

ok "Nginx config updated for React SPA"

# Test and reload
if nginx -t > /dev/null 2>&1; then
    systemctl reload nginx
    ok "Nginx reloaded"
else
    fail "Nginx config test failed — check ${NGINX_CONF}"
    exit 1
fi

# ── Clean up bundled dist from web root ──

if [ -d "$WEB_ROOT/pidoors-ui-dist" ]; then
    rm -rf "$WEB_ROOT/pidoors-ui-dist"
    ok "Cleaned up bundled dist from web root"
fi

# ── Done ──

echo
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  Migration Complete!${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo
echo -e "  Your PiDoors is now running the v3 React UI."
echo -e "  Open ${BOLD}http://$(hostname -I | awk '{print $1}')/${NC} in your browser."
echo
