<?php
/**
 * Server Update Worker
 * PiDoors Access Control System
 *
 * Contains the update deployment logic. This file is loaded from the
 * DOWNLOADED release (not the installed copy) so the latest update
 * logic always runs, even when upgrading from an older version.
 *
 * Usage:
 *   $result = pidoors_deploy_update($config, $pdo_access, $pdo, $extracted);
 *   // $result = ['ok' => true/false, 'msg' => '...', 'details' => [...]]
 *
 * @param array $config     Application config (from config.php)
 * @param PDO   $pdo_access PDO connection to 'access' database
 * @param PDO   $pdo        PDO connection to 'users' database
 * @param string $extracted Path to the extracted release directory
 */

function pidoors_deploy_update(array $config, PDO $pdo_access, PDO $pdo, string $extracted): array {
    $details = [];
    $web_src = $extracted . '/pidoorserv';
    $apppath = rtrim($config['apppath'], '/');

    if (!is_dir($web_src)) {
        return ['ok' => false, 'msg' => 'Web source directory not found in release archive.', 'details' => []];
    }

    // --- Pre-flight: verify all files are writable ---
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
            if (!is_dir($target)) {
                $check_dir = dirname($target);
                while ($check_dir !== $apppath && !is_dir($check_dir)) {
                    $check_dir = dirname($check_dir);
                }
                if (!is_writable($check_dir)) {
                    $preflight_errors[] = "Cannot create directory: $sub";
                }
            }
        } else {
            if ($sub === 'includes/config.php') continue;
            if (file_exists($target) && !is_writable($target)) {
                $preflight_errors[] = $sub;
            } elseif (!file_exists($target)) {
                $check_dir = dirname($target);
                while ($check_dir !== $apppath && !is_dir($check_dir)) {
                    $check_dir = dirname($check_dir);
                }
                if (!is_writable($check_dir)) {
                    $preflight_errors[] = "$sub (parent dir not writable)";
                }
            }
            $files_to_copy[] = ['src' => $item->getPathname(), 'sub' => $sub, 'target' => $target];
        }
    }

    $version_target = $apppath . '/VERSION';
    if (file_exists($version_target) && !is_writable($version_target)) {
        $preflight_errors[] = 'VERSION';
    }

    if (!empty($preflight_errors)) {
        $count = count($preflight_errors);
        $sample = array_slice($preflight_errors, 0, 5);
        $msg = "Update aborted: $count file(s) are not writable — " . implode(', ', $sample);
        if ($count > 5) $msg .= " and " . ($count - 5) . " more";
        $msg .= ". Fix file ownership and try again.";
        return ['ok' => false, 'msg' => $msg, 'details' => []];
    }

    // --- Copy PHP files (this updates the update scripts themselves) ---
    $copied = 0;
    $failed = 0;
    $failed_files = [];

    // Create directories first
    $dir_iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($web_src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($dir_iterator as $item) {
        if ($item->isDir()) {
            $target = $apppath . '/' . $dir_iterator->getSubPathName();
            if (!is_dir($target)) mkdir($target, 0755, true);
        }
    }

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
        $msg = "Update aborted after $failed copy failure(s): " . implode(', ', $sample);
        if ($failed > 5) $msg .= " and " . ($failed - 5) . " more";
        return ['ok' => false, 'msg' => $msg, 'details' => []];
    }
    $details[] = "$copied files copied";

    // --- Remove orphaned files ---
    $removed = 0;
    $remove_iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($apppath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($remove_iterator as $item) {
        $sub = substr($item->getPathname(), strlen($apppath) + 1);
        if ($sub === 'includes' . DIRECTORY_SEPARATOR . 'config.php' || $sub === 'includes/config.php') continue;
        if ($sub === 'VERSION' || $sub === 'database_migration.sql' || $sub === 'ca.pem') continue;
        if (str_starts_with($sub, 'pidoors-ui-dist') || str_starts_with($sub, 'pidoors-ui-dist' . DIRECTORY_SEPARATOR)) continue;
        if (str_starts_with(basename($sub), '.')) continue;

        $release_path = $web_src . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $sub);
        if ($item->isFile() && !file_exists($release_path)) {
            if (@unlink($item->getPathname())) $removed++;
        } elseif ($item->isDir() && !file_exists($release_path)) {
            @rmdir($item->getPathname());
        }
    }
    if ($removed > 0) $details[] = "$removed orphaned file(s) removed";

    // --- Fix file ownership and permissions ---
    @exec('chown -R www-data:www-data ' . escapeshellarg($apppath) . ' 2>/dev/null');
    @exec('chmod -R 755 ' . escapeshellarg($apppath) . ' 2>/dev/null');
    $config_file = $apppath . '/includes/config.php';
    if (file_exists($config_file)) {
        @chmod($config_file, 0640);
    }

    // --- Update VERSION ---
    $new_version = '';
    if (file_exists($extracted . '/VERSION')) {
        $new_version = trim(file_get_contents($extracted . '/VERSION'));
        if (!copy($extracted . '/VERSION', $apppath . '/VERSION')) {
            return ['ok' => false, 'msg' => "Files copied but VERSION file update failed.", 'details' => $details];
        }
    }

    // --- Copy database migration ---
    if (file_exists($extracted . '/database_migration.sql')) {
        copy($extracted . '/database_migration.sql', $apppath . '/database_migration.sql');
    }

    // --- Restore ca.pem if missing (may have been deleted by older updater versions) ---
    $ca_web = $apppath . '/ca.pem';
    $ca_src = '/etc/mysql/ssl/ca.pem';
    if (!file_exists($ca_web) && file_exists($ca_src)) {
        if (copy($ca_src, $ca_web)) {
            @chmod($ca_web, 0644);
            $details[] = 'CA certificate restored to web root';
        }
    }
    // Note: SSL directory permissions (/etc/mysql/ssl/) are fixed by server-update.sh
    // and install.sh which run as root. The web updater runs as www-data and cannot chown.

    // --- Ensure required directories ---
    $required_dirs = ['/var/backups/pidoors' => ['owner' => 'www-data:www-data', 'mode' => '750']];
    foreach ($required_dirs as $dir => $opts) {
        if (!is_dir($dir)) {
            @mkdir($dir, octdec($opts['mode']), true);
            @exec('chown ' . escapeshellarg($opts['owner']) . ' ' . escapeshellarg($dir) . ' 2>/dev/null');
        }
    }

    // --- Deploy React SPA ---
    $ui_root = '/var/www/pidoors-ui';

    // Check for pre-built SPA dist (from build-release.sh)
    // Try root-level first, then inside pidoorserv/ (for v2.x updater compat)
    $ui_dist_src = $extracted . '/pidoors-ui-dist';
    if (!is_dir($ui_dist_src) || !file_exists($ui_dist_src . '/index.html')) {
        $ui_dist_src = $web_src . '/pidoors-ui-dist';
    }
    // Also check for source to build from
    $ui_src = $extracted . '/pidoors-ui';

    if (is_dir($ui_dist_src) && file_exists($ui_dist_src . '/index.html')) {
        // Pre-built SPA — just copy files
        if (!is_dir($ui_root)) @mkdir($ui_root, 0755, true);
        if (is_dir($ui_root) && is_writable($ui_root)) {
            // Get expected JS bundle name from source dist
            $expected_assets = glob($ui_dist_src . '/assets/index-*.js');
            $expected_js = $expected_assets ? basename($expected_assets[0]) : null;

            $rm_output = [];
            exec('rm -rf ' . escapeshellarg($ui_root) . '/* 2>&1', $rm_output, $rm_code);
            if ($rm_code !== 0) {
                $details[] = 'WARNING: Could not clean React UI directory — ' . implode(' ', $rm_output);
                error_log("SPA rm -rf failed: " . implode(' ', $rm_output));
            }
            $cp_output = [];
            exec('cp -r ' . escapeshellarg($ui_dist_src) . '/* ' . escapeshellarg($ui_root) . '/ 2>&1', $cp_output, $cp_code);
            @exec('chown -R www-data:www-data ' . escapeshellarg($ui_root) . ' 2>/dev/null');

            // Verify the correct JS bundle was deployed, not a stale one
            if ($cp_code !== 0) {
                $details[] = 'WARNING: React SPA copy failed — ' . implode(' ', $cp_output);
                error_log("SPA cp failed: " . implode(' ', $cp_output));
            } elseif ($expected_js && !file_exists($ui_root . '/assets/' . $expected_js)) {
                $details[] = 'WARNING: React SPA deployed but JS bundle mismatch — old files may remain due to permissions';
            } elseif (file_exists($ui_root . '/index.html')) {
                $details[] = 'React UI deployed (pre-built)';
            } else {
                $details[] = 'WARNING: React SPA copy failed — index.html missing after copy';
            }
        } else {
            $details[] = "WARNING: React SPA skipped — $ui_root is not writable";
        }
    } elseif (is_dir($ui_src) && file_exists($ui_src . '/package.json')) {
        // Source only — try to build
        $node_path = trim(@exec('which node 2>/dev/null'));
        $npm_path = trim(@exec('which npm 2>/dev/null'));
        if ($node_path && $npm_path) {
            $build_dir = sys_get_temp_dir() . '/pidoors-ui-build-' . uniqid();
            @exec('cp -r ' . escapeshellarg($ui_src) . ' ' . escapeshellarg($build_dir));
            $build_output = [];
            $build_code = 0;
            @exec('cd ' . escapeshellarg($build_dir) . ' && npm install --loglevel=error 2>&1 && npm run build 2>&1', $build_output, $build_code);

            if ($build_code === 0 && file_exists($build_dir . '/dist/index.html')) {
                if (!is_dir($ui_root)) @mkdir($ui_root, 0755, true);
                @exec('rm -rf ' . escapeshellarg($ui_root) . '/*');
                @exec('cp -r ' . escapeshellarg($build_dir) . '/dist/* ' . escapeshellarg($ui_root) . '/');
                @exec('chown -R www-data:www-data ' . escapeshellarg($ui_root) . ' 2>/dev/null');
                if (file_exists($ui_root . '/index.html')) {
                    $details[] = 'React UI built and deployed';
                }
            } else {
                $details[] = 'React SPA build failed';
                error_log("React SPA build failed: " . implode("\n", $build_output));
            }
            @exec('rm -rf ' . escapeshellarg($build_dir));
        } else {
            $details[] = 'Node.js not installed — run server-update.sh to deploy React UI';
        }
    }

    // Clean up bundled dist from web root (it's been deployed or isn't needed)
    $bundled_in_webroot = $apppath . '/pidoors-ui-dist';
    if (is_dir($bundled_in_webroot)) {
        @exec('rm -rf ' . escapeshellarg($bundled_in_webroot));
    }

    // --- Database migration ---
    $migration_file = $extracted . '/database_migration.sql';
    if (file_exists($migration_file)) {
        putenv('MYSQL_PWD=' . $config['sqlpass']);
        $mig_cmd = sprintf('mysql -h %s -u %s %s < %s 2>&1',
            escapeshellarg($config['sqladdr']),
            escapeshellarg($config['sqluser']),
            escapeshellarg($config['sqldb2']),
            escapeshellarg($migration_file)
        );
        $mig_output = [];
        $mig_code = 0;
        exec($mig_cmd, $mig_output, $mig_code);
        putenv('MYSQL_PWD');
        if ($mig_code === 0) {
            $details[] = 'Database schema updated';
        } else {
            $mig_err = implode(' ', $mig_output);
            $details[] = 'WARNING: Database migration had errors — ' . $mig_err;
            error_log("Database migration failed (exit $mig_code): $mig_err");
        }
    }

    // --- Update DB version ---
    try {
        $stmt = $pdo_access->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('server_version', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$new_version]);
    } catch (PDOException $e) {
        // ignore
    }

    // --- Log event ---
    $user_id = $_SESSION['user_id'] ?? null;
    if (function_exists('log_security_event')) {
        log_security_event($pdo, 'server_update', $user_id, "Server updated to version $new_version ($copied files)");
    }

    $msg = "Server updated to version $new_version. " . implode('. ', $details) . '.';
    return ['ok' => true, 'msg' => $msg, 'details' => $details, 'version' => $new_version];
}
