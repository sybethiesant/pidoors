<?php
/**
 * Add New Card
 * PiDoors Access Control System
 */
$title = 'Add Card';
require_once './includes/header.php';

require_login($config);

$error_message = '';

// Fetch doors, schedules, and groups for dropdowns
try {
    $doors = $pdo_access->query("SELECT name FROM doors ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $schedules = $pdo_access->query("SELECT id, name FROM access_schedules ORDER BY name")->fetchAll();
    $groups = $pdo_access->query("SELECT id, name FROM access_groups ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    $doors = [];
    $schedules = [];
    $groups = [];
}

// Process form
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
        $doors_str = implode(' ', array_map('sanitize_string', $selected_doors));
        $schedule_id = validate_int($_POST['schedule_id'] ?? 0) ?: null;
        $group_id = validate_int($_POST['group_id'] ?? 0) ?: null;
        $valid_from = $_POST['valid_from'] ?? null;
        $valid_until = $_POST['valid_until'] ?? null;
        $active = isset($_POST['active']) ? 1 : 0;

        if (empty($user_id) || empty($facility)) {
            $error_message = 'User ID and Facility are required.';
        } else {
            try {
                // Generate card_id if not provided
                if (empty($card_id)) {
                    $card_id = sprintf("%08x", crc32($facility . $user_id . time()));
                }

                $stmt = $pdo_access->prepare("
                    INSERT INTO cards (card_id, user_id, facility, bstr, firstname, lastname, doors, active, group_id, schedule_id, valid_from, valid_until)
                    VALUES (?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $card_id, $user_id, $facility, $firstname, $lastname,
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
}
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Add New Access Card</h5>
            </div>
            <div class="card-body">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <form method="post" action="addcard.php">
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
                            <label for="card_id" class="form-label">Card ID (auto-generated if blank)</label>
                            <input type="text" class="form-control" id="card_id" name="card_id"
                                   value="<?php echo htmlspecialchars($_POST['card_id'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Door Access</label>
                        <div class="row">
                            <?php foreach ($doors as $door): ?>
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
                            <?php if (empty($doors)): ?>
                                <div class="col-12 text-muted">No doors configured. <a href="doors.php">Add doors first.</a></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="schedule_id" class="form-label">Access Schedule</label>
                            <select class="form-select" id="schedule_id" name="schedule_id">
                                <option value="">No restriction (24/7)</option>
                                <?php foreach ($schedules as $schedule): ?>
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
                                <?php foreach ($groups as $group): ?>
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

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="active" name="active" value="1" checked>
                        <label class="form-check-label" for="active">Active</label>
                    </div>

                    <div class="d-flex justify-content-between">
                        <a href="cards.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Add Card</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
