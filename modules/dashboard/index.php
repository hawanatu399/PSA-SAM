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
$canReviewRequisitions = hasRole(['super_admin', 'admin_officer', 'stores_officer', 'approving_officer']);
$metrics = [
    'assets' => (int) $pdo->query('SELECT COUNT(*) FROM assets')->fetchColumn(),
    'pending' => (int) $pdo->query("SELECT COUNT(*) FROM asset_transfers WHERE status = 'Pending'")->fetchColumn(),
    'requisitions' => $canReviewRequisitions
        ? (int) $pdo->query("SELECT COUNT(*) FROM store_requisitions WHERE status = 'Pending'")->fetchColumn()
        : 0,
    'low_stock' => (int) $pdo->query('SELECT COUNT(*) FROM store_items WHERE current_stock <= reorder_level')->fetchColumn(),
    'maintenance' => (int) $pdo->query(
        "SELECT COUNT(*) FROM maintenance_records WHERE status IN ('Reported', 'In-Progress')"
    )->fetchColumn(),
];
$conditionSummary = $pdo->query(
    'SELECT condition_status, COUNT(*) AS asset_count
     FROM assets
     GROUP BY condition_status
     ORDER BY FIELD(condition_status, \'Good\', \'Fair\', \'Poor\', \'Unserviceable\')'
)->fetchAll();
$largestConditionCount = max(array_column($conditionSummary, 'asset_count') ?: [0]);
$lowStockItems = [];
if (hasRole(['super_admin', 'admin_officer', 'stores_officer'])) {
    $lowStockItems = $pdo->query(
        'SELECT item_name, unit, current_stock, reorder_level
         FROM store_items
         WHERE current_stock <= reorder_level
         ORDER BY current_stock ASC, item_name
         LIMIT 5'
    )->fetchAll();
}

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
        'label' => 'Manage assets',
        'url' => APP_BASE_URL . '/modules/assets/index.php',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer'],
    ],
    [
        'label' => 'Manage inventory',
        'url' => APP_BASE_URL . '/modules/stores/index.php',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer'],
    ],
    [
        'label' => 'Request or review store requisitions',
        'url' => APP_BASE_URL . '/modules/requisitions/index.php',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer', 'approving_officer'],
    ],
    [
        'label' => 'Record physical verification',
        'url' => APP_BASE_URL . '/modules/verification/index.php',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer'],
    ],
    [
        'label' => 'View allocations & transfers',
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
    <section class="dashboard-welcome">
        <div class="dashboard-welcome-copy">
            <p class="eyebrow">Operational overview</p>
            <h1>Welcome back, <?= escapeHtml(strtok((string) ($_SESSION['user']['full_name'] ?? 'there'), ' ')) ?></h1>
            <p>A clear snapshot of assets, stores and work needing attention.</p>
        </div>
        <div class="dashboard-date">
            <span>Today</span>
            <strong><?= escapeHtml(date('l, F j, Y')) ?></strong>
        </div>
    </section>

    <section class="metric-grid dashboard-metrics" aria-label="Summary metrics">
        <article class="metric-card metric-assets">
            <span class="metric-label">Total assets</span>
            <strong class="metric-value"><?= number_format($metrics['assets']) ?></strong>
            <span class="metric-note">Registered in the system</span>
            <?php if (hasRole(['super_admin', 'admin_officer', 'stores_officer'])): ?>
                <a class="metric-link" href="<?= APP_BASE_URL ?>/modules/assets/index.php">Open asset register <span aria-hidden="true">→</span></a>
            <?php endif; ?>
        </article>
        <article class="metric-card metric-transfers">
            <span class="metric-label">Pending allocations</span>
            <strong class="metric-value"><?= number_format($metrics['pending']) ?></strong>
            <span class="metric-note">Transfers awaiting a decision</span>
            <?php if (hasRole(['super_admin', 'admin_officer', 'stores_officer', 'approving_officer'])): ?>
                <a class="metric-link" href="<?= APP_BASE_URL ?>/modules/allocation/index.php">Review transfers <span aria-hidden="true">→</span></a>
            <?php endif; ?>
        </article>
        <?php if ($canReviewRequisitions): ?>
            <article class="metric-card metric-requisitions">
                <span class="metric-label">Pending requisitions</span>
                <strong class="metric-value"><?= number_format($metrics['requisitions']) ?></strong>
                <span class="metric-note">Store requests awaiting review</span>
                <a class="metric-link" href="<?= APP_BASE_URL ?>/modules/requisitions/index.php">Open requisitions <span aria-hidden="true">→</span></a>
            </article>
        <?php endif; ?>
        <article class="metric-card metric-stock">
            <span class="metric-label">Low stock alerts</span>
            <strong class="metric-value"><?= number_format($metrics['low_stock']) ?></strong>
            <span class="metric-note">At or below reorder level</span>
            <?php if (hasRole(['super_admin', 'admin_officer', 'stores_officer'])): ?>
                <a class="metric-link" href="<?= APP_BASE_URL ?>/modules/stores/index.php">Review stock</a>
            <?php endif; ?>
        </article>
        <article class="metric-card metric-maintenance">
            <span class="metric-label">Needs maintenance</span>
            <strong class="metric-value"><?= number_format($metrics['maintenance']) ?></strong>
            <span class="metric-note">Reported or in-progress repairs</span>
            <?php if (hasRole(['super_admin', 'admin_officer', 'stores_officer'])): ?>
                <a class="metric-link" href="<?= APP_BASE_URL ?>/modules/maintenance/index.php">Review maintenance <span aria-hidden="true">→</span></a>
            <?php endif; ?>
        </article>
    </section>

    <section class="content-grid dashboard-insights" aria-label="Operational summaries">
        <article class="panel">
            <div class="panel-heading">
                <div><h2>Asset condition</h2><p class="muted">Registered assets by current condition</p></div>
            </div>
            <?php if ($conditionSummary === []): ?>
                <p class="empty-state">Asset condition summaries will appear after assets are registered.</p>
            <?php else: ?>
                <ul class="chart-list">
                    <?php foreach ($conditionSummary as $condition): ?>
                        <?php $assetCount = (int) $condition['asset_count']; ?>
                        <li>
                            <div class="chart-label"><span><?= escapeHtml($condition['condition_status']) ?></span><strong><?= number_format($assetCount) ?></strong></div>
                            <progress class="condition-<?= escapeHtml(strtolower($condition['condition_status'])) ?>" max="<?= max(1, (int) $largestConditionCount) ?>" value="<?= $assetCount ?>"
                                      aria-label="<?= escapeHtml($condition['condition_status'] . ': ' . $assetCount . ' assets') ?>"></progress>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
        <article class="panel">
            <div class="panel-heading">
                <div><h2>Stock needing attention</h2><p class="muted">Items currently at or below reorder level</p></div>
                <?php if (hasRole(['super_admin', 'admin_officer', 'stores_officer'])): ?>
                    <a class="text-link" href="<?= APP_BASE_URL ?>/modules/stores/index.php">Manage stock</a>
                <?php endif; ?>
            </div>
            <?php if (!hasRole(['super_admin', 'admin_officer', 'stores_officer'])): ?>
                <p class="empty-state">Stock details are available to stores and administration roles.</p>
            <?php elseif ($lowStockItems === []): ?>
                <p class="empty-state">No items currently need reordering.</p>
            <?php else: ?>
                <ul class="stock-alert-list">
                    <?php foreach ($lowStockItems as $item): ?>
                        <li>
                            <span><?= escapeHtml($item['item_name']) ?></span>
                            <strong><?= escapeHtml($item['current_stock'] . ' ' . $item['unit']) ?></strong>
                            <span class="muted">Reorder at <?= escapeHtml($item['reorder_level']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
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
