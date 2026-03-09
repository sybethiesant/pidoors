<?php
/**
 * Dashboard - Main Page
 * PiDoors Access Control System
 */

// AJAX endpoint: unlock a door (POST)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'unlock' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $config = include(__DIR__ . '/includes/config.php');
    require_once __DIR__ . '/includes/security.php';
    require_once $config['apppath'] . 'database/db_connection.php';
    secure_session_start($config);

    header('Content-Type: application/json');
    if (!is_logged_in() || !is_admin()) {
        echo json_encode(['ok' => false, 'msg' => 'Unauthorized']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $door_name = htmlspecialchars(trim($input['door'] ?? ''), ENT_QUOTES, 'UTF-8');
    $csrf = $input['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf)) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid token']);
        exit();
    }

    try {
        $stmt = $pdo_access->prepare("SELECT status FROM doors WHERE name = ?");
        $stmt->execute([$door_name]);
        $door_check = $stmt->fetch();
        if ($door_check && $door_check['status'] === 'online') {
            $stmt = $pdo_access->prepare("UPDATE doors SET unlock_requested = 1 WHERE name = ?");
            $stmt->execute([$door_name]);
            log_security_event($pdo, 'remote_unlock', $_SESSION['user_id'], "Remote unlock requested for door: $door_name");
            echo json_encode(['ok' => true, 'msg' => 'Unlock command sent']);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Door is not online']);
        }
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'msg' => 'Database error']);
    }
    exit();
}

