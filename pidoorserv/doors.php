<?php
/**
 * Doors Management
 * PiDoors Access Control System
 */

// AJAX endpoint: return door status as JSON (no HTML)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'door_status') {
    $config = include(__DIR__ . '/includes/config.php');
    require_once __DIR__ . '/includes/security.php';
    require_once $config['apppath'] . 'database/db_connection.php';
    secure_session_start($config);

    if (!is_logged_in() || !is_admin()) {
        http_response_code(403);
        exit();
    }

    header('Content-Type: application/json');

    $version_file = $config['apppath'] . 'VERSION';
    $target = file_exists($version_file) ? trim(file_get_contents($version_file)) : '';

    try {
        $stmt = $pdo_access->query("
            SELECT d.*, s.name as schedule_name
            FROM doors d
            LEFT JOIN access_schedules s ON d.schedule_id = s.id
            ORDER BY d.name
        ");
        $doors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $doors = [];
    }

    echo json_encode(['target_version' => $target, 'doors' => $doors]);
    exit();
}

$title = 'Doors';
require_once './includes/header.php';

require_admin($config);

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

// Fetch max unlock duration and target controller version from settings
try {
    $max_unlock_stmt = $pdo_access->prepare("SELECT setting_value FROM settings WHERE setting_key = 'max_unlock_duration'");
    $max_unlock_stmt->execute();
    $max_unlock_duration = (int)($max_unlock_stmt->fetchColumn() ?: 3600);
} catch (PDOException $e) {
    $max_unlock_duration = 3600;
}

// Controllers should match the server version
$version_file = $config['apppath'] . 'VERSION';
$target_controller_version = file_exists($version_file) ? trim(file_get_contents($version_file)) : '';

// Handle "Request Update" actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_update'])) {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        try {
            if ($_POST['request_update'] === 'all') {
                // Request update for all online doors
                $pdo_access->exec("UPDATE doors SET update_requested = 1 WHERE status = 'online'");
                header("Location: {$config['url']}/doors.php?success=Update requested for all online controllers.");
                exit();
            } else {
                $door_to_update = sanitize_string($_POST['request_update']);
                $stmt = $pdo_access->prepare("UPDATE doors SET update_requested = 1 WHERE name = ?");
                $stmt->execute([$door_to_update]);
                header("Location: {$config['url']}/doors.php?success=Update requested for " . htmlspecialchars($door_to_update) . ".");
                exit();
            }
        } catch (PDOException $e) {
            error_log("Request update error: " . $e->getMessage());
        }
    }
}

// Handle remote unlock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unlock') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $door_name = sanitize_string($_POST['door'] ?? '');
        try {
            // Verify door exists and is online
            $stmt = $pdo_access->prepare("SELECT status FROM doors WHERE name = ?");
            $stmt->execute([$door_name]);
            $door_check = $stmt->fetch();
            if ($door_check && $door_check['status'] === 'online') {
                $stmt = $pdo_access->prepare("UPDATE doors SET unlock_requested = 1 WHERE name = ?");
                $stmt->execute([$door_name]);
                log_security_event($pdo, 'remote_unlock', $_SESSION['user_id'], "Remote unlock requested for door: $door_name");
                header("Location: {$config['url']}/doors.php?success=Unlock command sent to " . urlencode($door_name) . ".");
                exit();
            } else {
                header("Location: {$config['url']}/doors.php?error=Door is not online.");
                exit();
            }
        } catch (PDOException $e) {
            error_log("Remote unlock error: " . $e->getMessage());
        }
    }
}

// Handle add door form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['request_update']) && !(isset($_POST['action']) && $_POST['action'] === 'unlock')) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } else {
        $name = sanitize_string($_POST['name'] ?? '');
        $location = sanitize_string($_POST['location'] ?? '');
        $doornum = trim($_POST['doornum'] ?? '') !== '' ? validate_int($_POST['doornum'] ?? '') : null;
        $description = sanitize_string($_POST['description'] ?? '');
        $schedule_id = validate_int($_POST['schedule_id'] ?? 0) ?: null;
        $unlock_duration = validate_int($_POST['unlock_duration'] ?? 5, 1, $max_unlock_duration) ?: 5;
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

<div class="d-flex justify-content-between align-items-center mb-3" id="doors-header">
    <div><span class="text-muted" id="doors-count"><?php echo count($doors); ?> doors configured</span></div>
    <div class="d-flex gap-2">
        <?php
        // Check if any online doors are outdated
        $has_outdated = false;
        if ($target_controller_version) {
            foreach ($doors as $d) {
                $cv = $d['controller_version'] ?? '';
                if ($d['status'] === 'online' && $cv && version_compare($cv, $target_controller_version, '<')) {
                    $has_outdated = true;
                    break;
                }
            }
        }
        if ($has_outdated): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Request update for ALL online controllers?');">
                <?php echo csrf_field(); ?>
                <button type="submit" name="request_update" value="all" class="btn btn-warning">
                    Update All Controllers
                </button>
            </form>
        <?php endif; ?>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDoorModal">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Add Door
        </button>
    </div>
