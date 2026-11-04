<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_once __DIR__ . '/../../includes/notifications.php';
requireRole([
    'super_admin',
    'admin_officer',
    'stores_officer',
    'approving_officer',
    'management',
]);

$notifications = currentNotifications(Database::connection());
$pageTitle = 'Notifications';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-heading">
        <div>
            <p class="eyebrow">Operational alerts</p>
            <h1>Notifications</h1>
            <p class="muted">Live alerts for items needing attention in your role.</p>
        </div>
    </div>
    <section class="panel notification-panel" aria-live="polite">
        <?php if ($notifications === []): ?>
            <div class="empty-state notification-empty">
                <strong>You’re all caught up.</strong>
                <p>No active alerts are available for your role.</p>
            </div>
        <?php else: ?>
            <ul class="notification-list">
                <?php foreach ($notifications as $notification): ?>
                    <li class="notification-item">
                        <span class="notification-indicator notification-<?= escapeHtml($notification['severity']) ?>"
                              aria-hidden="true"></span>
                        <div class="notification-content">
                            <span class="badge"><?= escapeHtml($notification['type']) ?></span>
                            <strong><?= escapeHtml($notification['title']) ?></strong>
                            <p class="muted"><?= escapeHtml($notification['message']) ?></p>
                        </div>
                        <a class="text-link" href="<?= escapeHtml($notification['url']) ?>">View details</a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="muted report-note">Alerts clear automatically when the underlying item is resolved. This list reflects current operational data and is not a read/unread message inbox.</p>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
