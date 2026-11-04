<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../auth/auth_check.php';
requireRole([
    'super_admin',
    'admin_officer',
    'stores_officer',
    'approving_officer',
    'management',
]);

$pdo = Database::connection();
$metrics = [
    'assets' => (int) $pdo->query('SELECT COUNT(*) FROM assets')->fetchColumn(),
    'pending' => (int) $pdo->query("SELECT COUNT(*) FROM asset_transfers WHERE status = 'Pending'")->fetchColumn(),
    'low_stock' => (int) $pdo->query('SELECT COUNT(*) FROM store_items WHERE current_stock <= reorder_level')->fetchColumn(),
    'maintenance' => (int) $pdo->query(
        "SELECT COUNT(*) FROM maintenance_records WHERE status IN ('Reported', 'In-Progress')"
    )->fetchColumn(),
];

$activityStatement = $pdo->query(
    'SELECT logs.action_performed, logs.target_table, logs.record_id, logs.`timestamp`,
            users.full_name
     FROM audit_logs AS logs
     LEFT JOIN users ON users.id = logs.user_id
     ORDER BY logs.`timestamp` DESC, logs.id DESC
     LIMIT 8'
);
$recentActivity = $activityStatement->fetchAll();

$quickActions = [
    [
        'label' => 'Asset register preview',
        'url' => APP_BASE_URL . '/modules/assets/index.php',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer'],
    ],
    [
        'label' => 'Inventory preview',
        'url' => APP_BASE_URL . '/modules/stores/index.php',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer'],
    ],
    [
        'label' => 'Allocations preview',
        'url' => APP_BASE_URL . '/modules/allocation/index.php',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer', 'approving_officer'],
    ],
    [
        'label' => 'View reports & audit',
        'url' => APP_BASE_URL . '/modules/reports/index.php',
        'roles' => ['super_admin', 'admin_officer', 'approving_officer', 'management'],
    ],
];

$pageTitle = 'Dashboard';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-heading">
        <div>
            <p class="eyebrow">Operational overview</p>
            <h1>Dashboard</h1>
            <p class="muted">A current snapshot of Academy assets and stores.</p>
        </div>
    </div>

    <section class="metric-grid" aria-label="Summary metrics">
        <article class="metric-card">
            <span class="metric-label">Total assets</span>
            <strong class="metric-value"><?= number_format($metrics['assets']) ?></strong>
            <span class="metric-note">Registered in the system</span>
        </article>
        <article class="metric-card">
            <span class="metric-label">Pending allocations</span>
            <strong class="metric-value"><?= number_format($metrics['pending']) ?></strong>
            <span class="metric-note">Transfers awaiting a decision</span>
        </article>
        <article class="metric-card">
            <span class="metric-label">Low stock alerts</span>
            <strong class="metric-value"><?= number_format($metrics['low_stock']) ?></strong>
            <span class="metric-note">At or below reorder level</span>
        </article>
        <article class="metric-card">
            <span class="metric-label">Needs maintenance</span>
            <strong class="metric-value"><?= number_format($metrics['maintenance']) ?></strong>
            <span class="metric-note">Reported or in-progress repairs</span>
        </article>
    </section>

    <section class="content-grid">
        <article class="panel activity-panel" id="recent-activity">
            <div class="panel-heading">
                <div>
                    <h2>Recent activity</h2>
                    <p class="muted">Latest recorded system events</p>
                </div>
                <?php if (hasRole(['super_admin', 'admin_officer', 'approving_officer', 'management'])): ?>
                    <a class="text-link" href="<?= APP_BASE_URL ?>/modules/reports/index.php">View reports &amp; audit</a>
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th scope="col">Activity</th>
                        <th scope="col">User</th>
                        <th scope="col">Record</th>
                        <th scope="col">Date &amp; time</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($recentActivity === []): ?>
                        <tr><td class="empty-state" colspan="4">No activity has been recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentActivity as $activity): ?>
                            <tr>
                                <td><?= escapeHtml($activity['action_performed']) ?></td>
                                <td><?= escapeHtml($activity['full_name'] ?? 'System') ?></td>
                                <td>
                                    <span class="badge"><?= escapeHtml($activity['target_table']) ?></span>
                                    <?php if ($activity['record_id'] !== null): ?>
                                        #<?= (int) $activity['record_id'] ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= escapeHtml(date('M j, Y H:i', strtotime($activity['timestamp']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <aside class="panel quick-actions">
            <div class="panel-heading">
                <div>
                    <h2>Quick actions</h2>
                    <p class="muted">Available for your role</p>
                </div>
            </div>
            <nav aria-label="Quick actions">
                <?php foreach ($quickActions as $action): ?>
                    <?php if (hasRole($action['roles'])): ?>
                        <a class="action-link" href="<?= escapeHtml($action['url']) ?>">
                            <?= escapeHtml($action['label']) ?><span aria-hidden="true">→</span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        </aside>
    </section>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
