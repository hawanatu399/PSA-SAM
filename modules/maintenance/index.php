<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
requireRole(['super_admin', 'admin_officer', 'stores_officer']);

$pdo = Database::connection();
$actorId = (int) $_SESSION['user']['id'];

if (isPostRequest()) {
    requireValidFormToken();
    $action = formString('action');

    try {
        $pdo->beginTransaction();

        if ($action === 'report_issue') {
            $assetId = filter_var($_POST['asset_id'] ?? null, FILTER_VALIDATE_INT);
            $description = formString('issue_description');
            if (!is_int($assetId) || $assetId < 1 || !is_string($description)
                || $description === '' || strlen($description) > 5000) {
                throw new InvalidArgumentException('Choose an asset and enter an issue description under 5,000 characters.');
            }

            $assetQuery = $pdo->prepare('SELECT id, asset_tag_id FROM assets WHERE id = :id FOR UPDATE');
            $assetQuery->execute(['id' => $assetId]);
            $asset = $assetQuery->fetch();
            if (!$asset) {
                throw new InvalidArgumentException('The selected asset was not found.');
            }

            $insert = $pdo->prepare(
                "INSERT INTO maintenance_records (asset_id, issue_description, repair_cost, status, reported_by)
                 VALUES (:asset_id, :description, 0, 'Reported', :reported_by)"
            );
            $insert->execute([
                'asset_id' => $assetId,
                'description' => $description,
                'reported_by' => $actorId,
            ]);
            $recordId = (int) $pdo->lastInsertId();
            recordAudit($pdo, 'Reported maintenance for ' . $asset['asset_tag_id'], 'maintenance_records', $recordId);
        } elseif ($action === 'update_record') {
            $recordId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
            $status = formString('status');
            $repairCost = formString('repair_cost');
            $serviceDate = formString('service_date');
            $dateIsValid = $serviceDate === '' || (
                is_string($serviceDate)
                && DateTime::createFromFormat('!Y-m-d', $serviceDate) !== false
                && DateTime::getLastErrors() === false
            );
            if (!is_int($recordId) || $recordId < 1
                || !in_array($status, ['Reported', 'In-Progress', 'Completed'], true)
                || !is_string($repairCost) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $repairCost)
                || (float) $repairCost < 0 || !$dateIsValid) {
                throw new InvalidArgumentException('Enter a valid maintenance status, repair cost, and service date.');
            }

            $recordQuery = $pdo->prepare(
                'SELECT maintenance.id, maintenance.status, maintenance.repair_cost,
                        maintenance.service_date, assets.asset_tag_id
                 FROM maintenance_records AS maintenance
                 JOIN assets ON assets.id = maintenance.asset_id
                 WHERE maintenance.id = :id FOR UPDATE'
            );
            $recordQuery->execute(['id' => $recordId]);
            $record = $recordQuery->fetch();
            if (!$record) {
                throw new InvalidArgumentException('The maintenance record was not found.');
            }

            $update = $pdo->prepare(
                'UPDATE maintenance_records
                 SET status = :status, repair_cost = :repair_cost,
                     service_date = CASE
                         WHEN :service_date <> \'\' THEN :provided_date
                         WHEN :is_completed = 1 THEN COALESCE(service_date, CURRENT_DATE)
                         ELSE service_date
                     END
                 WHERE id = :id'
            );
            $update->execute([
                'status' => $status,
                'repair_cost' => $repairCost,
                'service_date' => $serviceDate ?? '',
                'provided_date' => $serviceDate ?: null,
                'is_completed' => $status === 'Completed' ? 1 : 0,
                'id' => $recordId,
            ]);
            recordAudit(
                $pdo,
                'Updated maintenance for ' . $record['asset_tag_id'] . ' to ' . $status,
                'maintenance_records',
                $recordId,
                [
                    'before' => [
                        'status' => $record['status'],
                        'repair_cost' => $record['repair_cost'],
                        'service_date' => $record['service_date'],
                    ],
                    'after' => [
                        'status' => $status,
                        'repair_cost' => $repairCost,
                        'service_date' => $serviceDate ?: null,
                    ],
                ]
            );
        } else {
            throw new InvalidArgumentException('Choose a valid maintenance action.');
        }

        $pdo->commit();
        setFlashMessage('success', 'Maintenance record saved successfully.');
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', $exception->getMessage());
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        reportDatabaseFailure($exception);
    }

    redirectTo('/modules/maintenance/index.php');
}