// AJAX endpoint: return dashboard data as JSON (no HTML)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'dashboard') {
    $config = include(__DIR__ . '/includes/config.php');
    require_once __DIR__ . '/includes/security.php';
    require_once $config['apppath'] . 'database/db_connection.php';
    secure_session_start($config);

    if (!is_logged_in()) {
        http_response_code(403);
        exit();
    }

    header('Content-Type: application/json');

    try {
        $total_cards = (int)$pdo_access->query("SELECT COUNT(*) FROM cards")->fetchColumn();
        $active_cards = (int)$pdo_access->query("SELECT COUNT(*) FROM cards WHERE active = 1")->fetchColumn();
        $total_doors = (int)$pdo_access->query("SELECT COUNT(*) FROM doors")->fetchColumn();
        $online_doors = (int)$pdo_access->query("SELECT COUNT(*) FROM doors WHERE status = 'online'")->fetchColumn();
        $today_access = (int)$pdo_access->query("SELECT COUNT(*) FROM logs WHERE DATE(Date) = CURDATE()")->fetchColumn();
        $today_granted = (int)$pdo_access->query("SELECT COUNT(*) FROM logs WHERE DATE(Date) = CURDATE() AND Granted = 1")->fetchColumn();
        $today_denied = (int)$pdo_access->query("SELECT COUNT(*) FROM logs WHERE DATE(Date) = CURDATE() AND Granted = 0")->fetchColumn();

        $doors = $pdo_access->query("SELECT name, location, status, locked FROM doors ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

        $recent = $pdo_access->query("
            SELECT l.Date, l.Location, l.Granted, l.user_id, c.firstname, c.lastname
            FROM logs l LEFT JOIN cards c ON l.user_id = c.user_id
            ORDER BY l.Date DESC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        $hourly_stmt = $pdo_access->query("
            SELECT HOUR(Date) as hour, COUNT(*) as count
            FROM logs WHERE Date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY HOUR(Date) ORDER BY hour
        ");
        $hours = array_fill(0, 24, 0);
        foreach ($hourly_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hours[(int)$row['hour']] = (int)$row['count'];
        }
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Database error']);
        exit();
    }

    echo json_encode([
        'total_cards' => $total_cards, 'active_cards' => $active_cards,
        'total_doors' => $total_doors, 'online_doors' => $online_doors,
        'today_access' => $today_access, 'today_granted' => $today_granted,
        'today_denied' => $today_denied,
        'doors' => $doors, 'recent_logs' => $recent,
        'hourly' => array_values($hours)
    ]);
    exit();
}

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
                        <h3 class="mb-0" id="stat-total-cards"><?php echo number_format($total_cards); ?></h3>
                        <small class="text-success" id="stat-active-cards"><?php echo number_format($active_cards); ?> active</small>
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
                        <h3 class="mb-0" id="stat-total-doors"><?php echo number_format($total_doors); ?></h3>
                        <small class="text-success" id="stat-online-doors"><?php echo number_format($online_doors); ?> online</small>
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
                        <h3 class="mb-0" id="stat-today-access"><?php echo number_format($today_access); ?></h3>
                        <small class="text-success" id="stat-today-granted"><?php echo number_format($today_granted); ?> granted</small>
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
                        <h3 class="mb-0" id="stat-today-denied"><?php echo number_format($today_denied); ?></h3>
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
                <ul class="list-group list-group-flush" id="door-status-list">
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
                                <div class="d-flex align-items-center gap-2">
                                    <?php if (($door['status'] ?? '') === 'online' && is_admin()): ?>
                                        <button type="button" class="btn btn-sm btn-outline-warning py-0 px-1 btn-unlock" data-door="<?php echo htmlspecialchars($door['name']); ?>" title="Remote Unlock">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 9.9-1"></path></svg>
                                        </button>
                                    <?php endif; ?>
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
                        <tbody id="recent-logs-body">
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
(function() {
    var accessChart = null;
    var pollTimer = null;
    var csrfToken = '<?php echo htmlspecialchars(generate_csrf_token()); ?>';
    var isAdmin = <?php echo is_admin() ? 'true' : 'false'; ?>;
    var unlockSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 9.9-1"></path></svg>';

    function escHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    function formatDate(str) {
        if (!str) return '';
        var d = new Date(str);
        return d.toLocaleString([], {month:'short', day:'numeric', hour:'numeric', minute:'2-digit'});
    }

    function numberFormat(n) { return n.toLocaleString(); }

    // Initialize chart
    document.addEventListener('DOMContentLoaded', function() {
        var ctx = document.getElementById('accessChart');
        if (ctx && typeof Chart !== 'undefined') {
            var hourLabels = [];
            for (var i = 0; i < 24; i++) hourLabels.push(i + ':00');

            accessChart = new Chart(ctx, {
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
                    scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
                    plugins: { legend: { display: false } }
                }
            });
        }
    });

    function refreshDashboard() {
        $.getJSON('index.php?ajax=dashboard', function(data) {
            if (data.error) return;

            // Update stat cards
            $('#stat-total-cards').text(numberFormat(data.total_cards));
            $('#stat-active-cards').text(numberFormat(data.active_cards) + ' active');
            $('#stat-total-doors').text(numberFormat(data.total_doors));
            $('#stat-online-doors').text(numberFormat(data.online_doors) + ' online');
            $('#stat-today-access').text(numberFormat(data.today_access));
            $('#stat-today-granted').text(numberFormat(data.today_granted) + ' granted');
            $('#stat-today-denied').text(numberFormat(data.today_denied));

            // Update door status panel
            var doorList = $('#door-status-list');
            if (doorList.length) {
                var html = '';
                if (!data.doors || data.doors.length === 0) {
                    html = '<li class="list-group-item text-muted">No doors configured</li>';
                } else {
                    $.each(data.doors, function(i, door) {
                        var status = door.status || 'unknown';
                        var statusClass = status === 'online' ? 'success' : status === 'offline' ? 'danger' : 'secondary';
                        html += '<li class="list-group-item d-flex justify-content-between align-items-center">';
                        html += '<div><strong>' + escHtml(door.name) + '</strong><br><small class="text-muted">' + escHtml(door.location || '') + '</small></div>';
                        html += '<div class="d-flex align-items-center gap-2">';
                        if (status === 'online' && isAdmin) {
                            html += '<button type="button" class="btn btn-sm btn-outline-warning py-0 px-1 btn-unlock" data-door="' + escHtml(door.name) + '" title="Remote Unlock">' + unlockSvg + '</button>';
                        }
                        html += '<div class="text-end">';
                        html += '<span class="badge bg-' + statusClass + '">' + status.charAt(0).toUpperCase() + status.slice(1) + '</span>';
                        if (door.locked !== null && door.locked !== undefined) {
                            var locked = parseInt(door.locked);
                            html += '<br><small class="' + (locked ? 'text-success' : 'text-warning') + '">' + (locked ? 'Locked' : 'Unlocked') + '</small>';
                        }
                        html += '</div></div></li>';
                    });
                }
                doorList.html(html);
            }

            // Update recent logs
            var tbody = $('#recent-logs-body');
            if (tbody.length) {
                var rows = '';
                if (!data.recent_logs || data.recent_logs.length === 0) {
                    rows = '<tr><td colspan="4" class="text-center text-muted">No recent access logs</td></tr>';
                } else {
                    $.each(data.recent_logs, function(i, log) {
                        var name = ((log.firstname || '') + ' ' + (log.lastname || '')).trim();
                        if (!name) name = 'User #' + log.user_id;
                        rows += '<tr>';
                        rows += '<td>' + formatDate(log.Date) + '</td>';
                        rows += '<td>' + escHtml(name) + '</td>';
                        rows += '<td>' + escHtml(log.Location) + '</td>';
                        rows += '<td>' + (parseInt(log.Granted) === 1
                            ? '<span class="badge bg-success">Granted</span>'
                            : '<span class="badge bg-danger">Denied</span>') + '</td>';
                        rows += '</tr>';
                    });
                }
                tbody.html(rows);
            }

            // Update chart
            if (accessChart && data.hourly) {
                accessChart.data.datasets[0].data = data.hourly;
                accessChart.update();
            }
        });
    }

    // Unlock button handler (delegated for dynamically added buttons)
    $(document).on('click', '.btn-unlock', function() {
        var btn = $(this);
        var doorName = btn.data('door');
        if (!confirm('Unlock ' + doorName + '?')) return;
        btn.prop('disabled', true);
        $.ajax({
            url: 'index.php?ajax=unlock',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({door: doorName, csrf_token: csrfToken}),
            dataType: 'json',
            success: function(res) {
                if (res.ok) {
                    btn.removeClass('btn-outline-warning').addClass('btn-warning');
                    setTimeout(function() {
                        btn.removeClass('btn-warning').addClass('btn-outline-warning').prop('disabled', false);
                    }, 3000);
                } else {
                    alert(res.msg || 'Unlock failed');
                    btn.prop('disabled', false);
                }
            },
            error: function() { alert('Request failed'); btn.prop('disabled', false); }
        });
    });

    pollTimer = setInterval(refreshDashboard, 5000);

    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            clearInterval(pollTimer);
        } else {
            refreshDashboard();
            pollTimer = setInterval(refreshDashboard, 5000);
        }
    });
})();
</script>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
