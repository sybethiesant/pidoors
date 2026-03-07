<?php
/**
 * Notification Cron Script
 * PiDoors Access Control System
 *
 * Runs every 5 minutes via cron to detect events and send notifications.
 * Usage: */5 * * * * www-data php /var/www/pidoors/cron/notify.php
 */

// CLI only - reject web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// Bootstrap config and database (no header.php - no session/HTML needed)
$config = include(__DIR__ . '/../includes/config.php');
require_once __DIR__ . '/../includes/smtp.php';
require_once __DIR__ . '/../includes/notifications.php';

date_default_timezone_set($config['timezone'] ?? 'UTC');

try {
    $pdo_options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if (!empty($config['sql_ssl_ca']) && file_exists($config['sql_ssl_ca'])) {
        $pdo_options[PDO::MYSQL_ATTR_SSL_CA] = $config['sql_ssl_ca'];
        $pdo_options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    $pdo_access = new PDO(
        "mysql:host={$config['sqladdr']};dbname={$config['sqldb2']};charset=utf8mb4",
        $config['sqluser'],
        $config['sqlpass'],
        $pdo_options
    );
} catch (PDOException $e) {
    error_log("PiDoors notify cron: DB connection failed: " . $e->getMessage());
    exit(1);
}

// Load notification settings - bail early if disabled
$ns = get_notification_settings($pdo_access);
if (empty($ns['email_notifications']) || $ns['email_notifications'] !== '1') {
    exit(0);
}
if (empty($ns['notification_email'])) {
    exit(0);
}

// Get heartbeat interval for offline detection
$heartbeat = (int)($ns['heartbeat_interval'] ?? 60);
if ($heartbeat < 30) $heartbeat = 60;

// ── Check 1: Door went offline ──
// Door is marked 'online' but hasn't been seen in 3x heartbeat intervals
try {
    $offline_threshold = $heartbeat * 3;
    $stmt = $pdo_access->prepare(
        "SELECT id, name FROM doors
         WHERE status = 'online'
         AND last_seen IS NOT NULL
         AND last_seen < NOW() - INTERVAL ? SECOND"
    );
    $stmt->execute([$offline_threshold]);
    $offline_doors = $stmt->fetchAll();

    foreach ($offline_doors as $door) {
        $event_key = 'offline_' . $door['name'];
        if (!was_recently_notified($pdo_access, 'door_status', $event_key, 900)) {
            notify_door_status($door['name'], 'offline', $config, $pdo_access);
            log_notification($pdo_access, 'door_status', $event_key);
        }

        // Update door status to offline
        $update = $pdo_access->prepare("UPDATE doors SET status = 'offline' WHERE id = ?");
        $update->execute([$door['id']]);
    }
} catch (Exception $e) {
    error_log("PiDoors notify cron: offline check error: " . $e->getMessage());
}

// ── Check 2: Door came back online ──
// Door is marked 'offline' but has been seen recently (within 2x heartbeat)
try {
    $online_threshold = $heartbeat * 2;
    $stmt = $pdo_access->prepare(
        "SELECT id, name FROM doors
         WHERE status = 'offline'
         AND last_seen IS NOT NULL
         AND last_seen > NOW() - INTERVAL ? SECOND"
    );
    $stmt->execute([$online_threshold]);
    $online_doors = $stmt->fetchAll();

    foreach ($online_doors as $door) {
        $event_key = 'online_' . $door['name'];
        if (!was_recently_notified($pdo_access, 'door_status', $event_key, 900)) {
            notify_door_status($door['name'], 'online', $config, $pdo_access);
            log_notification($pdo_access, 'door_status', $event_key);
        }

        // Update door status to online
        $update = $pdo_access->prepare("UPDATE doors SET status = 'online' WHERE id = ?");
        $update->execute([$door['id']]);
    }
} catch (Exception $e) {
    error_log("PiDoors notify cron: online check error: " . $e->getMessage());
}

// ── Check 3: Repeated access denials ──
// 3+ denied attempts from the same card at the same door in the last hour
try {
    $stmt = $pdo_access->query(
        "SELECT user_id, Location, COUNT(*) as attempt_count
         FROM logs
         WHERE Granted = 0
         AND Date > NOW() - INTERVAL 1 HOUR
         GROUP BY user_id, Location
         HAVING COUNT(*) >= 3"
    );
    $denied = $stmt->fetchAll();

    foreach ($denied as $row) {
        $event_key = $row['user_id'] . '_' . $row['Location'];
        if (!was_recently_notified($pdo_access, 'access_denied', $event_key, 3600)) {
            notify_access_denied(
                $row['user_id'],
                $row['Location'] ?? 'Unknown',
                $row['attempt_count'],
                $config,
                $pdo_access
            );
            log_notification($pdo_access, 'access_denied', $event_key);
        }
    }
} catch (Exception $e) {
    error_log("PiDoors notify cron: access denied check error: " . $e->getMessage());
}

// ── Check 4: Daily summary ──
// Send once per day, after 6 AM, for yesterday's data
try {
    $hour = (int)date('G');
    if ($hour >= 6) {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $event_key = 'summary_' . $yesterday;
        if (!was_recently_notified($pdo_access, 'daily_summary', $event_key, 82800)) {
            send_daily_summary($config, $pdo_access);
            log_notification($pdo_access, 'daily_summary', $event_key);
        }
    }
} catch (Exception $e) {
    error_log("PiDoors notify cron: daily summary error: " . $e->getMessage());
}

// ── Cleanup old notification log entries (older than 30 days) ──
try {
    $pdo_access->exec("DELETE FROM notification_log WHERE sent_at < NOW() - INTERVAL 30 DAY");
} catch (Exception $e) {
    // Table might not exist yet on first run
}
