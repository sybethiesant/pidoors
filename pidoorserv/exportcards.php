<?php
/**
 * Export Cards to CSV
 * PiDoors Access Control System
 */
require_once './includes/header.php';

require_login($config);

try {
    $stmt = $pdo_access->query("
        SELECT c.card_id, c.user_id, c.facility, c.firstname, c.lastname,
               c.email, c.phone, c.department, c.employee_id, c.company,
               c.title, c.notes, c.group_id, c.schedule_id,
               c.valid_from, c.valid_until, c.pin_code, c.daily_scan_limit,
               CASE WHEN mc.id IS NOT NULL THEN 1 ELSE 0 END AS master
        FROM cards c
        LEFT JOIN master_cards mc ON c.card_id = mc.card_id
        ORDER BY c.id
    ");
    $cards = $stmt->fetchAll();

    // Discard buffered HTML from header.php before sending CSV
    ob_end_clean();

    // Set headers for CSV download
    $filename = 'cards_export_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Open output stream
    $output = fopen('php://output', 'w');

    // Add BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Write header row (matches import format)
    fputcsv($output, [
        'card_id', 'user_id', 'facility', 'firstname', 'lastname',
        'email', 'phone', 'department', 'employee_id', 'company',
        'title', 'notes', 'group_id', 'schedule_id',
        'valid_from', 'valid_until', 'pin_code', 'daily_scan_limit', 'master'
    ]);

    // Write data rows
    foreach ($cards as $card) {
        fputcsv($output, [
            $card['card_id'],
            $card['user_id'],
            $card['facility'],
            $card['firstname'] ?? '',
            $card['lastname'] ?? '',
            $card['email'] ?? '',
            $card['phone'] ?? '',
            $card['department'] ?? '',
            $card['employee_id'] ?? '',
            $card['company'] ?? '',
            $card['title'] ?? '',
            $card['notes'] ?? '',
            $card['group_id'] ?? '',
            $card['schedule_id'] ?? '',
            $card['valid_from'] ?? '',
            $card['valid_until'] ?? '',
            $card['pin_code'] ?? '',
            $card['daily_scan_limit'] ?? '',
            $card['master']
        ]);
    }

    fclose($output);

    // Log the export action
    if (isset($pdo)) {
        log_security_event($pdo, 'cards_export', $_SESSION['user_id'] ?? null, "Exported " . count($cards) . " cards to CSV");
    }

} catch (PDOException $e) {
    error_log("Export cards error: " . $e->getMessage());
    header("Location: {$config['url']}/cards.php?error=Export failed.");
    exit();
}