</div>

<div class="row" id="doors-container">
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
                    <p class="mb-1">
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
                    <?php
                    $cv = $door['controller_version'] ?? '';
                    $is_outdated = false;
                    if ($cv) {
                        $is_outdated = $target_controller_version && version_compare($cv, $target_controller_version, '<');
                    }
                    ?>
                    <p class="mb-0">
                        <strong>Version:</strong>
                        <?php if ($cv): ?>
                            <?php echo htmlspecialchars($cv); ?>
                            <?php if ($is_outdated): ?>
                                <span class="badge bg-warning text-dark">Outdated</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">Not reported</span>
                        <?php endif; ?>
                        <?php
                        $us = $door['update_status'] ?? '';
                        if ($us):
                            // Status may contain detail after colon, e.g. "failed: reason"
                            $us_base = explode(':', $us, 2)[0];
                            $us_detail = isset(explode(':', $us, 2)[1]) ? trim(explode(':', $us, 2)[1]) : '';
                            $usBadge = match(trim($us_base)) {
                                'success' => 'success',
                                'failed' => 'danger',
                                'updating' => 'info',
                                default => 'secondary'
                            };
                        ?>
                            <span class="badge bg-<?php echo $usBadge; ?>" <?php if ($us_detail): ?>title="<?php echo htmlspecialchars($us_detail); ?>" data-bs-toggle="tooltip"<?php endif; ?>><?php echo ucfirst(htmlspecialchars(trim($us_base))); ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="card-footer d-flex justify-content-between">
                    <div>
                        <a href="editdoor.php?name=<?php echo urlencode($door['name']); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                        <?php if ($door['status'] === 'online'): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Unlock this door remotely?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="unlock">
                                <input type="hidden" name="door" value="<?php echo htmlspecialchars($door['name']); ?>">
                                <button type="submit" class="btn btn-sm btn-outline-warning">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 9.9-1"></path></svg>Unlock
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if ($is_outdated && $door['status'] === 'online' && !$door['update_requested']): ?>
                            <form method="post" class="d-inline">
                                <?php echo csrf_field(); ?>
                                <button type="submit" name="request_update" value="<?php echo htmlspecialchars($door['name']); ?>" class="btn btn-sm btn-outline-warning">Request Update</button>
                            </form>
                        <?php elseif ($door['update_requested']): ?>
                            <span class="badge bg-info">Update Pending</span>
                        <?php endif; ?>
                    </div>
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
                                   min="1" max="<?php echo $max_unlock_duration; ?>" value="<?php echo htmlspecialchars($_POST['unlock_duration'] ?? '5'); ?>">
                            <div class="form-text">Max: <?php echo number_format($max_unlock_duration); ?>s (<?php echo round($max_unlock_duration / 60); ?> min)</div>
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

