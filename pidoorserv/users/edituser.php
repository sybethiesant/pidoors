<?php
/**
 * Edit User
 * PiDoors Access Control System
 */
$title = 'Edit User';
require_once '../includes/header.php';

require_login($config);
require_admin($config);

$error_message = '';
$user_id = validate_int($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$user_id) {
    header("Location: {$config['url']}/users/view_users.php?error=No user specified.");
    exit();
}

// Get user
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        header("Location: {$config['url']}/users/view_users.php?error=User not found.");
        exit();
    }
} catch (PDOException $e) {
    error_log("Edit user load error: " . $e->getMessage());
    header("Location: {$config['url']}/users/view_users.php?error=Error loading user.");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } else {
        $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $is_admin = isset($_POST['is_admin']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $new_password = $_POST['new_password'] ?? '';
        $first_name = sanitize_string($_POST['first_name'] ?? '');
        $last_name = sanitize_string($_POST['last_name'] ?? '');
        $phone = sanitize_string($_POST['phone'] ?? '');
        $department = sanitize_string($_POST['department'] ?? '');
        $employee_id_val = sanitize_string($_POST['employee_id'] ?? '');
        $company = sanitize_string($_POST['company'] ?? '');
        $job_title = sanitize_string($_POST['job_title'] ?? '');
        $notes = sanitize_string($_POST['notes'] ?? '');

        // Prevent removing own admin rights
        if ($user_id == $_SESSION['user_id'] && !$is_admin && $user['admin']) {
            $error_message = 'You cannot remove your own administrator privileges.';
        } elseif (!$email) {
            $error_message = 'Valid email address is required.';
        } else {
            try {
                $profile_fields = ", first_name = ?, last_name = ?, phone = ?, department = ?, employee_id = ?, company = ?, job_title = ?, notes = ?";
                $profile_values = [$first_name ?: null, $last_name ?: null, $phone ?: null, $department ?: null, $employee_id_val ?: null, $company ?: null, $job_title ?: null, $notes ?: null];

                // Update user
                if (!empty($new_password)) {
                    // Validate password
                    $password_validation = validate_password($new_password, $config);
                    if ($password_validation !== true) {
                        $error_message = $password_validation;
                    } else {
                        $password_hash = hash_password($new_password);
                        $stmt = $pdo->prepare("UPDATE users SET user_email = ?, admin = ?, active = ?, user_pass = ?" . $profile_fields . " WHERE id = ?");
                        $stmt->execute(array_merge([$email, $is_admin, $is_active, $password_hash], $profile_values, [$user_id]));

                        log_security_event($pdo, 'user_modified', $_SESSION['user_id'], "User {$user['user_name']} updated with password reset");
                    }
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET user_email = ?, admin = ?, active = ?" . $profile_fields . " WHERE id = ?");
                    $stmt->execute(array_merge([$email, $is_admin, $is_active], $profile_values, [$user_id]));

                    log_security_event($pdo, 'user_modified', $_SESSION['user_id'], "User {$user['user_name']} updated");
                }

                if (!$error_message) {
                    header("Location: {$config['url']}/users/view_users.php?success=User updated successfully.");
                    exit();
                }
            } catch (PDOException $e) {
                error_log("Update user error: " . $e->getMessage());
                $error_message = 'Failed to update user.';
            }
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Edit User: <?php echo htmlspecialchars($user['user_name']); ?></h5>
            </div>
            <div class="card-body">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo $user_id; ?>">

                    <h6 class="text-muted mb-3">Account Details</h6>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['user_name']); ?>" disabled>
                            <div class="form-text">Username cannot be changed.</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" required
                                   value="<?php echo htmlspecialchars($user['user_email'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password"
                                   minlength="<?php echo $config['password_min_length']; ?>">
                            <div class="form-text">Leave blank to keep current password.</div>
                        </div>
                        <div class="col-md-6 mb-3 pt-2">
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input" id="is_admin" name="is_admin"
                                       <?php echo $user['admin'] ? 'checked' : ''; ?>
                                       <?php echo $user_id == $_SESSION['user_id'] ? 'disabled' : ''; ?>>
                                <label class="form-check-label" for="is_admin">Administrator</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="is_active" name="is_active"
                                       <?php echo ($user['active'] ?? 1) ? 'checked' : ''; ?>
                                       <?php echo $user_id == $_SESSION['user_id'] ? 'disabled' : ''; ?>>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                            <?php if ($user_id == $_SESSION['user_id']): ?>
                                <div class="form-text text-warning">You cannot modify your own admin/active status.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <hr>
                    <h6 class="text-muted mb-3">Profile Details</h6>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="first_name" class="form-label">First Name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name"
                                   value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name"
                                   value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="phone" name="phone"
                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="employee_id" class="form-label">Employee ID</label>
                            <input type="text" class="form-control" id="employee_id" name="employee_id"
                                   value="<?php echo htmlspecialchars($user['employee_id'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="department" class="form-label">Department</label>
                            <input type="text" class="form-control" id="department" name="department"
                                   value="<?php echo htmlspecialchars($user['department'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="job_title" class="form-label">Job Title</label>
                            <input type="text" class="form-control" id="job_title" name="job_title"
                                   value="<?php echo htmlspecialchars($user['job_title'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="company" class="form-label">Company</label>
                            <input type="text" class="form-control" id="company" name="company"
                                   value="<?php echo htmlspecialchars($user['company'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="1"><?php echo htmlspecialchars($user['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="card bg-light mb-3">
                        <div class="card-body">
                            <h6>Account Information</h6>
                            <p class="mb-1"><strong>Created:</strong> <?php echo date('Y-m-d H:i:s', strtotime($user['created_at'] ?? 'now')); ?></p>
                            <?php if (isset($user['last_login'])): ?>
                                <p class="mb-0"><strong>Last Login:</strong> <?php echo date('Y-m-d H:i:s', strtotime($user['last_login'])); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between">
                        <a href="view_users.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
