<?php
/**
 * REST API Router
 * PiDoors Access Control System — React SPA Backend
 *
 * Single-file router that reuses existing PHP includes.
 * Supports both nginx ?route= rewrite (Docker) and PATH_INFO (production)
 */

// Bootstrap
$config = include(__DIR__ . '/includes/config.php');
require_once __DIR__ . '/includes/security.php';
require_once $config['apppath'] . 'database/db_connection.php';
require_once __DIR__ . '/includes/push.php';
secure_session_start($config);

// CORS not needed — same-origin via gateway nginx

header('Content-Type: application/json');

// Auto-migration: run database_migration.sql when VERSION changes
// This ensures schema is always up to date regardless of how files were deployed
// Uses file-based locking to prevent concurrent migration runs
try {
    $version_file = $config['apppath'] . 'VERSION';
    $file_version = file_exists($version_file) ? trim(file_get_contents($version_file)) : '';
    if ($file_version) {
        $sv_row = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'server_version'")->fetch();
        $db_version = ($sv_row && !empty($sv_row['setting_value'])) ? $sv_row['setting_value'] : '';
        if ($db_version !== $file_version) {
            $lock_file = sys_get_temp_dir() . '/pidoors_migration.lock';
            $lock_fp = @fopen($lock_file, 'w');
            if ($lock_fp && flock($lock_fp, LOCK_EX | LOCK_NB)) {
                $migration_file = $config['apppath'] . 'database_migration.sql';
                if (file_exists($migration_file)) {
                    // Try mysql CLI first, fall back to PDO for Docker containers without mysql client
                    $mysql_path = trim(@exec('which mysql 2>/dev/null'));
                    if ($mysql_path) {
                        putenv('MYSQL_PWD=' . $config['sqlpass']);
                        $mig_cmd = sprintf('mysql -h %s -u %s %s < %s 2>&1',
                            escapeshellarg($config['sqladdr']),
                            escapeshellarg($config['sqluser']),
                            escapeshellarg($config['sqldb2']),
                            escapeshellarg($migration_file)
                        );
                        exec($mig_cmd, $mig_output, $mig_code);
                        putenv('MYSQL_PWD');
                        if ($mig_code !== 0) {
                            error_log("Auto-migration CLI failed (exit $mig_code): " . implode(' ', $mig_output ?? []));
                        }
                    } else {
                        // PDO fallback: execute each statement from the migration SQL
                        $sql = file_get_contents($migration_file);
                        if ($sql) {
                            try {
                                $pdo_access->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
                                // Split on semicolons, filtering out empty statements
                                $statements = array_filter(array_map('trim', explode(';', $sql)), function($s) {
                                    return !empty($s) && $s !== 'COMMIT';
                                });
                                foreach ($statements as $stmt_sql) {
                                    try {
                                        $pdo_access->exec($stmt_sql);
                                    } catch (PDOException $stmt_e) {
                                        // Ignore "already exists" type errors, log others
                                        if (strpos($stmt_e->getMessage(), 'already exists') === false &&
                                            strpos($stmt_e->getMessage(), 'Duplicate') === false) {
                                            error_log("Auto-migration statement error: " . $stmt_e->getMessage());
                                        }
                                    }
                                }
                            } catch (Exception $mig_e) {
                                error_log("Auto-migration PDO failed: " . $mig_e->getMessage());
                            }
                        }
                    }
                }
                // Also deploy bundled SPA if present
                $ui_root = '/var/www/pidoors-ui';
                $bundled_dist = rtrim($config['apppath'], '/') . '/pidoors-ui-dist';
                if (is_dir($bundled_dist) && file_exists($bundled_dist . '/index.html')) {
                    if (!is_dir($ui_root)) @mkdir($ui_root, 0755, true);
                    if (is_dir($ui_root) && is_writable($ui_root)) {
                        @exec('rm -rf ' . escapeshellarg($ui_root) . '/*');
                        @exec('cp -r ' . escapeshellarg($bundled_dist) . '/* ' . escapeshellarg($ui_root) . '/');
                        @exec('chown -R www-data:www-data ' . escapeshellarg($ui_root) . ' 2>/dev/null');
                    }
                    @exec('rm -rf ' . escapeshellarg($bundled_dist));
                }
                $pdo_access->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES ('server_version', ?, 'Current server software version') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$file_version]);
                flock($lock_fp, LOCK_UN);
            }
            if ($lock_fp) fclose($lock_fp);
        }
    }
} catch (Exception $e) {
    error_log("Auto-migration check error: " . $e->getMessage());
}

// Load configured timezone for timestamp conversion
$app_timezone = null;
try {
    $tz_row = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'timezone'")->fetch();
    if ($tz_row && !empty($tz_row['setting_value'])) {
        $app_timezone = new DateTimeZone($tz_row['setting_value']);
    }
} catch (Exception $e) {}

// Convert a DB datetime string to the app's configured timezone
function convert_tz(string $datetime): string {
    global $app_timezone;
    if (!$app_timezone || empty($datetime)) return $datetime;
    try {
        $dt = new DateTime($datetime, new DateTimeZone(date_default_timezone_get()));
        $dt->setTimezone($app_timezone);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return $datetime;
    }
}

// Parse route — supports both ?route= (Docker gateway) and PATH_INFO (production nginx)
$route = trim($_GET['route'] ?? $_SERVER['PATH_INFO'] ?? '', '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = $route ? explode('/', $route) : [];
$resource = $segments[0] ?? '';
$id = $segments[1] ?? null;
$action = $segments[2] ?? null;

// Parse JSON body for POST/PUT
$input = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } elseif (strpos($contentType, 'multipart/form-data') !== false) {
        $input = $_POST;
    }
}

// Helper functions
function json_success($data = [], $msg = 'OK') {
    echo json_encode(array_merge(['ok' => true, 'msg' => $msg], $data));
    exit();
}

function json_error($msg, $status = 400) {
    http_response_code($status);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit();
}

function require_auth() {
    if (!is_logged_in()) json_error('Unauthorized', 401);
}

