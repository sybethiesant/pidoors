<?php
/**
 * Reports
 * PiDoors Access Control System
 */
$title = 'Reports';
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

// Get doors for filter
try {
    $doors = $pdo_access->query("SELECT name FROM doors ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $doors = [];
}

// Generate report data based on type
$report_data = [];
$chart_data = [];

try {
    switch ($report_type) {
        case 'daily':
            // Daily access summary
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
            $report_data = $stmt->fetchAll();

            // Prepare chart data
            foreach ($report_data as $row) {
                $chart_data['labels'][] = $row['date'];
                $chart_data['granted'][] = (int)$row['granted'];
                $chart_data['denied'][] = (int)$row['denied'];
            }
            break;

        case 'hourly':
            // Hourly access patterns
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
            $report_data = $stmt->fetchAll();

            // Fill in missing hours
            $hourly = array_fill(0, 24, ['total' => 0, 'granted' => 0]);
            foreach ($report_data as $row) {
                $hourly[$row['hour']] = ['total' => $row['total'], 'granted' => $row['granted']];
            }
            for ($h = 0; $h < 24; $h++) {
                $chart_data['labels'][] = sprintf('%02d:00', $h);
                $chart_data['total'][] = (int)$hourly[$h]['total'];
            }
            break;

        case 'door':
            // Per-door statistics
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
            $report_data = $stmt->fetchAll();

            foreach ($report_data as $row) {
                $chart_data['labels'][] = $row['door'];
                $chart_data['total'][] = (int)$row['total'];
            }
            break;

        case 'user':
            // Top users by access count
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
                LIMIT 50
            ");
            $params = [$date_from, $date_to];
            if ($door_filter) $params[] = $door_filter;
            $stmt->execute($params);
            $report_data = $stmt->fetchAll();
            break;

        case 'denied':
            // Denied access analysis
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
                LIMIT 100
            ");
            $params = [$date_from, $date_to];
            if ($door_filter) $params[] = $door_filter;
            $stmt->execute($params);
            $report_data = $stmt->fetchAll();
            break;
    }

    // Get summary statistics
    $stmt = $pdo_access->prepare("
        SELECT COUNT(*) as total,
               SUM(Granted = 1) as granted,
               SUM(Granted = 0) as denied,
               COUNT(DISTINCT user_id) as unique_users,
               COUNT(DISTINCT Location) as doors_used
        FROM logs
        WHERE DATE(Date) BETWEEN ? AND ?
        " . ($door_filter ? "AND Location = ?" : "") . "
    ");
    $params = [$date_from, $date_to];
    if ($door_filter) $params[] = $door_filter;
    $stmt->execute($params);
    $summary = $stmt->fetch();

} catch (PDOException $e) {
    error_log("Report error: " . $e->getMessage());
    $report_data = [];
    $summary = ['total' => 0, 'granted' => 0, 'denied' => 0, 'unique_users' => 0, 'doors_used' => 0];
}
?>

<!-- Report Controls -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Report Type</label>
                <select name="type" class="form-select" onchange="this.form.submit()">
                    <option value="daily" <?php echo $report_type === 'daily' ? 'selected' : ''; ?>>Daily Summary</option>
                    <option value="hourly" <?php echo $report_type === 'hourly' ? 'selected' : ''; ?>>Hourly Patterns</option>
                    <option value="door" <?php echo $report_type === 'door' ? 'selected' : ''; ?>>By Door</option>
                    <option value="user" <?php echo $report_type === 'user' ? 'selected' : ''; ?>>By User</option>
                    <option value="denied" <?php echo $report_type === 'denied' ? 'selected' : ''; ?>>Denied Access</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Door</label>
                <select name="door" class="form-select">
                    <option value="">All Doors</option>
                    <?php foreach ($doors as $door): ?>
                        <option value="<?php echo htmlspecialchars($door); ?>" <?php echo $door_filter === $door ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($door); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">Generate</button>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <a href="export_report.php?<?php echo http_build_query($_GET); ?>" class="btn btn-outline-success">
                    Export CSV
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="card card-stats">
            <div class="card-body">
                <h6 class="text-muted">Total Events</h6>
                <h3><?php echo number_format($summary['total']); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card card-stats success">
            <div class="card-body">
                <h6 class="text-muted">Granted</h6>
                <h3 class="text-success"><?php echo number_format($summary['granted']); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card card-stats danger">
            <div class="card-body">
                <h6 class="text-muted">Denied</h6>
                <h3 class="text-danger"><?php echo number_format($summary['denied']); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card card-stats">
            <div class="card-body">
                <h6 class="text-muted">Success Rate</h6>
                <h3><?php echo $summary['total'] > 0 ? round(($summary['granted'] / $summary['total']) * 100, 1) : 0; ?>%</h3>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card card-stats">
            <div class="card-body">
                <h6 class="text-muted">Unique Users</h6>
                <h3><?php echo number_format($summary['unique_users']); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card card-stats">
            <div class="card-body">
                <h6 class="text-muted">Doors Used</h6>
                <h3><?php echo number_format($summary['doors_used']); ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- Chart -->
<?php if (!empty($chart_data)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header">
        <h5 class="mb-0">
            <?php
            echo match($report_type) {
                'daily' => 'Daily Access Trend',
                'hourly' => 'Hourly Access Pattern',
                'door' => 'Access by Door',
                default => 'Report Chart'
            };
            ?>
        </h5>
    </div>
    <div class="card-body">
        <canvas id="reportChart" height="100"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- Data Table -->
<div class="card shadow-sm">
    <div class="card-header">
        <h5 class="mb-0">Report Data</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-dark">
                    <?php if ($report_type === 'daily'): ?>
                        <tr>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Granted</th>
                            <th>Denied</th>
                            <th>Success Rate</th>
                        </tr>
                    <?php elseif ($report_type === 'hourly'): ?>
                        <tr>
                            <th>Hour</th>
                            <th>Total</th>
                            <th>Granted</th>
                        </tr>
                    <?php elseif ($report_type === 'door'): ?>
                        <tr>
                            <th>Door</th>
                            <th>Total</th>
                            <th>Granted</th>
                            <th>Denied</th>
                            <th>Success Rate</th>
                        </tr>
                    <?php elseif ($report_type === 'user'): ?>
                        <tr>
                            <th>User</th>
                            <th>User ID</th>
                            <th>Total</th>
                            <th>Granted</th>
                            <th>Denied</th>
                        </tr>
                    <?php elseif ($report_type === 'denied'): ?>
                        <tr>
                            <th>User</th>
                            <th>User ID</th>
                            <th>Door</th>
                            <th>Attempts</th>
                            <th>Last Attempt</th>
                        </tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                    <?php foreach ($report_data as $row): ?>
                        <?php if ($report_type === 'daily'): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['date']); ?></td>
                                <td><?php echo number_format($row['total']); ?></td>
                                <td class="text-success"><?php echo number_format($row['granted']); ?></td>
                                <td class="text-danger"><?php echo number_format($row['denied']); ?></td>
                                <td><?php echo $row['total'] > 0 ? round(($row['granted'] / $row['total']) * 100, 1) : 0; ?>%</td>
                            </tr>
                        <?php elseif ($report_type === 'hourly'): ?>
                            <tr>
                                <td><?php echo sprintf('%02d:00 - %02d:59', $row['hour'], $row['hour']); ?></td>
                                <td><?php echo number_format($row['total']); ?></td>
                                <td class="text-success"><?php echo number_format($row['granted']); ?></td>
                            </tr>
                        <?php elseif ($report_type === 'door'): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['door']); ?></strong></td>
                                <td><?php echo number_format($row['total']); ?></td>
                                <td class="text-success"><?php echo number_format($row['granted']); ?></td>
                                <td class="text-danger"><?php echo number_format($row['denied']); ?></td>
                                <td><?php echo $row['total'] > 0 ? round(($row['granted'] / $row['total']) * 100, 1) : 0; ?>%</td>
                            </tr>
                        <?php elseif ($report_type === 'user'): ?>
                            <?php $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')); ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($name ?: 'Unknown'); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($row['user_id']); ?></code></td>
                                <td><?php echo number_format($row['total']); ?></td>
                                <td class="text-success"><?php echo number_format($row['granted']); ?></td>
                                <td class="text-danger"><?php echo number_format($row['denied']); ?></td>
                            </tr>
                        <?php elseif ($report_type === 'denied'): ?>
                            <?php $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')); ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($name ?: 'Unknown'); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($row['user_id']); ?></code></td>
                                <td><?php echo htmlspecialchars($row['Location']); ?></td>
                                <td><span class="badge bg-danger"><?php echo number_format($row['attempts']); ?></span></td>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($row['last_attempt'])); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (empty($report_data)): ?>
                        <tr><td colspan="5" class="text-center text-muted">No data found for selected criteria.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (!empty($chart_data)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('reportChart').getContext('2d');

    <?php if ($report_type === 'daily'): ?>
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_data['labels'] ?? []); ?>,
            datasets: [{
                label: 'Granted',
                data: <?php echo json_encode($chart_data['granted'] ?? []); ?>,
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                fill: true,
                tension: 0.3
            }, {
                label: 'Denied',
                data: <?php echo json_encode($chart_data['denied'] ?? []); ?>,
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true } }
        }
    });
    <?php elseif ($report_type === 'hourly'): ?>
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chart_data['labels'] ?? []); ?>,
            datasets: [{
                label: 'Access Events',
                data: <?php echo json_encode($chart_data['total'] ?? []); ?>,
                backgroundColor: '#0d6efd'
            }]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true } }
        }
    });
    <?php elseif ($report_type === 'door'): ?>
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chart_data['labels'] ?? []); ?>,
            datasets: [{
                label: 'Total Access',
                data: <?php echo json_encode($chart_data['total'] ?? []); ?>,
                backgroundColor: '#0d6efd'
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            scales: { x: { beginAtZero: true } }
        }
    });
    <?php endif; ?>
});
</script>
<?php endif; ?>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
