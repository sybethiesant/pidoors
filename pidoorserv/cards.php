<?php
/**
 * Cards Management
 * PiDoors Access Control System
 */
$title = 'Cards';
require_once './includes/header.php';

// Require login
require_login($config);

$error_message = '';
$show_modal = false;

// Handle delete action
if (isset($_GET['delete']) && isset($_GET['token'])) {
    if (verify_csrf_token($_GET['token'])) {
        $card_id = sanitize_string($_GET['delete']);
        try {
            $stmt = $pdo_access->prepare("DELETE FROM cards WHERE card_id = ?");
            $stmt->execute([$card_id]);
            header("Location: {$config['url']}/cards.php?success=Card deleted successfully.");
            exit();
        } catch (PDOException $e) {
            error_log("Delete card error: " . $e->getMessage());
        }
    }
}

// Fetch doors, schedules, and groups for the add card modal
try {
    $all_doors = $pdo_access->query("SELECT name FROM doors ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $all_schedules = $pdo_access->query("SELECT id, name FROM access_schedules ORDER BY name")->fetchAll();
    $all_groups = $pdo_access->query("SELECT id, name FROM access_groups ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    $all_doors = [];
    $all_schedules = [];
    $all_groups = [];
}

// Handle add card form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } else {
        $firstname = sanitize_string($_POST['firstname'] ?? '');
        $lastname = sanitize_string($_POST['lastname'] ?? '');
        $card_id = sanitize_string($_POST['card_id'] ?? '');
        $user_id = sanitize_string($_POST['user_id'] ?? '');
        $facility = sanitize_string($_POST['facility'] ?? '');
        $selected_doors = $_POST['doors'] ?? [];
        $doors_str = implode(',', array_map('sanitize_string', $selected_doors));
        $schedule_id = validate_int($_POST['schedule_id'] ?? 0) ?: null;
        $group_id = validate_int($_POST['group_id'] ?? 0) ?: null;
        $valid_from = $_POST['valid_from'] ?? null;
        $valid_until = $_POST['valid_until'] ?? null;
        $active = isset($_POST['active']) ? 1 : 0;
        $card_email = sanitize_string($_POST['card_email'] ?? '');
        $card_phone = sanitize_string($_POST['card_phone'] ?? '');
        $card_department = sanitize_string($_POST['card_department'] ?? '');
        $card_employee_id = sanitize_string($_POST['card_employee_id'] ?? '');
        $card_company = sanitize_string($_POST['card_company'] ?? '');
        $card_title = sanitize_string($_POST['card_title'] ?? '');
        $card_notes = sanitize_string($_POST['card_notes'] ?? '');

        if (empty($user_id) || empty($facility)) {
            $error_message = 'User ID and Facility are required.';
        } else {
            try {
                if (empty($card_id)) {
                    $card_id = bin2hex(random_bytes(4));
                }

                $stmt = $pdo_access->prepare("
                    INSERT INTO cards (card_id, user_id, facility, bstr, firstname, lastname, email, phone, department, employee_id, company, title, notes, doors, active, group_id, schedule_id, valid_from, valid_until)
                    VALUES (?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $card_id, $user_id, $facility, $firstname, $lastname,
                    $card_email ?: null, $card_phone ?: null, $card_department ?: null,
                    $card_employee_id ?: null, $card_company ?: null, $card_title ?: null, $card_notes ?: null,
                    $doors_str, $active, $group_id, $schedule_id,
                    $valid_from ?: null, $valid_until ?: null
                ]);

                header("Location: {$config['url']}/cards.php?success=Card added successfully.");
                exit();
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error_message = 'A card with this ID already exists.';
                } else {
                    error_log("Add card error: " . $e->getMessage());
                    $error_message = 'Failed to add card. Please try again.';
                }
            }
        }
    }
    $show_modal = true;
}