function require_admin_auth() {
    require_auth();
    // Re-verify admin + active status from DB on every admin call
    global $pdo;
    $stmt = $pdo->prepare("SELECT admin, active FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $db_user = $stmt->fetch();
    if (!$db_user || !$db_user['active'] || !$db_user['admin']) {
        json_error('Forbidden', 403);
    }
}

function require_csrf() {
    // Check X-CSRF-Token header (SPA) or body param (legacy)
    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $GLOBALS['input']['csrf_token']
        ?? '';
    if (!verify_csrf_token($token)) {
        json_error('Invalid CSRF token', 403);
    }
}

// ──────────────────────────────────────────────
// AUTH routes (public login, protected others)
// ──────────────────────────────────────────────
if ($resource === 'auth') {
    switch ($id) {
        case 'csrf':
            // Return CSRF token — requires active session but not login
            echo json_encode(['ok' => true, 'token' => generate_csrf_token()]);
            exit();

        case 'login':
            if ($method !== 'POST') json_error('Method not allowed', 405);
            require_csrf();

            $login = trim($input['login'] ?? '');
            $password = $input['password'] ?? '';

            if (empty($login) || empty($password)) {
                json_error('Please enter both username/email and password.');
            }

            // IP-based rate limiting (survives cookie clearing)
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $max_attempts = $config['max_failed_attempts'] ?? 5;
            $lockout_seconds = $config['lockout_duration'] ?? 300;

            $rate_stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE event_type = 'login_failed' AND ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
            $rate_stmt->execute([$ip, $lockout_seconds]);
            if ((int)$rate_stmt->fetchColumn() >= $max_attempts) {
                json_error('Too many failed attempts. Try again later.');
            }

            try {
                $stmt = $pdo->prepare("SELECT id, user_name, user_email, user_pass, admin, active FROM users WHERE user_email = ? OR user_name = ?");
                $stmt->execute([$login, $login]);
                $user = $stmt->fetch();

                $password_valid = false;
                $needs_upgrade = false;

                if ($user) {
                    if (strlen($user['user_pass']) === 32 && ctype_xdigit($user['user_pass'])) {
                        $legacy_hash = md5(($config['legacy_password_salt'] ?? '') . $password);
                        if (hash_equals($user['user_pass'], $legacy_hash)) {
                            $password_valid = true;
                            $needs_upgrade = true;
                        }
                    } else {
                        $password_valid = password_verify($password, $user['user_pass']);
                        if ($password_valid && password_needs_rehash($user['user_pass'], PASSWORD_BCRYPT, ['cost' => 12])) {
                            $needs_upgrade = true;
                        }
                    }
                } else {
                    // Constant-time dummy verify to prevent user enumeration
                    password_verify($password, '$2y$12$000000000000000000000uGHKGnr..PqIq1DSqGCPLlmIvMef/fXu');
                }

                if ($password_valid && $user['active']) {
                    if ($needs_upgrade) {
                        $new_hash = hash_password($password);
                        $pdo->prepare("UPDATE users SET user_pass = ? WHERE id = ?")->execute([$new_hash, $user['id']]);
                    }

                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['email'] = $user['user_email'];
                    $_SESSION['username'] = $user['user_name'];
                    $_SESSION['isadmin'] = ($user['admin'] == 1);
                    $_SESSION['login_time'] = time();

                    try {
                        log_security_event($pdo, 'login_success', $user['id'], "User {$user['user_name']} logged in via API");
                    } catch (Exception $e) {}

                    $version = trim(@file_get_contents($config['apppath'] . 'VERSION') ?: 'unknown');
                    json_success(['user' => [
                        'id' => (int)$user['id'],
                        'username' => $user['user_name'],
                        'email' => $user['user_email'],
                        'isAdmin' => ($user['admin'] == 1),
                        'version' => $version,
                    ]]);
                }

                // Failed login — single unified error message (no attempt count, no deactivation hint)
                try { log_security_event($pdo, 'login_failed', null, "Failed login for: $login"); } catch (Exception $e) {}
                json_error('Invalid username/email or password.');
            } catch (PDOException $e) {
                error_log("API login error: " . $e->getMessage());
                json_error('A system error occurred.', 500);
            }
            break;

        case 'logout':
            if ($method !== 'POST') json_error('Method not allowed', 405);
            require_auth();
            require_csrf();
            try {
                log_security_event($pdo, 'logout', $_SESSION['user_id'] ?? null, "User logged out via API");
            } catch (Exception $e) {}
            $_SESSION = [];
            // Clear session cookie
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
            session_destroy();
            json_success([], 'Logged out');
            break;

        case 'me':
            if (!is_logged_in()) json_error('Not authenticated', 401);
            $version = trim(@file_get_contents($config['apppath'] . 'VERSION') ?: 'unknown');
            json_success(['user' => [
                'id' => (int)$_SESSION['user_id'],
                'username' => $_SESSION['username'],
                'email' => $_SESSION['email'],
                'isAdmin' => $_SESSION['isadmin'] === true,
                'version' => $version,
            ]]);
            break;

        default:
            json_error('Not found', 404);
    }
    exit();
}

// ──────────────────────────────────────────────
// DASHBOARD
// ──────────────────────────────────────────────
if ($resource === 'dashboard' && $method === 'GET') {
    require_auth();
    poll_all_door_status($pdo_access);

    try {
        $total_cards = (int)$pdo_access->query("SELECT COUNT(*) FROM cards")->fetchColumn();
        $active_cards = (int)$pdo_access->query("SELECT COUNT(*) FROM cards WHERE active = 1")->fetchColumn();
        $total_doors = (int)$pdo_access->query("SELECT COUNT(*) FROM doors")->fetchColumn();
        $online_doors = (int)$pdo_access->query("SELECT COUNT(*) FROM doors WHERE status = 'online'")->fetchColumn();
        $today_access = (int)$pdo_access->query("SELECT COUNT(*) FROM logs WHERE DATE(Date) = CURDATE()")->fetchColumn();
        $today_granted = (int)$pdo_access->query("SELECT COUNT(*) FROM logs WHERE DATE(Date) = CURDATE() AND Granted = 1")->fetchColumn();
        $today_denied = (int)$pdo_access->query("SELECT COUNT(*) FROM logs WHERE DATE(Date) = CURDATE() AND Granted = 0")->fetchColumn();

        $doors = $pdo_access->query("SELECT name, location, status, locked, held_open, hold_requested, unlock_requested, push_available, door_sensor_gpio, door_open, door_sensor_invert FROM doors ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        // Cast numeric fields
        foreach ($doors as &$d) {
            $d['locked'] = (int)$d['locked'];
            $d['held_open'] = (int)$d['held_open'];
            $d['hold_requested'] = (int)$d['hold_requested'];
            $d['unlock_requested'] = (int)$d['unlock_requested'];
            $d['push_available'] = (int)($d['push_available'] ?? 0);
            $d['door_sensor_gpio'] = $d['door_sensor_gpio'] !== null ? (int)$d['door_sensor_gpio'] : null;
            $d['door_open'] = $d['door_open'] !== null ? (int)$d['door_open'] : null;
            $d['door_sensor_invert'] = (int)($d['door_sensor_invert'] ?? 0);
        }
        unset($d);

        $recent = $pdo_access->query("
            SELECT l.Date, l.Location, l.Granted, l.user_id, c.firstname, c.lastname
            FROM logs l LEFT JOIN cards c ON l.user_id = c.user_id
            ORDER BY l.Date DESC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recent as &$r) {
            $r['Granted'] = (int)$r['Granted'];
            $r['Date'] = convert_tz($r['Date']);
        }
        unset($r);

        $hourly_stmt = $pdo_access->query("
            SELECT HOUR(Date) as hour, COUNT(*) as count
            FROM logs WHERE Date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY HOUR(Date) ORDER BY hour
        ");
        $hours = array_fill(0, 24, 0);
        foreach ($hourly_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hours[(int)$row['hour']] = (int)$row['count'];
        }
    } catch (PDOException $e) {
        json_error('Database error', 500);
    }

    echo json_encode([
        'total_cards' => $total_cards, 'active_cards' => $active_cards,
        'total_doors' => $total_doors, 'online_doors' => $online_doors,
        'today_access' => $today_access, 'today_granted' => $today_granted,
        'today_denied' => $today_denied,
        'doors' => $doors, 'recent_logs' => $recent,
        'hourly' => array_values($hours),
    ]);
    exit();
}

// ──────────────────────────────────────────────
// DOORS
// ──────────────────────────────────────────────
if ($resource === 'doors') {
    if ($method === 'GET' && $id === null) {
        // List all doors
        require_auth();
        poll_all_door_status($pdo_access);
        $doors = $pdo_access->query("SELECT * FROM doors ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($doors as &$d) {
            $d['locked'] = (int)$d['locked'];
            $d['held_open'] = (int)$d['held_open'];
            $d['hold_requested'] = (int)$d['hold_requested'];
            $d['unlock_requested'] = (int)$d['unlock_requested'];
            $d['lockdown_mode'] = (int)($d['lockdown_mode'] ?? 0);
            $d['update_requested'] = (int)($d['update_requested'] ?? 0);
            $d['doornum'] = (int)($d['doornum'] ?? 0);
            $d['unlock_duration'] = (int)($d['unlock_duration'] ?? 5);
            $d['poll_interval'] = (int)($d['poll_interval'] ?? 10);
            $d['listen_port'] = $d['listen_port'] ? (int)$d['listen_port'] : null;
            $d['push_available'] = (int)($d['push_available'] ?? 0);
            $d['door_sensor_gpio'] = $d['door_sensor_gpio'] !== null ? (int)$d['door_sensor_gpio'] : null;
            $d['door_open'] = $d['door_open'] !== null ? (int)$d['door_open'] : null;
            $d['door_sensor_invert'] = (int)($d['door_sensor_invert'] ?? 0);
            unset($d['api_key']); // Never expose API key to browser
        }
        unset($d);
        json_success(['doors' => $doors]);
    }

    if ($method === 'GET' && $id !== null && $action === null) {
        // Get single door — ping controller for live status
        require_auth();
        $door_name = urldecode($id);

        // Ping this specific controller for fresh status
        $ping = ping_controller($pdo_access, $door_name);
        if (!empty($ping['ok'])) {
            $sets = ['status' => 'online', 'last_seen' => date('Y-m-d H:i:s'), 'push_available' => 1];
            if (isset($ping['locked'])) $sets['locked'] = (int) $ping['locked'];
            if (isset($ping['held_open'])) $sets['held_open'] = (int) $ping['held_open'];
            if (isset($ping['version'])) $sets['controller_version'] = $ping['version'];
            if (array_key_exists('door_open', $ping)) $sets['door_open'] = $ping['door_open'];
            $cols = []; $vals = [];
            foreach ($sets as $col => $val) { $cols[] = "$col = ?"; $vals[] = $val; }
            $vals[] = $door_name;
            $pdo_access->prepare("UPDATE doors SET " . implode(', ', $cols) . " WHERE name = ?")->execute($vals);
        } elseif (isset($ping['reason']) && $ping['reason'] !== 'no_push_config') {
            $pdo_access->prepare("UPDATE doors SET push_available = 0, status = IF(last_seen > NOW() - INTERVAL 600 SECOND, status, 'offline') WHERE name = ?")->execute([$door_name]);
        }

        $stmt = $pdo_access->prepare("SELECT * FROM doors WHERE name = ?");
        $stmt->execute([$door_name]);
        $door = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$door) json_error('Door not found', 404);
        $door['locked'] = (int)$door['locked'];
        $door['held_open'] = (int)$door['held_open'];
        $door['hold_requested'] = (int)$door['hold_requested'];
        $door['unlock_requested'] = (int)$door['unlock_requested'];
        $door['listen_port'] = $door['listen_port'] ? (int)$door['listen_port'] : null;
        $door['push_available'] = (int)($door['push_available'] ?? 0);
        $door['door_sensor_gpio'] = $door['door_sensor_gpio'] !== null ? (int)$door['door_sensor_gpio'] : null;
        $door['door_open'] = $door['door_open'] !== null ? (int)$door['door_open'] : null;
        $door['door_sensor_invert'] = (int)($door['door_sensor_invert'] ?? 0);
        unset($door['api_key']);
        json_success(['door' => $door]);
    }

    if ($method === 'POST' && $id === null) {
        // Add a door
        require_admin_auth();
        require_csrf();
        $name = sanitize_string($input['name'] ?? '');
        if (empty($name)) json_error('Door name is required');

        // Normalize door name: lowercase, spaces to underscores, strip non-alphanumeric
        $name = strtolower(str_replace(' ', '_', $name));
        $name = preg_replace('/[^a-z0-9_]/', '', $name);
        if (empty($name)) json_error('Door name contains no valid characters');

        $stmt = $pdo_access->prepare("SELECT COUNT(*) FROM doors WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetchColumn() > 0) json_error('A door with this name already exists');

        // Whitelist reader_type
        $reader_type = $input['reader_type'] ?? 'wiegand';
        if (!in_array($reader_type, ['wiegand', 'osdp', 'nfc_pn532', 'nfc_mfrc522'])) {
            $reader_type = 'wiegand';
        }

        $stmt = $pdo_access->prepare("INSERT INTO doors (name, location, doornum, description, ip_address, schedule_id, unlock_duration, reader_type, poll_interval, listen_port, door_sensor_gpio, door_sensor_invert, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unknown')");
        $stmt->execute([
            $name,
            sanitize_string($input['location'] ?? ''),
            (int)($input['doornum'] ?? 0),
            sanitize_string($input['description'] ?? ''),
            sanitize_string($input['ip_address'] ?? ''),
            !empty($input['schedule_id']) ? (int)$input['schedule_id'] : null,
            (int)($input['unlock_duration'] ?? 5),
            $reader_type,
            (int)($input['poll_interval'] ?? 10),
            !empty($input['listen_port']) ? (int)$input['listen_port'] : null,
            isset($input['door_sensor_gpio']) && $input['door_sensor_gpio'] !== null && $input['door_sensor_gpio'] !== '' ? (int)$input['door_sensor_gpio'] : null,
            !empty($input['door_sensor_invert']) ? 1 : 0,
        ]);
        log_security_event($pdo, 'door_created', $_SESSION['user_id'], "Door created: $name");
        json_success([], 'Door created');
    }

    if ($method === 'PUT' && $id !== null && $action === null) {
        // Edit a door
        require_admin_auth();
        require_csrf();
        $door_name = urldecode($id);

        $stmt = $pdo_access->prepare("SELECT name FROM doors WHERE name = ?");
        $stmt->execute([$door_name]);
        if (!$stmt->fetch()) json_error('Door not found', 404);

        $fields = [];
        $params = [];
        $allowed = ['location', 'description', 'ip_address'];
        foreach ($allowed as $f) {
            if (isset($input[$f])) {
                $fields[] = "$f = ?";
                $params[] = sanitize_string($input[$f]);
            }
        }
        if (isset($input['reader_type'])) {
            $valid_readers = ['wiegand', 'osdp', 'nfc_pn532', 'nfc_mfrc522'];
            if (!in_array($input['reader_type'], $valid_readers)) {
                json_error('Invalid reader_type. Must be one of: ' . implode(', ', $valid_readers));
            }
            $fields[] = "reader_type = ?";
            $params[] = $input['reader_type'];
        }
        $int_fields = ['doornum', 'unlock_duration', 'poll_interval', 'lockdown_mode'];
        foreach ($int_fields as $f) {
            if (isset($input[$f])) {
                $fields[] = "$f = ?";
                $params[] = (int)$input[$f];
            }
        }
        if (isset($input['schedule_id'])) {
            $fields[] = "schedule_id = ?";
            $params[] = !empty($input['schedule_id']) ? (int)$input['schedule_id'] : null;
        }
        if (array_key_exists('listen_port', $input)) {
            $fields[] = "listen_port = ?";
            $params[] = !empty($input['listen_port']) ? (int)$input['listen_port'] : null;
        }
        if (array_key_exists('door_sensor_gpio', $input)) {
            $fields[] = "door_sensor_gpio = ?";
            $params[] = ($input['door_sensor_gpio'] !== null && $input['door_sensor_gpio'] !== '') ? (int)$input['door_sensor_gpio'] : null;
        }
        if (array_key_exists('door_sensor_invert', $input)) {
            $fields[] = "door_sensor_invert = ?";
            $params[] = !empty($input['door_sensor_invert']) ? 1 : 0;
        }

        if (empty($fields)) json_error('No fields to update');

        $params[] = $door_name;
        $pdo_access->prepare("UPDATE doors SET " . implode(', ', $fields) . " WHERE name = ?")->execute($params);
        log_security_event($pdo, 'door_updated', $_SESSION['user_id'], "Door updated: $door_name");
        json_success([], 'Door updated');
    }

    if ($method === 'DELETE' && $id !== null && $action === null) {
        // Delete a door
        require_admin_auth();
        require_csrf();
        $door_name = urldecode($id);
        $stmt = $pdo_access->prepare("DELETE FROM doors WHERE name = ?");
        $stmt->execute([$door_name]);
        if ($stmt->rowCount() === 0) json_error('Door not found', 404);
        log_security_event($pdo, 'door_deleted', $_SESSION['user_id'], "Door deleted: $door_name");
        json_success([], 'Door deleted');
    }

    if ($method === 'POST' && $id !== null && $action === 'unlock') {
        // Unlock a door — try push first, fall back to DB flag
        require_admin_auth();
        require_csrf();
        $door_name = urldecode($id);

        $stmt = $pdo_access->prepare("SELECT status FROM doors WHERE name = ?");
        $stmt->execute([$door_name]);
        $door = $stmt->fetch();
        if (!$door) json_error('Door not found', 404);
        if ($door['status'] !== 'online') json_error('Door is not online');

        $result = push_to_controller($pdo_access, $door_name, 'unlock');
        $delivery = 'push';
        if ($result['ok']) {
            // Update DB immediately so dashboard poll sees the change right away
            $pdo_access->prepare("UPDATE doors SET locked = 0 WHERE name = ?")->execute([$door_name]);
        } else {
            // Fallback to database flag
            $pdo_access->prepare("UPDATE doors SET unlock_requested = 1 WHERE name = ?")->execute([$door_name]);
            $delivery = 'poll';
        }
        log_security_event($pdo, 'remote_unlock', $_SESSION['user_id'], "Remote unlock via API ($delivery): $door_name");
        json_success(['delivery' => $delivery], 'Unlock command sent');
    }

    if ($method === 'POST' && $id !== null && $action === 'hold') {
        // Hold/release a door — try push first, fall back to DB flag
        require_admin_auth();
        require_csrf();
        $door_name = urldecode($id);
        $hold_action = $input['action'] ?? '';

        if (!in_array($hold_action, ['hold', 'release'])) json_error('Invalid action');

        $stmt = $pdo_access->prepare("SELECT status FROM doors WHERE name = ?");
        $stmt->execute([$door_name]);
        $door = $stmt->fetch();
        if (!$door) json_error('Door not found', 404);
        if ($door['status'] !== 'online') json_error('Door is not online');

        $push_cmd = ($hold_action === 'hold') ? 'hold' : 'release';
        $result = push_to_controller($pdo_access, $door_name, $push_cmd);
        $delivery = 'push';
        if ($result['ok']) {
            // Update DB immediately so dashboard poll sees the change right away
            if ($hold_action === 'hold') {
                $pdo_access->prepare("UPDATE doors SET held_open = 1, locked = 0 WHERE name = ?")->execute([$door_name]);
            } else {
                $pdo_access->prepare("UPDATE doors SET held_open = 0, locked = 1 WHERE name = ?")->execute([$door_name]);
            }
        } else {
            $hold_val = ($hold_action === 'hold') ? 1 : 2;
            $pdo_access->prepare("UPDATE doors SET hold_requested = ? WHERE name = ?")->execute([$hold_val, $door_name]);
            $delivery = 'poll';
        }
        $label = ($hold_action === 'hold') ? 'Hold open' : 'Release hold';
        log_security_event($pdo, 'remote_hold', $_SESSION['user_id'], "$label via API ($delivery): $door_name");
        json_success(['delivery' => $delivery], "$label command sent");
    }

    if ($method === 'POST' && $id !== null && $action === 'ping') {
        // Ping a door controller for live status
        require_admin_auth();
        require_csrf();
        $door_name = urldecode($id);

        $stmt = $pdo_access->prepare("SELECT name FROM doors WHERE name = ?");
        $stmt->execute([$door_name]);
        if (!$stmt->fetch()) json_error('Door not found', 404);

        $result = ping_controller($pdo_access, $door_name);
        if (!empty($result['ok'])) {
            $sets = ['status' => 'online', 'last_seen' => date('Y-m-d H:i:s'), 'push_available' => 1];
            if (isset($result['locked'])) $sets['locked'] = (int) $result['locked'];
            if (isset($result['held_open'])) $sets['held_open'] = (int) $result['held_open'];
            if (isset($result['version'])) $sets['controller_version'] = $result['version'];
            if (array_key_exists('door_open', $result)) $sets['door_open'] = $result['door_open'];
            $cols = []; $vals = [];
            foreach ($sets as $col => $val) { $cols[] = "$col = ?"; $vals[] = $val; }
            $vals[] = $door_name;
            $pdo_access->prepare("UPDATE doors SET " . implode(', ', $cols) . " WHERE name = ?")->execute($vals);
        } else {
            $pdo_access->prepare("UPDATE doors SET push_available = 0, status = IF(last_seen > NOW() - INTERVAL 600 SECOND, status, 'offline') WHERE name = ?")->execute([$door_name]);
        }
        json_success(['ping' => $result]);
    }

    json_error('Not found', 404);
}

// ──────────────────────────────────────────────
// CARDS
// ──────────────────────────────────────────────
if ($resource === 'cards') {
    if ($method === 'GET' && $id === null) {
        require_admin_auth();
        $cards = $pdo_access->query("
            SELECT c.*, s.name as schedule_name, g.name as group_name,
                   CASE WHEN mc.id IS NOT NULL THEN 1 ELSE 0 END AS master_card
            FROM cards c
            LEFT JOIN access_schedules s ON c.schedule_id = s.id
            LEFT JOIN access_groups g ON c.group_id = g.id
            LEFT JOIN master_cards mc ON c.card_id = mc.card_id AND mc.active = 1
            ORDER BY c.lastname, c.firstname
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cards as &$c) {
            $c['active'] = (int)$c['active'];
            $c['master_card'] = (int)$c['master_card'];
            $c['group_id'] = $c['group_id'] !== null ? (int)$c['group_id'] : null;
            $c['schedule_id'] = $c['schedule_id'] !== null ? (int)$c['schedule_id'] : null;
            $c['daily_scan_limit'] = $c['daily_scan_limit'] !== null ? (int)$c['daily_scan_limit'] : null;
        }
        unset($c);
        json_success(['cards' => $cards]);
    }

    if ($id === 'import' && $method === 'POST') {
        require_admin_auth();
        require_csrf();

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            json_error('No file uploaded or upload error');
        }

        // File validation
        $max_size = 5 * 1024 * 1024; // 5MB
        if ($_FILES['file']['size'] > $max_size) {
            json_error('File is too large. Maximum size is 5MB.');
        }
        $mime = mime_content_type($_FILES['file']['tmp_name']);
        if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'])) {
            json_error('Invalid file type. Please upload a CSV file.');
        }

        $file = $_FILES['file']['tmp_name'];
        $handle = fopen($file, 'r');
        if (!$handle) json_error('Failed to read file');

        // Read header row
        $header = fgetcsv($handle);
        if (!$header) { fclose($handle); json_error('Empty CSV file'); }
        $header = array_map('strtolower', array_map('trim', $header));

        $required = ['user_id'];
        foreach ($required as $col) {
            if (!in_array($col, $header)) {
                fclose($handle);
                json_error("Missing required column: $col");
            }
        }

        $skip_duplicates = !empty($input['skip_duplicates'] ?? $_POST['skip_duplicates'] ?? true);
        $default_group = !empty($input['default_group'] ?? $_POST['default_group'] ?? '') ? (int)($input['default_group'] ?? $_POST['default_group']) : null;
        $default_schedule = !empty($input['default_schedule'] ?? $_POST['default_schedule'] ?? '') ? (int)($input['default_schedule'] ?? $_POST['default_schedule']) : null;

        $imported = 0;
        $skipped = 0;
        $errors_list = [];

        $pdo_access->beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) !== count($header)) { $skipped++; continue; }
                $data = array_combine($header, $row);

                $user_id = trim($data['user_id'] ?? '');
                if (empty($user_id)) { $skipped++; continue; }

                // Check if card already exists (by card_id or user_id)
                $check = $pdo_access->prepare("SELECT id FROM cards WHERE user_id = ?" . (isset($data['card_id']) && !empty(trim($data['card_id'])) ? " OR card_id = ?" : ""));
                $check_params = [$user_id];
                if (isset($data['card_id']) && !empty(trim($data['card_id']))) {
                    $check_params[] = trim($data['card_id']);
                }
                $check->execute($check_params);
                if ($check->fetch()) {
                    if ($skip_duplicates) { $skipped++; continue; }
                }

                $group_id = !empty(trim($data['group_id'] ?? '')) ? (int)$data['group_id'] : $default_group;
                $schedule_id = !empty(trim($data['schedule_id'] ?? '')) ? (int)$data['schedule_id'] : $default_schedule;

                try {
                    $stmt = $pdo_access->prepare("INSERT INTO cards (user_id, facility, firstname, lastname, doors, active, email, phone, department, employee_id, company, title, notes, group_id, schedule_id, valid_from, valid_until, pin_code, daily_scan_limit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $user_id,
                        trim($data['facility'] ?? ''),
                        trim($data['firstname'] ?? ''),
                        trim($data['lastname'] ?? ''),
                        trim($data['doors'] ?? ''),
                        (int)($data['active'] ?? 1),
                        trim($data['email'] ?? ''),
                        trim($data['phone'] ?? ''),
                        trim($data['department'] ?? ''),
                        trim($data['employee_id'] ?? ''),
                        trim($data['company'] ?? ''),
                        trim($data['title'] ?? ''),
                        trim($data['notes'] ?? ''),
                        $group_id,
                        $schedule_id,
                        !empty(trim($data['valid_from'] ?? '')) ? trim($data['valid_from']) : null,
                        !empty(trim($data['valid_until'] ?? '')) ? trim($data['valid_until']) : null,
                        trim($data['pin_code'] ?? ''),
                        !empty(trim($data['daily_scan_limit'] ?? '')) ? min(999, max(0, (int)$data['daily_scan_limit'])) : null,
                    ]);

                    // Handle master card
                    $is_master = isset($data['master']) && in_array(strtolower(trim($data['master'])), ['1', 'yes', 'true']);
                    if ($is_master) {
                        $new_cid = (int)$pdo_access->lastInsertId();
                        try {
                            $pdo_access->prepare("INSERT INTO master_cards (card_id, user_id, facility, description, active) VALUES (?, ?, ?, ?, 1)")
                                ->execute([$new_cid, $user_id, trim($data['facility'] ?? ''), trim($data['firstname'] ?? '') . ' ' . trim($data['lastname'] ?? '')]);
                        } catch (PDOException $e) { /* master_cards table may not exist */ }
                    }

                    $imported++;
                } catch (PDOException $e) {
                    $errors_list[] = "Row $user_id: " . $e->getMessage();
                    $skipped++;
                }
            }
            $pdo_access->commit();
        } catch (Exception $e) {
            $pdo_access->rollBack();
            fclose($handle);
            json_error('Import failed: ' . $e->getMessage());
        }
        fclose($handle);

        log_security_event($pdo, 'cards_imported', $_SESSION['user_id'], "CSV import: $imported imported, $skipped skipped");
        json_success(['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors_list], "Imported $imported cards, skipped $skipped");
    }

    if ($id === 'export' && $method === 'GET') {
        require_admin_auth();
        $cards = $pdo_access->query("
            SELECT c.card_id, c.user_id, c.facility, c.firstname, c.lastname, c.email, c.phone,
                   c.department, c.employee_id, c.company, c.title, c.notes,
                   c.group_id, c.schedule_id, c.valid_from, c.valid_until,
                   c.pin_code, c.daily_scan_limit,
                   CASE WHEN mc.id IS NOT NULL THEN 1 ELSE 0 END AS master
            FROM cards c LEFT JOIN master_cards mc ON c.card_id = mc.card_id
            ORDER BY c.lastname, c.firstname
        ")->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="cards_export_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        // UTF-8 BOM for Excel compatibility
        fwrite($out, "\xEF\xBB\xBF");
        if (!empty($cards)) {
            fputcsv($out, array_keys($cards[0]));
            foreach ($cards as $card) fputcsv($out, $card);
        }
        fclose($out);
        exit();
    }

    if ($method === 'GET' && $id !== null) {
        require_admin_auth();
        $stmt = $pdo_access->prepare("SELECT * FROM cards WHERE card_id = ?");
        $stmt->execute([$id]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$card) json_error('Card not found', 404);
        $card['active'] = (int)$card['active'];
        json_success(['card' => $card]);
    }

    if ($method === 'POST' && $id === null) {
        require_admin_auth();
        require_csrf();

        $user_id = trim($input['user_id'] ?? '');
        if (empty($user_id)) json_error('Card number (user_id) is required');

        $check = $pdo_access->prepare("SELECT card_id FROM cards WHERE user_id = ?");
        $check->execute([$user_id]);
        if ($check->fetch()) json_error('A card with this number already exists');

        // Generate card_id if not provided (hex identifier for Wiegand matching)
        $card_id = trim($input['card_id'] ?? '');
        if (empty($card_id)) {
            $card_id = bin2hex(random_bytes(4));
        }

        $stmt = $pdo_access->prepare("INSERT INTO cards (card_id, user_id, facility, firstname, lastname, doors, active, group_id, schedule_id, valid_from, valid_until, daily_scan_limit, email, phone, department, employee_id, company, title, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $card_id,
            $user_id,
            sanitize_string($input['facility'] ?? ''),
            sanitize_string($input['firstname'] ?? ''),
            sanitize_string($input['lastname'] ?? ''),
            sanitize_string($input['doors'] ?? ''),
            (int)($input['active'] ?? 1),
            !empty($input['group_id']) ? (int)$input['group_id'] : null,
            !empty($input['schedule_id']) ? (int)$input['schedule_id'] : null,
            !empty($input['valid_from']) ? $input['valid_from'] : null,
            !empty($input['valid_until']) ? $input['valid_until'] : null,
            !empty($input['daily_scan_limit']) ? (int)$input['daily_scan_limit'] : null,
            sanitize_string($input['email'] ?? ''),
            sanitize_string($input['phone'] ?? ''),
            sanitize_string($input['department'] ?? ''),
            sanitize_string($input['employee_id'] ?? ''),
            sanitize_string($input['company'] ?? ''),
            sanitize_string($input['title'] ?? ''),
            sanitize_string($input['notes'] ?? ''),
        ]);

        $new_card_id = $card_id;

        // Handle master card
        if (!empty($input['master_card']) && !empty($new_card_id)) {
            try {
                $pdo_access->prepare("INSERT INTO master_cards (card_id, user_id, facility, description, active) VALUES (?, ?, ?, ?, 1)")
                    ->execute([$new_card_id, $user_id, sanitize_string($input['facility'] ?? ''), sanitize_string($input['firstname'] ?? '') . ' ' . sanitize_string($input['lastname'] ?? '')]);
            } catch (PDOException $e) { /* master_cards table may not exist */ }
        }

        log_security_event($pdo, 'card_created', $_SESSION['user_id'], "Card created: $user_id");
        json_success(['card_id' => $new_card_id], 'Card created');
    }

    if ($method === 'PUT' && $id !== null) {
        require_admin_auth();
        require_csrf();

        $stmt = $pdo_access->prepare("SELECT card_id FROM cards WHERE card_id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) json_error('Card not found', 404);

        $fields = [];
        $params = [];
        $string_fields = ['user_id', 'facility', 'firstname', 'lastname', 'doors', 'email', 'phone', 'department', 'employee_id', 'company', 'title', 'notes'];
        foreach ($string_fields as $f) {
            if (isset($input[$f])) {
                $fields[] = "$f = ?";
                $params[] = sanitize_string($input[$f]);
            }
        }
        if (isset($input['active'])) { $fields[] = "active = ?"; $params[] = (int)$input['active']; }
        if (isset($input['group_id'])) { $fields[] = "group_id = ?"; $params[] = !empty($input['group_id']) ? (int)$input['group_id'] : null; }
        if (isset($input['schedule_id'])) { $fields[] = "schedule_id = ?"; $params[] = !empty($input['schedule_id']) ? (int)$input['schedule_id'] : null; }
        if (isset($input['valid_from'])) { $fields[] = "valid_from = ?"; $params[] = !empty($input['valid_from']) ? $input['valid_from'] : null; }
        if (isset($input['valid_until'])) { $fields[] = "valid_until = ?"; $params[] = !empty($input['valid_until']) ? $input['valid_until'] : null; }
        if (isset($input['daily_scan_limit'])) { $fields[] = "daily_scan_limit = ?"; $params[] = !empty($input['daily_scan_limit']) ? (int)$input['daily_scan_limit'] : null; }

        if (empty($fields) && !isset($input['master_card'])) json_error('No fields to update');

        if (!empty($fields)) {
            $params[] = $id;
            $pdo_access->prepare("UPDATE cards SET " . implode(', ', $fields) . " WHERE card_id = ?")->execute($params);
        }

        // Sync master_card status
        if (isset($input['master_card'])) {
            try {
                $mc_stmt = $pdo_access->prepare("SELECT id FROM master_cards WHERE card_id = ? AND active = 1");
                $mc_stmt->execute([$id]);
                $is_master = (bool)$mc_stmt->fetch();

                if ($input['master_card'] && !$is_master) {
                    // Get card details for master_cards entry
                    $card_detail = $pdo_access->prepare("SELECT user_id, facility, firstname, lastname FROM cards WHERE card_id = ?");
                    $card_detail->execute([$id]);
                    $cd = $card_detail->fetch(PDO::FETCH_ASSOC);
                    if ($cd) {
                        $pdo_access->prepare("INSERT INTO master_cards (card_id, user_id, facility, description, active) VALUES (?, ?, ?, ?, 1)")
                            ->execute([$id, $cd['user_id'], $cd['facility'], $cd['firstname'] . ' ' . $cd['lastname']]);
                    }
                } elseif (!$input['master_card'] && $is_master) {
                    $pdo_access->prepare("DELETE FROM master_cards WHERE card_id = ?")->execute([$id]);
                }
            } catch (PDOException $e) { /* master_cards table may not exist */ }
        }

        log_security_event($pdo, 'card_updated', $_SESSION['user_id'], "Card updated: card_id=$id");
        json_success([], 'Card updated');
    }

    if ($method === 'DELETE' && $id !== null) {
        require_admin_auth();
        require_csrf();
        // Get card info for audit log
        $stmt = $pdo_access->prepare("SELECT user_id, firstname, lastname FROM cards WHERE card_id = ?");
        $stmt->execute([$id]);
        $card_info = $stmt->fetch();
        if (!$card_info) json_error('Card not found', 404);

        // Delete from master_cards first (foreign key)
        $pdo_access->prepare("DELETE FROM master_cards WHERE card_id = ?")->execute([$id]);
        // Delete card
        $pdo_access->prepare("DELETE FROM cards WHERE card_id = ?")->execute([$id]);
        log_security_event($pdo, 'card_deleted', $_SESSION['user_id'], "Card deleted: {$card_info['firstname']} {$card_info['lastname']} (card_id=$id)");
        json_success([], 'Card deleted');
    }

    json_error('Not found', 404);
}

// ──────────────────────────────────────────────
// LOGS
// ──────────────────────────────────────────────
if ($resource === 'logs') {
    if ($id === 'export' && $method === 'GET') {
        require_admin_auth();

        $where = [];
        $params = [];

        if (!empty($_GET['from'])) { $where[] = "l.Date >= ?"; $params[] = $_GET['from']; }
        if (!empty($_GET['to'])) { $where[] = "l.Date <= ?"; $params[] = $_GET['to'] . ' 23:59:59'; }
        if (!empty($_GET['door'])) { $where[] = "l.Location = ?"; $params[] = $_GET['door']; }
        if (isset($_GET['granted']) && $_GET['granted'] !== '') { $where[] = "l.Granted = ?"; $params[] = (int)$_GET['granted']; }
        if (!empty($_GET['search'])) { $where[] = "(c.firstname LIKE ? OR c.lastname LIKE ? OR l.user_id LIKE ?)"; $s = '%' . $_GET['search'] . '%'; $params = array_merge($params, [$s, $s, $s]); }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT l.Date, l.user_id, l.Location, l.Granted, l.doorip, c.firstname, c.lastname, c.card_id FROM logs l LEFT JOIN cards c ON l.user_id = c.user_id $whereClause ORDER BY l.Date DESC LIMIT 10000";
        $stmt = $pdo_access->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="access_logs_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        // UTF-8 BOM for Excel compatibility
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Date/Time', 'User Name', 'Card ID', 'User ID', 'Location', 'Door IP', 'Status']);
        foreach ($logs as $log) {
            $name = trim(($log['firstname'] ?? '') . ' ' . ($log['lastname'] ?? ''));
            fputcsv($out, [
                convert_tz($log['Date']),
                $name ?: "User #{$log['user_id']}",
                $log['card_id'] ?? '',
                $log['user_id'],
                $log['Location'],
                $log['doorip'] ?? '',
                $log['Granted'] ? 'Granted' : 'Denied',
            ]);
        }
        fclose($out);
        log_security_event($pdo, 'log_export', $_SESSION['user_id'], "Log export: " . count($logs) . " records");
        exit();
    }

    if ($method === 'GET') {
        require_auth();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];

        if (!empty($_GET['from'])) { $where[] = "l.Date >= ?"; $params[] = $_GET['from']; }
        if (!empty($_GET['to'])) { $where[] = "l.Date <= ?"; $params[] = $_GET['to'] . ' 23:59:59'; }
        if (!empty($_GET['door'])) { $where[] = "l.Location = ?"; $params[] = $_GET['door']; }
        if (isset($_GET['granted']) && $_GET['granted'] !== '') { $where[] = "l.Granted = ?"; $params[] = (int)$_GET['granted']; }
        if (!empty($_GET['search'])) { $where[] = "(c.firstname LIKE ? OR c.lastname LIKE ? OR l.user_id LIKE ?)"; $s = '%' . $_GET['search'] . '%'; $params = array_merge($params, [$s, $s, $s]); }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count
        $countSql = "SELECT COUNT(*) FROM logs l LEFT JOIN cards c ON l.user_id = c.user_id $whereClause";
        $countStmt = $pdo_access->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Data
        $sql = "SELECT l.Date, l.user_id, l.Location, l.Granted, l.doorip, c.card_id, c.firstname, c.lastname, c.active AS card_active
                FROM logs l LEFT JOIN cards c ON l.user_id = c.user_id $whereClause
                ORDER BY l.Date DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $pdo_access->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($logs as &$l) {
            $l['Granted'] = (int)$l['Granted'];
            $l['Date'] = convert_tz($l['Date']);
        }
        unset($l);

        json_success(['logs' => $logs, 'total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => ceil($total / $limit)]);
    }

    json_error('Not found', 404);
}

// ──────────────────────────────────────────────
// SCHEDULES
// ──────────────────────────────────────────────
if ($resource === 'schedules') {
    if ($method === 'GET' && $id === null) {
        require_admin_auth();
        $schedules = $pdo_access->query("SELECT * FROM access_schedules ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($schedules as &$s) { $s['id'] = (int)$s['id']; $s['is_24_7'] = (int)$s['is_24_7']; }
        unset($s);
        json_success(['schedules' => $schedules]);
    }

    if ($method === 'POST' && $id === null) {
        require_admin_auth();
        require_csrf();
        $name = sanitize_string($input['name'] ?? '');
        if (empty($name)) json_error('Schedule name is required');

        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $cols = ['name', 'description', 'is_24_7'];
        $vals = [$name, sanitize_string($input['description'] ?? ''), (int)($input['is_24_7'] ?? 0)];
        foreach ($days as $day) {
            $cols[] = "{$day}_start";
            $cols[] = "{$day}_end";
            $vals[] = !empty($input["{$day}_start"]) ? $input["{$day}_start"] : null;
            $vals[] = !empty($input["{$day}_end"]) ? $input["{$day}_end"] : null;
        }
        $placeholders = implode(', ', array_fill(0, count($vals), '?'));
        try {
            $pdo_access->prepare("INSERT INTO access_schedules (" . implode(', ', $cols) . ") VALUES ($placeholders)")->execute($vals);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) json_error('A schedule with this name already exists');
            throw $e;
        }
        log_security_event($pdo, 'schedule_created', $_SESSION['user_id'], "Schedule created: $name");
        json_success(['id' => (int)$pdo_access->lastInsertId()], 'Schedule created');
    }

    if ($method === 'GET' && $id !== null) {
        require_admin_auth();
        $stmt = $pdo_access->prepare("SELECT * FROM access_schedules WHERE id = ?");
        $stmt->execute([(int)$id]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$schedule) json_error('Schedule not found', 404);
        $schedule['id'] = (int)$schedule['id'];
        $schedule['is_24_7'] = (int)$schedule['is_24_7'];
        json_success(['schedule' => $schedule]);
    }

    if ($method === 'PUT' && $id !== null) {
        require_admin_auth();
        require_csrf();

        $fields = [];
        $params = [];
        if (isset($input['name'])) { $fields[] = "name = ?"; $params[] = sanitize_string($input['name']); }
        if (isset($input['description'])) { $fields[] = "description = ?"; $params[] = sanitize_string($input['description']); }
        if (isset($input['is_24_7'])) { $fields[] = "is_24_7 = ?"; $params[] = (int)$input['is_24_7']; }
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        foreach ($days as $day) {
            if (isset($input["{$day}_start"])) { $fields[] = "{$day}_start = ?"; $params[] = !empty($input["{$day}_start"]) ? $input["{$day}_start"] : null; }
            if (isset($input["{$day}_end"])) { $fields[] = "{$day}_end = ?"; $params[] = !empty($input["{$day}_end"]) ? $input["{$day}_end"] : null; }
        }

        if (empty($fields)) json_error('No fields to update');
        $params[] = (int)$id;
        $pdo_access->prepare("UPDATE access_schedules SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        log_security_event($pdo, 'schedule_updated', $_SESSION['user_id'], "Schedule updated: id=$id");
        json_success([], 'Schedule updated');
    }

    if ($method === 'DELETE' && $id !== null) {
        require_admin_auth();
        require_csrf();
        $stmt = $pdo_access->prepare("DELETE FROM access_schedules WHERE id = ?");
        $stmt->execute([(int)$id]);
        if ($stmt->rowCount() === 0) json_error('Schedule not found', 404);
        log_security_event($pdo, 'schedule_deleted', $_SESSION['user_id'], "Schedule deleted: id=$id");
        json_success([], 'Schedule deleted');
    }

    json_error('Not found', 404);
}

// ──────────────────────────────────────────────
// GROUPS
// ──────────────────────────────────────────────
if ($resource === 'groups') {
    if ($method === 'GET' && $id === null) {
        require_admin_auth();
        $groups = $pdo_access->query("SELECT g.*, COUNT(c.id) as member_count FROM access_groups g LEFT JOIN cards c ON g.id = c.group_id GROUP BY g.id ORDER BY g.name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($groups as &$g) { $g['id'] = (int)$g['id']; $g['member_count'] = (int)$g['member_count']; }
        unset($g);
        json_success(['groups' => $groups]);
    }

    if ($method === 'POST' && $id === null) {
        require_admin_auth();
        require_csrf();
        $name = sanitize_string($input['name'] ?? '');
        if (empty($name)) json_error('Group name is required');

        $doors = $input['doors'] ?? '[]';
        if (is_array($doors)) $doors = json_encode($doors);

        $stmt = $pdo_access->prepare("INSERT INTO access_groups (name, description, doors) VALUES (?, ?, ?)");
        $stmt->execute([$name, sanitize_string($input['description'] ?? ''), $doors]);
        log_security_event($pdo, 'group_created', $_SESSION['user_id'], "Access group created: $name");
        json_success(['id' => (int)$pdo_access->lastInsertId()], 'Group created');
    }

    if ($method === 'PUT' && $id !== null) {
        require_admin_auth();
        require_csrf();

        $fields = [];
        $params = [];
        if (isset($input['name'])) { $fields[] = "name = ?"; $params[] = sanitize_string($input['name']); }
        if (isset($input['description'])) { $fields[] = "description = ?"; $params[] = sanitize_string($input['description']); }
        if (isset($input['doors'])) {
            $doors = is_array($input['doors']) ? json_encode($input['doors']) : $input['doors'];
            $fields[] = "doors = ?";
            $params[] = $doors;
        }

        if (empty($fields)) json_error('No fields to update');
        $params[] = (int)$id;
        $pdo_access->prepare("UPDATE access_groups SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        log_security_event($pdo, 'group_updated', $_SESSION['user_id'], "Access group updated: id=$id");
        json_success([], 'Group updated');
    }

    if ($method === 'DELETE' && $id !== null) {
        require_admin_auth();
        require_csrf();

        // Check if any cards reference this group
        $ref_check = $pdo_access->prepare("SELECT COUNT(*) FROM cards WHERE group_id = ?");
        $ref_check->execute([(int)$id]);
        if ($ref_check->fetchColumn() > 0) {
            json_error('Cannot delete group: cards are still assigned to it');
        }

        $stmt = $pdo_access->prepare("DELETE FROM access_groups WHERE id = ?");
        $stmt->execute([(int)$id]);
        if ($stmt->rowCount() === 0) json_error('Group not found', 404);
        log_security_event($pdo, 'group_deleted', $_SESSION['user_id'], "Access group deleted: id=$id");
        json_success([], 'Group deleted');
    }

    json_error('Not found', 404);
}

// ──────────────────────────────────────────────
// HOLIDAYS
// ──────────────────────────────────────────────
if ($resource === 'holidays') {
    if ($method === 'GET' && $id === null) {
        require_admin_auth();
        $holidays = $pdo_access->query("SELECT * FROM holidays ORDER BY date")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($holidays as &$h) {
            $h['id'] = (int)$h['id'];
            $h['recurring'] = (int)$h['recurring'];
            $h['access_denied'] = (int)($h['access_denied'] ?? 1);
        }
        unset($h);
        json_success(['holidays' => $holidays]);
    }

    if ($method === 'POST' && $id === null) {
        require_admin_auth();
        require_csrf();
        $name = sanitize_string($input['name'] ?? '');
        $date = $input['date'] ?? '';
        if (empty($name) || empty($date)) json_error('Name and date are required');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_error('Invalid date format (expected YYYY-MM-DD)');

        $stmt = $pdo_access->prepare("INSERT INTO holidays (name, date, recurring, access_denied) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $date, (int)($input['recurring'] ?? 0), (int)($input['access_denied'] ?? 1)]);
        log_security_event($pdo, 'holiday_created', $_SESSION['user_id'], "Holiday created: $name");
        json_success(['id' => (int)$pdo_access->lastInsertId()], 'Holiday created');
    }

    if ($method === 'PUT' && $id !== null) {
        require_admin_auth();
        require_csrf();
        $fields = [];
        $params = [];
        if (isset($input['name'])) { $fields[] = "name = ?"; $params[] = sanitize_string($input['name']); }
        if (isset($input['date'])) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['date'])) json_error('Invalid date format (expected YYYY-MM-DD)');
            $fields[] = "date = ?"; $params[] = $input['date'];
        }
        if (isset($input['recurring'])) { $fields[] = "recurring = ?"; $params[] = (int)$input['recurring']; }
        if (isset($input['access_denied'])) { $fields[] = "access_denied = ?"; $params[] = (int)$input['access_denied']; }
        if (empty($fields)) json_error('No fields to update');
        $params[] = (int)$id;
        $pdo_access->prepare("UPDATE holidays SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        json_success([], 'Holiday updated');
    }

    if ($method === 'DELETE' && $id !== null) {
        require_admin_auth();
        require_csrf();
        $stmt = $pdo_access->prepare("DELETE FROM holidays WHERE id = ?");
        $stmt->execute([(int)$id]);
        if ($stmt->rowCount() === 0) json_error('Holiday not found', 404);
        json_success([], 'Holiday deleted');
    }

    json_error('Not found', 404);
}

// ──────────────────────────────────────────────
// SETTINGS
// ──────────────────────────────────────────────
if ($resource === 'settings') {
    if ($id === 'test-email' && $method === 'POST') {
        require_admin_auth();
        require_csrf();

        $to = sanitize_string($input['to'] ?? '');
        if (empty($to)) json_error('Email address is required');

        // Load settings from DB
        $settings = [];
        $rows = $pdo_access->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) $settings[$row['setting_key']] = $row['setting_value'];

        if (empty($settings['smtp_host'])) json_error('SMTP is not configured');

        require_once $config['apppath'] . 'includes/smtp.php';
        try {
            $result = send_test_email($to, $settings);
            if ($result === true) {
                json_success([], 'Test email sent successfully');
            } else {
                json_error($result ?: 'Failed to send test email');
            }
        } catch (Exception $e) {
            json_error('SMTP error: ' . $e->getMessage());
        }
    }

    if ($method === 'GET') {
        require_admin_auth();
        $settings = [];
        $rows = $pdo_access->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) $settings[$row['setting_key']] = $row['setting_value'];
        // Mask sensitive values
        if (isset($settings['smtp_pass'])) $settings['smtp_pass'] = $settings['smtp_pass'] ? '••••••••' : '';
        json_success(['settings' => $settings]);
    }

    if ($method === 'POST' && $id === null) {
        require_admin_auth();
        require_csrf();

        $settings = $input['settings'] ?? $input;
        if (empty($settings) || !is_array($settings)) json_error('No settings provided');

        // Whitelist of allowed settings — must match keys used by PHP UI
        $allowed = [
            'site_name', 'session_timeout', 'password_min_length', 'password_require_mixed_case',
            'password_require_numbers', 'max_login_attempts', 'lockout_duration',
            'max_unlock_duration', 'default_unlock_duration', 'default_daily_scan_limit',
            'heartbeat_interval', 'cache_duration',
            'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from',
            'email_notifications', 'notification_email',
            'log_retention_days', 'timezone',
            'controller_update_url', 'default_poll_interval',
            'default_listen_port', 'push_timeout', 'push_fallback_poll_interval', 'status_check_timeout',
            'maintenance_mode', 'auto_backup', 'backup_retention_days',
            'auto_update_check',
        ];

        // Load current settings to preserve smtp_pass if not changed
        $current = [];
        $cur_rows = $pdo_access->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cur_rows as $r) $current[$r['setting_key']] = $r['setting_value'];

        foreach ($settings as $key => $value) {
            if (!in_array($key, $allowed)) continue;
            // Don't overwrite password with mask or empty value
            if ($key === 'smtp_pass' && ($value === '••••••••' || $value === '')) continue;

            $stmt = $pdo_access->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([$key, $value]);
        }

        log_security_event($pdo, 'settings_updated', $_SESSION['user_id'], 'Settings updated via API');
        json_success([], 'Settings saved');
    }

    json_error('Not found', 404);
}

// ──────────────────────────────────────────────
// USERS (panel users)
// ──────────────────────────────────────────────
if ($resource === 'users') {
    if ($method === 'GET' && $id === null) {
        require_admin_auth();
        $users = $pdo->query("SELECT id, user_name, user_email, admin, active, first_name, last_name, phone, department, employee_id, company, job_title, notes, created_at, last_login FROM users ORDER BY user_name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as &$u) {
            $u['id'] = (int)$u['id'];
            $u['admin'] = (int)$u['admin'];
            $u['active'] = (int)$u['active'];
        }
        unset($u);
        json_success(['users' => $users]);
    }

    if ($method === 'GET' && $id !== null) {
        require_admin_auth();
        $stmt = $pdo->prepare("SELECT id, user_name, user_email, admin, active, first_name, last_name, phone, department, employee_id, company, job_title, notes, created_at, last_login FROM users WHERE id = ?");
        $stmt->execute([(int)$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) json_error('User not found', 404);
        $user['id'] = (int)$user['id'];
        $user['admin'] = (int)$user['admin'];
        $user['active'] = (int)$user['active'];
        json_success(['user' => $user]);
    }

    if ($method === 'POST' && $id === null) {
        require_admin_auth();
        require_csrf();

        $username = sanitize_string($input['user_name'] ?? '');
        $email = sanitize_email($input['user_email'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            json_error('Username, email, and password are required');
        }
        if (!validate_email($email)) json_error('Invalid email address');

        $pw_check = validate_password($password, $config);
        if ($pw_check !== true) json_error($pw_check);

        // Check uniqueness
        $check = $pdo->prepare("SELECT id FROM users WHERE user_name = ? OR user_email = ?");
        $check->execute([$username, $email]);
        if ($check->fetch()) json_error('Username or email already exists');

        $hash = hash_password($password);
        $stmt = $pdo->prepare("INSERT INTO users (user_name, user_email, user_pass, admin, active, first_name, last_name, phone, department, employee_id, company, job_title, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $username, $email, $hash,
            (int)($input['admin'] ?? 0),
            (int)($input['active'] ?? 1),
            sanitize_string($input['first_name'] ?? ''),
            sanitize_string($input['last_name'] ?? ''),
            sanitize_string($input['phone'] ?? ''),
            sanitize_string($input['department'] ?? ''),
            sanitize_string($input['employee_id'] ?? ''),
            sanitize_string($input['company'] ?? ''),
            sanitize_string($input['job_title'] ?? ''),
            sanitize_string($input['notes'] ?? ''),
        ]);

        log_security_event($pdo, 'user_created', $_SESSION['user_id'], "User created: $username");
        json_success(['id' => (int)$pdo->lastInsertId()], 'User created');
    }

    if ($method === 'PUT' && $id !== null) {
        require_admin_auth();
        require_csrf();

        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([(int)$id]);
        if (!$stmt->fetch()) json_error('User not found', 404);

        $fields = [];
        $params = [];
        $string_fields = ['user_name', 'user_email', 'first_name', 'last_name', 'phone', 'department', 'employee_id', 'company', 'job_title', 'notes'];
        foreach ($string_fields as $f) {
            if (isset($input[$f])) {
                $fields[] = "$f = ?";
                $params[] = $f === 'user_email' ? sanitize_email($input[$f]) : sanitize_string($input[$f]);
            }
        }
        if (isset($input['admin'])) {
            // Prevent removing own admin rights
            if ((int)$id === (int)$_SESSION['user_id'] && !(int)$input['admin']) {
                json_error('Cannot remove your own admin privileges');
            }
            $fields[] = "admin = ?"; $params[] = (int)$input['admin'];
        }
        if (isset($input['active'])) { $fields[] = "active = ?"; $params[] = (int)$input['active']; }
        if (!empty($input['password'])) {
            $pw_check = validate_password($input['password'], $config);
            if ($pw_check !== true) json_error($pw_check);
            $fields[] = "user_pass = ?";
            $params[] = hash_password($input['password']);
        }

        if (empty($fields)) json_error('No fields to update');
        $params[] = (int)$id;
        $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        log_security_event($pdo, 'user_updated', $_SESSION['user_id'], "User updated: id=$id");
        json_success([], 'User updated');
    }

    if ($method === 'DELETE' && $id !== null) {
        require_admin_auth();
        require_csrf();

        // Prevent self-deletion
        if ((int)$id === (int)$_SESSION['user_id']) json_error('Cannot delete your own account');

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([(int)$id]);
        if ($stmt->rowCount() === 0) json_error('User not found', 404);
        log_security_event($pdo, 'user_deleted', $_SESSION['user_id'], "User deleted: id=$id");
        json_success([], 'User deleted');
    }

    json_error('Not found', 404);
}

// ──────────────────────────────────────────────
// PROFILE (current user)
// ──────────────────────────────────────────────
if ($resource === 'profile') {
    require_auth();

    if ($id === 'password' && $method === 'POST') {
        require_csrf();
        $current = $input['current_password'] ?? '';
        $new_pass = $input['new_password'] ?? '';

        if (empty($current) || empty($new_pass)) json_error('Current and new password are required');

        $stmt = $pdo->prepare("SELECT user_pass FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) json_error('User not found', 404);

        // Support legacy MD5 password verification
        $password_valid = false;
        if (strlen($user['user_pass']) === 32 && ctype_xdigit($user['user_pass'])) {
            $legacy_hash = md5(($config['legacy_password_salt'] ?? '') . $current);
            $password_valid = hash_equals($user['user_pass'], $legacy_hash);
        } else {
            $password_valid = password_verify($current, $user['user_pass']);
        }
        if (!$password_valid) {
            json_error('Current password is incorrect');
        }

        $pw_check = validate_password($new_pass, $config);
        if ($pw_check !== true) json_error($pw_check);

        $pdo->prepare("UPDATE users SET user_pass = ? WHERE id = ?")->execute([hash_password($new_pass), $_SESSION['user_id']]);
        log_security_event($pdo, 'password_changed', $_SESSION['user_id'], 'Password changed via API');
        json_success([], 'Password changed');
    }

    if ($method === 'GET') {
        $stmt = $pdo->prepare("SELECT id, user_name, user_email, admin, first_name, last_name, phone, department, employee_id, company, job_title, created_at, last_login FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($profile) {
            $profile['id'] = (int) $profile['id'];
            $profile['admin'] = (int) $profile['admin'];
        }
        json_success(['profile' => $profile]);
    }

    if ($method === 'PUT') {
        require_csrf();
        $fields = [];
        $params = [];
        $allowed = ['first_name', 'last_name', 'phone', 'department', 'company', 'job_title'];
        foreach ($allowed as $f) {
            if (isset($input[$f])) {
                $fields[] = "$f = ?";
                $params[] = sanitize_string($input[$f]);
            }
        }
        if (isset($input['user_email'])) {
            $email = sanitize_email($input['user_email']);
            if (!validate_email($email)) json_error('Invalid email');
            $fields[] = "user_email = ?";
            $params[] = $email;
        }
        if (empty($fields)) json_error('No fields to update');
        $params[] = $_SESSION['user_id'];
        $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        // Update session email if changed
        if (isset($input['user_email'])) $_SESSION['email'] = $email;
        json_success([], 'Profile updated');
    }

    json_error('Not found', 404);
}

// ──────────────────────────────────────────────
// AUDIT LOG
// ──────────────────────────────────────────────
if ($resource === 'audit') {
    if ($method === 'GET') {
        require_admin_auth();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];
        if (!empty($_GET['type'])) { $where[] = "a.event_type = ?"; $params[] = $_GET['type']; }
        if (!empty($_GET['from'])) { $where[] = "a.created_at >= ?"; $params[] = $_GET['from']; }
        if (!empty($_GET['to'])) { $where[] = "a.created_at <= ?"; $params[] = $_GET['to'] . ' 23:59:59'; }
        if (!empty($_GET['search'])) { $where[] = "(a.details LIKE ? OR u.user_name LIKE ?)"; $s = '%' . $_GET['search'] . '%'; $params = array_merge($params, [$s, $s]); }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id $whereClause";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT a.*, u.user_name as username FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id $whereClause ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($logs as &$l) {
            $l['id'] = (int)$l['id'];
            if (!empty($l['created_at'])) $l['created_at'] = convert_tz($l['created_at']);
        }
        unset($l);

        // Get distinct event types for filter dropdown
        $types = $pdo->query("SELECT DISTINCT event_type FROM audit_logs ORDER BY event_type")->fetchAll(PDO::FETCH_COLUMN);

        json_success(['logs' => $logs, 'total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => ceil($total / $limit), 'event_types' => $types]);
    }

    json_error('Not found', 404);
}

// ──────────────────────────────────────────────
// BACKUPS
// ──────────────────────────────────────────────
if ($resource === 'backups') {
    require_admin_auth();

    $backup_dir = '/var/backups/pidoors/';

    if ($method === 'GET' && $id === null) {
        $backups = [];
        if (is_dir($backup_dir)) {
            $files = glob($backup_dir . '*.sql*');
            foreach ($files as $file) {
                $backups[] = [
                    'name' => basename($file),
                    'size' => filesize($file),
                    'date' => date('Y-m-d H:i:s', filemtime($file)),
                ];
            }
            usort($backups, fn($a, $b) => strcmp($b['date'], $a['date']));
        }
        json_success(['backups' => $backups]);
    }

    if ($method === 'POST' && $id === null) {
        require_csrf();

        if (!is_dir($backup_dir)) {
            if (!@mkdir($backup_dir, 0750, true)) json_error('Cannot create backup directory');
        }

        $filename = 'pidoors_backup_' . date('Y-m-d_His') . '.sql';
        $filepath = $backup_dir . $filename;

        $db_host = $config['sqladdr'];
        $db_user = $config['sqluser'];
        $db_pass = $config['sqlpass'];
        $db_name1 = $config['sqldb'];
        $db_name2 = $config['sqldb2'];

        // Try mysqldump first, fall back to PHP PDO backup
        $mysqldump_path = trim(shell_exec('which mysqldump 2>/dev/null') ?: '');
        if (!empty($mysqldump_path)) {
            putenv('MYSQL_PWD=' . $db_pass);
            $cmd = "mysqldump -h " . escapeshellarg($db_host) . " -u " . escapeshellarg($db_user) . " --databases " . escapeshellarg($db_name1) . " " . escapeshellarg($db_name2) . " > " . escapeshellarg($filepath) . " 2>&1";
            exec($cmd, $output, $return_code);
            putenv('MYSQL_PWD');
            if ($return_code !== 0) {
                @unlink($filepath);
                json_error('Backup failed: ' . implode("\n", $output));
            }
        } else {
            // PHP PDO fallback (matches backup.php approach)
            $sql_content = "-- PiDoors Backup\n-- Date: " . date('Y-m-d H:i:s') . "\n\n";
            foreach ([$pdo => $db_name1, $pdo_access => $db_name2] as $db_pdo => $db_name) {
                $sql_content .= "-- Database: $db_name\n\n";
                $tables = $db_pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $create = $db_pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                    $sql_content .= "DROP TABLE IF EXISTS `$table`;\n";
                    $sql_content .= $create['Create Table'] . ";\n\n";
                    $rows = $db_pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        $vals = array_map(function($v) use ($db_pdo) {
                            return $v === null ? 'NULL' : $db_pdo->quote($v);
                        }, array_values($row));
                        $sql_content .= "INSERT INTO `$table` VALUES (" . implode(', ', $vals) . ");\n";
                    }
                    $sql_content .= "\n";
                }
            }
            if (file_put_contents($filepath, $sql_content) === false) {
                json_error('Failed to write backup file');
            }
        }

        // Compress
        if (function_exists('gzencode') && file_exists($filepath)) {
            $gz_path = $filepath . '.gz';
            $data = file_get_contents($filepath);
            if ($data !== false && file_put_contents($gz_path, gzencode($data)) !== false) {
                unlink($filepath);
                $filename .= '.gz';
                $filepath = $gz_path;
            }
        }

        log_security_event($pdo, 'backup_created', $_SESSION['user_id'], "Backup created: $filename");
        json_success(['name' => $filename, 'size' => filesize($filepath)], 'Backup created');
    }

    if ($method === 'GET' && $id !== null && $action === 'download') {
        $filename = basename(urldecode($id));
        $filepath = $backup_dir . $filename;
        if (!file_exists($filepath)) json_error('Backup not found', 404);

        $safe_filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safe_filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit();
    }

    if ($method === 'DELETE' && $id !== null) {
        require_csrf();
        $filename = basename(urldecode($id));
        $filepath = $backup_dir . $filename;
        if (!file_exists($filepath)) json_error('Backup not found', 404);
        unlink($filepath);
        log_security_event($pdo, 'backup_deleted', $_SESSION['user_id'], "Backup deleted: $filename");
        json_success([], 'Backup deleted');
    }

    json_error('Not found', 404);
}

// ──────────────────────────────────────────────
// REPORTS
// ──────────────────────────────────────────────
if ($resource === 'reports') {
    require_admin_auth();

    $valid_report_types = ['access_summary', 'daily_activity', 'user_activity', 'hourly_pattern', 'denied_access'];

    if ($id === 'export' && $method === 'GET') {
        $type = $_GET['type'] ?? 'access_summary';
        if (!in_array($type, $valid_report_types)) json_error('Invalid report type');
        // Build report data then export as CSV
        $report_data = build_report($pdo_access, $type, $_GET);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="report_' . $type . '_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        if (!empty($report_data)) {
            fputcsv($out, array_keys($report_data[0]));
            foreach ($report_data as $row) fputcsv($out, $row);
        }
        fclose($out);
        exit();
    }

    if ($method === 'GET') {
        $type = $_GET['type'] ?? 'access_summary';
        if (!in_array($type, $valid_report_types)) json_error('Invalid report type');
        $report_data = build_report($pdo_access, $type, $_GET);
        json_success(['report' => $report_data, 'type' => $type]);
    }

    json_error('Not found', 404);
}

