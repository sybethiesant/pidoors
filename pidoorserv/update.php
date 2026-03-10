<?php
/**
 * System Updates
 * PiDoors Access Control System
 */

// AJAX endpoint: return controller status as JSON (no HTML)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'controller_status') {
    $config = include(__DIR__ . '/includes/config.php');
    require_once __DIR__ . '/includes/security.php';
    require_once $config['apppath'] . 'database/db_connection.php';
    secure_session_start($config);

    if (!is_logged_in() || !is_admin()) {
        http_response_code(403);
        exit();
    }

    header('Content-Type: application/json');

    $version_file = $config['apppath'] . 'VERSION';
    $target = file_exists($version_file) ? trim(file_get_contents($version_file)) : '';

    try {
        $stmt = $pdo_access->query("SELECT name, controller_version, update_status, update_status_time, update_requested, status FROM doors ORDER BY name");
        $doors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $doors = [];
    }

    echo json_encode(['target_version' => $target, 'doors' => $doors]);
    exit();
}

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
    $settings_query = $pdo_access->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('server_version', 'github_latest_version', 'github_check_time')");
    $update_settings = [];
    while ($row = $settings_query->fetch()) {
        $update_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    $update_settings = [];
}

// Update server_version in DB if it doesn't match the VERSION file
if (($update_settings['server_version'] ?? '') !== $current_version) {
    // Run database migration for any missing columns/tables
    $migration_file = $config['apppath'] . 'database_migration.sql';
    if (file_exists($migration_file)) {
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
            error_log("Auto-migration failed (exit $mig_code): " . implode(' ', $mig_output));
        }
    }

    try {
        $stmt = $pdo_access->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES ('server_version', ?, 'Current server software version') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$current_version]);
        $update_settings['server_version'] = $current_version;
    } catch (PDOException $e) {
        // ignore
    }

    // Post-upgrade: deploy bundled React SPA if present but not yet deployed.
    // When upgrading from v2.x, the old updater copies pidoorserv/* (including
    // pidoors-ui-dist/) but can't deploy the SPA. This block handles it on
    // first page load after the upgrade.
    $ui_root = '/var/www/pidoors-ui';
    $bundled_dist = rtrim($config['apppath'], '/') . '/pidoors-ui-dist';
    if (is_dir($bundled_dist) && file_exists($bundled_dist . '/index.html')) {
        if (!file_exists($ui_root . '/index.html') || !is_dir($ui_root)) {
            if (!is_dir($ui_root)) @mkdir($ui_root, 0755, true);
            if (is_dir($ui_root) && is_writable($ui_root)) {
                @exec('cp -r ' . escapeshellarg($bundled_dist) . '/* ' . escapeshellarg($ui_root) . '/');
                @exec('chown -R www-data:www-data ' . escapeshellarg($ui_root) . ' 2>/dev/null');
            }
        }
        // Clean up the bundled dist from the web root (it's been deployed)
        @exec('rm -rf ' . escapeshellarg($bundled_dist));
    }
}

$github_latest = $update_settings['github_latest_version'] ?? '';
$github_check_time = $update_settings['github_check_time'] ?? '';
// Controllers should match the server version
$target_controller_version = $current_version !== 'unknown' ? $current_version : '';

// Auto-check GitHub if cache is stale (>1 hour) or on manual button press
$do_check = false;
if (isset($_POST['check_updates']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $do_check = true;
} elseif (!$github_check_time || (time() - strtotime($github_check_time)) > 3600) {
    $do_check = true;
}

if ($do_check) {
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

            if (isset($_POST['check_updates'])) {
                $success_message = "Update check complete.";
            }
        } else {
            if (isset($_POST['check_updates'])) {
                $error_message = 'Could not parse GitHub release data.';
            }
        }
    } else {
        if (isset($_POST['check_updates'])) {
            $error_message = 'Failed to reach GitHub API. HTTP code: ' . $http_code;
        }
    }
}

