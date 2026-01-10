<?php
/**
 * User Profile
 * PiDoors Access Control System
 */
$title = 'My Profile';
require_once '../includes/header.php';

require_login($config);

$error_message = '';
$success_message = '';

// Get current user
$user_id = $_SESSION['user_id'];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        header("Location: {$config['url']}/index.php");
        exit();
    }
} catch (PDOException $e) {
    error_log("Profile load error: " . $e->getMessage());
    header("Location: {$config['url']}/index.php");
    exit();
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);

            if (!$email) {
                $error_message = 'Valid email address is required.';
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                    $stmt->execute([$email, $user_id]);
                    $user['email'] = $email;
                    $success_message = 'Profile updated successfully.';

                    log_security_event($pdo, 'profile_update', $user_id, 'Email updated');
                } catch (PDOException $e) {
                    error_log("Profile update error: " . $e->getMessage());
                    $error_message = 'Failed to update profile.';
                }
            }
        } elseif ($action === 'change_password') {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $error_message = 'All password fields are required.';
            } elseif ($new_password !== $confirm_password) {
                $error_message = 'New passwords do not match.';
            } elseif (!verify_password($current_password, $user['user_pass'])) {
                // Also check legacy MD5
                $legacy_hash = md5($config['password']['salt'] . $current_password);
                if ($user['user_pass'] !== $legacy_hash) {
                    $error_message = 'Current password is incorrect.';
                }
            }

            if (!$error_message) {
                $password_validation = validate_password($new_password, $config);
                if ($password_validation !== true) {
                    $error_message = $password_validation;
                }
            }

            if (!$error_message) {
                try {
                    $new_hash = hash_password($new_password);
                    $stmt = $pdo->prepare("UPDATE users SET user_pass = ? WHERE id = ?");
                    $stmt->execute([$new_hash, $user_id]);
                    $success_message = 'Password changed successfully.';

                    log_security_event($pdo, 'password_change', $user_id, 'Password changed by user');
                } catch (PDOException $e) {
                    error_log("Password change error: " . $e->getMessage());
                    $error_message = 'Failed to change password.';
                }
            }
        }
    }
}

// Get recent activity
try {
    $stmt = $pdo->prepare("
        SELECT event_type, ip_address, created_at
        FROM audit_logs
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $activity = $stmt->fetchAll();
} catch (PDOException $e) {
    $activity = [];
}
?>

<div class="row">
    <div class="col-lg-6 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Profile Information</h5>
            </div>
            <div class="card-body">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>
                <?php if ($success_message): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>

                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['user_name']); ?>" disabled>
                        <div class="form-text">Username cannot be changed.</div>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <input type="text" class="form-control"
                               value="<?php echo $user['is_admin'] ? 'Administrator' : 'User'; ?>" disabled>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Account Created</label>
                        <input type="text" class="form-control"
                               value="<?php echo date('Y-m-d H:i:s', strtotime($user['created_at'] ?? 'now')); ?>" disabled>
                    </div>

                    <button type="submit" class="btn btn-primary">Update Profile</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-warning">
                <h5 class="mb-0">Change Password</h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required
                               minlength="<?php echo $config['password']['min_length']; ?>">
                        <div class="form-text">
                            Minimum <?php echo $config['password']['min_length']; ?> characters.
                            <?php if ($config['password']['require_mixed_case']): ?>Must include upper and lowercase.<?php endif; ?>
                            <?php if ($config['password']['require_numbers']): ?>Must include numbers.<?php endif; ?>
                            <?php if ($config['password']['require_special']): ?>Must include special characters.<?php endif; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>

                    <button type="submit" class="btn btn-warning">Change Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-12">
        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0">Recent Activity</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Date/Time</th>
                            <th>Event</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activity as $event): ?>
                            <tr>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($event['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $event['event_type']))); ?></td>
                                <td><code><?php echo htmlspecialchars($event['ip_address'] ?? 'N/A'); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($activity)): ?>
                            <tr><td colspan="3" class="text-center text-muted">No recent activity.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
