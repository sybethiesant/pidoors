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

    // Fetch max unlock duration from settings
    $max_unlock_stmt = $pdo_access->prepare("SELECT setting_value FROM settings WHERE setting_key = 'max_unlock_duration'");
    $max_unlock_stmt->execute();
    $max_unlock_duration = (int)($max_unlock_stmt->fetchColumn() ?: 3600);
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
        $doornum = trim($_POST['doornum'] ?? '') !== '' ? validate_int($_POST['doornum'] ?? '') : null;
        $description = sanitize_string($_POST['description'] ?? '');
        $schedule_id = validate_int($_POST['schedule_id'] ?? 0) ?: null;
        $unlock_duration = validate_int($_POST['unlock_duration'] ?? 5, 1, $max_unlock_duration) ?: 5;
        $poll_interval = validate_int($_POST['poll_interval'] ?? 3, 1, 60) ?: 3;
        $reader_type = sanitize_string($_POST['reader_type'] ?? 'wiegand');

        // Validate reader_type
        $valid_reader_types = ['wiegand', 'osdp', 'nfc_pn532', 'nfc_mfrc522'];
        if (!in_array($reader_type, $valid_reader_types)) {
            $reader_type = 'wiegand';
        }

        try {
            $stmt = $pdo_access->prepare("
                UPDATE doors SET location = ?, doornum = ?, description = ?,
                    schedule_id = ?, unlock_duration = ?, poll_interval = ?, reader_type = ?
                WHERE name = ?
            ");
            $stmt->execute([$location, $doornum, $description, $schedule_id, $unlock_duration, $poll_interval, $reader_type, $door_name]);

            header("Location: {$config['url']}/doors.php?success=Door updated successfully.");
            exit();
        } catch (PDOException $e) {
            error_log("Update door error: " . $e->getMessage());
            // Auto-fix missing columns and retry once
            if (stripos($e->getMessage(), 'Unknown column') !== false) {
                try {
                    $pdo_access->exec("ALTER TABLE doors ADD COLUMN IF NOT EXISTS unlock_requested tinyint(1) NOT NULL DEFAULT 0");
                    $pdo_access->exec("ALTER TABLE doors ADD COLUMN IF NOT EXISTS poll_interval int(11) NOT NULL DEFAULT 3");
                    $stmt = $pdo_access->prepare("
                        UPDATE doors SET location = ?, doornum = ?, description = ?,
                            schedule_id = ?, unlock_duration = ?, poll_interval = ?, reader_type = ?
                        WHERE name = ?
                    ");
                    $stmt->execute([$location, $doornum, $description, $schedule_id, $unlock_duration, $poll_interval, $reader_type, $door_name]);
                    header("Location: {$config['url']}/doors.php?success=Door updated successfully.");
                    exit();
                } catch (PDOException $e2) {
                    error_log("Update door retry error: " . $e2->getMessage());
                    $error_message = 'Failed to update door: ' . $e2->getMessage();
                }
            } else {
                $error_message = 'Failed to update door: ' . $e->getMessage();
            }
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
                               min="1" max="<?php echo $max_unlock_duration; ?>" value="<?php echo htmlspecialchars($door['unlock_duration'] ?? 5); ?>">
                        <?php $dur = (int)($door['unlock_duration'] ?? 5); ?>
                        <div class="form-text">
                            <?php if ($dur >= 60): ?>
                                Current: <?php echo floor($dur / 60); ?>m <?php echo $dur % 60 ? ($dur % 60 . 's') : ''; ?>.
                            <?php endif; ?>
                            Max allowed: <?php echo number_format($max_unlock_duration); ?>s (<?php echo round($max_unlock_duration / 60); ?> min).
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="poll_interval" class="form-label">Command Poll Interval (seconds)</label>
                        <input type="number" class="form-control" id="poll_interval" name="poll_interval"
                               min="1" max="60" value="<?php echo htmlspecialchars($door['poll_interval'] ?? 3); ?>">
                        <div class="form-text">How often the controller checks for remote commands like unlock. Lower = faster response.</div>
                    </div>

                    <div class="mb-3">
                        <label for="reader_type" class="form-label">Reader Type</label>
                        <select class="form-select" id="reader_type" name="reader_type">
                            <option value="wiegand" <?php echo ($door['reader_type'] ?? 'wiegand') === 'wiegand' ? 'selected' : ''; ?>>
                                Wiegand (26/32/34/35/36/37/48-bit)
                            </option>
                            <option value="osdp" <?php echo ($door['reader_type'] ?? '') === 'osdp' ? 'selected' : ''; ?>>
                                OSDP (RS-485 Encrypted)
                            </option>
                            <option value="nfc_pn532" <?php echo ($door['reader_type'] ?? '') === 'nfc_pn532' ? 'selected' : ''; ?>>
                                NFC PN532 (I2C/SPI)
                            </option>
                            <option value="nfc_mfrc522" <?php echo ($door['reader_type'] ?? '') === 'nfc_mfrc522' ? 'selected' : ''; ?>>
                                NFC MFRC522 (SPI)
                            </option>
                        </select>
                        <div class="form-text">Select the type of card reader connected to this door's controller.</div>
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
                                    <p class="mb-1"><strong>Last Seen:</strong> <?php echo date('Y-m-d H:i:s', strtotime($door['last_seen'])); ?></p>
                                <?php endif; ?>
                                <?php if ($door['controller_version'] ?? ''): ?>
                                    <p class="mb-1"><strong>Version:</strong> <?php echo htmlspecialchars($door['controller_version']); ?></p>
                                <?php endif; ?>
                                <?php
                                $us = $door['update_status'] ?? '';
                                if ($us):
                                    $us_parts = explode(':', $us, 2);
                                    $us_base = trim($us_parts[0]);
                                    $us_detail = isset($us_parts[1]) ? trim($us_parts[1]) : '';
                                    $usBadge = match($us_base) {
                                        'success' => 'success',
                                        'failed' => 'danger',
                                        'updating' => 'info',
                                        default => 'secondary'
                                    };
                                ?>
                                    <p class="mb-1"><strong>Update Status:</strong>
                                        <span class="badge bg-<?php echo $usBadge; ?>"><?php echo ucfirst(htmlspecialchars($us_base)); ?></span>
                                        <?php if ($door['update_status_time'] ?? ''): ?>
                                            <small class="text-muted">(<?php echo date('Y-m-d H:i:s', strtotime($door['update_status_time'])); ?>)</small>
                                        <?php endif; ?>
                                    </p>
                                    <?php if ($us_detail): ?>
                                        <p class="mb-0 text-<?php echo $us_base === 'failed' ? 'danger' : 'muted'; ?> small"><?php echo htmlspecialchars($us_detail); ?></p>
                                    <?php endif; ?>
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
