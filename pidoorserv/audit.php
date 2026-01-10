<?php
/**
 * Audit Log Viewer
 * PiDoors Access Control System
 */
$title = 'Audit Log';
require_once './includes/header.php';

require_login($config);
require_admin($config);

// Filter parameters
$filter_type = sanitize_string($_GET['type'] ?? '');
$filter_user = sanitize_string($_GET['user'] ?? '');
$filter_date_from = sanitize_string($_GET['date_from'] ?? '');
$filter_date_to = sanitize_string($_GET['date_to'] ?? '');

// Build query
$where = [];
$params = [];

if ($filter_type) {
    $where[] = "a.event_type = ?";
    $params[] = $filter_type;
}
if ($filter_user) {
    $where[] = "(u.user_name LIKE ? OR a.user_id = ?)";
    $params[] = "%$filter_user%";
    $params[] = $filter_user;
}
if ($filter_date_from) {
    $where[] = "DATE(a.created_at) >= ?";
    $params[] = $filter_date_from;
}
if ($filter_date_to) {
    $where[] = "DATE(a.created_at) <= ?";
    $params[] = $filter_date_to;
}

$where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";

try {
    // Get audit logs
    $stmt = $pdo->prepare("
        SELECT a.*, u.user_name
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        $where_clause
        ORDER BY a.created_at DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    // Get unique event types for filter
    $event_types = $pdo->query("SELECT DISTINCT event_type FROM audit_logs ORDER BY event_type")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Audit log error: " . $e->getMessage());
    $logs = [];
    $event_types = [];
}

// Event type labels and colors
$event_labels = [
    'login_success' => ['label' => 'Login Success', 'class' => 'bg-success'],
    'login_failed' => ['label' => 'Login Failed', 'class' => 'bg-danger'],
    'logout' => ['label' => 'Logout', 'class' => 'bg-secondary'],
    'password_change' => ['label' => 'Password Change', 'class' => 'bg-warning'],
    'user_created' => ['label' => 'User Created', 'class' => 'bg-info'],
    'user_deleted' => ['label' => 'User Deleted', 'class' => 'bg-danger'],
    'settings_change' => ['label' => 'Settings Changed', 'class' => 'bg-warning'],
    'card_created' => ['label' => 'Card Created', 'class' => 'bg-info'],
    'card_deleted' => ['label' => 'Card Deleted', 'class' => 'bg-danger'],
    'card_modified' => ['label' => 'Card Modified', 'class' => 'bg-warning'],
    'door_unlock' => ['label' => 'Manual Unlock', 'class' => 'bg-primary'],
    'backup_created' => ['label' => 'Backup Created', 'class' => 'bg-info'],
    'log_export' => ['label' => 'Log Export', 'class' => 'bg-secondary'],
];
?>

<!-- Filters -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" action="audit.php" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Event Type</label>
                <select name="type" class="form-select">
                    <option value="">All Events</option>
                    <?php foreach ($event_types as $type): ?>
                        <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $filter_type === $type ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($event_labels[$type]['label'] ?? ucfirst(str_replace('_', ' ', $type))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">User</label>
                <input type="text" name="user" class="form-control" placeholder="Name or ID" value="<?php echo htmlspecialchars($filter_user); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($filter_date_from); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($filter_date_to); ?>">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">Filter</button>
                <a href="audit.php" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Audit Log Table -->
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Audit Log</h5>
        <span class="text-muted"><?php echo count($logs); ?> records (max 500)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Date/Time</th>
                        <th>Event</th>
                        <th>User</th>
                        <th>IP Address</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $event_info = $event_labels[$log['event_type']] ?? ['label' => ucfirst(str_replace('_', ' ', $log['event_type'])), 'class' => 'bg-secondary'];
                        ?>
                        <tr>
                            <td><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                            <td>
                                <span class="badge <?php echo $event_info['class']; ?>">
                                    <?php echo htmlspecialchars($event_info['label']); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                if ($log['user_name']) {
                                    echo htmlspecialchars($log['user_name']);
                                } elseif ($log['user_id']) {
                                    echo "User #{$log['user_id']}";
                                } else {
                                    echo '<span class="text-muted">System</span>';
                                }
                                ?>
                            </td>
                            <td><code><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></code></td>
                            <td>
                                <?php if ($log['details']): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($log['details']); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="5" class="text-center text-muted">No audit logs found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
