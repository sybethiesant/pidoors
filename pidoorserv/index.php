<?php
/**
 * Dashboard - Main Page
 * PiDoors Access Control System
 */
$title = 'Dashboard';
require_once './includes/header.php';

// Require login
require_login($config);

// Fetch statistics
try {
    // Total cards
    $stmt = $pdo_access->query("SELECT COUNT(*) as total FROM cards");
    $total_cards = $stmt->fetch()['total'];

    // Active cards
    $stmt = $pdo_access->query("SELECT COUNT(*) as total FROM cards WHERE active = 1");
    $active_cards = $stmt->fetch()['total'];

    // Total doors
    $stmt = $pdo_access->query("SELECT COUNT(*) as total FROM doors");
    $total_doors = $stmt->fetch()['total'];

    // Online doors
    $stmt = $pdo_access->query("SELECT COUNT(*) as total FROM doors WHERE status = 'online'");
    $online_doors = $stmt->fetch()['total'];

    // Today's access count
    $stmt = $pdo_access->query("SELECT COUNT(*) as total FROM logs WHERE DATE(Date) = CURDATE()");
    $today_access = $stmt->fetch()['total'];

    // Today's granted access
    $stmt = $pdo_access->query("SELECT COUNT(*) as total FROM logs WHERE DATE(Date) = CURDATE() AND Granted = 1");
    $today_granted = $stmt->fetch()['total'];

    // Today's denied access
    $stmt = $pdo_access->query("SELECT COUNT(*) as total FROM logs WHERE DATE(Date) = CURDATE() AND Granted = 0");
    $today_denied = $stmt->fetch()['total'];

    // Recent access logs
    $stmt = $pdo_access->query("
        SELECT l.*, c.firstname, c.lastname, c.card_id
        FROM logs l
        LEFT JOIN cards c ON l.user_id = c.user_id
        ORDER BY l.Date DESC
        LIMIT 10
    ");
    $recent_logs = $stmt->fetchAll();

    // Door status
    $stmt = $pdo_access->query("SELECT * FROM doors ORDER BY name");
    $doors = $stmt->fetchAll();

    // Access by hour for chart (last 24 hours)
    $stmt = $pdo_access->query("
        SELECT HOUR(Date) as hour, COUNT(*) as count
        FROM logs
        WHERE Date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY HOUR(Date)
        ORDER BY hour
    ");
    $hourly_data = $stmt->fetchAll();
    $hours = array_fill(0, 24, 0);
    foreach ($hourly_data as $row) {
        $hours[(int)$row['hour']] = (int)$row['count'];
    }

} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $total_cards = $active_cards = $total_doors = $online_doors = 0;
    $today_access = $today_granted = $today_denied = 0;
    $recent_logs = [];
    $doors = [];
    $hours = array_fill(0, 24, 0);
}
?>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card card-stats h-100 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted mb-1">Total Cards</h6>
                        <h3 class="mb-0"><?php echo number_format($total_cards); ?></h3>
                        <small class="text-success"><?php echo number_format($active_cards); ?> active</small>
                    </div>
                    <div class="align-self-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#0d6efd" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card card-stats success h-100 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted mb-1">Doors</h6>
                        <h3 class="mb-0"><?php echo number_format($total_doors); ?></h3>
                        <small class="text-success"><?php echo number_format($online_doors); ?> online</small>
                    </div>
                    <div class="align-self-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#28a745" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="9" y1="3" x2="9" y2="21"></line></svg>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card card-stats warning h-100 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted mb-1">Today's Access</h6>
                        <h3 class="mb-0"><?php echo number_format($today_access); ?></h3>
                        <small class="text-success"><?php echo number_format($today_granted); ?> granted</small>
                    </div>
                    <div class="align-self-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ffc107" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card card-stats danger h-100 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted mb-1">Denied Today</h6>
                        <h3 class="mb-0"><?php echo number_format($today_denied); ?></h3>
                        <small class="text-muted">access attempts</small>
                    </div>
                    <div class="align-self-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#dc3545" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Door Status Panel -->
    <div class="col-lg-4 mb-4">
        <div class="card shadow-sm h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Door Status</h5>
                <a href="doors.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if (empty($doors)): ?>
                        <li class="list-group-item text-muted">No doors configured</li>
                    <?php else: ?>
                        <?php foreach ($doors as $door): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?php echo htmlspecialchars($door['name']); ?></strong>
                                    <br>
                                    <small class="text-muted"><?php echo htmlspecialchars($door['location'] ?? ''); ?></small>
                                </div>
                                <div class="text-end">
                                    <?php
                                    $status = $door['status'] ?? 'unknown';
                                    $statusClass = match($status) {
                                        'online' => 'success',
                                        'offline' => 'danger',
                                        default => 'secondary'
                                    };
                                    ?>
                                    <span class="badge bg-<?php echo $statusClass; ?>">
                                        <?php echo ucfirst($status); ?>
                                    </span>
                                    <?php if (isset($door['locked'])): ?>
                                        <br>
                                        <small class="<?php echo $door['locked'] ? 'text-success' : 'text-warning'; ?>">
                                            <?php echo $door['locked'] ? 'Locked' : 'Unlocked'; ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- Access Chart -->
    <div class="col-lg-8 mb-4">
        <div class="card shadow-sm h-100">
            <div class="card-header">
                <h5 class="mb-0">Access Activity (Last 24 Hours)</h5>
            </div>
            <div class="card-body">
                <canvas id="accessChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Recent Access Logs -->
<div class="row">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Recent Access</h5>
                <a href="logs.php" class="btn btn-sm btn-outline-primary">View All Logs</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Time</th>
                                <th>User</th>
                                <th>Location</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_logs)): ?>
                                <tr><td colspan="4" class="text-center text-muted">No recent access logs</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_logs as $log): ?>
                                    <tr>
                                        <td><?php echo date('M j, g:i A', strtotime($log['Date'])); ?></td>
                                        <td>
                                            <?php
                                            $name = trim(($log['firstname'] ?? '') . ' ' . ($log['lastname'] ?? ''));
                                            echo htmlspecialchars($name ?: "User #{$log['user_id']}");
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['Location']); ?></td>
                                        <td>
                                            <?php if ($log['Granted'] == 1): ?>
                                                <span class="badge bg-success">Granted</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Denied</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Access chart
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('accessChart');
    if (ctx && typeof Chart !== 'undefined') {
        const hourLabels = [];
        for (let i = 0; i < 24; i++) {
            hourLabels.push(i + ':00');
        }

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: hourLabels,
                datasets: [{
                    label: 'Access Events',
                    data: <?php echo json_encode(array_values($hours)); ?>,
                    backgroundColor: 'rgba(13, 110, 253, 0.5)',
                    borderColor: 'rgba(13, 110, 253, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    }
});
</script>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
