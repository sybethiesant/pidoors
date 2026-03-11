<?php
/**
 * Push Command Helper
 * Sends HTTPS commands directly to door controllers for instant response.
 * Falls back to database flags if push fails.
 */

define('PIDOORS_CA_PATH', '/var/www/pidoors/ca.pem');

/**
 * Build SSL curl options: verify against CA if available, else disable verification.
 */
function _push_ssl_opts() {
    if (file_exists(PIDOORS_CA_PATH) && filesize(PIDOORS_CA_PATH) > 0) {
        return [
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO         => PIDOORS_CA_PATH,
        ];
    }
    return [
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ];
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

    // Load push timeout from settings, default 3s
    $timeout = 3;
    $ts = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'push_timeout'")->fetch(PDO::FETCH_ASSOC);
    if ($ts && $ts['setting_value']) {
        $timeout = max(1, (int) $ts['setting_value']);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$key}",
        ],
        CURLOPT_POSTFIELDS     => json_encode($body ?: new \stdClass()),
    ] + _push_ssl_opts());

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

    $timeout = 3;
    $ts = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'push_timeout'")->fetch(PDO::FETCH_ASSOC);
    if ($ts && $ts['setting_value']) {
        $timeout = max(1, (int) $ts['setting_value']);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$key}",
        ],
        CURLOPT_POSTFIELDS     => '{}',
    ] + _push_ssl_opts());

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
 * - Failed response: update status=offline, push_available=0
 * - Doors without push config: skipped (status unchanged)
 *
 * @param PDO $pdo_access Database connection (access DB)
 */
function poll_all_door_status($pdo_access) {
    // Load status_check_timeout setting (default 2s)
    $timeout = 2;
    $ts = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'status_check_timeout'")->fetch(PDO::FETCH_ASSOC);
    if ($ts && $ts['setting_value']) {
        $timeout = max(1, min(10, (int) $ts['setting_value']));
    }

    // Get all doors with push config
    $doors = $pdo_access->query(
        "SELECT name, ip_address, listen_port, api_key FROM doors WHERE ip_address IS NOT NULL AND listen_port IS NOT NULL AND api_key IS NOT NULL AND ip_address != '' AND api_key != ''"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (empty($doors)) return;

    // Build curl_multi handles
    $mh = curl_multi_init();
    $handles = [];

    $ssl_opts = _push_ssl_opts();

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
        "UPDATE doors SET status = 'online', last_seen = NOW(), locked = ?, held_open = ?, controller_version = ?, door_open = ?, push_available = 1 WHERE name = ?"
    );
    $offline_stmt = $pdo_access->prepare(
        "UPDATE doors SET status = 'offline', push_available = 0 WHERE name = ?"
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
                $online_stmt->execute([$locked, $held_open, $version, $door_open, $name]);
            } else {
                // Valid HTTP response but bad JSON — still mark online
                $online_stmt->execute([1, 0, null, null, $name]);
            }
        } else {
            $offline_stmt->execute([$name]);
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
}
