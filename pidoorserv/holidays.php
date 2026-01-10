<?php
/**
 * Holidays Management
 * PiDoors Access Control System
 */
$title = 'Holidays';
require_once './includes/header.php';

require_login($config);

// Handle delete
if (isset($_GET['delete']) && isset($_GET['token'])) {
    if (verify_csrf_token($_GET['token'])) {
        $id = validate_int($_GET['delete']);
        if ($id) {
            try {
                $stmt = $pdo_access->prepare("DELETE FROM holidays WHERE id = ?");
                $stmt->execute([$id]);
                header("Location: {$config['url']}/holidays.php?success=Holiday deleted.");
                exit();
            } catch (PDOException $e) {
                error_log("Delete holiday error: " . $e->getMessage());
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
        $stmt = $pdo_access->prepare("SELECT * FROM holidays WHERE id = ?");
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
        $date = sanitize_string($_POST['date'] ?? '');
        $recurring = isset($_POST['recurring']) ? 1 : 0;
        $no_access = isset($_POST['no_access']) ? 1 : 0;

        if (empty($name)) {
            $error_message = 'Holiday name is required.';
        } elseif (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $error_message = 'Valid date is required.';
        } else {
            try {
                if ($id) {
                    // Update
                    $stmt = $pdo_access->prepare("UPDATE holidays SET name = ?, date = ?, recurring = ?, no_access = ? WHERE id = ?");
                    $stmt->execute([$name, $date, $recurring, $no_access, $id]);
                    $message = 'Holiday updated successfully.';
                } else {
                    // Insert
                    $stmt = $pdo_access->prepare("INSERT INTO holidays (name, date, recurring, no_access) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $date, $recurring, $no_access]);
                    $message = 'Holiday added successfully.';
                }
                header("Location: {$config['url']}/holidays.php?success=" . urlencode($message));
                exit();
            } catch (PDOException $e) {
                error_log("Holiday error: " . $e->getMessage());
                $error_message = 'Failed to save holiday.';
            }
        }
    }
}

// Fetch holidays
try {
    $holidays = $pdo_access->query("SELECT * FROM holidays ORDER BY date")->fetchAll();
} catch (PDOException $e) {
    $holidays = [];
}
?>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="mb-0">Holidays</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th>
                            <th>Name</th>
                            <th>Recurring</th>
                            <th>Access</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($holidays as $holiday): ?>
                            <tr>
                                <td>
                                    <?php
                                    $date = new DateTime($holiday['date']);
                                    echo $date->format('M j, Y');
                                    if ($holiday['recurring']) {
                                        echo ' <small class="text-muted">(yearly)</small>';
                                    }
                                    ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars($holiday['name']); ?></strong></td>
                                <td>
                                    <?php if ($holiday['recurring']): ?>
                                        <span class="badge bg-info">Yearly</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">One-time</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($holiday['no_access']): ?>
                                        <span class="badge bg-danger">No Access</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Normal Access</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="?edit=<?php echo $holiday['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <a href="?delete=<?php echo $holiday['id']; ?>&token=<?php echo htmlspecialchars($csrf_token); ?>"
                                       class="btn btn-sm btn-outline-danger"
                                       onclick="return confirmDelete('Delete this holiday?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($holidays)): ?>
                            <tr><td colspan="5" class="text-muted text-center">No holidays defined.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><?php echo $editing ? 'Edit Holiday' : 'Add Holiday'; ?></h5>
            </div>
            <div class="card-body">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo $editing['id'] ?? ''; ?>">

                    <div class="mb-3">
                        <label for="name" class="form-label">Holiday Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required
                               value="<?php echo htmlspecialchars($editing['name'] ?? ''); ?>"
                               placeholder="e.g., Christmas Day">
                    </div>

                    <div class="mb-3">
                        <label for="date" class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="date" name="date" required
                               value="<?php echo htmlspecialchars($editing['date'] ?? ''); ?>">
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="recurring" name="recurring"
                               <?php echo ($editing['recurring'] ?? 0) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="recurring">Recurring (every year)</label>
                        <div class="form-text">If checked, this holiday will repeat annually on the same month/day.</div>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="no_access" name="no_access"
                               <?php echo ($editing['no_access'] ?? 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="no_access">No Access on this day</label>
                        <div class="form-text">If checked, scheduled access will be denied on this day.</div>
                    </div>

                    <div class="d-flex justify-content-between mt-3">
                        <?php if ($editing): ?>
                            <a href="holidays.php" class="btn btn-secondary">Cancel</a>
                        <?php else: ?>
                            <div></div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary"><?php echo $editing ? 'Update' : 'Add'; ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mt-4">
            <div class="card-header">
                <h6 class="mb-0">About Holidays</h6>
            </div>
            <div class="card-body">
                <p class="small mb-2">Holidays affect access control in the following ways:</p>
                <ul class="small mb-0">
                    <li><strong>No Access</strong>: Blocks all scheduled access on the holiday.</li>
                    <li><strong>Normal Access</strong>: Access continues as per normal schedules.</li>
                    <li><strong>24/7 schedules</strong> are not affected by holidays.</li>
                    <li><strong>Master cards</strong> always have access regardless of holidays.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
