<?php
/**
 * Push Command Helper
 * Sends HTTPS commands directly to door controllers for instant response.
 * Falls back to database flags if push fails.
 */

define('PIDOORS_CA_PATH', '/var/www/pidoors/ca.pem');

/**
 * Build SSL curl options. FAILS CLOSED: TLS verification is always enforced and
 * pinned to our CA. If the CA file is missing or empty we CANNOT verify the
 * controller's certificate, so we return null and callers MUST refuse to send.
 * We never disable VERIFYPEER/VERIFYHOST, because doing so would leak the
 * per-door unlock api_key (sent as a Bearer token) to any host answering the IP.
 *
 * @return array|null curl SSL options on success, or null if the CA is unavailable.
 */
function _push_ssl_opts() {
    if (file_exists(PIDOORS_CA_PATH) && filesize(PIDOORS_CA_PATH) > 0) {
        return [
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO         => PIDOORS_CA_PATH,
        ];
    }
    // No usable CA pin available — cannot establish a trusted channel. Fail closed.
    return null;
}

/**
 * Push a command to a door controller via HTTPS.
 *
 * @param PDO    $pdo_access  Database connection (access DB)
 * @param string $door_name   Door name to push to
 * @param string $command     Command path (e.g. 'unlock', 'hold', 'release', 'update', 'sync')
 * @param array  $body        Optional JSON body to send
 * @return array  ['ok' => bool, ...response] on success, ['ok' => false, 'fallback' => true] on failure
 */
function push_to_controller($pdo_access, $door_name, $command, $body = []) {
    // Look up controller connection info
    $stmt = $pdo_access->prepare(
        "SELECT ip_address, listen_port, api_key FROM doors WHERE name = ?"
    );
    $stmt->execute([$door_name]);
    $door = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$door || !$door['ip_address'] || !$door['listen_port'] || !$door['api_key']) {
        return ['ok' => false, 'fallback' => true, 'reason' => 'no_push_config'];
    }

    $ip   = $door['ip_address'];
    $port = (int) $door['listen_port'];
    $key  = $door['api_key'];
    $url  = "https://{$ip}:{$port}/cmd/{$command}";

    // Fail closed: refuse to push if we cannot verify the controller's TLS cert.
    // Sending the Bearer api_key over an unverified channel would leak the
    // door-unlock key to any host answering the IP. Fall back to DB flags instead.
    $ssl_opts = _push_ssl_opts();
    if ($ssl_opts === null) {
        return ['ok' => false, 'fallback' => true, 'reason' => 'ca_missing'];
    }

    // Load push timeout from settings, default 5s
    // TLS handshake on Pi Zero hardware can take 1-2s, so 3s was too tight
    $timeout = 5;
    $ts = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'push_timeout'")->fetch(PDO::FETCH_ASSOC);
    if ($ts && $ts['setting_value']) {
        $timeout = max(2, (int) $ts['setting_value']);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout - 1,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$key}",
        ],
        CURLOPT_POSTFIELDS     => json_encode($body ?: new \stdClass()),
    ] + $ssl_opts);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response !== false && $http_code >= 200 && $http_code < 300) {
        // Push succeeded — mark controller as push-available
        $pdo_access->prepare(
            "UPDATE doors SET push_available = 1 WHERE name = ?"
        )->execute([$door_name]);

        $result = json_decode($response, true);
        return is_array($result) ? $result : ['ok' => true];
    }

    // Push failed — mark as unavailable and signal fallback
    $pdo_access->prepare(
        "UPDATE doors SET push_available = 0 WHERE name = ?"
    )->execute([$door_name]);

    return ['ok' => false, 'fallback' => true, 'reason' => $err ?: "HTTP {$http_code}"];
}

/**
 * Queue a cache-sync push to door controllers, delivered after the HTTP
 * response has been sent (json_success() calls flush_deferred_pushes()).
 *
 * Controllers only re-read cards, schedules, holidays, groups and door/global
 * settings from the database once an hour. Anything that edits that data
 * should call this so online doors pick the change up within a second or two
 * instead of waiting for the hourly resync. Offline doors still catch up on
 * their next sync — nothing here is load-bearing.
 *
 * @param array|null $door_names  Specific doors, or null for every door
 */
function queue_controller_sync($door_names = null) {
    if (!array_key_exists('_pidoors_sync_queue', $GLOBALS)) {
        $GLOBALS['_pidoors_sync_queue'] = [];
    }
    if ($door_names === null || $GLOBALS['_pidoors_sync_queue'] === null) {
        $GLOBALS['_pidoors_sync_queue'] = null;   // null = all doors
        return;
    }
    foreach ((array)$door_names as $n) {
        $GLOBALS['_pidoors_sync_queue'][$n] = true;
    }
}

