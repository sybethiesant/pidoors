<?php
/**
 * Cards Management
 * PiDoors Access Control System
 */
$title = 'Cards';
require_once './includes/header.php';

// Require login
require_login($config);

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

    // Fetch doors for reference
    $stmt = $pdo_access->query("SELECT name FROM doors ORDER BY name");
    $doors = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Cards fetch error: " . $e->getMessage());
    $cards = [];
    $doors = [];
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <span class="text-muted"><?php echo count($cards); ?> total cards</span>
    </div>
    <div>
        <a href="addcard.php" class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Add Card
        </a>
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

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
