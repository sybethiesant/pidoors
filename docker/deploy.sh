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
log "  Login:  admin@pidoors.local / PiDoors2024!"
log ""
log "Logs:"
log "  docker-compose -f $REMOTE_DIR/docker/docker-compose.yml logs -f server"
log "  docker-compose -f $REMOTE_DIR/docker/docker-compose.yml logs -f door"
