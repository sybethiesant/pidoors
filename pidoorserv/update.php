<?php
/**
 * System Updates
 * PiDoors Access Control System
 */
$title = 'System Updates';
require_once './includes/header.php';

require_login($config);
require_admin($config);

$error_message = '';
$success_message = '';

// Read current server version from VERSION file
$version_file = $config['apppath'] . 'VERSION';
$current_version = 'unknown';
if (file_exists($version_file)) {
    $current_version = trim(file_get_contents($version_file));
}

// Load settings
try {
    $settings_query = $pdo_access->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('server_version', 'github_latest_version', 'github_check_time', 'target_controller_version')");
    $update_settings = [];
    while ($row = $settings_query->fetch()) {
        $update_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    $update_settings = [];
}

// Update server_version in DB if it doesn't match the VERSION file
if (($update_settings['server_version'] ?? '') !== $current_version) {
    try {
        $stmt = $pdo_access->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES ('server_version', ?, 'Current server software version') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$current_version]);
        $update_settings['server_version'] = $current_version;
    } catch (PDOException $e) {
        // ignore
    }
}

$github_latest = $update_settings['github_latest_version'] ?? '';
$github_check_time = $update_settings['github_check_time'] ?? '';
$target_controller_version = $update_settings['target_controller_version'] ?? '';

// Handle "Check for Updates" button
if (isset($_POST['check_updates']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $github_latest = '';
    $ch = curl_init('https://api.github.com/repos/sybethiesant/pidoors/releases/latest');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['User-Agent: PiDoors-Update-Check'],
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['tag_name'])) {
            $github_latest = ltrim($data['tag_name'], 'v');
            $github_check_time = date('Y-m-d H:i:s');

            try {
                $stmt = $pdo_access->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute(['github_latest_version', $github_latest]);
                $stmt->execute(['github_check_time', $github_check_time]);
            } catch (PDOException $e) {
                // ignore
            }

            $success_message = "Update check complete.";
        } else {
            $error_message = 'Could not parse GitHub release data.';
        }
    } else {
        $error_message = 'Failed to reach GitHub API. HTTP code: ' . $http_code;
    }
}

