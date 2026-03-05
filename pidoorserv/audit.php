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
    'user_modified' => ['label' => 'User Modified', 'class' => 'bg-warning'],
    'profile_update' => ['label' => 'Profile Updated', 'class' => 'bg-info'],
    'door_unlock' => ['label' => 'Manual Unlock', 'class' => 'bg-primary'],
    'backup_created' => ['label' => 'Backup Created', 'class' => 'bg-info'],
    'log_export' => ['label' => 'Log Export', 'class' => 'bg-secondary'],
    'cards_export' => ['label' => 'Cards Export', 'class' => 'bg-secondary'],
    'cards_imported' => ['label' => 'Cards Imported', 'class' => 'bg-info'],
    'report_export' => ['label' => 'Report Export', 'class' => 'bg-secondary'],
    'server_update' => ['label' => 'Server Update', 'class' => 'bg-success'],
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
                        $user_display = $log['user_name'] ? $log['user_name'] : ($log['user_id'] ? "User #{$log['user_id']}" : 'System');
                        ?>
                        <tr style="cursor:pointer" onclick="showAuditDetail(this)"
                            data-event-type="<?php echo htmlspecialchars($log['event_type']); ?>"
                            data-event-label="<?php echo htmlspecialchars($event_info['label']); ?>"
                            data-event-class="<?php echo htmlspecialchars($event_info['class']); ?>"
                            data-timestamp="<?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?>"
                            data-user="<?php echo htmlspecialchars($user_display); ?>"
                            data-ip="<?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?>"
                            data-user-agent="<?php echo htmlspecialchars($log['user_agent'] ?? 'N/A'); ?>"
                            data-details="<?php echo htmlspecialchars($log['details'] ?? ''); ?>">
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

<!-- Audit Detail Modal -->
<div class="modal fade" id="auditDetailModal" tabindex="-1" aria-labelledby="auditDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="auditDetailModalLabel"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Event</dt>
                    <dd class="col-sm-8" id="modal-event"></dd>
                    <dt class="col-sm-4">Date/Time</dt>
                    <dd class="col-sm-8" id="modal-timestamp"></dd>
                    <dt class="col-sm-4">User</dt>
                    <dd class="col-sm-8" id="modal-user"></dd>
                    <dt class="col-sm-4">IP Address</dt>
                    <dd class="col-sm-8" id="modal-ip"></dd>
                    <dt class="col-sm-4">User Agent</dt>
                    <dd class="col-sm-8" id="modal-user-agent"></dd>
                    <dt class="col-sm-4">Details</dt>
                    <dd class="col-sm-8"><pre class="mb-0" style="white-space:pre-wrap;word-break:break-word;font-size:.875rem" id="modal-details"></pre></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<script>
function showAuditDetail(row) {
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    document.getElementById('auditDetailModalLabel').innerHTML =
        '<span class="badge ' + esc(row.dataset.eventClass) + ' me-2">' + esc(row.dataset.eventLabel) + '</span>' +
        esc(row.dataset.timestamp);
    document.getElementById('modal-event').innerHTML =
        '<span class="badge ' + esc(row.dataset.eventClass) + '">' + esc(row.dataset.eventLabel) + '</span>';
    document.getElementById('modal-timestamp').textContent = row.dataset.timestamp;
    document.getElementById('modal-user').textContent = row.dataset.user;
    document.getElementById('modal-ip').innerHTML = '<code>' + esc(row.dataset.ip) + '</code>';
    document.getElementById('modal-user-agent').textContent = row.dataset.userAgent;
    document.getElementById('modal-details').textContent = row.dataset.details || '\u2014';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('auditDetailModal')).show();
}
</script>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
