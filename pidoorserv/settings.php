<?php
/**
 * System Settings
 * PiDoors Access Control System
 */
$title = 'System Settings';
require_once './includes/header.php';

require_login($config);
require_admin($config);

$error_message = '';
$success_message = '';

// Load current settings from database
try {
    $settings_query = $pdo_access->query("SELECT setting_key, setting_value FROM settings");
    $db_settings = [];
    while ($row = $settings_query->fetch()) {
        $db_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    $db_settings = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } else {
        $settings_to_save = [
            'site_name' => sanitize_string($_POST['site_name'] ?? 'PiDoors'),
            'max_unlock_duration' => validate_int($_POST['max_unlock_duration'] ?? 3600, 60, 86400) ?: 3600,
            'default_unlock_duration' => validate_int($_POST['default_unlock_duration'] ?? 5, 1, 86400) ?: 5,
            'default_daily_scan_limit' => validate_int($_POST['default_daily_scan_limit'] ?? 0, 0, 999),
            'session_timeout' => validate_int($_POST['session_timeout'] ?? 3600, 300, 86400) ?: 3600,
            'max_login_attempts' => validate_int($_POST['max_login_attempts'] ?? 5, 3, 20) ?: 5,
            'lockout_duration' => validate_int($_POST['lockout_duration'] ?? 900, 60, 86400) ?: 900,
            'heartbeat_interval' => validate_int($_POST['heartbeat_interval'] ?? 60, 30, 600) ?: 60,
            'cache_duration' => validate_int($_POST['cache_duration'] ?? 86400, 3600, 604800) ?: 86400,
            'email_notifications' => isset($_POST['email_notifications']) ? '1' : '0',
            'notification_email' => filter_var($_POST['notification_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '',
            'smtp_host' => sanitize_string($_POST['smtp_host'] ?? ''),
            'smtp_port' => validate_int($_POST['smtp_port'] ?? 587, 25, 65535) ?: 587,
            'smtp_user' => sanitize_string($_POST['smtp_user'] ?? ''),
            'smtp_pass' => ($_POST['smtp_pass'] ?? '') !== '' ? $_POST['smtp_pass'] : ($db_settings['smtp_pass'] ?? ''),
            'smtp_from' => filter_var($_POST['smtp_from'] ?? '', FILTER_VALIDATE_EMAIL) ?: '',
            'log_retention_days' => validate_int($_POST['log_retention_days'] ?? 365, 30, 3650) ?: 365,
            'timezone' => sanitize_string($_POST['timezone'] ?? 'UTC'),
        ];

        try {
            // Build detailed change log before saving
            $changes = [];
            foreach ($settings_to_save as $key => $value) {
                $old = $db_settings[$key] ?? '';
                if ((string)$old !== (string)$value) {
                    if ($key === 'smtp_pass') {
                        $changes[] = "smtp_pass (changed)";
                    } else {
                        $changes[] = "$key ($old → $value)";
                    }
                }
            }

            $stmt = $pdo_access->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

            foreach ($settings_to_save as $key => $value) {
                $stmt->execute([$key, $value]);
                $db_settings[$key] = $value;
            }

            $success_message = 'Settings saved successfully.';

            // Log settings change with details
            if ($changes) {
                $detail = 'Changed: ' . implode(', ', $changes);
            } else {
                $detail = 'Settings saved (no changes)';
            }
            log_security_event($pdo, 'settings_change', $_SESSION['user_id'] ?? null, $detail);

        } catch (PDOException $e) {
            error_log("Save settings error: " . $e->getMessage());
            $error_message = 'Failed to save settings.';
        }
    }
}

// Get default values
$settings = [
    'site_name' => $db_settings['site_name'] ?? 'PiDoors',
    'max_unlock_duration' => $db_settings['max_unlock_duration'] ?? 3600,
    'default_unlock_duration' => $db_settings['default_unlock_duration'] ?? 5,
    'default_daily_scan_limit' => $db_settings['default_daily_scan_limit'] ?? 0,
    'session_timeout' => $db_settings['session_timeout'] ?? 3600,
    'max_login_attempts' => $db_settings['max_login_attempts'] ?? 5,
    'lockout_duration' => $db_settings['lockout_duration'] ?? 900,
    'heartbeat_interval' => $db_settings['heartbeat_interval'] ?? 60,
    'cache_duration' => $db_settings['cache_duration'] ?? 86400,
    'email_notifications' => $db_settings['email_notifications'] ?? '0',
    'notification_email' => $db_settings['notification_email'] ?? '',
    'smtp_host' => $db_settings['smtp_host'] ?? '',
    'smtp_port' => $db_settings['smtp_port'] ?? '587',
    'smtp_user' => $db_settings['smtp_user'] ?? '',
    'smtp_pass' => $db_settings['smtp_pass'] ?? '',
    'smtp_from' => $db_settings['smtp_from'] ?? '',
    'log_retention_days' => $db_settings['log_retention_days'] ?? 365,
    'timezone' => $db_settings['timezone'] ?? 'UTC',
];

// Get list of timezones
$timezones = DateTimeZone::listIdentifiers();
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <form method="post">
            <?php echo csrf_field(); ?>

            <!-- General Settings -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">General Settings</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="site_name" class="form-label">Site Name</label>
                        <input type="text" class="form-control" id="site_name" name="site_name"
                               value="<?php echo htmlspecialchars($settings['site_name']); ?>">
                        <div class="form-text">Displayed in the header and page titles.</div>
                    </div>

                    <div class="mb-3">
                        <label for="timezone" class="form-label">Timezone</label>
                        <select class="form-select" id="timezone" name="timezone">
                            <?php foreach ($timezones as $tz): ?>
                                <option value="<?php echo $tz; ?>" <?php echo $settings['timezone'] === $tz ? 'selected' : ''; ?>>
                                    <?php echo $tz; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="default_unlock_duration" class="form-label">Default Unlock Duration (seconds)</label>
                        <input type="number" class="form-control" id="default_unlock_duration" name="default_unlock_duration"
                               min="1" max="86400" value="<?php echo htmlspecialchars($settings['default_unlock_duration']); ?>">
                        <div class="form-text">How long doors stay unlocked after access is granted. Max is controlled by the setting below.</div>
                    </div>
                </div>
            </div>

            <!-- Security Settings -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-warning">
                    <h5 class="mb-0">Security Settings</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="session_timeout" class="form-label">Session Timeout (seconds)</label>
                            <input type="number" class="form-control" id="session_timeout" name="session_timeout"
                                   min="300" max="86400" value="<?php echo htmlspecialchars($settings['session_timeout']); ?>">
                            <div class="form-text">Auto-logout after inactivity (5 min - 24 hours).</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="max_login_attempts" class="form-label">Max Login Attempts</label>
                            <input type="number" class="form-control" id="max_login_attempts" name="max_login_attempts"
                                   min="3" max="20" value="<?php echo htmlspecialchars($settings['max_login_attempts']); ?>">
                            <div class="form-text">Failed attempts before lockout (3-20).</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="lockout_duration" class="form-label">Lockout Duration (seconds)</label>
                        <input type="number" class="form-control" id="lockout_duration" name="lockout_duration"
                               min="60" max="86400" value="<?php echo htmlspecialchars($settings['lockout_duration']); ?>">
                        <div class="form-text">How long an account is locked after max failed attempts.</div>
                    </div>
                </div>
            </div>

            <!-- Door Controller Settings -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Door Controller Settings</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="max_unlock_duration" class="form-label">Max Unlock Duration (seconds)</label>
                            <input type="number" class="form-control" id="max_unlock_duration" name="max_unlock_duration"
                                   min="60" max="86400" value="<?php echo htmlspecialchars($settings['max_unlock_duration']); ?>">
                            <div class="form-text">Maximum unlock duration admins can set per door (60s - 24 hours).</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="default_daily_scan_limit" class="form-label">Default Daily Scan Limit</label>
                            <input type="number" class="form-control" id="default_daily_scan_limit" name="default_daily_scan_limit"
                                   min="0" max="999" value="<?php echo htmlspecialchars($settings['default_daily_scan_limit']); ?>">
                            <div class="form-text">Default daily scan limit for new cards (0 = unlimited).</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="heartbeat_interval" class="form-label">Heartbeat Interval (seconds)</label>
                            <input type="number" class="form-control" id="heartbeat_interval" name="heartbeat_interval"
                                   min="30" max="600" value="<?php echo htmlspecialchars($settings['heartbeat_interval']); ?>">
                            <div class="form-text">How often door controllers report status.</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="cache_duration" class="form-label">Cache Duration (seconds)</label>
                            <input type="number" class="form-control" id="cache_duration" name="cache_duration"
                                   min="3600" max="604800" value="<?php echo htmlspecialchars($settings['cache_duration']); ?>">
                            <div class="form-text">How long controllers cache access data (1 hour - 7 days).</div>
                        </div>
                    </div>
                    <div class="alert alert-info mb-0">
                        <strong>Note:</strong> Current cache duration is <?php echo round($settings['cache_duration'] / 3600); ?> hours.
                        Controllers will continue to grant access for this period even if they lose connection to the server.
                    </div>
                </div>
            </div>

            <!-- Notification Settings -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Notification Settings</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="email_notifications" name="email_notifications"
                               <?php echo $settings['email_notifications'] === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="email_notifications">Enable Email Notifications</label>
                    </div>
                    <div class="mb-3">
                        <label for="notification_email" class="form-label">Notification Email</label>
                        <input type="email" class="form-control" id="notification_email" name="notification_email"
                               value="<?php echo htmlspecialchars($settings['notification_email']); ?>">
                        <div class="form-text">Email address to receive security alerts and notifications.</div>
                    </div>

                    <hr>
                    <h6 class="fw-bold mb-3">SMTP Configuration</h6>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label for="smtp_host" class="form-label">SMTP Server</label>
                            <input type="text" class="form-control" id="smtp_host" name="smtp_host"
                                   value="<?php echo htmlspecialchars($settings['smtp_host']); ?>"
                                   placeholder="smtp.gmail.com">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="smtp_port" class="form-label">Port</label>
                            <input type="number" class="form-control" id="smtp_port" name="smtp_port"
                                   min="25" max="65535" value="<?php echo htmlspecialchars($settings['smtp_port']); ?>">
                            <div class="form-text">587 STARTTLS, 465 SSL, 25 plain</div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="smtp_user" class="form-label">Username</label>
                            <input type="text" class="form-control" id="smtp_user" name="smtp_user"
                                   value="<?php echo htmlspecialchars($settings['smtp_user']); ?>"
                                   placeholder="user@gmail.com" autocomplete="off">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="smtp_pass" class="form-label">Password</label>
                            <input type="password" class="form-control" id="smtp_pass" name="smtp_pass"
                                   value="" placeholder="<?php echo !empty($settings['smtp_pass']) ? '••••••••' : ''; ?>" autocomplete="new-password">
                            <div class="form-text">For Gmail, use an App Password. Leave blank to keep existing.</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="smtp_from" class="form-label">From Address</label>
                        <input type="email" class="form-control" id="smtp_from" name="smtp_from"
                               value="<?php echo htmlspecialchars($settings['smtp_from']); ?>"
                               placeholder="noreply@yourdomain.com">
                        <div class="form-text">The sender address for notification emails.</div>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <button type="button" class="btn btn-outline-primary" id="btn-test-email">Send Test Email</button>
                        <span id="test-email-result" class="small"></span>
                    </div>
                </div>
            </div>

            <!-- Maintenance Settings -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Maintenance Settings</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="log_retention_days" class="form-label">Log Retention (days)</label>
                        <input type="number" class="form-control" id="log_retention_days" name="log_retention_days"
                               min="30" max="3650" value="<?php echo htmlspecialchars($settings['log_retention_days']); ?>">
                        <div class="form-text">How long to keep access logs before automatic cleanup (30 days - 10 years).</div>
                    </div>
                </div>
            </div>

            <!-- Version & Updates -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Version & Updates</h5>
                </div>
                <div class="card-body">
                    <?php
                    $version_file = $config['apppath'] . 'VERSION';
                    $server_version = 'unknown';
                    if (file_exists($version_file)) {
                        $server_version = trim(file_get_contents($version_file));
                    }
                    ?>
                    <p class="mb-3"><strong>Server Version:</strong> <?php echo htmlspecialchars($server_version); ?>
                        &mdash; <a href="update.php">Check for Updates</a>
                    </p>
                    <p class="mb-0 text-muted">Door controllers are expected to match the server version. Outdated controllers can be updated from the <a href="doors.php">Doors</a> page.</p>
                </div>
            </div>

            <div class="d-flex justify-content-end mb-4">
                <button type="submit" class="btn btn-primary btn-lg">Save Settings</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('btn-test-email')?.addEventListener('click', function() {
    const btn = this;
    const result = document.getElementById('test-email-result');
    btn.disabled = true;
    btn.textContent = 'Sending...';
    result.textContent = '';
    result.className = 'small';

    const formData = new FormData();
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    formData.append('notification_email', document.getElementById('notification_email').value);
    formData.append('smtp_host', document.getElementById('smtp_host').value);
    formData.append('smtp_port', document.getElementById('smtp_port').value);
    formData.append('smtp_user', document.getElementById('smtp_user').value);
    formData.append('smtp_pass', document.getElementById('smtp_pass').value);
    formData.append('smtp_from', document.getElementById('smtp_from').value);
    formData.append('site_name', document.getElementById('site_name').value);

    fetch('test_email.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            result.textContent = data.message;
            result.className = 'small ' + (data.success ? 'text-success fw-bold' : 'text-danger');
        })
        .catch(err => {
            result.textContent = 'Request failed: ' + err.message;
            result.className = 'small text-danger';
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = 'Send Test Email';
        });
});
</script>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
