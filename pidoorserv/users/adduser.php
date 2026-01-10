<?php
/**
 * Add Panel User
 * PiDoors Access Control System
 */
$title = 'Add Panel User';
require_once __DIR__ . '/../includes/header.php';

// Require admin access
require_admin($config);

$error_message = '';
$success_message = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token. Please try again.';
    } else {
        $user_name = sanitize_string($_POST['name'] ?? '');
        $user_email = sanitize_email($_POST['email'] ?? '');
        $password = $_POST['pass'] ?? '';
        $user_isadmin = isset($_POST['isadmin']) ? 1 : 0;

        // Validate inputs
        if (empty($user_name)) {
            $error_message = 'Please enter a username.';
        } elseif (empty($user_email) || !validate_email($user_email)) {
            $error_message = 'Please enter a valid email address.';
        } elseif (empty($password)) {
            $error_message = 'Please enter a password.';
        } else {
            // Validate password strength
            $password_errors = validate_password($password, $config);
            if (!empty($password_errors)) {
                $error_message = implode(' ', $password_errors);
            } else {
                try {
                    // Check if email already exists
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_email = ?");
                    $stmt->execute([$user_email]);

                    if ($stmt->fetch()) {
                        $error_message = 'This email address is already registered.';
                    } else {
                        // Hash password securely
                        $password_hash = hash_password($password);

                        // Insert new user
                        $stmt = $pdo->prepare("INSERT INTO users (user_name, user_pass, user_email, admin) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$user_name, $password_hash, $user_email, $user_isadmin]);

                        // Log the action
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
}
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Add New Panel User</h5>
                </div>
                <div class="card-body">
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="adduser.php">
                        <?php echo csrf_field(); ?>

                        <div class="mb-3">
                            <label for="name" class="form-label">Username</label>
                            <input type="text" class="form-control" id="name" name="name"
                                   placeholder="Enter username" required
                                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   placeholder="Enter email address" required
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label for="pass" class="form-label">Password</label>
                            <input type="password" class="form-control" id="pass" name="pass"
                                   placeholder="Enter password" required>
                            <div class="form-text">
                                Password must be at least <?php echo $config['password_min_length']; ?> characters,
                                include uppercase and lowercase letters, and contain at least one number.
                            </div>
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="isadmin" name="isadmin" value="1">
                            <label class="form-check-label" for="isadmin">Administrator Access</label>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="view_users.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Add User</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
