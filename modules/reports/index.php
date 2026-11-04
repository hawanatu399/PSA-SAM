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
    'pending_requisitions' => (int) $pdo->query("SELECT COUNT(*) FROM store_requisitions WHERE status = 'Pending'")->fetchColumn(),
    'missing_asset_checks' => (int) $pdo->query(
        "SELECT COUNT(*) FROM asset_verifications AS checks
         WHERE checks.result = 'Missing'
           AND NOT EXISTS (
               SELECT 1 FROM asset_verifications AS newer
               WHERE newer.asset_id = checks.asset_id
                 AND (newer.verified_at > checks.verified_at
                      OR (newer.verified_at = checks.verified_at AND newer.id > checks.id))
           )"
    )->fetchColumn(),
];

$searchInput = $_GET['search'] ?? '';
$auditSearch = is_string($searchInput) ? substr(trim($searchInput), 0, 180) : '';
$userInput = $_GET['user'] ?? '';
$auditUserId = is_string($userInput) && $userInput !== ''
    ? filter_var($userInput, FILTER_VALIDATE_INT)
    : null;
$tableInput = $_GET['table'] ?? '';
$auditTable = is_string($tableInput) ? substr(trim($tableInput), 0, 100) : '';
$fromInput = $_GET['from'] ?? '';
$auditFrom = is_string($fromInput) ? trim($fromInput) : '';
$toInput = $_GET['to'] ?? '';
$auditTo = is_string($toInput) ? trim($toInput) : '';

foreach (['from' => $auditFrom, 'to' => $auditTo] as $dateField => $dateValue) {
    if ($dateValue !== '') {
        $date = DateTime::createFromFormat('!Y-m-d', $dateValue);
        $dateErrors = DateTime::getLastErrors();
        if ($date === false || ($dateErrors !== false
                && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $date->format('Y-m-d') !== $dateValue) {
            http_response_code(400);
            exit('Enter valid audit date filters.');
        }
    }
}

if (($userInput !== '' && (!is_int($auditUserId) || $auditUserId < 1))
    || ($auditFrom !== '' && $auditTo !== '' && $auditFrom > $auditTo)) {
    http_response_code(400);
    exit('Enter valid audit filters.');
}

$pageInput = $_GET['page'] ?? '1';
$auditPage = is_string($pageInput) ? filter_var($pageInput, FILTER_VALIDATE_INT) : false;
if (!is_int($auditPage) || $auditPage < 1) {
    http_response_code(400);
    exit('Choose a valid audit page.');
}

$auditConditions = [];
$auditParameters = [];
if ($auditSearch !== '') {
    $auditConditions[] = '(logs.action_performed LIKE :search_action
        OR logs.target_table LIKE :search_table
        OR users.full_name LIKE :search_name
        OR users.email LIKE :search_email
        OR logs.details LIKE :search_details)';
    foreach (['action', 'table', 'name', 'email', 'details'] as $field) {
        $auditParameters['search_' . $field] = '%' . $auditSearch . '%';
    }
}
if (is_int($auditUserId)) {
    $auditConditions[] = 'logs.user_id = :user_id';
    $auditParameters['user_id'] = $auditUserId;
}
if ($auditTable !== '') {
    $auditConditions[] = 'logs.target_table = :target_table';
    $auditParameters['target_table'] = $auditTable;
}
if ($auditFrom !== '') {
    $auditConditions[] = 'logs.`timestamp` >= :from_date';
    $auditParameters['from_date'] = $auditFrom;
}
if ($auditTo !== '') {
    $auditConditions[] = 'logs.`timestamp` < DATE_ADD(:to_date, INTERVAL 1 DAY)';
    $auditParameters['to_date'] = $auditTo;
}
$auditWhere = $auditConditions === [] ? '' : ' WHERE ' . implode(' AND ', $auditConditions);
$auditFromSql = ' FROM audit_logs AS logs LEFT JOIN users ON users.id = logs.user_id' . $auditWhere;

$countAudit = $pdo->prepare('SELECT COUNT(*)' . $auditFromSql);
$countAudit->execute($auditParameters);
$auditCount = (int) $countAudit->fetchColumn();
$pageSize = 50;
$pageCount = max(1, (int) ceil($auditCount / $pageSize));
$auditPage = min($auditPage, $pageCount);
$auditOffset = ($auditPage - 1) * $pageSize;

