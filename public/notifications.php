<?php
session_start();

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
include '../templates/header.php';

$user = $_SESSION['user']['username'];
$role = $_SESSION['user']['role'];
$user_id = $_SESSION['user']['id'] ?? null;

try {
    // Fetch notifications for the logged-in user (or all for admin/manager)
    if ($role === 'tenant') {
        // Tenants see only their notifications
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$user_id]);
    } else {
        // Admin and manager see all notifications
        $stmt = $pdo->query("SELECT * FROM notifications ORDER BY created_at DESC");
    }

    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Database error: " . htmlspecialchars($e->getMessage()) . "</div>";
    $notifications = [];
}
?>

<div class="container mt-4">
    <h1>Notifications</h1>

    <?php if (count($notifications) === 0): ?>
        <p>No notifications found.</p>
    <?php else: ?>
        <ul class="list-group">
            <?php foreach ($notifications as $notif): ?>
                <li class="list-group-item <?= $notif['is_read'] ? '' : 'list-group-item-warning' ?>">
                    <strong><?= htmlspecialchars($notif['title']) ?></strong><br>
                    <small class="text-muted"><?= date('Y-m-d H:i', strtotime($notif['created_at'])) ?></small>
                    <p><?= nl2br(htmlspecialchars($notif['message'])) ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php include '../templates/footer.php'; ?>
