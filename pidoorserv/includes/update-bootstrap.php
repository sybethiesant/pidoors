<?php
/**
 * Server Update Bootstrap
 * PiDoors Access Control System
 *
 * Downloads the release tarball, extracts it, then loads the NEW release's
 * update-worker.php and delegates the actual deployment to it. This ensures
 * the latest update logic always runs, even when upgrading from an older version.
 *
 * Usage:
 *   $result = pidoors_bootstrap_update($config, $pdo_access, $pdo, $target_version);
 */

function pidoors_bootstrap_update(array $config, PDO $pdo_access, PDO $pdo, string $target_version): array {
    $tag_with_v = 'v' . $target_version;

    // Download URLs — try release asset first (has pre-built SPA), fall back to source archive
    $tarball_urls = [
        "https://github.com/sybethiesant/pidoors/releases/download/{$tag_with_v}/{$tag_with_v}.tar.gz",
        "https://github.com/sybethiesant/pidoors/archive/refs/tags/{$tag_with_v}.tar.gz",
    ];

    $tmpdir = sys_get_temp_dir() . '/pidoors-server-update-' . uniqid();
    if (!mkdir($tmpdir, 0700, true)) {
        return ['ok' => false, 'msg' => 'Failed to create temporary directory.', 'details' => []];
    }
    $tarball = $tmpdir . '/release.tar.gz';

    // Download — try each URL until one succeeds
    $http_code = 0;
    foreach ($tarball_urls as $url) {
        $ch = curl_init($url);
        $fp = fopen($tarball, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['User-Agent: PiDoors-Update'],
        ]);
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if ($http_code === 200 && file_exists($tarball) && filesize($tarball) >= 1000) {
            break;
        }
    }

    if ($http_code !== 200 || !file_exists($tarball) || filesize($tarball) < 1000) {
        @exec('rm -rf ' . escapeshellarg($tmpdir));
        return ['ok' => false, 'msg' => "Failed to download release tarball (HTTP $http_code).", 'details' => []];
    }

    // Extract
    try {
        $phar = new PharData($tarball);
        $phar->extractTo($tmpdir);
    } catch (Exception $e) {
        @exec('rm -rf ' . escapeshellarg($tmpdir));
        return ['ok' => false, 'msg' => 'Failed to extract release archive: ' . $e->getMessage(), 'details' => []];
    }

    // Find extracted directory
    $dirs = glob($tmpdir . '/pidoors-*', GLOB_ONLYDIR);
    if (empty($dirs)) {
        @exec('rm -rf ' . escapeshellarg($tmpdir));
        return ['ok' => false, 'msg' => 'Could not find extracted release directory.', 'details' => []];
    }

    $extracted = $dirs[0];

    // Load the NEW release's update worker (self-update pattern)
    // This ensures the latest deployment logic always runs
    $new_worker = $extracted . '/pidoorserv/includes/update-worker.php';
    if (file_exists($new_worker)) {
        include $new_worker;
    } else {
        // Fallback: use the currently installed worker (pre-3.0.1 releases)
        if (!function_exists('pidoors_deploy_update')) {
            require_once __DIR__ . '/update-worker.php';
        }
    }

    if (!function_exists('pidoors_deploy_update')) {
        @exec('rm -rf ' . escapeshellarg($tmpdir));
        return ['ok' => false, 'msg' => 'Update worker function not found in release.', 'details' => []];
    }

    // Run the deployment using the new release's logic
    $result = pidoors_deploy_update($config, $pdo_access, $pdo, $extracted);

    // Cleanup temp files
    @exec('rm -rf ' . escapeshellarg($tmpdir));

    return $result;
}
