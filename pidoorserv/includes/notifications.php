<?php
/**
 * Email Notification System
 * PiDoors Access Control System
 */

/**
 * Send an email notification
 *
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $body HTML email body
 * @param array $config Application config
 * @return bool Success status
 */
function send_notification($to, $subject, $body, $config) {
    // Check if notifications are enabled
    if (empty($to)) {
        return false;
    }

    $from = $config['notification_from'] ?? 'noreply@pidoors.local';
    $site_name = $config['site_name'] ?? 'PiDoors';

    // Build email headers
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        "From: {$site_name} <{$from}>",
        "Reply-To: {$from}",
        'X-Mailer: PiDoors Notification System',
    ];

    // Wrap body in HTML template
    $html_body = notification_template($subject, $body, $site_name);

    // Send email
    return mail($to, "[{$site_name}] {$subject}", $html_body, implode("\r\n", $headers));
}

/**
 * Email HTML template
 */
function notification_template($title, $content, $site_name) {
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: #0d6efd; color: white; padding: 20px; text-align: center;">
        <h1 style="margin: 0;">{$site_name}</h1>
        <p style="margin: 5px 0 0 0;">Access Control System</p>
    </div>
    <div style="background: #f8f9fa; padding: 20px; border: 1px solid #dee2e6;">
        <h2 style="color: #0d6efd; margin-top: 0;">{$title}</h2>
        {$content}
    </div>
    <div style="text-align: center; padding: 15px; font-size: 12px; color: #6c757d;">
        <p>This is an automated message from {$site_name}.</p>
        <p>Please do not reply to this email.</p>
    </div>
</body>
</html>
HTML;
}

/**
 * Send security alert notification
 */
