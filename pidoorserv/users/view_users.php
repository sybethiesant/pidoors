<?php
/**
 * View Panel Users
 * PiDoors Access Control System
 */
$title = 'Panel Users';
require_once __DIR__ . '/../includes/header.php';

// Require admin access
require_admin($config);

// Fetch all users
try {
    $stmt = $pdo->query("SELECT id, user_name, user_email, admin FROM users ORDER BY user_name");
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("View users error: " . $e->getMessage());
    $users = [];
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div></div>
    <a href="adduser.php" class="btn btn-primary">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add User
    </a>
</div>

<div class="card shadow">
    <div class="card-body">
        <table class="table table-striped table-hover datatable">
            <thead class="table-dark">
                <tr>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th width="100">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['user_name']); ?></td>
                        <td><?php echo htmlspecialchars($user['user_email']); ?></td>
                        <td>
                            <?php if ($user['admin'] == 1): ?>
                                <span class="badge bg-danger">Administrator</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">User</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="edituser.php?id=<?php echo (int)$user['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </a>
                            <?php if ($user['user_email'] !== $_SESSION['email']): ?>
                                <a href="delete.php?id=<?php echo (int)$user['id']; ?>&token=<?php echo htmlspecialchars($csrf_token); ?>"
                                   class="btn btn-sm btn-outline-danger"
                                   title="Delete"
                                   onclick="return confirmDelete('Are you sure you want to delete this user?');">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
