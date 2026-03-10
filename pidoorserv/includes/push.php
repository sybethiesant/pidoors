<?php
/**
 * Push Command Helper
 * Sends HTTPS commands directly to door controllers for instant response.
 * Falls back to database flags if push fails.
 */

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
        CURLOPT_SSL_VERIFYPEER => false,  // Self-signed cert
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

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
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

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
