#!/bin/bash
set -e

# ── Wait for database ──
echo "Controller: waiting for database..."
until python3 -c "
import pymysql, sys
try:
    pymysql.connect(host='db', user='pidoors', password='pidoors_pass',
                    database='access', connect_timeout=3)
    sys.exit(0)
except Exception:
    sys.exit(1)
" 2>/dev/null; do
    sleep 2
done
echo "Controller: database is ready"

# ── Run DB migrations (idempotent) ──
python3 -c "
import pymysql
db = pymysql.connect(host='db', user='pidoors', password='pidoors_pass', database='access')
cur = db.cursor()
migrations = [
    'ALTER TABLE doors ADD COLUMN IF NOT EXISTS ip_address varchar(45) DEFAULT NULL AFTER description',
    'ALTER TABLE doors ADD COLUMN IF NOT EXISTS schedule_id int(11) DEFAULT NULL AFTER ip_address',
    'ALTER TABLE doors ADD COLUMN IF NOT EXISTS unlock_duration int(11) DEFAULT 5 AFTER schedule_id',
    \"ALTER TABLE doors ADD COLUMN IF NOT EXISTS status enum('online','offline','unknown') DEFAULT 'unknown' AFTER unlock_duration\",
    'ALTER TABLE doors ADD COLUMN IF NOT EXISTS last_seen datetime DEFAULT NULL AFTER status',
    'ALTER TABLE doors ADD COLUMN IF NOT EXISTS locked tinyint(1) DEFAULT 1 AFTER last_seen',
    'ALTER TABLE doors ADD COLUMN IF NOT EXISTS lockdown_mode tinyint(1) DEFAULT 0 AFTER locked',
    \"ALTER TABLE doors ADD COLUMN IF NOT EXISTS reader_type enum('wiegand','osdp','nfc_pn532','nfc_mfrc522') DEFAULT 'wiegand' AFTER lockdown_mode\",
    'ALTER TABLE doors ADD COLUMN IF NOT EXISTS controller_version varchar(20) DEFAULT NULL AFTER reader_type',
    'ALTER TABLE doors ADD COLUMN IF NOT EXISTS update_requested tinyint(1) DEFAULT 0 AFTER controller_version',
    'ALTER TABLE doors ADD COLUMN IF NOT EXISTS update_status varchar(255) DEFAULT NULL AFTER update_requested',
    'ALTER TABLE doors ADD COLUMN IF NOT EXISTS update_status_time datetime DEFAULT NULL AFTER update_status',
    'ALTER TABLE doors ADD COLUMN IF NOT EXISTS unlock_requested tinyint(1) NOT NULL DEFAULT 0 AFTER update_status_time',
    'ALTER TABLE doors ADD COLUMN IF NOT EXISTS poll_interval int(11) NOT NULL DEFAULT 3 AFTER unlock_requested',
]
for sql in migrations:
    cur.execute(sql)
db.commit()
db.close()
print('Controller: DB migrations complete')
"

# ── Write config files ──
mkdir -p /opt/pidoors/conf /opt/pidoors/cache

cat > /opt/pidoors/conf/zone.json <<'EOF'
{"zone": "docker_door"}
EOF

cat > /opt/pidoors/conf/config.json <<'EOF'
{
  "docker_door": {
    "sqladdr": "db",
    "sqluser": "pidoors",
    "sqlpass": "pidoors_pass",
    "sqldb": "access",
    "d0": 24,
    "d1": 23,
    "latch_gpio": 18,
    "unlock_value": 1,
    "open_delay": 5,
    "reader_type": "wiegand"
  }
}
EOF

# ── Register door in database ──
python3 -c "
import pymysql
db = pymysql.connect(host='db', user='pidoors', password='pidoors_pass', database='access')
cur = db.cursor()
cur.execute(\"\"\"
    INSERT IGNORE INTO doors (name, location, description)
    VALUES ('docker_door', 'Docker Test', 'Simulated controller')
\"\"\")
db.commit()
db.close()
print('Controller: door registered in database')
"

# ── Launch controller ──
exec "$@"