function build_report($pdo_access, $type, $params) {
    $from = $params['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to = ($params['to'] ?? date('Y-m-d')) . ' 23:59:59';

    switch ($type) {
        case 'access_summary':
            $stmt = $pdo_access->prepare("
                SELECT l.Location as door, COUNT(*) as total,
                       SUM(CASE WHEN l.Granted = 1 THEN 1 ELSE 0 END) as granted,
                       SUM(CASE WHEN l.Granted = 0 THEN 1 ELSE 0 END) as denied
                FROM logs l WHERE l.Date BETWEEN ? AND ?
                GROUP BY l.Location ORDER BY total DESC
            ");
            $stmt->execute([$from, $to]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        case 'daily_activity':
            $stmt = $pdo_access->prepare("
                SELECT DATE(Date) as date, COUNT(*) as total,
                       SUM(CASE WHEN Granted = 1 THEN 1 ELSE 0 END) as granted,
                       SUM(CASE WHEN Granted = 0 THEN 1 ELSE 0 END) as denied
                FROM logs WHERE Date BETWEEN ? AND ?
                GROUP BY DATE(Date) ORDER BY date
            ");
            $stmt->execute([$from, $to]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        case 'user_activity':
            $stmt = $pdo_access->prepare("
                SELECT l.user_id, c.firstname, c.lastname, COUNT(*) as total,
                       SUM(CASE WHEN l.Granted = 1 THEN 1 ELSE 0 END) as granted,
                       SUM(CASE WHEN l.Granted = 0 THEN 1 ELSE 0 END) as denied,
                       MAX(l.Date) as last_access
                FROM logs l LEFT JOIN cards c ON l.user_id = c.user_id
                WHERE l.Date BETWEEN ? AND ?
                GROUP BY l.user_id, c.firstname, c.lastname ORDER BY total DESC
            ");
            $stmt->execute([$from, $to]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        case 'hourly_pattern':
            $stmt = $pdo_access->prepare("
                SELECT HOUR(Date) as hour, COUNT(*) as total,
                       SUM(CASE WHEN Granted = 1 THEN 1 ELSE 0 END) as granted
                FROM logs WHERE Date BETWEEN ? AND ?
                GROUP BY HOUR(Date) ORDER BY hour
            ");
            $stmt->execute([$from, $to]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $hours = [];
            for ($i = 0; $i < 24; $i++) $hours[$i] = ['hour' => $i, 'total' => 0, 'granted' => 0];
            foreach ($data as $row) $hours[(int)$row['hour']] = $row;
            return array_values($hours);

        case 'denied_access':
            $stmt = $pdo_access->prepare("
                SELECT l.Date, l.user_id, c.firstname, c.lastname, l.Location
                FROM logs l LEFT JOIN cards c ON l.user_id = c.user_id
                WHERE l.Granted = 0 AND l.Date BETWEEN ? AND ?
                ORDER BY l.Date DESC LIMIT 500
            ");
            $stmt->execute([$from, $to]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        default:
            return [];
    }
}

// ──────────────────────────────────────────────
// UPDATE
// ──────────────────────────────────────────────
if ($resource === 'update') {
    require_admin_auth();

    if ($id === 'status' && $method === 'GET') {
        // Current server version + latest GitHub version (cached 1 hour)
        // Pass ?force=1 to bypass cache. auto_update_check=0 disables automatic checks.
        $version = trim(file_get_contents($config['apppath'] . 'VERSION') ?: 'unknown');
        $latest = 'unknown';
        $force_check = !empty($_GET['force']);

        // Check if auto update checking is disabled
        $auto_check = true;
        try {
            $ac = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'auto_update_check'")->fetch();
            if ($ac && $ac['setting_value'] === '0') $auto_check = false;
        } catch (Exception $e) {}

        // If auto-check is off and not a manual force check, return current version only
        if (!$auto_check && !$force_check) {
            json_success(['current_version' => $version, 'latest_version' => 'disabled']);
        }

        // Check cache first
        try {
            $cache_stmt = $pdo_access->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('github_latest_version', 'github_check_time')");
            $cache = [];
            while ($row = $cache_stmt->fetch()) $cache[$row['setting_key']] = $row['setting_value'];
        } catch (Exception $e) { $cache = []; }

        $cache_stale = true;
        if (!$force_check && !empty($cache['github_check_time'])) {
            $check_time = strtotime($cache['github_check_time']);
            if ($check_time && (time() - $check_time) < 3600) {
                $cache_stale = false;
                $latest = $cache['github_latest_version'] ?? 'unknown';
            }
        }

        if ($cache_stale) {
            $ch = curl_init('https://api.github.com/repos/sybethiesant/pidoors/releases/latest');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => ['User-Agent: PiDoors-Update-Check'],
            ]);
            $gh_json = curl_exec($ch);
            $gh_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($gh_code === 200 && $gh_json) {
                $gh = json_decode($gh_json, true);
                $latest = $gh['tag_name'] ?? 'unknown';
                try {
                    $upd = $pdo_access->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    $upd->execute(['github_latest_version', ltrim($latest, 'v')]);
                    $upd->execute(['github_check_time', date('Y-m-d H:i:s')]);
                } catch (Exception $e) {}
            } elseif (!empty($cache['github_latest_version'])) {
                $latest = $cache['github_latest_version'];
            }
        }

        json_success(['current_version' => $version, 'latest_version' => $latest]);
    }

    if ($id === 'server' && $method === 'POST') {
        require_csrf();

        // Determine target version
        $target = '';
        $ch = curl_init('https://api.github.com/repos/sybethiesant/pidoors/releases/latest');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['User-Agent: PiDoors-Update-Check'],
        ]);
        $gh_json = curl_exec($ch);
        $gh_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($gh_code === 200 && $gh_json) {
            $gh = json_decode($gh_json, true);
            $target = ltrim($gh['tag_name'] ?? '', 'v');
        }

        if (empty($target)) {
            json_error('Could not determine latest version from GitHub.');
        }

        require_once __DIR__ . '/includes/update-bootstrap.php';
        $result = pidoors_bootstrap_update($config, $pdo_access, $pdo, $target);

        if (!$result['ok']) {
            json_error($result['msg']);
        }

        json_success(['output' => $result['msg'], 'version' => $result['version'] ?? $target], $result['msg']);
    }

    if ($id === 'controllers' && $method === 'GET') {
        $doors = $pdo_access->query("SELECT name, ip_address, controller_version, status, update_requested, update_status FROM doors ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($doors as &$d) {
            $d['update_requested'] = (int)($d['update_requested'] ?? 0);
        }
        unset($d);
        json_success(['controllers' => $doors]);
    }

    if ($id === 'controllers' && $action === 'all' && $method === 'POST') {
        // Update all online controllers
        require_csrf();
        $online_doors = $pdo_access->query("SELECT name FROM doors WHERE status = 'online'")->fetchAll(PDO::FETCH_COLUMN);
        $count = 0;
        foreach ($online_doors as $name) {
            $result = push_to_controller($pdo_access, $name, 'update');
            if (!$result['ok']) {
                $pdo_access->prepare("UPDATE doors SET update_requested = 1 WHERE name = ?")->execute([$name]);
            }
            $count++;
        }
        log_security_event($pdo, 'controller_update_requested', $_SESSION['user_id'], "Update requested for all online controllers ($count)");
        json_success(['count' => $count], "Update requested for $count controller(s)");
    }

    if ($id === 'controllers' && $action !== null && $action !== 'all' && $method === 'POST') {
        require_csrf();
        $door_name = urldecode($action);

        // Verify door exists
        $stmt = $pdo_access->prepare("SELECT name FROM doors WHERE name = ?");
        $stmt->execute([$door_name]);
        if (!$stmt->fetch()) json_error('Door not found', 404);

        // Try push first
        $result = push_to_controller($pdo_access, $door_name, 'update');
        $delivery = 'push';
        if (!$result['ok']) {
            $pdo_access->prepare("UPDATE doors SET update_requested = 1 WHERE name = ?")->execute([$door_name]);
            $delivery = 'poll';
        }
        log_security_event($pdo, 'controller_update_requested', $_SESSION['user_id'], "Controller update requested ($delivery): $door_name");
        json_success(['delivery' => $delivery], 'Update requested');
    }

    json_error('Not found', 404);
}

// ──────────────────────────────────────────────
// CERT SIGNING (headless controller auth via DB credentials)
// ──────────────────────────────────────────────
if ($resource === 'certs' && $id === 'sign' && $method === 'POST') {
    // Authenticate via DB credentials (not session — used by headless controller installs)
    $db_user_input = $input['db_user'] ?? '';
    $db_pass_input = $input['db_pass'] ?? '';
    $csr_pem = $input['csr'] ?? '';
    $door_name = preg_replace('/[^a-z0-9_]/', '', $input['door_name'] ?? '');
    $door_ip = filter_var($input['door_ip'] ?? '', FILTER_VALIDATE_IP) ?: '';

    if (empty($db_user_input) || empty($db_pass_input) || empty($csr_pem) || empty($door_name)) {
        json_error('Missing required fields: db_user, db_pass, csr, door_name');
    }

    // Verify DB credentials by attempting a connection
    try {
        new PDO(
            "mysql:host=localhost;dbname=access",
            $db_user_input,
            $db_pass_input,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
    } catch (PDOException $e) {
        json_error('Invalid database credentials', 403);
    }

    // Validate CSR
    if (strpos($csr_pem, '-----BEGIN CERTIFICATE REQUEST-----') === false) {
        json_error('Invalid CSR format');
    }

    $ca_cert = '/etc/mysql/ssl/ca.pem';
    $ca_key = '/etc/mysql/ssl/ca-key.pem';
    if (!file_exists($ca_cert) || !file_exists($ca_key)) {
        json_error('CA not available on this server', 500);
    }

    // Write CSR to temp file
    $csr_tmp = tempnam(sys_get_temp_dir(), 'pidoors_csr_');
    $cert_tmp = tempnam(sys_get_temp_dir(), 'pidoors_cert_');
    $ext_tmp = tempnam(sys_get_temp_dir(), 'pidoors_ext_');
    file_put_contents($csr_tmp, $csr_pem);

    // Build SAN extension
    $san_entries = "subjectAltName = DNS:{$door_name},DNS:{$door_name}.local";
    if (!empty($door_ip)) {
        $san_entries .= ",IP:{$door_ip}";
    }
    file_put_contents($ext_tmp, $san_entries);

    // Sign with CA — use temp serial file to avoid needing write access to /etc/mysql/ssl/
    $serial_tmp = tempnam(sys_get_temp_dir(), 'pidoors_serial_');
    file_put_contents($serial_tmp, dechex(time()) . "\n");
    $cmd = sprintf(
        'openssl x509 -req -days 3650 -in %s -CA %s -CAkey %s -CAserial %s -out %s -extfile %s 2>&1',
        escapeshellarg($csr_tmp),
        escapeshellarg($ca_cert),
        escapeshellarg($ca_key),
        escapeshellarg($serial_tmp),
        escapeshellarg($cert_tmp),
        escapeshellarg($ext_tmp)
    );
    exec($cmd, $output, $return_code);

    $signed_cert = '';
    if ($return_code === 0 && file_exists($cert_tmp)) {
        $signed_cert = file_get_contents($cert_tmp);
    }

    // Cleanup temp files
    @unlink($csr_tmp);
    @unlink($cert_tmp);
    @unlink($ext_tmp);
    @unlink($serial_tmp);

    if (empty($signed_cert) || strpos($signed_cert, '-----BEGIN CERTIFICATE-----') === false) {
        json_error('Certificate signing failed: ' . implode(' ', $output), 500);
    }

    json_success(['cert' => $signed_cert], 'Certificate signed');
}

// ──────────────────────────────────────────────
// Fallback
// ──────────────────────────────────────────────
json_error('Not found', 404);
