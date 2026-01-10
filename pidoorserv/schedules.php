<?php
/**
 * Access Schedules Management
 * PiDoors Access Control System
 */
$title = 'Access Schedules';
require_once './includes/header.php';

require_login($config);

// Handle delete
if (isset($_GET['delete']) && isset($_GET['token'])) {
    if (verify_csrf_token($_GET['token'])) {
        $id = validate_int($_GET['delete']);
        if ($id) {
            try {
                $stmt = $pdo_access->prepare("DELETE FROM access_schedules WHERE id = ?");
                $stmt->execute([$id]);
                header("Location: {$config['url']}/schedules.php?success=Schedule deleted.");
                exit();
            } catch (PDOException $e) {
                error_log("Delete schedule error: " . $e->getMessage());
            }
        }
    }
}

// Handle add/edit
$editing = null;
$error_message = '';

if (isset($_GET['edit'])) {
    $edit_id = validate_int($_GET['edit']);
    if ($edit_id) {
        $stmt = $pdo_access->prepare("SELECT * FROM access_schedules WHERE id = ?");
        $stmt->execute([$edit_id]);
        $editing = $stmt->fetch();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } else {
        $id = validate_int($_POST['id'] ?? 0);
        $name = sanitize_string($_POST['name'] ?? '');
        $description = sanitize_string($_POST['description'] ?? '');
        $is_24_7 = isset($_POST['is_24_7']) ? 1 : 0;

        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $times = [];
        foreach ($days as $day) {
            $times["{$day}_start"] = $_POST["{$day}_start"] ?: null;
            $times["{$day}_end"] = $_POST["{$day}_end"] ?: null;
        }

        if (empty($name)) {
            $error_message = 'Schedule name is required.';
        } else {
            try {
                if ($id) {
                    // Update
                    $stmt = $pdo_access->prepare("
                        UPDATE access_schedules SET name = ?, description = ?, is_24_7 = ?,
                            monday_start = ?, monday_end = ?,
                            tuesday_start = ?, tuesday_end = ?,
                            wednesday_start = ?, wednesday_end = ?,
                            thursday_start = ?, thursday_end = ?,
                            friday_start = ?, friday_end = ?,
                            saturday_start = ?, saturday_end = ?,
                            sunday_start = ?, sunday_end = ?
                        WHERE id = ?
                    ");
                    $params = [$name, $description, $is_24_7];
                    foreach ($days as $day) {
                        $params[] = $times["{$day}_start"];
                        $params[] = $times["{$day}_end"];
                    }
                    $params[] = $id;
                    $stmt->execute($params);
                    $message = 'Schedule updated successfully.';
                } else {
                    // Insert
                    $stmt = $pdo_access->prepare("
                        INSERT INTO access_schedules (name, description, is_24_7,
                            monday_start, monday_end, tuesday_start, tuesday_end,
                            wednesday_start, wednesday_end, thursday_start, thursday_end,
                            friday_start, friday_end, saturday_start, saturday_end,
                            sunday_start, sunday_end)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $params = [$name, $description, $is_24_7];
                    foreach ($days as $day) {
                        $params[] = $times["{$day}_start"];
                        $params[] = $times["{$day}_end"];
                    }
                    $stmt->execute($params);
                    $message = 'Schedule added successfully.';
                }
                header("Location: {$config['url']}/schedules.php?success=" . urlencode($message));
                exit();
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error_message = 'A schedule with this name already exists.';
                } else {
                    error_log("Schedule error: " . $e->getMessage());
                    $error_message = 'Failed to save schedule.';
                }
            }
        }
    }
}

// Fetch schedules
try {
    $schedules = $pdo_access->query("SELECT * FROM access_schedules ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    $schedules = [];
}
?>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="mb-0">Access Schedules</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Type</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schedules as $schedule): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($schedule['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($schedule['description'] ?? ''); ?></td>
                                <td>
                                    <?php if ($schedule['is_24_7']): ?>
                                        <span class="badge bg-success">24/7</span>
                                    <?php else: ?>
                                        <span class="badge bg-info">Custom</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="?edit=<?php echo $schedule['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <a href="?delete=<?php echo $schedule['id']; ?>&token=<?php echo htmlspecialchars($csrf_token); ?>"
                                       class="btn btn-sm btn-outline-danger"
                                       onclick="return confirmDelete('Delete this schedule?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($schedules)): ?>
                            <tr><td colspan="4" class="text-muted text-center">No schedules defined.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><?php echo $editing ? 'Edit Schedule' : 'Add Schedule'; ?></h5>
            </div>
            <div class="card-body">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo $editing['id'] ?? ''; ?>">

                    <div class="mb-3">
                        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required
                               value="<?php echo htmlspecialchars($editing['name'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <input type="text" class="form-control" id="description" name="description"
                               value="<?php echo htmlspecialchars($editing['description'] ?? ''); ?>">
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="is_24_7" name="is_24_7"
                               <?php echo ($editing['is_24_7'] ?? 0) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_24_7">24/7 Access (ignore times below)</label>
                    </div>

                    <hr>
                    <h6>Access Hours</h6>

                    <?php
                    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    foreach ($days as $day):
                        $key = strtolower($day);
                    ?>
                        <div class="row mb-2">
                            <div class="col-4">
                                <label class="form-label small"><?php echo $day; ?></label>
                            </div>
                            <div class="col-4">
                                <input type="time" class="form-control form-control-sm" name="<?php echo $key; ?>_start"
                                       value="<?php echo $editing["{$key}_start"] ?? ''; ?>">
                            </div>
                            <div class="col-4">
                                <input type="time" class="form-control form-control-sm" name="<?php echo $key; ?>_end"
                                       value="<?php echo $editing["{$key}_end"] ?? ''; ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="d-flex justify-content-between mt-3">
                        <?php if ($editing): ?>
                            <a href="schedules.php" class="btn btn-secondary">Cancel</a>
                        <?php else: ?>
                            <div></div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary"><?php echo $editing ? 'Update' : 'Add'; ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