/**
 * Deliver queued sync pushes. Called by json_success() after the response
 * body is written; finishes the request first (PHP-FPM) so the browser is not
 * kept waiting on controller round-trips.
 */
function flush_deferred_pushes($pdo_access) {
    if (!array_key_exists('_pidoors_sync_queue', $GLOBALS)) return;
    $queue = $GLOBALS['_pidoors_sync_queue'];
    unset($GLOBALS['_pidoors_sync_queue']);
    if (is_array($queue) && empty($queue)) return;

    // Release the session lock and hand the response to the client before
    // talking to controllers, so a slow or unreachable door never delays the UI.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        @flush();
    }
    ignore_user_abort(true);

    try {
        push_sync_to_controllers($pdo_access, is_array($queue) ? array_keys($queue) : null);
    } catch (Throwable $e) {
        error_log('PiDoors: deferred sync push failed: ' . $e->getMessage());
    }
}

/**
 * POST /cmd/sync to a set of door controllers in parallel (curl_multi).
 * Best-effort: doors without push config, or that have not been reachable,
 * are skipped; a failure just marks push_available = 0 like push_to_controller().
 *
 * @param array|null $door_names  Specific doors, or null for every door
 * @return array  door name => bool delivered
 */
function push_sync_to_controllers($pdo_access, $door_names = null) {
    $sql = "SELECT name, ip_address, listen_port, api_key FROM doors
            WHERE ip_address IS NOT NULL AND ip_address <> ''
              AND listen_port IS NOT NULL AND listen_port > 0
              AND api_key IS NOT NULL AND api_key <> ''
              AND (status = 'online' OR push_available = 1)";
    $params = [];
    if (is_array($door_names)) {
        if (empty($door_names)) return [];
        $sql .= " AND name IN (" . implode(',', array_fill(0, count($door_names), '?')) . ")";
        $params = array_values($door_names);
    }
    $stmt = $pdo_access->prepare($sql);
    $stmt->execute($params);
    $doors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$doors) return [];

    $ssl_opts = _push_ssl_opts();
    if ($ssl_opts === null) return [];   // cannot verify controller certs — fail closed

    $timeout = 5;
    $ts = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'push_timeout'")->fetch(PDO::FETCH_ASSOC);
    if ($ts && $ts['setting_value']) $timeout = max(2, (int)$ts['setting_value']);

    $mh = curl_multi_init();
    $handles = [];
    foreach ($doors as $d) {
        $ch = curl_init("https://{$d['ip_address']}:" . (int)$d['listen_port'] . "/cmd/sync");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout - 1,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$d['api_key']}",
            ],
            CURLOPT_POSTFIELDS     => '{}',
        ] + $ssl_opts);
        curl_multi_add_handle($mh, $ch);
        $handles[$d['name']] = $ch;
    }

    $errors = [];   // transfer errors by handle id — curl_error() is empty for multi handles
    do {
        $status = curl_multi_exec($mh, $active);
        while ($info = curl_multi_info_read($mh)) {
            if ($info['result'] !== CURLE_OK) {
                $errors[spl_object_id($info['handle'])] = curl_strerror($info['result']);
            }
        }
        if ($active) curl_multi_select($mh, 1.0);
    } while ($active && $status === CURLM_OK);

    $results = [];
    $mark = $pdo_access->prepare("UPDATE doors SET push_available = ? WHERE name = ?");
    foreach ($handles as $name => $ch) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ok = ($code >= 200 && $code < 300);
        $results[$name] = $ok;
        $mark->execute([$ok ? 1 : 0, $name]);
        if (!$ok) error_log("PiDoors: sync push to {$name} failed: " . ($errors[spl_object_id($ch)] ?? "HTTP {$code}"));
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

/**
 * Ping a door controller and return its live status.
 *
 * @param PDO    $pdo_access  Database connection (access DB)
 * @param string $door_name   Door name to ping
 * @return array  Controller status or error
 */
function ping_controller($pdo_access, $door_name) {
    $stmt = $pdo_access->prepare(
        "SELECT ip_address, listen_port, api_key FROM doors WHERE name = ?"
    );
    $stmt->execute([$door_name]);
    $door = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$door || !$door['ip_address'] || !$door['listen_port'] || !$door['api_key']) {
        return ['ok' => false, 'reason' => 'no_push_config'];
    }

    $ip   = $door['ip_address'];
    $port = (int) $door['listen_port'];
    $key  = $door['api_key'];
    $url  = "https://{$ip}:{$port}/ping";

    // Fail closed: refuse to send the Bearer api_key if we cannot verify the
    // controller's TLS cert (CA missing/empty). See _push_ssl_opts().
    $ssl_opts = _push_ssl_opts();
    if ($ssl_opts === null) {
        return ['ok' => false, 'reason' => 'ca_missing'];
    }

    $timeout = 5;
    $ts = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'push_timeout'")->fetch(PDO::FETCH_ASSOC);
    if ($ts && $ts['setting_value']) {
        $timeout = max(2, (int) $ts['setting_value']);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout - 1,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$key}",
        ],
        CURLOPT_POSTFIELDS     => '{}',
    ] + $ssl_opts);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response !== false && $http_code >= 200 && $http_code < 300) {
        $pdo_access->prepare(
            "UPDATE doors SET push_available = 1 WHERE name = ?"
        )->execute([$door_name]);

        $result = json_decode($response, true);
        return is_array($result) ? array_merge(['ok' => true], $result) : ['ok' => true];
    }

    $pdo_access->prepare(
        "UPDATE doors SET push_available = 0 WHERE name = ?"
    )->execute([$door_name]);

    return ['ok' => false, 'reason' => $err ?: "HTTP {$http_code}"];
}

