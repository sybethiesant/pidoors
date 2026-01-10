<?php
/**
 * Import Cards from CSV
 * PiDoors Access Control System
 */
$title = 'Import Cards';
require_once './includes/header.php';

require_login($config);
require_admin($config);

$error_message = '';
$success_message = '';
$import_results = [];

// Get groups and schedules for mapping
try {
    $groups = $pdo_access->query("SELECT id, name FROM access_groups ORDER BY name")->fetchAll();
    $schedules = $pdo_access->query("SELECT id, name FROM access_schedules ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    $groups = [];
    $schedules = [];
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } else {
        $file = $_FILES['csv_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_message = 'File upload failed.';
        } elseif ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
            $error_message = 'File is too large. Maximum size is 5MB.';
        } else {
            $mime = mime_content_type($file['tmp_name']);
            if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv'])) {
                $error_message = 'Invalid file type. Please upload a CSV file.';
            } else {
                // Process CSV
                $handle = fopen($file['tmp_name'], 'r');
                if ($handle) {
                    $header = fgetcsv($handle);
                    if (!$header) {
                        $error_message = 'CSV file is empty or invalid.';
                    } else {
                        // Normalize headers
                        $header = array_map('strtolower', array_map('trim', $header));

                        // Map columns
                        $col_map = [];
                        $required_cols = ['card_id', 'user_id'];
                        $optional_cols = ['firstname', 'lastname', 'group_id', 'schedule_id', 'valid_from', 'valid_until', 'pin_code'];

                        foreach ($required_cols as $col) {
                            $idx = array_search($col, $header);
                            if ($idx === false) {
                                $error_message = "Missing required column: {$col}";
                                break;
                            }
                            $col_map[$col] = $idx;
                        }

                        if (!$error_message) {
                            foreach ($optional_cols as $col) {
                                $idx = array_search($col, $header);
                                if ($idx !== false) {
                                    $col_map[$col] = $idx;
                                }
                            }

                            $default_group = validate_int($_POST['default_group'] ?? 0) ?: null;
                            $default_schedule = validate_int($_POST['default_schedule'] ?? 0) ?: null;
                            $skip_duplicates = isset($_POST['skip_duplicates']);

                            $imported = 0;
                            $skipped = 0;
                            $errors = 0;
                            $line_num = 1;

                            $pdo_access->beginTransaction();

                            try {
                                $insert_stmt = $pdo_access->prepare("
                                    INSERT INTO cards (card_id, user_id, firstname, lastname, group_id, schedule_id, valid_from, valid_until, pin_code)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ");

                                while (($row = fgetcsv($handle)) !== false) {
                                    $line_num++;

                                    if (count($row) < count($required_cols)) {
                                        $import_results[] = "Line {$line_num}: Skipped - insufficient columns";
                                        $skipped++;
                                        continue;
                                    }

                                    $card_id = sanitize_string($row[$col_map['card_id']] ?? '');
                                    $user_id = sanitize_string($row[$col_map['user_id']] ?? '');

                                    if (empty($card_id) || empty($user_id)) {
                                        $import_results[] = "Line {$line_num}: Skipped - missing card_id or user_id";
                                        $skipped++;
                                        continue;
                                    }

                                    // Check for duplicate
                                    if ($skip_duplicates) {
                                        $check = $pdo_access->prepare("SELECT id FROM cards WHERE card_id = ? OR user_id = ?");
                                        $check->execute([$card_id, $user_id]);
                                        if ($check->fetch()) {
                                            $import_results[] = "Line {$line_num}: Skipped - duplicate card_id or user_id";
                                            $skipped++;
                                            continue;
                                        }
                                    }

                                    $firstname = sanitize_string($row[$col_map['firstname'] ?? -1] ?? '');
                                    $lastname = sanitize_string($row[$col_map['lastname'] ?? -1] ?? '');
                                    $group_id = validate_int($row[$col_map['group_id'] ?? -1] ?? 0) ?: $default_group;
                                    $schedule_id = validate_int($row[$col_map['schedule_id'] ?? -1] ?? 0) ?: $default_schedule;
                                    $valid_from = sanitize_string($row[$col_map['valid_from'] ?? -1] ?? '') ?: null;
                                    $valid_until = sanitize_string($row[$col_map['valid_until'] ?? -1] ?? '') ?: null;
                                    $pin_code = sanitize_string($row[$col_map['pin_code'] ?? -1] ?? '') ?: null;

                                    try {
                                        $insert_stmt->execute([
                                            $card_id, $user_id, $firstname, $lastname,
                                            $group_id, $schedule_id, $valid_from, $valid_until, $pin_code
                                        ]);
                                        $imported++;
                                    } catch (PDOException $e) {
                                        if ($e->getCode() == 23000) {
                                            $import_results[] = "Line {$line_num}: Failed - duplicate entry";
                                            $skipped++;
                                        } else {
                                            $import_results[] = "Line {$line_num}: Error - " . $e->getMessage();
                                            $errors++;
                                        }
                                    }
                                }

                                $pdo_access->commit();
                                $success_message = "Import complete: {$imported} imported, {$skipped} skipped, {$errors} errors.";

                                log_security_event($pdo, 'cards_imported', $_SESSION['user_id'] ?? null, "{$imported} cards imported from CSV");

                            } catch (Exception $e) {
                                $pdo_access->rollBack();
                                $error_message = 'Import failed: ' . $e->getMessage();
                            }
                        }
                    }
                    fclose($handle);
                }
            }
        }
    }
}
?>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Import Cards from CSV</h5>
            </div>
            <div class="card-body">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>
                <?php if ($success_message): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>

                    <div class="mb-3">
                        <label for="csv_file" class="form-label">CSV File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                        <div class="form-text">Maximum file size: 5MB</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="default_group" class="form-label">Default Access Group</label>
                            <select class="form-select" id="default_group" name="default_group">
                                <option value="">None</option>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo $group['id']; ?>">
                                        <?php echo htmlspecialchars($group['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Applied when group_id is not in CSV</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="default_schedule" class="form-label">Default Schedule</label>
                            <select class="form-select" id="default_schedule" name="default_schedule">
                                <option value="">None (24/7)</option>
                                <?php foreach ($schedules as $schedule): ?>
                                    <option value="<?php echo $schedule['id']; ?>">
                                        <?php echo htmlspecialchars($schedule['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Applied when schedule_id is not in CSV</div>
                        </div>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="skip_duplicates" name="skip_duplicates" checked>
                        <label class="form-check-label" for="skip_duplicates">Skip duplicate card_id/user_id entries</label>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                        Import Cards
                    </button>
                </form>

                <?php if (!empty($import_results)): ?>
                    <hr>
                    <h6>Import Details:</h6>
                    <div class="bg-light p-3" style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($import_results as $result): ?>
                            <div class="small"><?php echo htmlspecialchars($result); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h6 class="mb-0">CSV Format Requirements</h6>
            </div>
            <div class="card-body">
                <p class="small"><strong>Required columns:</strong></p>
                <ul class="small">
                    <li><code>card_id</code> - The card number (Wiegand format)</li>
                    <li><code>user_id</code> - Unique user identifier</li>
                </ul>

                <p class="small"><strong>Optional columns:</strong></p>
                <ul class="small">
                    <li><code>firstname</code> - First name</li>
                    <li><code>lastname</code> - Last name</li>
                    <li><code>group_id</code> - Access group ID</li>
                    <li><code>schedule_id</code> - Schedule ID</li>
                    <li><code>valid_from</code> - Start date (YYYY-MM-DD)</li>
                    <li><code>valid_until</code> - End date (YYYY-MM-DD)</li>
                    <li><code>pin_code</code> - Optional PIN</li>
                </ul>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h6 class="mb-0">Sample CSV</h6>
            </div>
            <div class="card-body">
                <pre class="small bg-light p-2 mb-0">card_id,user_id,firstname,lastname
12345678,U001,John,Smith
23456789,U002,Jane,Doe
34567890,U003,Bob,Wilson</pre>
                <a href="data:text/csv;charset=utf-8,card_id,user_id,firstname,lastname%0A12345678,U001,John,Smith%0A23456789,U002,Jane,Doe"
                   download="sample_cards.csv" class="btn btn-sm btn-outline-secondary mt-2">Download Sample</a>
            </div>
        </div>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
