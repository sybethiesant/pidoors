#!/bin/bash
#
# PiDoors Docker Deploy Script
# Deploys the 2-container Bookworm test environment to Unraid.
#
# Usage:  ./docker/deploy.sh [user@host]
#         Default: root@192.168.4.5
#
# Handles first-run setup AND updates. Safe to run repeatedly.
# Data persists in Docker volumes across rebuilds.
#

set -euo pipefail

REMOTE="${1:-root@192.168.4.5}"
REMOTE_DIR="/mnt/user/appdata/pidoors"

# Resolve project root (script lives in docker/)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

log() { echo "[deploy] $1"; }
run() { ssh "$REMOTE" "$@"; }

# ── Sync files to Unraid ──
log "Syncing files to $REMOTE:$REMOTE_DIR ..."

run "mkdir -p $REMOTE_DIR/docker $REMOTE_DIR/pidoors $REMOTE_DIR/pidoorserv $REMOTE_DIR/pidoors-ui $REMOTE_DIR/nginx"

# Docker files
scp -q "$PROJECT_ROOT"/docker/docker-compose.yml \
       "$PROJECT_ROOT"/docker/Dockerfile.server \
       "$PROJECT_ROOT"/docker/Dockerfile.door \
       "$PROJECT_ROOT"/docker/server-entrypoint.sh \
       "$PROJECT_ROOT"/docker/door-entrypoint.sh \
       "$PROJECT_ROOT"/docker/mock_gpio.py \
       "$REMOTE:$REMOTE_DIR/docker/"

# VERSION file and database migration
scp -q "$PROJECT_ROOT"/VERSION "$REMOTE:$REMOTE_DIR/VERSION"
scp -q "$PROJECT_ROOT"/database_migration.sql "$REMOTE:$REMOTE_DIR/database_migration.sql"

# Nginx config template
scp -q "$PROJECT_ROOT"/nginx/pidoors.conf "$REMOTE:$REMOTE_DIR/nginx/"

# Controller source
scp -rq "$PROJECT_ROOT"/pidoors/* "$REMOTE:$REMOTE_DIR/pidoors/"

# Web source
scp -rq "$PROJECT_ROOT"/pidoorserv/* "$REMOTE:$REMOTE_DIR/pidoorserv/"

# React UI source
scp -rq "$PROJECT_ROOT"/pidoors-ui/* "$REMOTE:$REMOTE_DIR/pidoors-ui/"
[ -f "$PROJECT_ROOT/pidoors-ui/.env" ] && scp -q "$PROJECT_ROOT/pidoors-ui/.env" "$REMOTE:$REMOTE_DIR/pidoors-ui/" || true

log "Files synced."

# ── Secrets preflight (NO hardcoded defaults) ──
# These must be provided by the operator's environment (e.g. exported in the
# shell or sourced from a local, git-ignored .env before running deploy).
# We FAIL CLOSED if any is missing rather than shipping a default credential.
require_local_secret() {
    local name="$1"
    if [ -z "${!name:-}" ]; then
        log "FATAL: required secret '$name' is not set in your local environment."
        log "       Export DB_PASS, DB_ROOT_PASS, ADMIN_EMAIL and ADMIN_PASS"
        log "       (or 'set -a; . ./your.env; set +a') before running deploy."
        exit 1
    fi
}
require_local_secret DB_PASS
require_local_secret DB_ROOT_PASS
require_local_secret ADMIN_EMAIL
require_local_secret ADMIN_PASS

# Write the secrets to a root-only .env in the remote compose working dir so
# `docker-compose` can interpolate them. Sent over the existing SSH channel
# (not echoed locally) and written with a strict umask so the file is 0600.
log "Writing remote .env (mode 0600) ..."
run "umask 077 && cat > $REMOTE_DIR/.env" <<ENVEOF
DB_PASS=${DB_PASS}
DB_ROOT_PASS=${DB_ROOT_PASS}
ADMIN_EMAIL=${ADMIN_EMAIL}
ADMIN_PASS=${ADMIN_PASS}
ENVEOF

# ── Build and start ──
log "Building and starting containers ..."
run "cd $REMOTE_DIR && docker-compose -f docker/docker-compose.yml down 2>/dev/null || true"
run "cd $REMOTE_DIR && docker-compose -f docker/docker-compose.yml up --build -d"

log ""
log "Container status:"
run "docker ps --filter name=pidoors --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'"
log ""
log "Done."
log "  Web UI: https://$(echo "$REMOTE" | cut -d@ -f2):8088"
# Do NOT print the admin login here — credentials are set by the operator via
# the ADMIN_EMAIL / ADMIN_PASS env vars (see .env.example) and must not be
# echoed to the terminal or any deploy logs.
log "  Login:  use the ADMIN_EMAIL / ADMIN_PASS you configured (see .env.example)"
log ""
log "Logs:"
log "  docker-compose -f $REMOTE_DIR/docker/docker-compose.yml logs -f server"
log "  docker-compose -f $REMOTE_DIR/docker/docker-compose.yml logs -f door"
