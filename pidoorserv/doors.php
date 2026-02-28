<?php
/**
 * Doors Management
 * PiDoors Access Control System
 */
$title = 'Doors';
require_once './includes/header.php';

require_login($config);

$error_message = '';
$show_modal = false;

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

// Fetch schedules for the modal dropdown
try {
    $schedules = $pdo_access->query("SELECT id, name FROM access_schedules ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    $schedules = [];
}

// Handle add door form
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

        $valid_reader_types = ['wiegand', 'osdp', 'nfc_pn532', 'nfc_mfrc522'];
        if (!in_array($reader_type, $valid_reader_types)) {
            $reader_type = 'wiegand';
        }

        // Normalize door name: lowercase, spaces to underscores, alphanumeric+underscore only
        $name = strtolower(trim($name));
        $name = preg_replace('/\s+/', '_', $name);
        $name = preg_replace('/[^a-z0-9_]/', '', $name);

        if (empty($name)) {
            $error_message = 'Door name is required (letters, numbers, underscores only).';
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
    $show_modal = true;
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
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDoorModal">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add Door
    </button>
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
                        <p class="mb-1"><strong>Schedule:</strong> <?php echo htmlspecialchars($door['schedule_name']); ?></p>
                    <?php endif; ?>
                    <p class="mb-0">
                        <strong>Reader:</strong>
                        <?php
                        $reader_type = $door['reader_type'] ?? 'wiegand';
                        $reader_labels = [
                            'wiegand' => 'Wiegand',
                            'osdp' => 'OSDP',
                            'nfc_pn532' => 'NFC PN532',
                            'nfc_mfrc522' => 'NFC MFRC522'
                        ];
                        echo htmlspecialchars($reader_labels[$reader_type] ?? $reader_type);
                        ?>
                    </p>
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
            <div class="alert alert-info">No doors configured. Click "Add Door" to get started.</div>
        </div>
    <?php endif; ?>
</div>

<!-- Add Door Modal -->
<div class="modal fade" id="addDoorModal" tabindex="-1" aria-labelledby="addDoorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addDoorModalLabel">Add New Door</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                    <?php endif; ?>

                    <?php echo csrf_field(); ?>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="name" class="form-label">Door Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" required
                                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                   placeholder="e.g., frontdoor, backgate">
                            <div class="form-text">Unique identifier used in configuration files.</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="location" class="form-label">Location</label>
                            <input type="text" class="form-control" id="location" name="location"
                                   value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>"
                                   placeholder="e.g., Building A, Floor 1">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="doornum" class="form-label">Door Number</label>
                            <input type="text" class="form-control" id="doornum" name="doornum"
                                   value="<?php echo htmlspecialchars($_POST['doornum'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="unlock_duration" class="form-label">Unlock Duration (seconds)</label>
                            <input type="number" class="form-control" id="unlock_duration" name="unlock_duration"
                                   min="1" max="60" value="<?php echo htmlspecialchars($_POST['unlock_duration'] ?? '5'); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
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
                        <div class="col-md-6 mb-3">
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
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Door</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($show_modal): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        new bootstrap.Modal(document.getElementById('addDoorModal')).show();
    });
</script>
<?php endif; ?>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
