<?php
/**
 * Doors Management
 * PiDoors Access Control System
 */
$title = 'Doors';
require_once './includes/header.php';

require_login($config);

// Handle delete
if (isset($_GET['delete']) && isset($_GET['token'])) {
    if (verify_csrf_token($_GET['token'])) {
        $door_name = sanitize_string($_GET['delete']);
        try {
            $stmt = $pdo_access->prepare("DELETE FROM doors WHERE name = ?");
            $stmt->execute([$door_name]);
            header("Location: {$config['url']}/doors.php?success=Door deleted.");
            exit();
        } catch (PDOException $e) {
            error_log("Delete door error: " . $e->getMessage());
        }
    }
}

// Fetch doors
try {
    $stmt = $pdo_access->query("
        SELECT d.*, s.name as schedule_name
        FROM doors d
        LEFT JOIN access_schedules s ON d.schedule_id = s.id
        ORDER BY d.name
    ");
    $doors = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Doors error: " . $e->getMessage());
    $doors = [];
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div><span class="text-muted"><?php echo count($doors); ?> doors configured</span></div>
    <a href="adddoor.php" class="btn btn-primary">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add Door
    </a>
</div>

<div class="row">
    <?php foreach ($doors as $door): ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?php echo htmlspecialchars($door['name']); ?></h5>
                    <?php
                    $status = $door['status'] ?? 'unknown';
                    $statusClass = match($status) {
                        'online' => 'success',
                        'offline' => 'danger',
                        default => 'secondary'
                    };
                    ?>
                    <span class="badge bg-<?php echo $statusClass; ?>"><?php echo ucfirst($status); ?></span>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong>Location:</strong> <?php echo htmlspecialchars($door['location'] ?? 'N/A'); ?></p>
                    <p class="mb-1"><strong>Description:</strong> <?php echo htmlspecialchars($door['description'] ?? 'N/A'); ?></p>
                    <?php if ($door['ip_address']): ?>
                        <p class="mb-1"><strong>IP:</strong> <?php echo htmlspecialchars($door['ip_address']); ?></p>
                    <?php endif; ?>
                    <?php if ($door['last_seen']): ?>
                        <p class="mb-1"><strong>Last Seen:</strong> <?php echo date('M j, g:i A', strtotime($door['last_seen'])); ?></p>
                    <?php endif; ?>
                    <p class="mb-1">
                        <strong>Lock Status:</strong>
                        <?php if (isset($door['locked'])): ?>
                            <span class="badge <?php echo $door['locked'] ? 'bg-success' : 'bg-warning'; ?>">
                                <?php echo $door['locked'] ? 'Locked' : 'Unlocked'; ?>
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Unknown</span>
                        <?php endif; ?>
                    </p>
                    <?php if ($door['schedule_name']): ?>
                        <p class="mb-0"><strong>Schedule:</strong> <?php echo htmlspecialchars($door['schedule_name']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="card-footer d-flex justify-content-between">
                    <a href="editdoor.php?name=<?php echo urlencode($door['name']); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                    <a href="doors.php?delete=<?php echo urlencode($door['name']); ?>&token=<?php echo htmlspecialchars($csrf_token); ?>"
                       class="btn btn-sm btn-outline-danger"
                       onclick="return confirmDelete('Delete this door?');">Delete</a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (empty($doors)): ?>
        <div class="col-12">
            <div class="alert alert-info">No doors configured. <a href="adddoor.php">Add your first door.</a></div>
        </div>
    <?php endif; ?>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