// Fetch all cards with schedule info
try {
    $stmt = $pdo_access->query("
        SELECT c.*, s.name as schedule_name, g.name as group_name
        FROM cards c
        LEFT JOIN access_schedules s ON c.schedule_id = s.id
        LEFT JOIN access_groups g ON c.group_id = g.id
        ORDER BY c.lastname, c.firstname
    ");
    $cards = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Cards fetch error: " . $e->getMessage());
    $cards = [];
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <span class="text-muted"><?php echo count($cards); ?> total cards</span>
    </div>
    <div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCardModal">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Add Card
        </button>
        <a href="importcards.php" class="btn btn-outline-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
            Import CSV
        </a>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <table class="table table-striped table-hover datatable" id="cardsTable">
            <thead class="table-dark">
                <tr>
                    <th>Name</th>
                    <th>Card ID</th>
                    <th>Facility</th>
                    <th>Doors</th>
                    <th>Schedule</th>
                    <th>Status</th>
                    <th width="120">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cards as $card): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars(trim($card['firstname'] . ' ' . $card['lastname']) ?: 'Unnamed'); ?></strong>
                            <?php if ($card['group_name']): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($card['group_name']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <code><?php echo htmlspecialchars($card['user_id']); ?></code>
                        </td>
                        <td><?php echo htmlspecialchars($card['facility']); ?></td>
                        <td>
                            <?php
                            $cardDoors = array_filter(array_map('trim', explode(',', $card['doors'])));
                            if (empty($cardDoors)) {
                                echo '<span class="text-muted">None</span>';
                            } else {
                                foreach (array_slice($cardDoors, 0, 3) as $door) {
                                    echo '<span class="badge bg-secondary me-1">' . htmlspecialchars($door) . '</span>';
                                }
                                if (count($cardDoors) > 3) {
                                    echo '<span class="text-muted">+' . (count($cardDoors) - 3) . ' more</span>';
                                }
                            }
                            ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($card['schedule_name'] ?? 'None'); ?>
                            <?php if ($card['valid_from'] || $card['valid_until']): ?>
                                <br>
                                <small class="text-muted">
                                    <?php
                                    if ($card['valid_from']) echo 'From: ' . date('M j, Y', strtotime($card['valid_from']));
                                    if ($card['valid_from'] && $card['valid_until']) echo ' - ';
                                    if ($card['valid_until']) echo 'Until: ' . date('M j, Y', strtotime($card['valid_until']));
                                    ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($card['active'] == 1): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="editcard.php?id=<?php echo urlencode($card['card_id']); ?>"
                               class="btn btn-sm btn-outline-primary" title="Edit">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </a>
                            <a href="cards.php?delete=<?php echo urlencode($card['card_id']); ?>&token=<?php echo htmlspecialchars($csrf_token); ?>"
                               class="btn btn-sm btn-outline-danger"
                               title="Delete"
                               onclick="return confirmDelete('Are you sure you want to delete this card?');">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Card Modal -->
<div class="modal fade" id="addCardModal" tabindex="-1" aria-labelledby="addCardModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addCardModalLabel">Add New Access Card</h5>
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
                            <label for="firstname" class="form-label">First Name</label>
                            <input type="text" class="form-control" id="firstname" name="firstname"
                                   value="<?php echo htmlspecialchars($_POST['firstname'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="lastname" class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="lastname" name="lastname"
                                   value="<?php echo htmlspecialchars($_POST['lastname'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="user_id" class="form-label">User ID <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="user_id" name="user_id" required
                                   value="<?php echo htmlspecialchars($_POST['user_id'] ?? ''); ?>"
                                   placeholder="Card number from reader">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="facility" class="form-label">Facility Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="facility" name="facility" required
                                   value="<?php echo htmlspecialchars($_POST['facility'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="card_id" class="form-label">Card ID (auto if blank)</label>
                            <input type="text" class="form-control" id="card_id" name="card_id"
                                   value="<?php echo htmlspecialchars($_POST['card_id'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Door Access</label>
                        <div class="row">
                            <?php foreach ($all_doors as $door): ?>
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="doors[]"
                                               value="<?php echo htmlspecialchars($door); ?>"
                                               id="door_<?php echo htmlspecialchars($door); ?>">
                                        <label class="form-check-label" for="door_<?php echo htmlspecialchars($door); ?>">
                                            <?php echo htmlspecialchars($door); ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($all_doors)): ?>
                                <div class="col-12 text-muted">No doors configured. Add doors first.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="schedule_id" class="form-label">Access Schedule</label>
                            <select class="form-select" id="schedule_id" name="schedule_id">
                                <option value="">No restriction (24/7)</option>
                                <?php foreach ($all_schedules as $schedule): ?>
                                    <option value="<?php echo $schedule['id']; ?>">
                                        <?php echo htmlspecialchars($schedule['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="group_id" class="form-label">Access Group</label>
                            <select class="form-select" id="group_id" name="group_id">
                                <option value="">None</option>
                                <?php foreach ($all_groups as $group): ?>
                                    <option value="<?php echo $group['id']; ?>">
                                        <?php echo htmlspecialchars($group['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="valid_from" class="form-label">Valid From</label>
                            <input type="date" class="form-control" id="valid_from" name="valid_from"
                                   value="<?php echo htmlspecialchars($_POST['valid_from'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="valid_until" class="form-label">Valid Until</label>
                            <input type="date" class="form-control" id="valid_until" name="valid_until"
                                   value="<?php echo htmlspecialchars($_POST['valid_until'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="active" name="active" value="1" checked>
                        <label class="form-check-label" for="active">Active</label>
                    </div>

                    <hr>
                    <h6 class="text-muted mb-3">Cardholder Details <small>(optional)</small></h6>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="card_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="card_email" name="card_email"
                                   value="<?php echo htmlspecialchars($_POST['card_email'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="card_phone" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="card_phone" name="card_phone"
                                   value="<?php echo htmlspecialchars($_POST['card_phone'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="card_department" class="form-label">Department</label>
                            <input type="text" class="form-control" id="card_department" name="card_department"
                                   value="<?php echo htmlspecialchars($_POST['card_department'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="card_employee_id" class="form-label">Employee ID</label>
                            <input type="text" class="form-control" id="card_employee_id" name="card_employee_id"
                                   value="<?php echo htmlspecialchars($_POST['card_employee_id'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="card_company" class="form-label">Company</label>
                            <input type="text" class="form-control" id="card_company" name="card_company"
                                   value="<?php echo htmlspecialchars($_POST['card_company'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="card_title" class="form-label">Title</label>
                            <input type="text" class="form-control" id="card_title" name="card_title"
                                   value="<?php echo htmlspecialchars($_POST['card_title'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="card_notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="card_notes" name="card_notes" rows="2"><?php echo htmlspecialchars($_POST['card_notes'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Card</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($show_modal): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        new bootstrap.Modal(document.getElementById('addCardModal')).show();
    });
</script>
<?php endif; ?>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
