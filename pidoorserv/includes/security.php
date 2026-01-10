<?php
/**
 * Security Helper Functions
 * PiDoors Access Control System
 */

/**
 * Generate CSRF token
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verify_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Output CSRF hidden input field
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generate_csrf_token()) . '">';
}

/**
 * Check CSRF token from POST request
 */
function check_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            die('Invalid security token. Please refresh the page and try again.');
        }
    }
}

/**
 * Sanitize string input
 */
function sanitize_string($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize email input
 */
function sanitize_email($email) {
    return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
}

/**
 * Validate email format
 */
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate password strength
 */
function validate_password($password, $config) {
    $errors = [];

    if (strlen($password) < $config['password_min_length']) {
        $errors[] = "Password must be at least {$config['password_min_length']} characters long.";
    }

    if ($config['password_require_mixed_case']) {
        if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain both uppercase and lowercase letters.";
        }
    }

    if ($config['password_require_numbers']) {
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number.";
        }
    }

    // Return true if no errors, otherwise return error message string
    return empty($errors) ? true : implode(' ', $errors);
}

/**
 * Hash password securely using bcrypt
 */
function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify password against hash
 */
function verify_password($password, $hash) {
    // Support legacy MD5 hashes during migration
    if (strlen($hash) === 32 && ctype_xdigit($hash)) {
        return false; // Force password reset for MD5 hashes
    }
    return password_verify($password, $hash);
}

/**
 * Check if password needs rehashing (upgrade from MD5)
 */
function password_needs_upgrade($hash) {
    // MD5 hashes are 32 hex characters
    if (strlen($hash) === 32 && ctype_xdigit($hash)) {
        return true;
    }
    return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Regenerate session ID for security
 */
function secure_session_start($config) {
    if (session_status() === PHP_SESSION_NONE) {
        session_name($config['session_name']);
        session_start();

        // Regenerate session ID periodically
        if (!isset($_SESSION['created'])) {
            $_SESSION['created'] = time();
        } else if (time() - $_SESSION['created'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        }

        // Check session timeout
        if (isset($_SESSION['last_activity']) &&
            (time() - $_SESSION['last_activity'] > $config['session_timeout'])) {
            session_unset();
            session_destroy();
            session_start();
        }
        $_SESSION['last_activity'] = time();
    }
}

/**
 * Check if user is logged in
 */
function is_logged_in() {
    return isset($_SESSION['email']) && !empty($_SESSION['email']);
}

/**
 * Check if user is admin
 */
function is_admin() {
    return isset($_SESSION['isadmin']) && $_SESSION['isadmin'] === true;
}

/**
 * Require login - redirect if not authenticated
 */
function require_login($config) {
    if (!is_logged_in()) {
        header("Location: {$config['url']}/users/login.php");
        exit();
    }
}

/**
 * Require admin - redirect if not admin
 */
function require_admin($config) {
    require_login($config);
    if (!is_admin()) {
        header("Location: {$config['url']}/index.php?error=unauthorized");
        exit();
    }
}

/**
 * Validate integer input
 */
function validate_int($value, $min = null, $max = null) {
    $value = filter_var($value, FILTER_VALIDATE_INT);
    if ($value === false) {
        return false;
    }
    if ($min !== null && $value < $min) {
        return false;
    }
    if ($max !== null && $value > $max) {
        return false;
    }
    return $value;
}

/**
 * Log security event
 */
function log_security_event($pdo, $event_type, $user_id, $details) {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (event_type, user_id, ip_address, user_agent, details, created_at)
                               VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $event_type,
            $user_id,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            $details
        ]);
    } catch (PDOException $e) {
        error_log("Failed to log security event: " . $e->getMessage());
    }
}
