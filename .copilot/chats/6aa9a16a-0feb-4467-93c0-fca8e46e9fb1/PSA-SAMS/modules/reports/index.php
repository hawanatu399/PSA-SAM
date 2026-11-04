<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
requireRole(['super_admin', 'admin_officer', 'approving_officer', 'management']);

$pdo = Database::connection();
$summary = [
    'total_assets' => (int) $pdo->query('SELECT COUNT(*) FROM assets')->fetchColumn(),
    'verified_assets' => (int) $pdo->query("SELECT COUNT(*) FROM assets WHERE verification_status = 'Verified'")->fetchColumn(),
    'low_stock' => (int) $pdo->query('SELECT COUNT(*) FROM store_items WHERE current_stock <= reorder_level')->fetchColumn(),
    'open_maintenance' => (int) $pdo->query("SELECT COUNT(*) FROM maintenance_records WHERE status IN ('Reported', 'In-Progress')")->fetchColumn(),
    'pending_transfers' => (int) $pdo->query("SELECT COUNT(*) FROM asset_transfers WHERE status = 'Pending'")->fetchColumn(),
];

$activity = $pdo->query(
    'SELECT logs.action_performed, logs.target_table, logs.record_id, logs.`timestamp`,
            users.full_name, users.email
     FROM audit_logs AS logs
     LEFT JOIN users ON users.id = logs.user_id
     ORDER BY logs.`timestamp` DESC, logs.id DESC LIMIT 200'
)->fetchAll();

$pageTitle = 'Reports & audit';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-heading"><div><p class="eyebrow">Oversight</p><h1>Reports &amp; audit</h1>
        <p class="muted">Read-only operational indicators and recent recorded events.</p></div></div>
    <section class="metric-grid" aria-label="Operational indicators">
        <article class="metric-card"><span class="metric-label">Total assets</span><strong class="metric-value"><?= number_format($summary['total_assets']) ?></strong></article>
        <article class="metric-card"><span class="metric-label">Verified assets</span><strong class="metric-value"><?= number_format($summary['verified_assets']) ?></strong></article>
        <article class="metric-card"><span class="metric-label">Low stock items</span><strong class="metric-value"><?= number_format($summary['low_stock']) ?></strong></article>
        <article class="metric-card"><span class="metric-label">Open maintenance</span><strong class="metric-value"><?= number_format($summary['open_maintenance']) ?></strong></article>
        <article class="metric-card"><span class="metric-label">Pending transfers</span><strong class="metric-value"><?= number_format($summary['pending_transfers']) ?></strong></article>
    </section>
    <section class="panel table-panel"><div class="panel-heading"><div><h2>Export reports</h2><p class="muted">CSV exports are limited to the latest 5,000 records.</p></div></div>
        <nav class="export-actions" aria-label="Download reports">
            <?php foreach (['assets' => 'Asset register', 'stock' => 'Stock transactions', 'transfers' => 'Asset transfers', 'maintenance' => 'Maintenance', 'audit' => 'Audit events'] as $type => $label): ?>
                <a class="button button-secondary" href="<?= APP_BASE_URL ?>/modules/reports/export.php?type=<?= escapeHtml($type) ?>"><?= escapeHtml($label) ?> CSV</a>
            <?php endforeach; ?>
        </nav>
    </section>
    <section class="panel table-panel"><div class="panel-heading"><div><h2>Recent audit events</h2><p class="muted">Latest 200 recorded events</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Date &amp; time</th><th>User</th><th>Activity</th><th>Record</th><th>Details</th></tr></thead><tbody>
            <?php if ($activity === []): ?><tr><td class="empty-state" colspan="5">No audit activity has been recorded.</td></tr>
            <?php else: foreach ($activity as $event): ?><tr>
                <td><?= escapeHtml(date('M j, Y H:i', strtotime($event['timestamp']))) ?></td>
                <td><?= escapeHtml($event['full_name'] ?? 'System') ?></td>
                <td><?= escapeHtml($event['action_performed']) ?></td>
                <td><span class="badge"><?= escapeHtml($event['target_table']) ?></span><?php if ($event['record_id'] !== null): ?> #<?= (int) $event['record_id'] ?><?php endif; ?></td>
                <td><?php if ($event['details'] !== null): ?><details><summary>View details</summary><pre class="audit-details"><?= escapeHtml($event['details']) ?></pre></details><?php else: ?>—<?php endif; ?></td>
            </tr><?php endforeach; endif; ?>
        </tbody></table></div>
        <?php if (hasRole('management')): ?><p class="muted report-note">Management access is read-only. User details and account administration are restricted to the super administrator.</p><?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
