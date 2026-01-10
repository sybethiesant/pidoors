<?php
/**
 * Edit Door
 * PiDoors Access Control System
 */
$title = 'Edit Door';
require_once './includes/header.php';

require_login($config);
require_admin($config);

$error_message = '';
$door_name = sanitize_string($_GET['name'] ?? $_POST['original_name'] ?? '');

if (empty($door_name)) {
    header("Location: {$config['url']}/doors.php?error=No door specified.");
    exit();
}

try {
    $stmt = $pdo_access->prepare("SELECT * FROM doors WHERE name = ?");
    $stmt->execute([$door_name]);
    $door = $stmt->fetch();

    if (!$door) {
        header("Location: {$config['url']}/doors.php?error=Door not found.");
        exit();
    }

    $schedules = $pdo_access->query("SELECT id, name FROM access_schedules ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    error_log("Edit door error: " . $e->getMessage());
    header("Location: {$config['url']}/doors.php?error=Error loading door.");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } else {
        $location = sanitize_string($_POST['location'] ?? '');
        $doornum = sanitize_string($_POST['doornum'] ?? '');
        $description = sanitize_string($_POST['description'] ?? '');
        $schedule_id = validate_int($_POST['schedule_id'] ?? 0) ?: null;
        $unlock_duration = validate_int($_POST['unlock_duration'] ?? 5, 1, 60) ?: 5;

        try {
            $stmt = $pdo_access->prepare("
                UPDATE doors SET location = ?, doornum = ?, description = ?,
                    schedule_id = ?, unlock_duration = ?
                WHERE name = ?
            ");
            $stmt->execute([$location, $doornum, $description, $schedule_id, $unlock_duration, $door_name]);

            header("Location: {$config['url']}/doors.php?success=Door updated successfully.");
            exit();
        } catch (PDOException $e) {
            error_log("Update door error: " . $e->getMessage());
            $error_message = 'Failed to update door.';
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Edit Door: <?php echo htmlspecialchars($door['name']); ?></h5>
            </div>
            <div class="card-body">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="original_name" value="<?php echo htmlspecialchars($door_name); ?>">

                    <div class="mb-3">
                        <label class="form-label">Door Name</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($door['name']); ?>" disabled>
                    </div>

                    <div class="mb-3">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" class="form-control" id="location" name="location"
                               value="<?php echo htmlspecialchars($door['location']); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="doornum" class="form-label">Door Number</label>
                        <input type="text" class="form-control" id="doornum" name="doornum"
                               value="<?php echo htmlspecialchars($door['doornum']); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?php echo htmlspecialchars($door['description']); ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="schedule_id" class="form-label">Access Schedule</label>
                        <select class="form-select" id="schedule_id" name="schedule_id">
                            <option value="">Always accessible</option>
                            <?php foreach ($schedules as $schedule): ?>
                                <option value="<?php echo $schedule['id']; ?>"
                                    <?php echo ($door['schedule_id'] == $schedule['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($schedule['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="unlock_duration" class="form-label">Unlock Duration (seconds)</label>
                        <input type="number" class="form-control" id="unlock_duration" name="unlock_duration"
                               min="1" max="60" value="<?php echo htmlspecialchars($door['unlock_duration'] ?? 5); ?>">
                    </div>

                    <?php if ($door['status'] || $door['ip_address'] || $door['last_seen']): ?>
                        <div class="card bg-light mb-3">
                            <div class="card-body">
                                <h6>Controller Status</h6>
                                <p class="mb-1"><strong>Status:</strong>
                                    <span class="badge bg-<?php echo $door['status'] === 'online' ? 'success' : ($door['status'] === 'offline' ? 'danger' : 'secondary'); ?>">
                                        <?php echo ucfirst($door['status'] ?? 'Unknown'); ?>
                                    </span>
                                </p>
                                <?php if ($door['ip_address']): ?>
                                    <p class="mb-1"><strong>IP Address:</strong> <?php echo htmlspecialchars($door['ip_address']); ?></p>
                                <?php endif; ?>
                                <?php if ($door['last_seen']): ?>
                                    <p class="mb-0"><strong>Last Seen:</strong> <?php echo date('Y-m-d H:i:s', strtotime($door['last_seen'])); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between">
                        <a href="doors.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
