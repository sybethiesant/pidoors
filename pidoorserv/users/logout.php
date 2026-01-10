<?php
/**
 * Logout Handler
 * PiDoors Access Control System
 */
$config = include(__DIR__ . '/../includes/config.php');
require_once __DIR__ . '/../includes/security.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_name($config['session_name']);
    session_start();
}

// Log logout event
if (isset($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/../database/db_connection.php';
        log_security_event($pdo, 'logout', $_SESSION['user_id'], 'User logged out');
    } catch (Exception $e) {
        // Ignore if audit log doesn't exist
    }
}

// Clear all session data
$_SESSION = array();

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect to login page
header("Location: {$config['url']}/users/login.php?success=You have been logged out successfully.");
exit();
