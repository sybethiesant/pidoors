<?php
/**
 * Backup & Restore
 * PiDoors Access Control System
 */
$title = 'Backup & Restore';
require_once './includes/header.php';

require_login($config);
require_admin($config);

$error_message = '';
$success_message = '';
$backup_dir = $config['apppath'] . 'backups/';

// Create backup directory if it doesn't exist
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0750, true);
}

// Handle backup creation
if (isset($_POST['create_backup']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    try {
        $timestamp = date('Y-m-d_His');
        $backup_file = $backup_dir . "pidoors_backup_{$timestamp}.sql";

        // Get database credentials from config
        $db_host = $config['mysql']['server'];
        $db_user = $config['mysql']['user'];
        $db_pass = $config['mysql']['password'];

        // Backup both databases
        $databases = ['users', 'access'];
        $backup_content = "-- PiDoors Backup\n-- Created: " . date('Y-m-d H:i:s') . "\n\n";

        foreach ($databases as $db_name) {
            $backup_content .= "-- Database: {$db_name}\n";
            $backup_content .= "CREATE DATABASE IF NOT EXISTS `{$db_name}`;\nUSE `{$db_name}`;\n\n";

            // Get connection for this database
            $pdo_backup = $db_name === 'users' ? $pdo : $pdo_access;

            // Get tables
            $tables = $pdo_backup->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                // Get CREATE TABLE statement
                $create = $pdo_backup->query("SHOW CREATE TABLE `{$table}`")->fetch();
                $backup_content .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $backup_content .= $create['Create Table'] . ";\n\n";

                // Get data
                $rows = $pdo_backup->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $columns = array_keys($rows[0]);
                    $backup_content .= "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES\n";

                    $values = [];
                    foreach ($rows as $row) {
                        $row_values = [];
                        foreach ($row as $value) {
                            if ($value === null) {
                                $row_values[] = 'NULL';
                            } else {
                                $row_values[] = $pdo_backup->quote($value);
                            }
                        }
                        $values[] = "(" . implode(', ', $row_values) . ")";
                    }
                    $backup_content .= implode(",\n", $values) . ";\n\n";
                }
            }
        }

        // Write backup file
        file_put_contents($backup_file, $backup_content);

        // Compress if possible
        if (function_exists('gzencode')) {
            $gz_file = $backup_file . '.gz';
            file_put_contents($gz_file, gzencode($backup_content, 9));
            unlink($backup_file);
            $backup_file = $gz_file;
        }

        $success_message = 'Backup created successfully: ' . basename($backup_file);

        // Log the backup
        log_security_event($pdo, 'backup_created', $_SESSION['user_id'] ?? null, basename($backup_file));

    } catch (Exception $e) {
        error_log("Backup error: " . $e->getMessage());
        $error_message = 'Failed to create backup: ' . $e->getMessage();
    }
}

// Handle backup download
if (isset($_GET['download']) && verify_csrf_token($_GET['token'] ?? '')) {
    $file = sanitize_string($_GET['download']);
    $filepath = $backup_dir . $file;

    // Security check - ensure file is in backup directory
    if (strpos(realpath($filepath), realpath($backup_dir)) === 0 && file_exists($filepath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit();
    } else {
        $error_message = 'Backup file not found.';
    }
}

// Handle backup deletion
if (isset($_GET['delete']) && verify_csrf_token($_GET['token'] ?? '')) {
    $file = sanitize_string($_GET['delete']);
    $filepath = $backup_dir . $file;

    // Security check
    if (strpos(realpath($filepath), realpath($backup_dir)) === 0 && file_exists($filepath)) {
        unlink($filepath);
        header("Location: {$config['url']}/backup.php?success=Backup deleted.");
        exit();
    } else {
        $error_message = 'Backup file not found.';
    }
}

// Get list of backups
$backups = [];
if (is_dir($backup_dir)) {
    $files = scandir($backup_dir, SCANDIR_SORT_DESCENDING);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && (str_ends_with($file, '.sql') || str_ends_with($file, '.sql.gz'))) {
            $filepath = $backup_dir . $file;
            $backups[] = [
                'name' => $file,
                'size' => filesize($filepath),
                'date' => filemtime($filepath),
            ];
        }
    }
}
?>

<div class="row">
    <div class="col-lg-8">
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Available Backups</h5>
                <form method="post" class="d-inline">
                    <?php echo csrf_field(); ?>
                    <button type="submit" name="create_backup" class="btn btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                        Create Backup
                    </button>
                </form>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Filename</th>
                            <th>Size</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup): ?>
                            <tr>
                                <td>
                                    <code><?php echo htmlspecialchars($backup['name']); ?></code>
                                </td>
                                <td><?php echo number_format($backup['size'] / 1024, 1); ?> KB</td>
                                <td><?php echo date('Y-m-d H:i:s', $backup['date']); ?></td>
                                <td>
                                    <a href="?download=<?php echo urlencode($backup['name']); ?>&token=<?php echo htmlspecialchars($csrf_token); ?>"
                                       class="btn btn-sm btn-outline-primary">Download</a>
                                    <a href="?delete=<?php echo urlencode($backup['name']); ?>&token=<?php echo htmlspecialchars($csrf_token); ?>"
                                       class="btn btn-sm btn-outline-danger"
                                       onclick="return confirmDelete('Delete this backup?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($backups)): ?>
                            <tr><td colspan="4" class="text-center text-muted">No backups found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-warning">
                <h5 class="mb-0">Restore from Backup</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-danger">
                    <strong>Warning:</strong> Restoring a backup will overwrite all current data. This action cannot be undone.
                </div>
                <p class="small">To restore from a backup:</p>
                <ol class="small">
                    <li>Download the backup file you want to restore</li>
                    <li>Access the MySQL command line or phpMyAdmin</li>
                    <li>Import the SQL file into your databases</li>
                </ol>
                <p class="small text-muted">For security reasons, automatic restore is disabled. Please restore manually using database tools.</p>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0">Backup Information</h5>
            </div>
            <div class="card-body">
                <p class="small"><strong>Backup Location:</strong><br><code><?php echo htmlspecialchars($backup_dir); ?></code></p>
                <p class="small"><strong>Total Backups:</strong> <?php echo count($backups); ?></p>
                <p class="small mb-0"><strong>Total Size:</strong>
                    <?php
                    $total_size = array_sum(array_column($backups, 'size'));
                    echo number_format($total_size / 1024 / 1024, 2) . ' MB';
                    ?>
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
