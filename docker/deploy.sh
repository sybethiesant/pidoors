#!/bin/bash
#
# PiDoors Docker Deploy Script
# Deploys the full Docker test environment to Unraid.
#
# Usage:  ./docker/deploy.sh [user@host]
#         Default: root@192.168.4.5
#
# Handles first-run setup AND updates. Safe to run repeatedly.
# Data persists in Docker volumes across rebuilds.
#

set -euo pipefail

REMOTE="${1:?Usage: $0 user@host (e.g. root@192.168.1.100)}"
REMOTE_DIR="/mnt/user/appdata/pidoors"
NETWORK="pidoors-network"

# Resolve project root (script lives in docker/)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

log() { echo "[deploy] $1"; }
run() { ssh "$REMOTE" "$@"; }

# ── Sync files to Unraid ──
log "Syncing files to $REMOTE:$REMOTE_DIR ..."

run "mkdir -p $REMOTE_DIR/docker $REMOTE_DIR/pidoors $REMOTE_DIR/pidoorserv"

# Docker files
scp -q "$PROJECT_ROOT"/docker/docker-compose.yml \
       "$PROJECT_ROOT"/docker/Dockerfile.php \
       "$PROJECT_ROOT"/docker/Dockerfile.controller \
       "$PROJECT_ROOT"/docker/entrypoint.sh \
       "$PROJECT_ROOT"/docker/controller-entrypoint.sh \
       "$PROJECT_ROOT"/docker/controller-wrapper.py \
       "$PROJECT_ROOT"/docker/controller-update.sh \
       "$PROJECT_ROOT"/docker/mock_gpio.py \
       "$PROJECT_ROOT"/docker/init-db.sql \
       "$PROJECT_ROOT"/docker/nginx.conf \
       "$REMOTE:$REMOTE_DIR/docker/"

# VERSION file (build context root)
scp -q "$PROJECT_ROOT"/VERSION "$REMOTE:$REMOTE_DIR/VERSION"

