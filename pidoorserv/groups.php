<?php
/**
 * Access Groups Management
 * PiDoors Access Control System
 */
$title = 'Access Groups';
require_once './includes/header.php';

require_login($config);

// Handle delete
if (isset($_GET['delete']) && isset($_GET['token'])) {
    if (verify_csrf_token($_GET['token'])) {
        $id = validate_int($_GET['delete']);
        if ($id) {
            try {
                // Check if group has assigned cards
                $stmt = $pdo_access->prepare("SELECT COUNT(*) FROM cards WHERE group_id = ?");
                $stmt->execute([$id]);
                if ($stmt->fetchColumn() > 0) {
                    header("Location: {$config['url']}/groups.php?error=" . urlencode('Cannot delete group with assigned cards.'));
                    exit();
                }

                $stmt = $pdo_access->prepare("DELETE FROM access_groups WHERE id = ?");
                $stmt->execute([$id]);
                header("Location: {$config['url']}/groups.php?success=Group deleted.");
                exit();
            } catch (PDOException $e) {
                error_log("Delete group error: " . $e->getMessage());
            }
        }
    }
}

// Handle add/edit
$editing = null;
$error_message = '';
$show_modal = false;

if (isset($_GET['edit'])) {
    $edit_id = validate_int($_GET['edit']);
    if ($edit_id) {
        $stmt = $pdo_access->prepare("SELECT * FROM access_groups WHERE id = ?");
        $stmt->execute([$edit_id]);
        $editing = $stmt->fetch();
        if ($editing) $show_modal = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } else {
        $id = validate_int($_POST['id'] ?? 0);
        $name = sanitize_string($_POST['name'] ?? '');
        $description = sanitize_string($_POST['description'] ?? '');
        $post_doors = $_POST['doors'] ?? [];

        // Validate doors array
        $valid_doors = [];
        foreach ($post_doors as $door) {
            $door = sanitize_string($door);
            if (!empty($door)) {
                $valid_doors[] = $door;
            }
        }
        $doors_json = json_encode($valid_doors);

        if (empty($name)) {
            $error_message = 'Group name is required.';
        } else {
            try {
                if ($id) {
                    // Update
                    $stmt = $pdo_access->prepare("UPDATE access_groups SET name = ?, description = ?, doors = ? WHERE id = ?");
                    $stmt->execute([$name, $description, $doors_json, $id]);
                    $message = 'Group updated successfully.';
                } else {
                    // Insert
                    $stmt = $pdo_access->prepare("INSERT INTO access_groups (name, description, doors) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $description, $doors_json]);
                    $message = 'Group added successfully.';
                }
                header("Location: {$config['url']}/groups.php?success=" . urlencode($message));
                exit();
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error_message = 'A group with this name already exists.';
                } else {
                    error_log("Group error: " . $e->getMessage());
                    $error_message = 'Failed to save group.';
                }
            }
        }
    }
    $show_modal = true;
}

// Fetch groups with member count
try {
    $groups = $pdo_access->query("
        SELECT g.*, COUNT(c.id) as member_count
        FROM access_groups g
        LEFT JOIN cards c ON g.id = c.group_id
        GROUP BY g.id
        ORDER BY g.name
    ")->fetchAll();

    $doors = $pdo_access->query("SELECT name FROM doors ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $groups = [];
    $doors = [];
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div><span class="text-muted"><?php echo count($groups); ?> groups</span></div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#groupModal">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add Group
    </button>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Doors</th>
                        <th>Members</th>
                        <th width="120">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $group): ?>
                        <?php $group_doors = json_decode($group['doors'] ?? '[]', true) ?: []; ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($group['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($group['description'] ?? ''); ?></td>
                            <td>
                                <?php if (empty($group_doors)): ?>
                                    <span class="text-muted">All doors</span>
                                <?php else: ?>
                                    <?php foreach ($group_doors as $door): ?>
                                        <span class="badge bg-secondary me-1"><?php echo htmlspecialchars($door); ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-info"><?php echo $group['member_count']; ?></span></td>
                            <td>
                                <a href="?edit=<?php echo $group['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                <a href="?delete=<?php echo $group['id']; ?>&token=<?php echo htmlspecialchars($csrf_token); ?>"
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirmDelete('Delete this group?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($groups)): ?>
                        <tr><td colspan="5" class="text-muted text-center">No access groups defined.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Group Modal -->
<div class="modal fade" id="groupModal" tabindex="-1" aria-labelledby="groupModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="groupModalLabel"><?php echo $editing ? 'Edit Group' : 'Add Group'; ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                    <?php endif; ?>

                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo $editing['id'] ?? ''; ?>">

                    <div class="mb-3">
                        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required
                               value="<?php echo htmlspecialchars($editing['name'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?php echo htmlspecialchars($editing['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Allowed Doors</label>
                        <div class="form-text mb-2">Leave unchecked for access to all doors.</div>
                        <?php
                        $selected_doors = [];
                        if ($editing) {
                            $selected_doors = json_decode($editing['doors'] ?? '[]', true) ?: [];
                        }
                        ?>
                        <?php foreach ($doors as $door): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="doors[]"
                                       value="<?php echo htmlspecialchars($door); ?>"
                                       id="door_<?php echo htmlspecialchars($door); ?>"
                                       <?php echo in_array($door, $selected_doors) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="door_<?php echo htmlspecialchars($door); ?>">
                                    <?php echo htmlspecialchars($door); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($doors)): ?>
                            <div class="text-muted">No doors configured.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><?php echo $editing ? 'Update' : 'Add Group'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($show_modal): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        new bootstrap.Modal(document.getElementById('groupModal')).show();
    });
</script>
<?php endif; ?>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
