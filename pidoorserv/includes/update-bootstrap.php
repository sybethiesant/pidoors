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

    // Only the published release ASSET has a matching .sha256 we can verify
    // against. The GitHub auto-generated source archive is not covered by our
    // checksum (and contains no built SPA), so it is deliberately NOT a fallback —
    // that would silently defeat the supply-chain check server-update.sh enforces.
    $asset_url = "https://github.com/sybethiesant/pidoors/releases/download/{$tag_with_v}/{$tag_with_v}.tar.gz";
    $sha_url   = $asset_url . '.sha256';

    $tmpdir = sys_get_temp_dir() . '/pidoors-server-update-' . uniqid();
    if (!mkdir($tmpdir, 0700, true)) {
        return ['ok' => false, 'msg' => 'Failed to create temporary directory.', 'details' => []];
    }
    $tarball = $tmpdir . '/release.tar.gz';

    $fetch = function (string $url, string $dest) {
        $ch = curl_init($url);
        $fp = fopen($dest, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['User-Agent: PiDoors-Update'],
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        return $code;
    };

    $http_code = $fetch($asset_url, $tarball);
    if ($http_code !== 200 || !file_exists($tarball) || filesize($tarball) < 1000) {
        @exec('rm -rf ' . escapeshellarg($tmpdir));
        return ['ok' => false, 'msg' => "Failed to download release asset {$tag_with_v}.tar.gz (HTTP $http_code). A published release with a .sha256 asset is required.", 'details' => []];
    }

    // Verify the tarball against its published checksum BEFORE extracting.
    // Fails secure: any download/parse/mismatch problem aborts the update.
    $sum_file = $tarball . '.sha256';
    $sum_code = $fetch($sha_url, $sum_file);
    $published = '';
    if ($sum_code === 200 && is_file($sum_file)) {
        $published = strtolower(trim((string)strtok((string)file_get_contents($sum_file), " \t\r\n")));
    }
    if (!preg_match('/^[0-9a-f]{64}$/', $published)) {
        @exec('rm -rf ' . escapeshellarg($tmpdir));
        return ['ok' => false, 'msg' => "Could not fetch a valid checksum for {$tag_with_v} (HTTP $sum_code). Refusing to deploy an unverified release.", 'details' => []];
    }
    $actual = hash_file('sha256', $tarball);
    if (!hash_equals($published, $actual)) {
        @exec('rm -rf ' . escapeshellarg($tmpdir));
        return ['ok' => false, 'msg' => "Checksum mismatch for {$tag_with_v}.tar.gz — refusing to deploy. Expected $published, got $actual.", 'details' => []];
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
