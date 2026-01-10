<?php
/**
 * Access Logs
 * PiDoors Access Control System
 */
$title = 'Access Logs';
require_once './includes/header.php';

require_login($config);

// Filter parameters
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
    // Get logs
    $stmt = $pdo_access->prepare("
        SELECT l.*, c.firstname, c.lastname, c.card_id
        FROM logs l
        LEFT JOIN cards c ON l.user_id = c.user_id
        $where_clause
        ORDER BY l.Date DESC
        LIMIT 1000
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    // Get doors for filter
    $doors = $pdo_access->query("SELECT DISTINCT name FROM doors ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Logs error: " . $e->getMessage());
    $logs = [];
    $doors = [];
}
?>

<!-- Filters -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" action="logs.php" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Door</label>
                <select name="door" class="form-select">
                    <option value="">All Doors</option>
                    <?php foreach ($doors as $door): ?>
                        <option value="<?php echo htmlspecialchars($door); ?>" <?php echo $filter_door === $door ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($door); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <option value="1" <?php echo $filter_status === '1' ? 'selected' : ''; ?>>Granted</option>
                    <option value="0" <?php echo $filter_status === '0' ? 'selected' : ''; ?>>Denied</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($filter_date_from); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($filter_date_to); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Search User</label>
                <input type="text" name="user" class="form-control" placeholder="Name or ID" value="<?php echo htmlspecialchars($filter_user); ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">Filter</button>
                <a href="logs.php" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Export Button -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <span class="text-muted"><?php echo count($logs); ?> records (max 1000)</span>
    <a href="export_logs.php?<?php echo http_build_query($_GET); ?>" class="btn btn-outline-success">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
        Export CSV
    </a>
</div>

<!-- Logs Table -->
<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0 datatable">
                <thead class="table-dark">
                    <tr>
                        <th>Date/Time</th>
                        <th>User</th>
                        <th>Card ID</th>
                        <th>Location</th>
                        <th>Door IP</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo date('Y-m-d H:i:s', strtotime($log['Date'])); ?></td>
                            <td>
                                <?php
                                $name = trim(($log['firstname'] ?? '') . ' ' . ($log['lastname'] ?? ''));
                                echo htmlspecialchars($name ?: "User #{$log['user_id']}");
                                ?>
                            </td>
                            <td><code><?php echo htmlspecialchars($log['user_id']); ?></code></td>
                            <td><?php echo htmlspecialchars($log['Location']); ?></td>
                            <td><?php echo htmlspecialchars($log['doorip'] ?? 'N/A'); ?></td>
                            <td>
                                <?php if ($log['Granted'] == 1): ?>
                                    <span class="badge bg-success">Granted</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Denied</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No access logs found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
