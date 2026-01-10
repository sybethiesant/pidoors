<?php
/**
 * Delete User Handler
 * PiDoors Access Control System
 */
$config = include(__DIR__ . '/../includes/config.php');
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../database/db_connection.php';

// Start secure session
secure_session_start($config);

// Require admin access
require_admin($config);

// Verify CSRF token
$token = $_GET['token'] ?? '';
if (!verify_csrf_token($token)) {
    header("Location: {$config['url']}/users/view_users.php?error=Invalid security token.");
    exit();
}

// Get and validate user ID
$delete_id = validate_int($_GET['id'] ?? 0, 1);
if (!$delete_id) {
    header("Location: {$config['url']}/users/view_users.php?error=Invalid user ID.");
    exit();
}

try {
    // Get user info to check if trying to delete self
    $stmt = $pdo->prepare("SELECT user_email FROM users WHERE id = ?");
    $stmt->execute([$delete_id]);
    $user = $stmt->fetch();

    if (!$user) {
        header("Location: {$config['url']}/users/view_users.php?error=User not found.");
        exit();
    }

    // Prevent self-deletion
    if ($user['user_email'] === $_SESSION['email']) {
        header("Location: {$config['url']}/users/view_users.php?error=You cannot delete your own account.");
        exit();
    }

    // Delete the user
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$delete_id]);

    // Log the action
    try {
        log_security_event($pdo, 'user_deleted', $_SESSION['user_id'],
            "Deleted user: {$user['user_email']}");
    } catch (Exception $e) {
        // Ignore if audit log doesn't exist
    }

    header("Location: {$config['url']}/users/view_users.php?success=User has been deleted successfully.");
    exit();

} catch (PDOException $e) {
    error_log("Delete user error: " . $e->getMessage());
    header("Location: {$config['url']}/users/view_users.php?error=Failed to delete user. Please try again.");
    exit();
}