$activityQuery = $pdo->prepare(
    'SELECT logs.action_performed, logs.target_table, logs.record_id, logs.`timestamp`,
            logs.details, users.full_name, users.email
     ' . $auditFromSql . '
     ORDER BY logs.`timestamp` DESC, logs.id DESC
     LIMIT :page_size OFFSET :page_offset'
);
foreach ($auditParameters as $parameter => $value) {
    $activityQuery->bindValue(':' . $parameter, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$activityQuery->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
$activityQuery->bindValue(':page_offset', $auditOffset, PDO::PARAM_INT);
$activityQuery->execute();
$activity = $activityQuery->fetchAll();

$auditUsers = $pdo->query(
    'SELECT DISTINCT users.id, users.full_name
     FROM audit_logs AS logs
     JOIN users ON users.id = logs.user_id
     ORDER BY users.full_name'
)->fetchAll();
$auditTables = $pdo->query(
    'SELECT DISTINCT target_table FROM audit_logs ORDER BY target_table'
)->fetchAll(PDO::FETCH_COLUMN);
$filterParameters = array_filter([
    'search' => $auditSearch,
    'user' => is_int($auditUserId) ? (string) $auditUserId : '',
    'table' => $auditTable,
    'from' => $auditFrom,
    'to' => $auditTo,
], static fn (string $value): bool => $value !== '');
$previousUrl = APP_BASE_URL . '/modules/reports/index.php?' . http_build_query($filterParameters + ['page' => max(1, $auditPage - 1)]);
$nextUrl = APP_BASE_URL . '/modules/reports/index.php?' . http_build_query($filterParameters + ['page' => min($pageCount, $auditPage + 1)]);

$pageTitle = 'Reports & audit';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-heading" data-print-date="<?= escapeHtml($printDate) ?>"><div><p class="eyebrow">Oversight</p><h1>Reports &amp; audit</h1>
        <p class="muted">Read-only operational indicators and recent recorded events.</p></div>
        <button class="button button-secondary no-print" type="button" data-print-page>Print current report</button>
    </div>
    <section class="metric-grid" aria-label="Operational indicators">
        <article class="metric-card"><span class="metric-label">Total assets</span><strong class="metric-value"><?= number_format($summary['total_assets']) ?></strong></article>
        <article class="metric-card"><span class="metric-label">Verified assets</span><strong class="metric-value"><?= number_format($summary['verified_assets']) ?></strong></article>
        <article class="metric-card"><span class="metric-label">Low stock items</span><strong class="metric-value"><?= number_format($summary['low_stock']) ?></strong></article>
        <article class="metric-card"><span class="metric-label">Open maintenance</span><strong class="metric-value"><?= number_format($summary['open_maintenance']) ?></strong></article>
        <article class="metric-card"><span class="metric-label">Pending transfers</span><strong class="metric-value"><?= number_format($summary['pending_transfers']) ?></strong></article>
        <article class="metric-card"><span class="metric-label">Pending requisitions</span><strong class="metric-value"><?= number_format($summary['pending_requisitions']) ?></strong></article>
        <article class="metric-card"><span class="metric-label">Assets currently reported missing</span><strong class="metric-value"><?= number_format($summary['missing_asset_checks']) ?></strong></article>
    </section>
    <section class="panel table-panel no-print"><div class="panel-heading"><div><h2>Export reports</h2><p class="muted">CSV exports are limited to the latest 5,000 records.</p></div></div>
        <nav class="export-actions" aria-label="Download reports">
            <?php foreach (['assets' => 'Asset register', 'stock' => 'Stock transactions', 'transfers' => 'Asset transfers', 'maintenance' => 'Maintenance', 'requisitions' => 'Store requisitions', 'verification' => 'Physical verification', 'verification_campaigns' => 'Verification campaigns', 'audit' => 'Audit events'] as $type => $label): ?>
                <a class="button button-secondary" href="<?= APP_BASE_URL ?>/modules/reports/export.php?type=<?= escapeHtml($type) ?>"><?= escapeHtml($label) ?> CSV</a>
            <?php endforeach; ?>
        </nav>
    </section>
    <section class="panel table-panel"><div class="panel-heading"><div><h2>Recent audit events</h2><p class="muted">Search and filter the full recorded audit history.</p></div></div>
        <form method="get" class="data-form audit-filters no-print">
            <label>Search activity<input type="search" name="search" value="<?= escapeHtml($auditSearch) ?>" placeholder="Action, record, user, email or details"></label>
            <label>User<select name="user"><option value="">All users</option><?php foreach ($auditUsers as $auditUser): ?><option value="<?= (int) $auditUser['id'] ?>" <?= $auditUserId === (int) $auditUser['id'] ? 'selected' : '' ?>><?= escapeHtml($auditUser['full_name']) ?></option><?php endforeach; ?></select></label>
            <label>Record type<select name="table"><option value="">All record types</option><?php foreach ($auditTables as $table): ?><option value="<?= escapeHtml($table) ?>" <?= $auditTable === $table ? 'selected' : '' ?>><?= escapeHtml($table) ?></option><?php endforeach; ?></select></label>
            <label>From date<input type="date" name="from" value="<?= escapeHtml($auditFrom) ?>"></label>
            <label>To date<input type="date" name="to" value="<?= escapeHtml($auditTo) ?>"></label>
            <div class="filter-actions"><button class="button button-primary" type="submit">Apply filters</button><a class="button button-secondary" href="<?= APP_BASE_URL ?>/modules/reports/index.php#audit-history">Clear</a></div>
        </form>
        <div class="audit-result-summary" aria-live="polite">
            <p class="muted"><?= number_format($auditCount) ?> matching event<?= $auditCount === 1 ? '' : 's' ?><?= $auditCount > 0 ? ' · Showing ' . number_format($auditOffset + 1) . '–' . number_format($auditOffset + count($activity)) : '' ?></p>
        </div>
        <div class="table-wrap"><table><thead><tr><th>Date &amp; time</th><th>User</th><th>Activity</th><th>Record</th><th>Details</th></tr></thead><tbody>
            <?php if ($activity === []): ?><tr><td class="empty-state" colspan="5">No audit activity has been recorded.</td></tr>
            <?php else: foreach ($activity as $event): ?><tr>
                <td><time datetime="<?= escapeHtml(date(DATE_ATOM, strtotime($event['timestamp']))) ?>"><?= escapeHtml(date('M j, Y H:i', strtotime($event['timestamp']))) ?></time></td>
                <td><?= escapeHtml($event['full_name'] ?? 'System') ?><?php if ($event['email'] !== null): ?><br><span class="muted audit-user-email"><?= escapeHtml($event['email']) ?></span><?php endif; ?></td>
                <td><?= escapeHtml($event['action_performed']) ?></td>
                <td><span class="badge"><?= escapeHtml($event['target_table']) ?></span><?php if ($event['record_id'] !== null): ?> #<?= (int) $event['record_id'] ?><?php endif; ?></td>
                <td><?php if ($event['details'] !== null): ?><details><summary>View details</summary><pre class="audit-details"><?= escapeHtml(is_string($event['details']) ? json_encode(json_decode($event['details'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $event['details'] : $event['details']) ?></pre></details><?php else: ?>—<?php endif; ?></td>
            </tr><?php endforeach; endif; ?>
        </tbody></table></div>
        <?php if ($pageCount > 1): ?>
            <nav class="pagination" aria-label="Audit history pages">
                <a class="button button-secondary<?= $auditPage <= 1 ? ' is-disabled' : '' ?>"
                   href="<?= escapeHtml($previousUrl) ?>" <?= $auditPage <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Previous</a>
                <span class="muted">Page <?= number_format($auditPage) ?> of <?= number_format($pageCount) ?></span>
                <a class="button button-secondary<?= $auditPage >= $pageCount ? ' is-disabled' : '' ?>"
                   href="<?= escapeHtml($nextUrl) ?>" <?= $auditPage >= $pageCount ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Next</a>
            </nav>
        <?php endif; ?>
        <span id="audit-history"></span>
        <?php if (hasRole('management')): ?><p class="muted report-note">Management access is read-only. User details and account administration are restricted to the super administrator.</p><?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
