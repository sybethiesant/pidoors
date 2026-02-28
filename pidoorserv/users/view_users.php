<?php
/**
 * View Panel Users
 * PiDoors Access Control System
 */
$title = 'Panel Users';
require_once __DIR__ . '/../includes/header.php';

// Require admin access
require_admin($config);

$error_message = '';
$show_modal = false;

// Process add user form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token. Please try again.';
    } else {
        $user_name = sanitize_string($_POST['name'] ?? '');
        $user_email = sanitize_email($_POST['email'] ?? '');
        $password = $_POST['pass'] ?? '';
        $user_isadmin = isset($_POST['isadmin']) ? 1 : 0;

            $first_name = sanitize_string($_POST['first_name'] ?? '');
        $last_name = sanitize_string($_POST['last_name'] ?? '');
        $phone = sanitize_string($_POST['phone'] ?? '');
        $department = sanitize_string($_POST['department'] ?? '');
        $employee_id = sanitize_string($_POST['employee_id'] ?? '');
        $company = sanitize_string($_POST['company'] ?? '');
        $job_title = sanitize_string($_POST['job_title'] ?? '');
        $notes = sanitize_string($_POST['notes'] ?? '');

        if (empty($user_name)) {
            $error_message = 'Please enter a username.';
        } elseif (empty($user_email) || !validate_email($user_email)) {
            $error_message = 'Please enter a valid email address.';
        } elseif (empty($password)) {
            $error_message = 'Please enter a password.';
        } else {
            $password_errors = validate_password($password, $config);
            if (!empty($password_errors)) {
                $error_message = implode(' ', $password_errors);
            } else {
                try {
                    // Check for duplicate email or username
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_email = ? OR user_name = ?");
                    $stmt->execute([$user_email, $user_name]);

                    if ($stmt->fetch()) {
                        $error_message = 'A user with this email or username already exists.';
                    } else {
                        $password_hash = hash_password($password);
                        $stmt = $pdo->prepare("INSERT INTO users (user_name, user_pass, user_email, admin, first_name, last_name, phone, department, employee_id, company, job_title, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$user_name, $password_hash, $user_email, $user_isadmin, $first_name ?: null, $last_name ?: null, $phone ?: null, $department ?: null, $employee_id ?: null, $company ?: null, $job_title ?: null, $notes ?: null]);

                        try {
                            log_security_event($pdo, 'user_created', $_SESSION['user_id'],
                                "Created user: $user_email (admin: $user_isadmin)");
                        } catch (Exception $e) {
                            // Ignore if audit log doesn't exist
                        }

                        header("Location: {$config['url']}/users/view_users.php?success=User has been added successfully.");
                        exit();
                    }
                } catch (PDOException $e) {
                    error_log("Add user error: " . $e->getMessage());
                    $error_message = 'A system error occurred. Please try again.';
                }
            }
        }
    }
    $show_modal = true;
}

// Fetch all users
try {
    $stmt = $pdo->query("SELECT id, user_name, user_email, admin, first_name, last_name, department, company FROM users ORDER BY user_name");
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("View users error: " . $e->getMessage());
    $users = [];
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div></div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add User
    </button>
</div>

<div class="card shadow">
    <div class="card-body">
        <table class="table table-striped table-hover datatable">
            <thead class="table-dark">
                <tr>
                    <th>Username</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Department</th>
                    <th>Role</th>
                    <th width="100">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['user_name']); ?></td>
                        <td><?php echo htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($user['user_email']); ?></td>
                        <td><?php echo htmlspecialchars($user['department'] ?? '-'); ?></td>
                        <td>
                            <?php if ($user['admin'] == 1): ?>
                                <span class="badge bg-danger">Administrator</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">User</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="edituser.php?id=<?php echo (int)$user['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </a>
                            <?php if ($user['user_email'] !== $_SESSION['email']): ?>
                                <a href="delete.php?id=<?php echo (int)$user['id']; ?>&token=<?php echo htmlspecialchars($csrf_token); ?>"
                                   class="btn btn-sm btn-outline-danger"
                                   title="Delete"
                                   onclick="return confirmDelete('Are you sure you want to delete this user?');">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addUserModalLabel">Add New Panel User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="view_users.php">
                <div class="modal-body">
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                    <?php endif; ?>

                    <?php echo csrf_field(); ?>

                    <h6 class="text-muted mb-3">Account Details <span class="text-danger">*</span></h6>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="name" class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name"
                                   placeholder="Enter username" required
                                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email"
                                   placeholder="Enter email address" required
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="pass" class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="pass" name="pass"
                                   placeholder="Enter password" required>
                            <div class="form-text">
                                Min <?php echo $config['password_min_length']; ?> chars, mixed case + number.
                            </div>
                        </div>
                        <div class="col-md-6 mb-3 d-flex align-items-center pt-4">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="isadmin" name="isadmin" value="1">
                                <label class="form-check-label" for="isadmin">Administrator Access</label>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h6 class="text-muted mb-3">Profile Details <small>(optional)</small></h6>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="first_name" class="form-label">First Name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name"
                                   value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name"
                                   value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="phone" name="phone"
                                   value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="employee_id" class="form-label">Employee ID</label>
                            <input type="text" class="form-control" id="employee_id" name="employee_id"
                                   value="<?php echo htmlspecialchars($_POST['employee_id'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="department" class="form-label">Department</label>
                            <input type="text" class="form-control" id="department" name="department"
                                   value="<?php echo htmlspecialchars($_POST['department'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="job_title" class="form-label">Job Title</label>
                            <input type="text" class="form-control" id="job_title" name="job_title"
                                   value="<?php echo htmlspecialchars($_POST['job_title'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="company" class="form-label">Company</label>
                            <input type="text" class="form-control" id="company" name="company"
                                   value="<?php echo htmlspecialchars($_POST['company'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="1"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($show_modal): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        new bootstrap.Modal(document.getElementById('addUserModal')).show();
    });
</script>
<?php endif; ?>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