// Handle "Update Server" button
if (isset($_POST['update_server']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    // Determine which version to download
    $target_tag = $github_latest ?: $current_version;
    if (empty($target_tag) || $target_tag === 'unknown') {
        $error_message = 'No version available. Check for updates first.';
    } else {
        $tag_with_v = 'v' . $target_tag;
        $tarball_url = "https://github.com/sybethiesant/pidoors/archive/refs/tags/{$tag_with_v}.tar.gz";

        $tmpdir = sys_get_temp_dir() . '/pidoors-server-update-' . uniqid();
        mkdir($tmpdir, 0755, true);
        $tarball = $tmpdir . '/release.tar.gz';

        // Download
        $ch = curl_init($tarball_url);
        $fp = fopen($tarball, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['User-Agent: PiDoors-Update'],
        ]);
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($http_code !== 200 || !file_exists($tarball) || filesize($tarball) < 1000) {
            $error_message = "Failed to download release tarball (HTTP $http_code).";
            // cleanup
            array_map('unlink', glob("$tmpdir/*"));
            rmdir($tmpdir);
        } else {
            // Extract
            try {
                $phar = new PharData($tarball);
                $phar->extractTo($tmpdir);
            } catch (Exception $e) {
                $error_message = 'Failed to extract release archive: ' . $e->getMessage();
                $cleanup_cmd = 'rm -rf ' . escapeshellarg($tmpdir);
                @exec($cleanup_cmd);
                goto render_page;
            }

            // Find extracted dir
            $dirs = glob($tmpdir . '/pidoors-*', GLOB_ONLYDIR);
            if (empty($dirs)) {
                $error_message = 'Could not find extracted release directory.';
            } else {
                $extracted = $dirs[0];
                $web_src = $extracted . '/pidoorserv';
                $apppath = rtrim($config['apppath'], '/');

                if (!is_dir($web_src)) {
                    $error_message = "Update failed: web source directory not found in release archive.";
                    $cleanup_cmd = 'rm -rf ' . escapeshellarg($tmpdir);
                    @exec($cleanup_cmd);
                    goto render_page;
                }

                // --- Pre-flight check: verify all files are writable before copying ---
                $files_to_copy = [];
                $preflight_errors = [];

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($web_src, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $item) {
                    $sub = $iterator->getSubPathName();
                    $target = $apppath . '/' . $sub;
                    if ($item->isDir()) {
                        // Check that parent dir exists or is creatable
                        if (!is_dir($target) && !is_writable(dirname($target))) {
                            $preflight_errors[] = "Cannot create directory: $sub";
                        }
                    } else {
                        if ($sub === 'includes/config.php') continue;
                        if (file_exists($target) && !is_writable($target)) {
                            $preflight_errors[] = $sub;
                        } elseif (!file_exists($target) && !is_writable(dirname($target))) {
                            $preflight_errors[] = "$sub (parent dir not writable)";
                        }
                        $files_to_copy[] = ['src' => $item->getPathname(), 'sub' => $sub, 'target' => $target];
                    }
                }

                // Check VERSION file too
                $version_target = $apppath . '/VERSION';
                if (file_exists($version_target) && !is_writable($version_target)) {
                    $preflight_errors[] = 'VERSION';
                }

                if (!empty($preflight_errors)) {
                    $count = count($preflight_errors);
                    $sample = array_slice($preflight_errors, 0, 5);
                    $error_message = "Update aborted: $count file(s) are not writable — " . implode(', ', $sample);
                    if ($count > 5) $error_message .= " and " . ($count - 5) . " more";
                    $error_message .= ". Fix file ownership and try again.";
                    $cleanup_cmd = 'rm -rf ' . escapeshellarg($tmpdir);
                    @exec($cleanup_cmd);
                    goto render_page;
                }

                // --- All checks passed, perform the update ---
                $copied = 0;
                $failed = 0;
                $failed_files = [];

                // Create directories first
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($web_src, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $item) {
                    if ($item->isDir()) {
                        $target = $apppath . '/' . $iterator->getSubPathName();
                        if (!is_dir($target)) mkdir($target, 0755, true);
                    }
                }

                // Copy all files
                foreach ($files_to_copy as $file) {
                    if (copy($file['src'], $file['target'])) {
                        $copied++;
                    } else {
                        $failed++;
                        $failed_files[] = $file['sub'];
                    }
                }

                if ($failed > 0) {
                    $sample = array_slice($failed_files, 0, 5);
                    $error_message = "Update aborted after $failed copy failure(s): " . implode(', ', $sample);
                    if ($failed > 5) $error_message .= " and " . ($failed - 5) . " more";
                    $error_message .= ". $copied files were copied before the failure. Manual cleanup may be needed.";
                    $cleanup_cmd = 'rm -rf ' . escapeshellarg($tmpdir);
                    @exec($cleanup_cmd);
                    goto render_page;
                }

                // Only update VERSION after all files copied successfully
                if (file_exists($extracted . '/VERSION')) {
                    $new_version = trim(file_get_contents($extracted . '/VERSION'));
                    if (!copy($extracted . '/VERSION', $apppath . '/VERSION')) {
                        $error_message = "All $copied files copied but VERSION file update failed. Version may be mismatched.";
                        $cleanup_cmd = 'rm -rf ' . escapeshellarg($tmpdir);
                        @exec($cleanup_cmd);
                        goto render_page;
                    }
                    $current_version = $new_version;
                }

                // Only update DB version after everything succeeded
                try {
                    $stmt = $pdo_access->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('server_version', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    $stmt->execute([$current_version]);
                } catch (PDOException $e) {
                    // ignore — files are updated, DB will catch up on next page load
                }

                log_security_event($pdo, 'server_update', $_SESSION['user_id'] ?? null, "Server updated to version $current_version ($copied files)");

                $success_message = "Server updated to version $current_version. $copied files copied successfully. Refresh the page to see updated code.";
            }

            // Cleanup temp files
            $cleanup_cmd = 'rm -rf ' . escapeshellarg($tmpdir);
            @exec($cleanup_cmd);
        }
    }
}

render_page:
// Check if cache is stale (older than 1 hour)
$cache_stale = true;
if ($github_check_time) {
    $check_time = strtotime($github_check_time);
    if ($check_time && (time() - $check_time) < 3600) {
        $cache_stale = false;
    }
}

$update_available = false;
if ($github_latest && $current_version !== 'unknown' && version_compare($github_latest, $current_version, '>')) {
    $update_available = true;
}

// Fetch door controller versions
try {
    $doors_stmt = $pdo_access->query("SELECT name, controller_version, update_status, update_status_time, status FROM doors ORDER BY name");
    $doors = $doors_stmt->fetchAll();
} catch (PDOException $e) {
    $doors = [];
}
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <!-- Server Version -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Server Version</h5>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="mb-1">
                            <?php echo htmlspecialchars($current_version); ?>
                            <?php if ($update_available): ?>
                                <span class="badge bg-warning text-dark ms-2">Update Available</span>
                            <?php else: ?>
                                <span class="badge bg-success ms-2">Up to Date</span>
                            <?php endif; ?>
                        </h4>
                        <?php if ($github_latest): ?>
                            <p class="text-muted mb-0">
                                Latest on GitHub: <strong><?php echo htmlspecialchars($github_latest); ?></strong>
                                <?php if ($github_check_time): ?>
                                    <small>(checked <?php echo htmlspecialchars($github_check_time); ?>)</small>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <form method="post" class="d-inline">
                        <?php echo csrf_field(); ?>
                        <button type="submit" name="check_updates" class="btn btn-outline-primary">
                            Check for Updates
                        </button>
                    </form>
                    <?php if ($update_available): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Update server to version <?php echo htmlspecialchars($github_latest); ?>? The web interface files will be replaced.');">
                            <?php echo csrf_field(); ?>
                            <button type="submit" name="update_server" class="btn btn-warning">
                                Update Server to <?php echo htmlspecialchars($github_latest); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Controller Versions -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">Door Controller Versions</h5>
            </div>
            <div class="card-body">
                <?php if ($target_controller_version): ?>
                    <p class="mb-3">Target controller version: <strong><?php echo htmlspecialchars($target_controller_version); ?></strong>
                        <small class="text-muted">(set in <a href="settings.php">Settings</a>)</small>
                    </p>
                <?php else: ?>
                    <p class="text-muted mb-3">No target controller version set. <a href="settings.php">Set one in Settings</a> to see outdated warnings.</p>
                <?php endif; ?>

                <?php if (empty($doors)): ?>
                    <p class="text-muted">No doors configured.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Door</th>
                                    <th>Status</th>
                                    <th>Version</th>
                                    <th>Update Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($doors as $door): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($door['name']); ?></td>
                                        <td>
                                            <?php
                                            $statusClass = match($door['status'] ?? 'unknown') {
                                                'online' => 'success',
                                                'offline' => 'danger',
                                                default => 'secondary'
                                            };
                                            ?>
                                            <span class="badge bg-<?php echo $statusClass; ?>"><?php echo ucfirst($door['status'] ?? 'unknown'); ?></span>
                                        </td>
                                        <td>
                                            <?php
                                            $cv = $door['controller_version'] ?? '';
                                            if ($cv) {
                                                echo htmlspecialchars($cv);
                                                if ($target_controller_version && version_compare($cv, $target_controller_version, '<')) {
                                                    echo ' <span class="badge bg-warning text-dark">Outdated</span>';
                                                }
                                            } else {
                                                echo '<span class="text-muted">Not reported</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            $us = $door['update_status'] ?? '';
                                            if ($us) {
                                                $usBadge = match($us) {
                                                    'success' => 'success',
                                                    'failed' => 'danger',
                                                    'updating' => 'info',
                                                    default => 'secondary'
                                                };
                                                echo '<span class="badge bg-' . $usBadge . '">' . ucfirst(htmlspecialchars($us)) . '</span>';
                                                if ($door['update_status_time']) {
                                                    echo ' <small class="text-muted">' . date('M j, g:i A', strtotime($door['update_status_time'])) . '</small>';
                                                }
                                            } else {
                                                echo '<span class="text-muted">-</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- How It Works -->
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="mb-0">How Updates Work</h5>
            </div>
            <div class="card-body">
                <h6>Server Updates</h6>
                <p>Click "Check for Updates" to query GitHub for the latest release. If a newer version is available, click "Update Server" to download and install the new web interface files. Your <code>config.php</code> is preserved.</p>

                <h6>Controller Updates</h6>
                <p>Set the <strong>Target Controller Version</strong> in <a href="settings.php">Settings</a>, then use the "Request Update" button on the <a href="doors.php">Doors</a> page. On the next heartbeat (every 60 seconds), the controller will download and install the update, then restart itself.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
