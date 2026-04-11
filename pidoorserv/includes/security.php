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
 * Verify CSRF token (supports form field, JSON body, or X-CSRF-Token header)
 */
function verify_csrf_token($token = null) {
    // If no token passed, check X-CSRF-Token header
    if ($token === null || $token === '') {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }
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
 * Regenerate session ID for security
 */
function secure_session_start($config) {
    if (session_status() === PHP_SESSION_NONE) {
        // Resolve idle timeout BEFORE starting the session so we can configure
        // PHP's session GC and cookie lifetime to match. Otherwise PHP/Debian's
        // session cleanup cron deletes session files at 24 minutes regardless
        // of our app-level setting, logging users out unexpectedly.
        $idle_timeout = (int)($config['session_timeout'] ?? 3600);
        try {
            global $pdo_access;
            if (isset($pdo_access)) {
                $t_stmt = $pdo_access->prepare("SELECT setting_value FROM settings WHERE setting_key = 'session_timeout'");
                $t_stmt->execute();
                $t_val = $t_stmt->fetchColumn();
                if ($t_val !== false) $idle_timeout = (int)$t_val;
            }
        } catch (\Exception $e) { /* use config default */ }

        // Configure PHP session GC. 0 = "unlimited" → use a very large value
        // (1 year) so the GC cron never sweeps the session file.
        $gc_lifetime = ($idle_timeout > 0) ? max($idle_timeout, 1440) : 31536000;
        @ini_set('session.gc_maxlifetime', (string)$gc_lifetime);
        @ini_set('session.cookie_lifetime', '0'); // session cookie until browser closes

        // Use a custom save path so Debian's phpsessionclean cron (which reads
        // session.gc_maxlifetime from the CLI config, not from our ini_set)
        // doesn't sweep our session files prematurely. We manage GC ourselves
        // via the app-level idle timeout check below.
        $custom_save_path = '/var/lib/php/pidoors-sessions';
        if (!is_dir($custom_save_path)) {
            @mkdir($custom_save_path, 0700, true);
            @chown($custom_save_path, 'www-data');
            @chgrp($custom_save_path, 'www-data');
        }
        if (is_writable($custom_save_path)) {
            session_save_path($custom_save_path);
        }

        session_name($config['session_name']);
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly'  => true,
            'samesite'  => 'Lax',
        ]);
        session_start();

        // Regenerate session ID periodically
        if (!isset($_SESSION['created'])) {
            $_SESSION['created'] = time();
        } else if (time() - $_SESSION['created'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        }

        // App-level idle timeout check (0 = unlimited)
        if ($idle_timeout > 0 && isset($_SESSION['last_activity']) &&
            (time() - $_SESSION['last_activity'] > $idle_timeout)) {
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
