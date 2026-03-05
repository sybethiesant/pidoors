<?php
/**
 * Export Access Logs to CSV
 * PiDoors Access Control System
 */
require_once './includes/header.php';

require_login($config);

// Filter parameters (same as logs.php)
$filter_door = sanitize_string($_GET['door'] ?? '');
$filter_status = sanitize_string($_GET['status'] ?? '');
$filter_date_from = sanitize_string($_GET['date_from'] ?? '');
$filter_date_to = sanitize_string($_GET['date_to'] ?? '');
$filter_user = sanitize_string($_GET['user'] ?? '');

// Build query
$where = [];
$params = [];

if ($filter_door) {
    $where[] = "l.Location = ?";
    $params[] = $filter_door;
}
if ($filter_status === '1') {
    $where[] = "l.Granted = 1";
} elseif ($filter_status === '0') {
    $where[] = "l.Granted = 0";
}
if ($filter_date_from) {
    $where[] = "DATE(l.Date) >= ?";
    $params[] = $filter_date_from;
}
if ($filter_date_to) {
    $where[] = "DATE(l.Date) <= ?";
    $params[] = $filter_date_to;
}
if ($filter_user) {
    $where[] = "(c.firstname LIKE ? OR c.lastname LIKE ? OR l.user_id LIKE ?)";
    $search = "%$filter_user%";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";

try {
    // Get logs (increased limit for export)
    $stmt = $pdo_access->prepare("
        SELECT l.*, c.firstname, c.lastname, c.card_id
        FROM logs l
        LEFT JOIN cards c ON l.user_id = c.user_id
        $where_clause
        ORDER BY l.Date DESC
        LIMIT 10000
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    // Set headers for CSV download
    $filename = 'access_logs_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Open output stream
    $output = fopen('php://output', 'w');

    // Add BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Write header row
    fputcsv($output, [
        'Date/Time',
        'User Name',
        'Card ID',
        'User ID',
        'Location',
        'Door IP',
        'Status'
    ]);

    // Write data rows
    foreach ($logs as $log) {
        $name = trim(($log['firstname'] ?? '') . ' ' . ($log['lastname'] ?? ''));
        if (empty($name)) {
            $name = "User #{$log['user_id']}";
        }

        fputcsv($output, [
            date('Y-m-d H:i:s', strtotime($log['Date'])),
            $name,
            $log['card_id'] ?? '',
            $log['user_id'],
            $log['Location'],
            $log['doorip'] ?? '',
            $log['Granted'] == 1 ? 'Granted' : 'Denied'
        ]);
    }

    fclose($output);

    // Log the export action
    if (isset($pdo)) {
        log_security_event($pdo, 'log_export', $_SESSION['user_id'] ?? null, "Exported " . count($logs) . " access log records");
    }

} catch (PDOException $e) {
    error_log("Export logs error: " . $e->getMessage());
    header("Location: {$config['url']}/logs.php?error=Export failed.");
    exit();
}