# Controller source
scp -rq "$PROJECT_ROOT"/pidoors/* "$REMOTE:$REMOTE_DIR/pidoors/"

# Web source
scp -rq "$PROJECT_ROOT"/pidoorserv/* "$REMOTE:$REMOTE_DIR/pidoorserv/"

log "Files synced."

# ── Build images ──
log "Building images ..."
run "cd $REMOTE_DIR && docker build -q -f docker/Dockerfile.php -t pidoors-php . && echo 'php image built'"
run "cd $REMOTE_DIR && docker build -q -f docker/Dockerfile.controller -t pidoors-controller . && echo 'controller image built'"

# ── Ensure network exists ──
run "docker network inspect $NETWORK >/dev/null 2>&1 || docker network create $NETWORK" >/dev/null
# Also accept the compose-prefixed name from older deploys
ACTUAL_NETWORK=$(run "docker inspect pidoors-db --format '{{range .NetworkSettings.Networks}}{{println .NetworkID}}{{end}}' 2>/dev/null | head -1" || true)
if [ -n "$ACTUAL_NETWORK" ]; then
    NETWORK=$(run "docker network inspect $ACTUAL_NETWORK --format '{{.Name}}' 2>/dev/null" || echo "$NETWORK")
    log "Using existing network: $NETWORK"
fi

# ── Helper: ensure container is running ──
ensure_container() {
    local name="$1" ; shift
    # All remaining args are `docker run` flags
    local existing
    existing=$(run "docker ps -aq --filter name=^${name}$" || true)
    if [ -n "$existing" ]; then
        log "Replacing $name ..."
        run "docker stop $name >/dev/null 2>&1 || true"
        run "docker rm $name >/dev/null 2>&1 || true"
    fi
    log "Starting $name ..."
    run "docker run -d --name $name --restart unless-stopped --network $NETWORK $*" >/dev/null
}

# ── Database ──
DB_RUNNING=$(run "docker ps -q --filter name=^pidoors-db$" || true)
if [ -z "$DB_RUNNING" ]; then
    ensure_container pidoors-db \
        -e MARIADB_ROOT_PASSWORD=pidoors_root_pass \
        -e MARIADB_USER=pidoors \
        -e MARIADB_PASSWORD=pidoors_pass \
        -v db_data:/var/lib/mysql \
        -v "$REMOTE_DIR/docker/init-db.sql:/docker-entrypoint-initdb.d/01-init.sql" \
        --health-cmd "'healthcheck.sh --connect --innodb_initialized'" \
        --health-interval 10s --health-timeout 5s --health-retries 5 \
        mariadb:10.6
    log "Waiting for database to be healthy ..."
    run "until docker inspect pidoors-db --format '{{.State.Health.Status}}' 2>/dev/null | grep -q healthy; do sleep 2; done"
else
    log "Database already running."
fi

# ── PHP ──
ensure_container pidoors-php \
    -v app_data:/var/www/pidoors \
    pidoors-php

# Wait for PHP to finish entrypoint setup
sleep 2

# ── Web (nginx) ──
ensure_container pidoors-web \
    -p 8088:80 \
    -v app_data:/var/www/pidoors:ro \
    -v "$REMOTE_DIR/docker/nginx.conf:/etc/nginx/conf.d/default.conf:ro" \
    nginx:1.25-alpine

# ── Controller ──
ensure_container pidoors-controller pidoors-controller

# ── Run DB migrations (idempotent) ──
log "Running DB migrations ..."
run "docker exec pidoors-db sh -c 'until healthcheck.sh --connect 2>/dev/null; do sleep 1; done'" 2>/dev/null
run "docker exec pidoors-db mysql -upidoors -ppidoors_pass access -e \"
ALTER TABLE doors ADD COLUMN IF NOT EXISTS ip_address varchar(45) DEFAULT NULL AFTER description;
ALTER TABLE doors ADD COLUMN IF NOT EXISTS schedule_id int(11) DEFAULT NULL AFTER ip_address;
ALTER TABLE doors ADD COLUMN IF NOT EXISTS unlock_duration int(11) DEFAULT 5 AFTER schedule_id;
ALTER TABLE doors ADD COLUMN IF NOT EXISTS status enum('online','offline','unknown') DEFAULT 'unknown' AFTER unlock_duration;
ALTER TABLE doors ADD COLUMN IF NOT EXISTS last_seen datetime DEFAULT NULL AFTER status;
ALTER TABLE doors ADD COLUMN IF NOT EXISTS locked tinyint(1) DEFAULT 1 AFTER last_seen;
ALTER TABLE doors ADD COLUMN IF NOT EXISTS lockdown_mode tinyint(1) DEFAULT 0 AFTER locked;
ALTER TABLE doors ADD COLUMN IF NOT EXISTS reader_type enum('wiegand','osdp','nfc_pn532','nfc_mfrc522') DEFAULT 'wiegand' AFTER lockdown_mode;
ALTER TABLE doors ADD COLUMN IF NOT EXISTS controller_version varchar(20) DEFAULT NULL AFTER reader_type;
ALTER TABLE doors ADD COLUMN IF NOT EXISTS update_requested tinyint(1) DEFAULT 0 AFTER controller_version;
ALTER TABLE doors ADD COLUMN IF NOT EXISTS update_status varchar(255) DEFAULT NULL AFTER update_requested;
ALTER TABLE doors ADD COLUMN IF NOT EXISTS update_status_time datetime DEFAULT NULL AFTER update_status;
ALTER TABLE doors ADD COLUMN IF NOT EXISTS unlock_requested tinyint(1) NOT NULL DEFAULT 0 AFTER update_status_time;
ALTER TABLE doors ADD COLUMN IF NOT EXISTS poll_interval int(11) NOT NULL DEFAULT 3 AFTER unlock_requested;
\"" 2>/dev/null

# ── Verify ──
log ""
log "Container status:"
run "docker ps --filter name=pidoors --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'"
log ""
log "Done. Web UI: http://$(echo "$REMOTE" | cut -d@ -f2):8088"