// Handle "Request Controller Update" buttons
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_update'])) {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        try {
            if ($_POST['request_update'] === 'all') {
                $pdo_access->exec("UPDATE doors SET update_requested = 1 WHERE status = 'online'");
                $success_message = 'Update requested for all online controllers.';
            } else {
                $door_to_update = sanitize_string($_POST['request_update']);
                $stmt = $pdo_access->prepare("UPDATE doors SET update_requested = 1 WHERE name = ?");
                $stmt->execute([$door_to_update]);
                $success_message = 'Update requested for ' . htmlspecialchars($door_to_update) . '.';
            }
        } catch (PDOException $e) {
            error_log("Request update error: " . $e->getMessage());
            $error_message = 'Failed to request controller update.';
        }
    }
}

// Handle "Update Server" button
if (isset($_POST['update_server']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    // Determine which version to download
    $target_tag = $github_latest ?: $current_version;
    if (empty($target_tag) || $target_tag === 'unknown') {
        $error_message = 'No version available. Check for updates first.';
    } else {
        require_once __DIR__ . '/includes/update-bootstrap.php';
        $result = pidoors_bootstrap_update($config, $pdo_access, $pdo, $target_tag);

        if ($result['ok']) {
            $current_version = $result['version'] ?? $target_tag;
            $success_message = $result['msg'] . ' Refresh the page to see updated code.';
        } else {
            $error_message = $result['msg'];
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
    $doors_stmt = $pdo_access->query("SELECT name, controller_version, update_status, update_status_time, update_requested, status FROM doors ORDER BY name");
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
                <p class="mb-3">Expected controller version: <strong><?php echo htmlspecialchars($target_controller_version ?: 'unknown'); ?></strong>
                    <small class="text-muted">(matches server version)</small>
                </p>

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
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="controller-table-body">
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
                                                $us_parts = explode(':', $us, 2);
                                                $us_base = trim($us_parts[0]);
                                                $us_detail = isset($us_parts[1]) ? trim($us_parts[1]) : '';
                                                $usBadge = match($us_base) {
                                                    'success' => 'success',
                                                    'failed' => 'danger',
                                                    'updating' => 'info',
                                                    default => 'secondary'
                                                };
                                                echo '<span class="badge bg-' . $usBadge . '">' . ucfirst(htmlspecialchars($us_base)) . '</span>';
                                                if ($us_detail) {
                                                    echo ' <small class="text-muted">' . htmlspecialchars($us_detail) . '</small>';
                                                }
                                                if ($door['update_status_time']) {
                                                    echo ' <small class="text-muted">(' . date('M j, g:i A', strtotime($door['update_status_time'])) . ')</small>';
                                                }
                                            } else {
                                                echo '<span class="text-muted">-</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            $cv = $door['controller_version'] ?? '';
                                            $is_outdated = $cv && $target_controller_version && version_compare($cv, $target_controller_version, '<');
                                            if ($door['update_requested']):
                                            ?>
                                                <span class="badge bg-info">Update Pending</span>
                                            <?php elseif ($is_outdated && $door['status'] === 'online'): ?>
                                                <form method="post" class="d-inline">
                                                    <?php echo csrf_field(); ?>
                                                    <button type="submit" name="request_update" value="<?php echo htmlspecialchars($door['name']); ?>" class="btn btn-sm btn-outline-warning">Request Update</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div id="update-all-container">
                    <?php
                    // Check if any online doors are outdated
                    $has_outdated = false;
                    if ($target_controller_version) {
                        foreach ($doors as $d) {
                            $cv = $d['controller_version'] ?? '';
                            if ($d['status'] === 'online' && $cv && version_compare($cv, $target_controller_version, '<')) {
                                $has_outdated = true;
                                break;
                            }
                        }
                    }
                    if ($has_outdated): ?>
                        <form method="post" class="mt-2" onsubmit="return confirm('Request update for all online controllers?');">
                            <?php echo csrf_field(); ?>
                            <button type="submit" name="request_update" value="all" class="btn btn-warning">Update All Controllers</button>
                        </form>
                    <?php endif; ?>
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
                <p>Controllers are expected to match the server version. After updating the server, use the "Request Update" button above (or on the <a href="doors.php">Doors</a> page). On the next heartbeat (every 60 seconds), the controller will download and install the update, then restart itself.</p>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var csrfToken = '<?php echo htmlspecialchars(generate_csrf_token()); ?>';
    var targetVersion = '<?php echo htmlspecialchars($target_controller_version); ?>';
    var pollTimer = null;

    function versionCompare(a, b) {
        var pa = a.split('.').map(Number), pb = b.split('.').map(Number);
        for (var i = 0; i < Math.max(pa.length, pb.length); i++) {
            var na = pa[i] || 0, nb = pb[i] || 0;
            if (na < nb) return -1;
            if (na > nb) return 1;
        }
        return 0;
    }

    function statusBadgeClass(base) {
        switch (base) {
            case 'success': return 'success';
            case 'failed': return 'danger';
            case 'updating': return 'info';
            default: return 'secondary';
        }
    }

    function doorStatusClass(status) {
        switch (status) {
            case 'online': return 'success';
            case 'offline': return 'danger';
            default: return 'secondary';
        }
    }

    function ucfirst(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

    function escHtml(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    function refreshControllers() {
        $.getJSON('update.php?ajax=controller_status', function(data) {
            var tbody = $('#controller-table-body');
            if (!tbody.length || !data.doors) return;
            targetVersion = data.target_version || targetVersion;

            var hasOutdated = false;
            var rows = '';
            $.each(data.doors, function(i, door) {
                var cv = door.controller_version || '';
                var isOutdated = cv && targetVersion && versionCompare(cv, targetVersion) < 0;
                var status = door.status || 'unknown';
                if (isOutdated && status === 'online') hasOutdated = true;

                rows += '<tr>';
                // Name
                rows += '<td>' + escHtml(door.name) + '</td>';
                // Status
                rows += '<td><span class="badge bg-' + doorStatusClass(status) + '">' + ucfirst(status) + '</span></td>';
                // Version
                rows += '<td>' + (cv ? escHtml(cv) : '<span class="text-muted">Not reported</span>');
                if (isOutdated) rows += ' <span class="badge bg-warning text-dark">Outdated</span>';
                rows += '</td>';
                // Update Status
                var us = door.update_status || '';
                rows += '<td>';
                if (us) {
                    var parts = us.split(':', 2);
                    var usBase = parts[0].trim();
                    var usDetail = parts.length > 1 ? parts.slice(1).join(':').trim() : '';
                    rows += '<span class="badge bg-' + statusBadgeClass(usBase) + '">' + ucfirst(escHtml(usBase)) + '</span>';
                    if (usDetail) rows += ' <small class="text-muted">' + escHtml(usDetail) + '</small>';
                    if (door.update_status_time) {
                        var d = new Date(door.update_status_time);
                        rows += ' <small class="text-muted">(' + d.toLocaleString([], {month:'short', day:'numeric', hour:'numeric', minute:'2-digit'}) + ')</small>';
                    }
                } else {
                    rows += '<span class="text-muted">-</span>';
                }
                rows += '</td>';
                // Action
                rows += '<td>';
                if (door.update_requested == 1) {
                    rows += '<span class="badge bg-info">Update Pending</span>';
                } else if (isOutdated && status === 'online') {
                    rows += '<form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="' + csrfToken + '">';
                    rows += '<button type="submit" name="request_update" value="' + escHtml(door.name) + '" class="btn btn-sm btn-outline-warning">Request Update</button></form>';
                } else {
                    rows += '<span class="text-muted">-</span>';
                }
                rows += '</td>';
                rows += '</tr>';
            });
            tbody.html(rows);

            // Update the "Update All" button
            var container = $('#update-all-container');
            if (hasOutdated) {
                container.html('<form method="post" class="mt-2" onsubmit="return confirm(\'Request update for all online controllers?\');"><input type="hidden" name="csrf_token" value="' + csrfToken + '"><button type="submit" name="request_update" value="all" class="btn btn-warning">Update All Controllers</button></form>');
            } else {
                container.html('');
            }
        });
    }

    // Poll every 5 seconds
    pollTimer = setInterval(refreshControllers, 2000);

    // Stop polling when page is hidden, resume when visible
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            clearInterval(pollTimer);
        } else {
            refreshControllers();
            pollTimer = setInterval(refreshControllers, 2000);
        }
    });
})();
</script>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