<script>
(function() {
    var csrfToken = '<?php echo htmlspecialchars(generate_csrf_token()); ?>';
    var pollTimer = null;

    function versionCompare(a, b) {
        var pa = a.split('.').map(Number), pb = b.split('.').map(Number);
        for (var i = 0; i < Math.max(pa.length, pb.length); i++) {
            var na = pa[i] || 0, nb = pb[i] || 0;
            if (na < nb) return -1;
            if (na > nb) return 1;
        }
        return 0;
    }

    function escHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    function ucfirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

    function statusBadgeClass(status) {
        switch (status) {
            case 'online': return 'success';
            case 'offline': return 'danger';
            default: return 'secondary';
        }
    }

    function readerLabel(type) {
        var labels = {wiegand:'Wiegand', osdp:'OSDP', nfc_pn532:'NFC PN532', nfc_mfrc522:'NFC MFRC522'};
        return labels[type] || type || 'wiegand';
    }

    function formatDate(str) {
        if (!str) return '';
        var d = new Date(str);
        return d.toLocaleString([], {month:'short', day:'numeric', hour:'numeric', minute:'2-digit'});
    }

    function refreshDoors() {
        $.getJSON('doors.php?ajax=door_status', function(data) {
            if (!data.doors) return;
            var targetVersion = data.target_version || '';
            var container = $('#doors-container');
            var hasOutdated = false;
            var html = '';

            $.each(data.doors, function(i, door) {
                var status = door.status || 'unknown';
                var cv = door.controller_version || '';
                var isOutdated = cv && targetVersion && versionCompare(cv, targetVersion) < 0;
                if (isOutdated && status === 'online') hasOutdated = true;

                html += '<div class="col-md-6 col-lg-4 mb-4">';
                html += '<div class="card shadow-sm h-100">';

                // Card header
                html += '<div class="card-header d-flex justify-content-between align-items-center">';
                html += '<h5 class="mb-0">' + escHtml(door.name) + '</h5>';
                html += '<span class="badge bg-' + statusBadgeClass(status) + '">' + ucfirst(status) + '</span>';
                html += '</div>';

                // Card body
                html += '<div class="card-body">';
                html += '<p class="mb-1"><strong>Location:</strong> ' + escHtml(door.location || 'N/A') + '</p>';
                html += '<p class="mb-1"><strong>Description:</strong> ' + escHtml(door.description || 'N/A') + '</p>';
                if (door.ip_address) html += '<p class="mb-1"><strong>IP:</strong> ' + escHtml(door.ip_address) + '</p>';
                if (door.last_seen) html += '<p class="mb-1"><strong>Last Seen:</strong> ' + formatDate(door.last_seen) + '</p>';

                // Lock status
                html += '<p class="mb-1"><strong>Lock Status:</strong> ';
                if (door.locked !== null && door.locked !== undefined) {
                    var locked = parseInt(door.locked);
                    html += '<span class="badge ' + (locked ? 'bg-success' : 'bg-warning') + '">' + (locked ? 'Locked' : 'Unlocked') + '</span>';
                } else {
                    html += '<span class="badge bg-secondary">Unknown</span>';
                }
                html += '</p>';

                if (door.schedule_name) html += '<p class="mb-1"><strong>Schedule:</strong> ' + escHtml(door.schedule_name) + '</p>';
                html += '<p class="mb-1"><strong>Reader:</strong> ' + escHtml(readerLabel(door.reader_type)) + '</p>';

                // Version
                html += '<p class="mb-0"><strong>Version:</strong> ';
                if (cv) {
                    html += escHtml(cv);
                    if (isOutdated) html += ' <span class="badge bg-warning text-dark">Outdated</span>';
                } else {
                    html += '<span class="text-muted">Not reported</span>';
                }

                // Update status
                var us = door.update_status || '';
                if (us) {
                    var parts = us.split(':', 2);
                    var usBase = parts[0].trim();
                    var usDetail = parts.length > 1 ? parts.slice(1).join(':').trim() : '';
                    var usBadge = usBase === 'success' ? 'success' : usBase === 'failed' ? 'danger' : usBase === 'updating' ? 'info' : 'secondary';
                    html += ' <span class="badge bg-' + usBadge + '"';
                    if (usDetail) html += ' title="' + escHtml(usDetail) + '" data-bs-toggle="tooltip"';
                    html += '>' + ucfirst(escHtml(usBase)) + '</span>';
                }
                html += '</p>';
                html += '</div>';

                // Card footer
                html += '<div class="card-footer d-flex justify-content-between">';
                html += '<div>';
                html += '<a href="editdoor.php?name=' + encodeURIComponent(door.name) + '" class="btn btn-sm btn-outline-primary">Edit</a> ';
                if (status === 'online') {
                    html += '<form method="post" class="d-inline" onsubmit="return confirm(\'Unlock this door remotely?\');">';
                    html += '<input type="hidden" name="csrf_token" value="' + csrfToken + '">';
                    html += '<input type="hidden" name="action" value="unlock">';
                    html += '<input type="hidden" name="door" value="' + escHtml(door.name) + '">';
                    html += '<button type="submit" class="btn btn-sm btn-outline-warning">';
                    html += '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 9.9-1"></path></svg>Unlock</button>';
                    html += '</form> ';
                }
                if (isOutdated && status === 'online' && !parseInt(door.update_requested)) {
                    html += '<form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="' + csrfToken + '">';
                    html += '<button type="submit" name="request_update" value="' + escHtml(door.name) + '" class="btn btn-sm btn-outline-warning">Request Update</button></form>';
                } else if (parseInt(door.update_requested)) {
                    html += '<span class="badge bg-info">Update Pending</span>';
                }
                html += '</div>';
                html += '<a href="doors.php?delete=' + encodeURIComponent(door.name) + '&token=' + csrfToken + '" class="btn btn-sm btn-outline-danger" onclick="return confirmDelete(\'Delete this door?\');">Delete</a>';
                html += '</div>';

                html += '</div></div>';
            });

            if (data.doors.length === 0) {
                html += '<div class="col-12"><div class="alert alert-info">No doors configured. Click "Add Door" to get started.</div></div>';
            }

            container.html(html);
            $('#doors-count').text(data.doors.length + ' doors configured');

            // Re-init tooltips
            $('[data-bs-toggle="tooltip"]').each(function() { new bootstrap.Tooltip(this); });
        });
    }

    pollTimer = setInterval(refreshDoors, 5000);

    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            clearInterval(pollTimer);
        } else {
            refreshDoors();
            pollTimer = setInterval(refreshDoors, 5000);
        }
    });
})();
</script>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
