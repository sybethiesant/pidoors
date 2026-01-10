<?php
/**
 * Edit Card
 * PiDoors Access Control System
 */
$title = 'Edit Card';
require_once './includes/header.php';

require_login($config);
require_admin($config);

$error_message = '';
$card = null;

// Get card ID from URL
$card_id = sanitize_string($_GET['id'] ?? $_POST['card_id'] ?? '');

if (empty($card_id)) {
    header("Location: {$config['url']}/cards.php?error=No card specified.");
    exit();
}

// Fetch card data
try {
    $stmt = $pdo_access->prepare("SELECT * FROM cards WHERE card_id = ?");
    $stmt->execute([$card_id]);
    $card = $stmt->fetch();

    if (!$card) {
        header("Location: {$config['url']}/cards.php?error=Card not found.");
        exit();
    }

    $doors = $pdo_access->query("SELECT name FROM doors ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $schedules = $pdo_access->query("SELECT id, name FROM access_schedules ORDER BY name")->fetchAll();
    $groups = $pdo_access->query("SELECT id, name FROM access_groups ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    error_log("Edit card error: " . $e->getMessage());
    header("Location: {$config['url']}/cards.php?error=Error loading card.");
    exit();
}

// Process form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } else {
        $firstname = sanitize_string($_POST['firstname'] ?? '');
        $lastname = sanitize_string($_POST['lastname'] ?? '');
        $selected_doors = $_POST['doors'] ?? [];
        $doors_str = implode(' ', array_map('sanitize_string', $selected_doors));
        $schedule_id = validate_int($_POST['schedule_id'] ?? 0) ?: null;
        $group_id = validate_int($_POST['group_id'] ?? 0) ?: null;
        $valid_from = $_POST['valid_from'] ?? null;
        $valid_until = $_POST['valid_until'] ?? null;
        $active = isset($_POST['active']) ? 1 : 0;

        try {
            $stmt = $pdo_access->prepare("
                UPDATE cards SET
                    firstname = ?, lastname = ?, doors = ?, active = ?,
                    group_id = ?, schedule_id = ?, valid_from = ?, valid_until = ?,
                    updated_at = NOW()
                WHERE card_id = ?
            ");
            $stmt->execute([
                $firstname, $lastname, $doors_str, $active,
                $group_id, $schedule_id,
                $valid_from ?: null, $valid_until ?: null,
                $card_id
            ]);

            header("Location: {$config['url']}/cards.php?success=Card updated successfully.");
            exit();
        } catch (PDOException $e) {
            error_log("Update card error: " . $e->getMessage());
            $error_message = 'Failed to update card.';
        }
    }
}

$card_doors = array_filter(explode(' ', $card['doors']));
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Edit Card: <?php echo htmlspecialchars($card['user_id']); ?></h5>
            </div>
            <div class="card-body">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <form method="post" action="editcard.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="card_id" value="<?php echo htmlspecialchars($card_id); ?>">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="firstname" class="form-label">First Name</label>
                            <input type="text" class="form-control" id="firstname" name="firstname"
                                   value="<?php echo htmlspecialchars($card['firstname']); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="lastname" class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="lastname" name="lastname"
                                   value="<?php echo htmlspecialchars($card['lastname']); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">User ID</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($card['user_id']); ?>" disabled>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Facility Code</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($card['facility']); ?>" disabled>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Card ID</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($card['card_id']); ?>" disabled>
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
                                               id="door_<?php echo htmlspecialchars($door); ?>"
                                               <?php echo in_array($door, $card_doors) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="door_<?php echo htmlspecialchars($door); ?>">
                                            <?php echo htmlspecialchars($door); ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="schedule_id" class="form-label">Access Schedule</label>
                            <select class="form-select" id="schedule_id" name="schedule_id">
                                <option value="">No restriction (24/7)</option>
                                <?php foreach ($schedules as $schedule): ?>
                                    <option value="<?php echo $schedule['id']; ?>"
                                        <?php echo ($card['schedule_id'] == $schedule['id']) ? 'selected' : ''; ?>>
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
                                    <option value="<?php echo $group['id']; ?>"
                                        <?php echo ($card['group_id'] == $group['id']) ? 'selected' : ''; ?>>
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
                                   value="<?php echo $card['valid_from'] ? date('Y-m-d', strtotime($card['valid_from'])) : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="valid_until" class="form-label">Valid Until</label>
                            <input type="date" class="form-control" id="valid_until" name="valid_until"
                                   value="<?php echo $card['valid_until'] ? date('Y-m-d', strtotime($card['valid_until'])) : ''; ?>">
                        </div>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="active" name="active" value="1"
                               <?php echo $card['active'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="active">Active</label>
                    </div>

                    <div class="d-flex justify-content-between">
                        <a href="cards.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
