<?php
/**
 * Add Door
 * PiDoors Access Control System
 */
$title = 'Add Door';
require_once './includes/header.php';

require_login($config);
require_admin($config);

$error_message = '';

try {
    $schedules = $pdo_access->query("SELECT id, name FROM access_schedules ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    $schedules = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } else {
        $name = sanitize_string($_POST['name'] ?? '');
        $location = sanitize_string($_POST['location'] ?? '');
        $doornum = sanitize_string($_POST['doornum'] ?? '');
        $description = sanitize_string($_POST['description'] ?? '');
        $schedule_id = validate_int($_POST['schedule_id'] ?? 0) ?: null;
        $unlock_duration = validate_int($_POST['unlock_duration'] ?? 5, 1, 60) ?: 5;
        $reader_type = sanitize_string($_POST['reader_type'] ?? 'wiegand');

        // Validate reader_type
        $valid_reader_types = ['wiegand', 'osdp', 'nfc_pn532', 'nfc_mfrc522'];
        if (!in_array($reader_type, $valid_reader_types)) {
            $reader_type = 'wiegand';
        }

        if (empty($name)) {
            $error_message = 'Door name is required.';
        } else {
            try {
                $stmt = $pdo_access->prepare("
                    INSERT INTO doors (name, location, doornum, description, schedule_id, unlock_duration, reader_type, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'unknown')
                ");
                $stmt->execute([$name, $location, $doornum, $description, $schedule_id, $unlock_duration, $reader_type]);

                header("Location: {$config['url']}/doors.php?success=Door added successfully.");
                exit();
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error_message = 'A door with this name already exists.';
                } else {
                    error_log("Add door error: " . $e->getMessage());
                    $error_message = 'Failed to add door.';
                }
            }
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Add New Door</h5>
            </div>
            <div class="card-body">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <form method="post">
                    <?php echo csrf_field(); ?>

                    <div class="mb-3">
                        <label for="name" class="form-label">Door Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required
                               value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                               placeholder="e.g., frontdoor, backgate">
                        <div class="form-text">Unique identifier used in configuration files.</div>
                    </div>

                    <div class="mb-3">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" class="form-control" id="location" name="location"
                               value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>"
                               placeholder="e.g., Building A, Floor 1">
                    </div>

                    <div class="mb-3">
                        <label for="doornum" class="form-label">Door Number</label>
                        <input type="text" class="form-control" id="doornum" name="doornum"
                               value="<?php echo htmlspecialchars($_POST['doornum'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="schedule_id" class="form-label">Access Schedule</label>
                        <select class="form-select" id="schedule_id" name="schedule_id">
                            <option value="">Always accessible</option>
                            <?php foreach ($schedules as $schedule): ?>
                                <option value="<?php echo $schedule['id']; ?>">
                                    <?php echo htmlspecialchars($schedule['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="unlock_duration" class="form-label">Unlock Duration (seconds)</label>
                        <input type="number" class="form-control" id="unlock_duration" name="unlock_duration"
                               min="1" max="60" value="<?php echo htmlspecialchars($_POST['unlock_duration'] ?? '5'); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="reader_type" class="form-label">Reader Type</label>
                        <select class="form-select" id="reader_type" name="reader_type">
                            <option value="wiegand" <?php echo ($_POST['reader_type'] ?? 'wiegand') === 'wiegand' ? 'selected' : ''; ?>>
                                Wiegand (26/32/34/35/36/37/48-bit)
                            </option>
                            <option value="osdp" <?php echo ($_POST['reader_type'] ?? '') === 'osdp' ? 'selected' : ''; ?>>
                                OSDP (RS-485 Encrypted)
                            </option>
                            <option value="nfc_pn532" <?php echo ($_POST['reader_type'] ?? '') === 'nfc_pn532' ? 'selected' : ''; ?>>
                                NFC PN532 (I2C/SPI)
                            </option>
                            <option value="nfc_mfrc522" <?php echo ($_POST['reader_type'] ?? '') === 'nfc_mfrc522' ? 'selected' : ''; ?>>
                                NFC MFRC522 (SPI)
                            </option>
                        </select>
                        <div class="form-text">Select the type of card reader connected to this door's controller.</div>
                    </div>

                    <div class="d-flex justify-content-between">
                        <a href="doors.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Add Door</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
