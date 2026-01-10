<?php
/**
 * Login Page
 * PiDoors Access Control System
 */
$title = 'Login';
require_once __DIR__ . '/../includes/header.php';

// Redirect if already logged in
if (is_logged_in()) {
    header("Location: {$config['url']}/index.php");
    exit();
}

$error_message = '';

// Initialize login attempts tracking
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_lockout_time'] = 0;
}

// Check if account is locked out
if ($_SESSION['login_lockout_time'] > time()) {
    $remaining = ceil(($_SESSION['login_lockout_time'] - time()) / 60);
    $error_message = "Too many failed login attempts. Please try again in {$remaining} minute(s).";
}

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error_message)) {
    // Verify CSRF token
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token. Please try again.';
    } else {
        $email = sanitize_email($_POST['email'] ?? '');
        $password = $_POST['pass'] ?? '';

        if (empty($email) || empty($password)) {
            $error_message = 'Please enter both email and password.';
        } elseif (!validate_email($email)) {
            $error_message = 'Please enter a valid email address.';
        } else {
            try {
                // Use prepared statement to prevent SQL injection
                $stmt = $pdo->prepare("SELECT id, user_name, user_email, user_pass, admin FROM users WHERE user_email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user) {
                    $password_valid = false;
                    $needs_upgrade = false;

                    // Check if this is a legacy MD5 hash (32 hex chars)
                    if (strlen($user['user_pass']) === 32 && ctype_xdigit($user['user_pass'])) {
                        // Legacy MD5 verification (with salt)
                        $legacy_hash = md5('pid00rsmd5saltedsalter' . $password);
                        if (hash_equals($user['user_pass'], $legacy_hash)) {
                            $password_valid = true;
                            $needs_upgrade = true;
                        }
                    } else {
                        // Modern bcrypt verification
                        $password_valid = password_verify($password, $user['user_pass']);
                    }

                    if ($password_valid) {
                        // Reset login attempts on successful login
                        $_SESSION['login_attempts'] = 0;
                        $_SESSION['login_lockout_time'] = 0;

                        // Upgrade password hash if using legacy MD5
                        if ($needs_upgrade) {
                            $new_hash = hash_password($password);
                            $update_stmt = $pdo->prepare("UPDATE users SET user_pass = ? WHERE id = ?");
                            $update_stmt->execute([$new_hash, $user['id']]);
                        }

                        // Regenerate session ID to prevent session fixation
                        session_regenerate_id(true);

                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['email'] = $user['user_email'];
                        $_SESSION['username'] = $user['user_name'];
                        $_SESSION['isadmin'] = ($user['admin'] == 1);
                        $_SESSION['login_time'] = time();

                        // Log successful login
                        try {
                            log_security_event($pdo, 'login_success', $user['id'], 'User logged in successfully');
                        } catch (Exception $e) {
                            // Audit log table might not exist yet, ignore
                        }

                        header("Location: {$config['url']}/index.php");
                        exit();
                    } else {
                        // Increment failed login attempts
                        $_SESSION['login_attempts']++;

                        // Lock out after max_failed_attempts for lockout_duration
                        $max_attempts = $config['max_failed_attempts'] ?? 5;
                        $lockout_seconds = $config['lockout_duration'] ?? 300;
                        if ($_SESSION['login_attempts'] >= $max_attempts) {
                            $_SESSION['login_lockout_time'] = time() + $lockout_seconds;
                            $lockout_minutes = ceil($lockout_seconds / 60);
                            $error_message = "Too many failed login attempts. Account locked for {$lockout_minutes} minute(s).";
                        } else {
                            $remaining_attempts = $max_attempts - $_SESSION['login_attempts'];
                            $error_message = 'Invalid email or password. ' . $remaining_attempts . ' attempt(s) remaining.';
                        }

                        // Log failed login attempt
                        try {
                            log_security_event($pdo, 'login_failed', null, "Failed login attempt for: $email");
                        } catch (Exception $e) {
                            // Audit log table might not exist yet, ignore
                        }
                    }
                } else {
                    // Increment failed login attempts for non-existent user
                    $_SESSION['login_attempts']++;
                    $max_attempts = $config['max_failed_attempts'] ?? 5;
                    $lockout_seconds = $config['lockout_duration'] ?? 300;

                    if ($_SESSION['login_attempts'] >= $max_attempts) {
                        $_SESSION['login_lockout_time'] = time() + $lockout_seconds;
                        $lockout_minutes = ceil($lockout_seconds / 60);
                        $error_message = "Too many failed login attempts. Account locked for {$lockout_minutes} minute(s).";
                    } else {
                        $error_message = 'Invalid email or password.';
                    }
                }
            } catch (PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                $error_message = 'A system error occurred. Please try again later.';
            }
        }
    }
}
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-4">
            <div class="card shadow mt-5">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">Sign In</h4>
                </div>
                <div class="card-body">
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="login.php">
                        <?php echo csrf_field(); ?>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   placeholder="Enter your email" required autofocus
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label for="pass" class="form-label">Password</label>
                            <input type="password" class="form-control" id="pass" name="pass"
                                   placeholder="Enter your password" required>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">Sign In</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