/**
 * Poll all push-enabled door controllers for live status using curl_multi.
 *
 * For each door with ip_address, listen_port, and api_key configured:
 * - Successful response: update status=online, last_seen, locked, held_open,
 *   controller_version, door_open, push_available=1
 * - Failed response: push_available=0, status only set to offline if last heartbeat >10min ago
 * - Doors without push config: skipped (status unchanged)
 *
 * @param PDO $pdo_access Database connection (access DB)
 */
function poll_all_door_status($pdo_access) {
    // Load status_check_timeout setting (default 4s — TLS handshake on Pi Zero takes 1-2s)
    $timeout = 4;
    $ts = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'status_check_timeout'")->fetch(PDO::FETCH_ASSOC);
    if ($ts && $ts['setting_value']) {
        $timeout = max(2, min(10, (int) $ts['setting_value']));
    }

    // Get all doors with push config
    $doors = $pdo_access->query(
        "SELECT name, ip_address, listen_port, api_key FROM doors WHERE ip_address IS NOT NULL AND listen_port IS NOT NULL AND api_key IS NOT NULL AND ip_address != '' AND api_key != ''"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (empty($doors)) return;

    // Fail closed: if we cannot verify controller TLS certs (CA missing/empty),
    // do not send any pings — that would leak each door's Bearer api_key over an
    // unverified channel. Mark all push-enabled doors unavailable and bail.
    $ssl_opts = _push_ssl_opts();
    if ($ssl_opts === null) {
        $unavail_stmt = $pdo_access->prepare(
            "UPDATE doors SET push_available = 0 WHERE name = ?"
        );
        foreach ($doors as $door) {
            $unavail_stmt->execute([$door['name']]);
        }
        return;
    }

    // Build curl_multi handles
    $mh = curl_multi_init();
    $handles = [];

    foreach ($doors as $door) {
        $url = "https://{$door['ip_address']}:{$door['listen_port']}/ping";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$door['api_key']}",
            ],
            CURLOPT_POSTFIELDS     => '{}',
        ] + $ssl_opts);
        curl_multi_add_handle($mh, $ch);
        $handles[(int) $ch] = ['ch' => $ch, 'name' => $door['name']];
    }

    // Execute all in parallel
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 0.1);
        }
    } while ($running > 0);

    // Process results
    $online_stmt = $pdo_access->prepare(
        "UPDATE doors SET status = 'online', last_seen = NOW(), locked = ?, held_open = ?, controller_version = ?, door_open = ?, gate_state = ?, gate_held = ?, push_available = 1 WHERE name = ?"
    );
    // When push fails, only mark offline if last heartbeat is stale (>10 min).
    // This prevents false "offline" when push/TLS is broken but controller is running fine.
    $offline_stmt = $pdo_access->prepare(
        "UPDATE doors SET push_available = 0, status = IF(last_seen > NOW() - INTERVAL 600 SECOND, status, 'offline') WHERE name = ?"
    );

    foreach ($handles as $info) {
        $ch = $info['ch'];
        $name = $info['name'];
        $response = curl_multi_getcontent($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response !== false && $http_code >= 200 && $http_code < 300) {
            $data = json_decode($response, true);
            if (is_array($data)) {
                $locked = isset($data['locked']) ? (int) $data['locked'] : 1;
                $held_open = isset($data['held_open']) ? (int) $data['held_open'] : 0;
                $version = $data['version'] ?? $data['controller_version'] ?? null;
                $door_open = array_key_exists('door_open', $data) ? $data['door_open'] : null;
                $gate_state = $data['gate_state'] ?? 'idle';
                $gate_held = !empty($data['gate_held']) ? 1 : 0;
                $online_stmt->execute([$locked, $held_open, $version, $door_open, $gate_state, $gate_held, $name]);
            } else {
                // Valid HTTP response but bad JSON — still mark online
                $online_stmt->execute([1, 0, null, null, 'idle', 0, $name]);
            }
        } else {
            $offline_stmt->execute([$name]);
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
}
