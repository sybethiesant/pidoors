<?php
/**
 * Export Report to CSV
 * PiDoors Access Control System
 */
require_once './includes/header.php';

require_login($config);

// Report parameters
$report_type = sanitize_string($_GET['type'] ?? 'daily');
$date_from = sanitize_string($_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days')));
$date_to = sanitize_string($_GET['date_to'] ?? date('Y-m-d'));
$door_filter = sanitize_string($_GET['door'] ?? '');

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) $date_to = date('Y-m-d');

try {
    switch ($report_type) {
        case 'daily':
            $stmt = $pdo_access->prepare("
                SELECT DATE(Date) as date,
                       COUNT(*) as total,
                       SUM(Granted = 1) as granted,
                       SUM(Granted = 0) as denied
                FROM logs
                WHERE DATE(Date) BETWEEN ? AND ?
                " . ($door_filter ? "AND Location = ?" : "") . "
                GROUP BY DATE(Date)
                ORDER BY date
            ");
            $params = [$date_from, $date_to];
            if ($door_filter) $params[] = $door_filter;
            $stmt->execute($params);
            $data = $stmt->fetchAll();
            $headers = ['Date', 'Total', 'Granted', 'Denied', 'Success Rate'];
            $rows = array_map(function($row) {
                return [
                    $row['date'],
                    $row['total'],
                    $row['granted'],
                    $row['denied'],
                    $row['total'] > 0 ? round(($row['granted'] / $row['total']) * 100, 1) . '%' : '0%'
                ];
            }, $data);
            break;

        case 'hourly':
            $stmt = $pdo_access->prepare("
                SELECT HOUR(Date) as hour,
                       COUNT(*) as total,
                       SUM(Granted = 1) as granted
                FROM logs
                WHERE DATE(Date) BETWEEN ? AND ?
                " . ($door_filter ? "AND Location = ?" : "") . "
                GROUP BY HOUR(Date)
                ORDER BY hour
            ");
            $params = [$date_from, $date_to];
            if ($door_filter) $params[] = $door_filter;
            $stmt->execute($params);
            $data = $stmt->fetchAll();
            $headers = ['Hour', 'Total', 'Granted'];
            $rows = array_map(function($row) {
                return [
                    sprintf('%02d:00 - %02d:59', $row['hour'], $row['hour']),
                    $row['total'],
                    $row['granted']
                ];
            }, $data);
            break;

        case 'door':
            $stmt = $pdo_access->prepare("
                SELECT Location as door,
                       COUNT(*) as total,
                       SUM(Granted = 1) as granted,
                       SUM(Granted = 0) as denied
                FROM logs
                WHERE DATE(Date) BETWEEN ? AND ?
                GROUP BY Location
                ORDER BY total DESC
            ");
            $stmt->execute([$date_from, $date_to]);
            $data = $stmt->fetchAll();
            $headers = ['Door', 'Total', 'Granted', 'Denied', 'Success Rate'];
            $rows = array_map(function($row) {
                return [
                    $row['door'],
                    $row['total'],
                    $row['granted'],
                    $row['denied'],
                    $row['total'] > 0 ? round(($row['granted'] / $row['total']) * 100, 1) . '%' : '0%'
                ];
            }, $data);
            break;

        case 'user':
            $stmt = $pdo_access->prepare("
                SELECT l.user_id, c.firstname, c.lastname,
                       COUNT(*) as total,
                       SUM(l.Granted = 1) as granted,
                       SUM(l.Granted = 0) as denied
                FROM logs l
                LEFT JOIN cards c ON l.user_id = c.user_id
                WHERE DATE(l.Date) BETWEEN ? AND ?
                " . ($door_filter ? "AND l.Location = ?" : "") . "
                GROUP BY l.user_id, c.firstname, c.lastname
                ORDER BY total DESC
            ");
            $params = [$date_from, $date_to];
            if ($door_filter) $params[] = $door_filter;
            $stmt->execute($params);
            $data = $stmt->fetchAll();
            $headers = ['User Name', 'User ID', 'Total', 'Granted', 'Denied'];
            $rows = array_map(function($row) {
                $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
                return [
                    $name ?: 'Unknown',
                    $row['user_id'],
                    $row['total'],
                    $row['granted'],
                    $row['denied']
                ];
            }, $data);
            break;

        case 'denied':
            $stmt = $pdo_access->prepare("
                SELECT l.user_id, c.firstname, c.lastname, l.Location,
                       COUNT(*) as attempts,
                       MAX(l.Date) as last_attempt
                FROM logs l
                LEFT JOIN cards c ON l.user_id = c.user_id
                WHERE l.Granted = 0
                AND DATE(l.Date) BETWEEN ? AND ?
                " . ($door_filter ? "AND l.Location = ?" : "") . "
                GROUP BY l.user_id, c.firstname, c.lastname, l.Location
                ORDER BY attempts DESC
            ");
            $params = [$date_from, $date_to];
            if ($door_filter) $params[] = $door_filter;
            $stmt->execute($params);
            $data = $stmt->fetchAll();
            $headers = ['User Name', 'User ID', 'Door', 'Attempts', 'Last Attempt'];
            $rows = array_map(function($row) {
                $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
                return [
                    $name ?: 'Unknown',
                    $row['user_id'],
                    $row['Location'],
                    $row['attempts'],
                    $row['last_attempt']
                ];
            }, $data);
            break;

        default:
            header("Location: {$config['url']}/reports.php?error=Invalid report type.");
            exit();
    }

    // Set headers for CSV download
    $filename = "report_{$report_type}_{$date_from}_to_{$date_to}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Open output stream
    $output = fopen('php://output', 'w');

    // Add BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Add report metadata
    fputcsv($output, ['Report Type: ' . ucfirst($report_type)]);
    fputcsv($output, ['Date Range: ' . $date_from . ' to ' . $date_to]);
    if ($door_filter) {
        fputcsv($output, ['Door Filter: ' . $door_filter]);
    }
    fputcsv($output, ['Generated: ' . date('Y-m-d H:i:s')]);
    fputcsv($output, []); // Empty row

    // Write header row
    fputcsv($output, $headers);

    // Write data rows
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }

    fclose($output);

    // Log the export
    if (isset($pdo)) {
        log_security_event($pdo, 'report_export', $_SESSION['user_id'] ?? null, "Exported {$report_type} report");
    }

} catch (PDOException $e) {
    error_log("Export report error: " . $e->getMessage());
    header("Location: {$config['url']}/reports.php?error=Export failed.");
    exit();
}