$assets = $pdo->query('SELECT id, asset_tag_id, asset_name FROM assets ORDER BY asset_tag_id')->fetchAll();
$records = $pdo->query(
    'SELECT maintenance.id, maintenance.issue_description, maintenance.repair_cost,
            maintenance.status, maintenance.service_date, assets.asset_tag_id, assets.asset_name,
            users.full_name AS reported_by_name
     FROM maintenance_records AS maintenance
     JOIN assets ON assets.id = maintenance.asset_id
     JOIN users ON users.id = maintenance.reported_by
     ORDER BY FIELD(maintenance.status, \'Reported\', \'In-Progress\', \'Completed\'),
              maintenance.id DESC LIMIT 100'
)->fetchAll();

$pageTitle = 'Maintenance';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-heading"><div><p class="eyebrow">Asset care</p><h1>Maintenance</h1>
        <p class="muted">Report issues and track service progress and costs.</p></div></div>
    <?php showFlashMessage(); ?>
    <section class="panel form-panel"><div class="panel-heading"><div><h2>Report an issue</h2><p class="muted">New records are marked as reported.</p></div></div>
        <form method="post" class="data-form data-form-single">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>"><input type="hidden" name="action" value="report_issue">
            <label>Asset<select name="asset_id" required><option value="">Select asset</option><?php foreach ($assets as $asset): ?><option value="<?= (int) $asset['id'] ?>"><?= escapeHtml($asset['asset_tag_id'] . ' — ' . $asset['asset_name']) ?></option><?php endforeach; ?></select></label>
            <label>Issue description<textarea name="issue_description" rows="4" maxlength="5000" required></textarea></label>
            <button class="button button-primary" type="submit">Report issue</button>
        </form>
    </section>
    <section class="panel table-panel"><div class="panel-heading"><div><h2>Maintenance records</h2><p class="muted">Latest 100 records</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Asset</th><th>Issue</th><th>Status</th><th>Cost</th><th>Reported by</th><th>Update</th></tr></thead><tbody>
            <?php if ($records === []): ?><tr><td class="empty-state" colspan="6">No maintenance issues have been reported.</td></tr>
            <?php else: foreach ($records as $record): ?><tr>
                <td><?= escapeHtml($record['asset_tag_id'] . ' — ' . $record['asset_name']) ?></td>
                <td><?= escapeHtml($record['issue_description']) ?></td>
                <td><span class="badge"><?= escapeHtml($record['status']) ?></span></td>
                <td><?= number_format((float) $record['repair_cost'], 2) ?></td>
                <td><?= escapeHtml($record['reported_by_name']) ?></td>
                <td><details><summary>Update</summary><form method="post" class="data-form data-form-single compact-form">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>"><input type="hidden" name="action" value="update_record">
                    <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                    <label>Status<select name="status"><?php foreach (['Reported', 'In-Progress', 'Completed'] as $status): ?><option value="<?= escapeHtml($status) ?>" <?= $record['status'] === $status ? 'selected' : '' ?>><?= escapeHtml($status) ?></option><?php endforeach; ?></select></label>
                    <label>Repair cost<input name="repair_cost" type="number" min="0" step="0.01" value="<?= escapeHtml($record['repair_cost']) ?>" required></label>
                    <label>Service date<input name="service_date" type="date" value="<?= escapeHtml($record['service_date'] ?? '') ?>"></label>
                    <button class="button button-primary button-small" type="submit">Save update</button>
                </form></details></td>
            </tr><?php endforeach; endif; ?>
        </tbody></table></div>
    </section>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