function notify_security_alert($event_type, $details, $config, $pdo_access) {
    try {
        // Get notification email from settings
        $stmt = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'notification_email'");
        $result = $stmt->fetch();
        $notification_email = $result['setting_value'] ?? '';

        $stmt = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'email_notifications'");
        $result = $stmt->fetch();
        $notifications_enabled = ($result['setting_value'] ?? '0') === '1';

        if (!$notifications_enabled || empty($notification_email)) {
            return false;
        }

        $subject = "Security Alert: " . ucfirst(str_replace('_', ' ', $event_type));

        $body = "<p><strong>Event Type:</strong> " . htmlspecialchars($event_type) . "</p>";
        $body .= "<p><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>";
        $body .= "<p><strong>Details:</strong></p>";
        $body .= "<div style='background: white; padding: 10px; border-left: 4px solid #dc3545;'>";
        $body .= nl2br(htmlspecialchars($details));
        $body .= "</div>";
        $body .= "<p style='margin-top: 20px;'><a href='" . htmlspecialchars($config['url']) . "/audit.php' style='background: #0d6efd; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>View Audit Log</a></p>";

        return send_notification($notification_email, $subject, $body, $config);
    } catch (Exception $e) {
        error_log("Notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send door status alert
 */
function notify_door_status($door_name, $status, $config, $pdo_access) {
    try {
        $stmt = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'notification_email'");
        $result = $stmt->fetch();
        $notification_email = $result['setting_value'] ?? '';

        $stmt = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'email_notifications'");
        $result = $stmt->fetch();
        $notifications_enabled = ($result['setting_value'] ?? '0') === '1';

        if (!$notifications_enabled || empty($notification_email)) {
            return false;
        }

        $subject = "Door Alert: " . htmlspecialchars($door_name) . " is " . ucfirst($status);

        $status_color = $status === 'offline' ? '#dc3545' : '#28a745';

        $body = "<p>The door controller status has changed:</p>";
        $body .= "<div style='background: white; padding: 15px; border-left: 4px solid {$status_color};'>";
        $body .= "<p><strong>Door:</strong> " . htmlspecialchars($door_name) . "</p>";
        $body .= "<p><strong>Status:</strong> <span style='color: {$status_color}; font-weight: bold;'>" . ucfirst($status) . "</span></p>";
        $body .= "<p><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>";
        $body .= "</div>";
        $body .= "<p style='margin-top: 20px;'><a href='" . htmlspecialchars($config['url']) . "/doors.php' style='background: #0d6efd; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>View Doors</a></p>";

        return send_notification($notification_email, $subject, $body, $config);
    } catch (Exception $e) {
        error_log("Door notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send access denied alert for repeated failures
 */
function notify_access_denied($card_id, $door_name, $attempt_count, $config, $pdo_access) {
    try {
        $stmt = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'notification_email'");
        $result = $stmt->fetch();
        $notification_email = $result['setting_value'] ?? '';

        $stmt = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'email_notifications'");
        $result = $stmt->fetch();
        $notifications_enabled = ($result['setting_value'] ?? '0') === '1';

        if (!$notifications_enabled || empty($notification_email)) {
            return false;
        }

        $subject = "Access Alert: Repeated Denied Attempts";

        $body = "<p style='color: #dc3545; font-weight: bold;'>Multiple access denied events detected!</p>";
        $body .= "<div style='background: white; padding: 15px; border-left: 4px solid #dc3545;'>";
        $body .= "<p><strong>Card ID:</strong> " . htmlspecialchars($card_id) . "</p>";
        $body .= "<p><strong>Door:</strong> " . htmlspecialchars($door_name) . "</p>";
        $body .= "<p><strong>Attempts:</strong> {$attempt_count} in the last hour</p>";
        $body .= "<p><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>";
        $body .= "</div>";
        $body .= "<p>This may indicate a lost or stolen card, or an attempted breach.</p>";
        $body .= "<p style='margin-top: 20px;'><a href='" . htmlspecialchars($config['url']) . "/logs.php' style='background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>View Access Logs</a></p>";

        return send_notification($notification_email, $subject, $body, $config);
    } catch (Exception $e) {
        error_log("Access denied notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send daily summary report
 */
function send_daily_summary($config, $pdo_access) {
    try {
        $stmt = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'notification_email'");
        $result = $stmt->fetch();
        $notification_email = $result['setting_value'] ?? '';

        $stmt = $pdo_access->query("SELECT setting_value FROM settings WHERE setting_key = 'email_notifications'");
        $result = $stmt->fetch();
        $notifications_enabled = ($result['setting_value'] ?? '0') === '1';

        if (!$notifications_enabled || empty($notification_email)) {
            return false;
        }

        // Get yesterday's date
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        // Get statistics
        $stmt = $pdo_access->prepare("SELECT COUNT(*) as total, SUM(Granted = 1) as granted, SUM(Granted = 0) as denied FROM logs WHERE DATE(Date) = ?");
        $stmt->execute([$yesterday]);
        $stats = $stmt->fetch();

        // Get door status
        $doors = $pdo_access->query("SELECT name, status, last_seen FROM doors")->fetchAll();
        $online_count = 0;
        $offline_count = 0;
        foreach ($doors as $door) {
            if ($door['status'] === 'online') $online_count++;
            else $offline_count++;
        }

        $subject = "Daily Summary Report - " . $yesterday;

        $body = "<h3>Access Summary for {$yesterday}</h3>";
        $body .= "<table style='width: 100%; border-collapse: collapse;'>";
        $body .= "<tr><td style='padding: 10px; border: 1px solid #dee2e6; background: #e7f5ff;'><strong>Total Access Events</strong></td><td style='padding: 10px; border: 1px solid #dee2e6; text-align: right;'>{$stats['total']}</td></tr>";
        $body .= "<tr><td style='padding: 10px; border: 1px solid #dee2e6; background: #d3f9d8;'><strong>Access Granted</strong></td><td style='padding: 10px; border: 1px solid #dee2e6; text-align: right;'>{$stats['granted']}</td></tr>";
        $body .= "<tr><td style='padding: 10px; border: 1px solid #dee2e6; background: #ffe3e3;'><strong>Access Denied</strong></td><td style='padding: 10px; border: 1px solid #dee2e6; text-align: right;'>{$stats['denied']}</td></tr>";
        $body .= "</table>";

        $body .= "<h3 style='margin-top: 20px;'>Door Controller Status</h3>";
        $body .= "<table style='width: 100%; border-collapse: collapse;'>";
        $body .= "<tr><td style='padding: 10px; border: 1px solid #dee2e6; background: #d3f9d8;'><strong>Online</strong></td><td style='padding: 10px; border: 1px solid #dee2e6; text-align: right;'>{$online_count}</td></tr>";
        $body .= "<tr><td style='padding: 10px; border: 1px solid #dee2e6; background: #ffe3e3;'><strong>Offline</strong></td><td style='padding: 10px; border: 1px solid #dee2e6; text-align: right;'>{$offline_count}</td></tr>";
        $body .= "</table>";

        if ($offline_count > 0) {
            $body .= "<h4 style='color: #dc3545;'>Offline Doors:</h4>";
            $body .= "<ul>";
            foreach ($doors as $door) {
                if ($door['status'] !== 'online') {
                    $body .= "<li>" . htmlspecialchars($door['name']);
                    if ($door['last_seen']) {
                        $body .= " (last seen: " . date('Y-m-d H:i:s', strtotime($door['last_seen'])) . ")";
                    }
                    $body .= "</li>";
                }
            }
            $body .= "</ul>";
        }

        $body .= "<p style='margin-top: 20px;'><a href='" . htmlspecialchars($config['url']) . "/reports.php' style='background: #0d6efd; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>View Full Reports</a></p>";

        return send_notification($notification_email, $subject, $body, $config);
    } catch (Exception $e) {
        error_log("Daily summary error: " . $e->getMessage());
        return false;
    }
}
