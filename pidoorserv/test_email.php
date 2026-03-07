<?php
/**
 * Test Email Endpoint (AJAX)
 * PiDoors Access Control System
 *
 * POST-only endpoint for the "Send Test Email" button in Settings.
 * Reads SMTP settings from POST so the user can test before saving.
 */
$title = 'Test Email';
require_once './includes/header.php';
ob_end_clean();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

if (!is_logged_in() || !is_admin()) {
    echo json_encode(['success' => false, 'message' => 'Admin login required']);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

require_once __DIR__ . '/includes/smtp.php';

$to = filter_var($_POST['notification_email'] ?? '', FILTER_VALIDATE_EMAIL);
if (!$to) {
    echo json_encode(['success' => false, 'message' => 'Valid notification email required']);
    exit;
}

$smtp_settings = [
    'host' => trim($_POST['smtp_host'] ?? ''),
    'port' => (int)($_POST['smtp_port'] ?? 587),
    'user' => trim($_POST['smtp_user'] ?? ''),
    'pass' => $_POST['smtp_pass'] ?? '',
    'from' => trim($_POST['smtp_from'] ?? ''),
];

// If password field is empty, try to load from DB
if (empty($smtp_settings['pass'])) {
    try {
        $stmt = $pdo_access->prepare("SELECT setting_value FROM settings WHERE setting_key = 'smtp_pass'");
        $stmt->execute();
        $row = $stmt->fetch();
        $smtp_settings['pass'] = $row['setting_value'] ?? '';
    } catch (Exception $e) {
        // ignore
    }
}

if (empty($smtp_settings['host'])) {
    echo json_encode(['success' => false, 'message' => 'SMTP server is required']);
    exit;
}

$site_name = trim($_POST['site_name'] ?? 'PiDoors');
$subject = "[{$site_name}] Test Email";
$body = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;padding:20px;'>";
$body .= "<h2 style='color:#0d6efd;'>PiDoors Test Email</h2>";
$body .= "<p>This is a test email from your PiDoors notification system.</p>";
$body .= "<p>If you received this, your SMTP settings are configured correctly.</p>";
$body .= "<p style='color:#6c757d;font-size:12px;'>Sent at " . date('Y-m-d H:i:s') . "</p>";
$body .= "</body></html>";

$result = smtp_send($to, $subject, $body, $smtp_settings);

if ($result === true) {
    echo json_encode(['success' => true, 'message' => 'Test email sent successfully']);
} else {
    echo json_encode(['success' => false, 'message' => $result]);
}
