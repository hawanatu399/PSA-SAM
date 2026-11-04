<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
requireRole(['super_admin', 'admin_officer', 'stores_officer', 'approving_officer']);

$pdo = Database::connection();
$actorId = (int) $_SESSION['user']['id'];

if (isPostRequest()) {
    requireValidFormToken();
    $action = formString('action');

    try {
        $pdo->beginTransaction();

        if ($action === 'request_transfer' && hasRole(['super_admin', 'admin_officer', 'stores_officer'])) {
            $assetId = filter_var($_POST['asset_id'] ?? null, FILTER_VALIDATE_INT);
            $toDepartmentId = filter_var($_POST['to_department_id'] ?? null, FILTER_VALIDATE_INT);
            if (!is_int($assetId) || $assetId < 1 || !is_int($toDepartmentId) || $toDepartmentId < 1) {
                throw new InvalidArgumentException('Choose a valid asset and destination department.');
            }

            $assetQuery = $pdo->prepare('SELECT id, department_id, asset_tag_id FROM assets WHERE id = :id FOR UPDATE');
            $assetQuery->execute(['id' => $assetId]);
            $asset = $assetQuery->fetch();
            if (!$asset) {
                throw new InvalidArgumentException('The selected asset was not found.');
            }
            if ((int) $asset['department_id'] === $toDepartmentId) {
                throw new InvalidArgumentException('Choose a different destination department.');
            }

            $destinationQuery = $pdo->prepare('SELECT id FROM departments WHERE id = :id');
            $destinationQuery->execute(['id' => $toDepartmentId]);
            if (!$destinationQuery->fetchColumn()) {
                throw new InvalidArgumentException('The selected department was not found.');
            }

            $pendingQuery = $pdo->prepare(
                "SELECT id FROM asset_transfers WHERE asset_id = :asset_id AND status = 'Pending' LIMIT 1"
            );
            $pendingQuery->execute(['asset_id' => $assetId]);
            if ($pendingQuery->fetchColumn()) {
                throw new InvalidArgumentException('This asset already has a transfer awaiting a decision.');
            }

            $insert = $pdo->prepare(
                "INSERT INTO asset_transfers (asset_id, from_department_id, to_department_id, requested_by)
                 VALUES (:asset_id, :from_department_id, :to_department_id, :requested_by)"
            );
            $insert->execute([
                'asset_id' => $assetId,
                'from_department_id' => (int) $asset['department_id'],
                'to_department_id' => $toDepartmentId,
                'requested_by' => $actorId,
            ]);
            recordAudit(
                $pdo,
                'Requested transfer for ' . $asset['asset_tag_id'],
                'asset_transfers',
                (int) $pdo->lastInsertId(),
                ['from_department_id' => (int) $asset['department_id'], 'to_department_id' => $toDepartmentId]
            );
        } elseif ($action === 'decide_transfer' && hasRole(['super_admin', 'approving_officer'])) {
            $transferId = filter_var($_POST['transfer_id'] ?? null, FILTER_VALIDATE_INT);
            $decision = formString('decision');
            if (!is_int($transferId) || $transferId < 1 || !in_array($decision, ['Approved', 'Rejected'], true)) {
                throw new InvalidArgumentException('Choose a valid transfer and decision.');
            }

            $transferQuery = $pdo->prepare(
                'SELECT transfers.*, assets.asset_tag_id
                 FROM asset_transfers AS transfers
                 JOIN assets ON assets.id = transfers.asset_id
                 WHERE transfers.id = :id FOR UPDATE'
            );
            $transferQuery->execute(['id' => $transferId]);
            $transfer = $transferQuery->fetch();
            if (!$transfer || $transfer['status'] !== 'Pending') {
                throw new InvalidArgumentException('This transfer is no longer awaiting a decision.');
            }
            if ((int) $transfer['requested_by'] === $actorId) {
                throw new InvalidArgumentException('You cannot approve or reject a transfer you requested.');
            }

            if ($decision === 'Approved') {
                $assetCheck = $pdo->prepare(
                    'SELECT department_id, condition_status FROM assets WHERE id = :id FOR UPDATE'
                );
                $assetCheck->execute(['id' => (int) $transfer['asset_id']]);
                $currentAsset = $assetCheck->fetch();
                if (!$currentAsset
                    || (int) $currentAsset['department_id'] !== (int) $transfer['from_department_id']) {
                    throw new InvalidArgumentException('The asset department changed after this transfer was requested. Reject this request and submit a new one.');
                }
                if ($currentAsset['condition_status'] === 'Unserviceable') {
                    throw new InvalidArgumentException('Unserviceable assets cannot be transferred.');
                }
                $assignmentCheck = $pdo->prepare(
                    "SELECT id FROM asset_assignments WHERE asset_id = :id AND status = 'Active' LIMIT 1"
                );
                $assignmentCheck->execute(['id' => (int) $transfer['asset_id']]);
                if ($assignmentCheck->fetchColumn()) {
                    throw new InvalidArgumentException('Return the active assignment before approving this transfer.');
                }
            }

            $update = $pdo->prepare(
                'UPDATE asset_transfers
                 SET status = :status, approved_by = :approved_by, transfer_date = CASE WHEN :approved = 1 THEN CURRENT_DATE ELSE NULL END
                 WHERE id = :id AND status = :pending'
            );
            $update->execute([
                'status' => $decision,
                'approved_by' => $actorId,
                'approved' => $decision === 'Approved' ? 1 : 0,
                'id' => $transferId,
                'pending' => 'Pending',
            ]);

            if ($decision === 'Approved') {
                $moveAsset = $pdo->prepare('UPDATE assets SET department_id = :department_id WHERE id = :asset_id');
                $moveAsset->execute([
                    'department_id' => (int) $transfer['to_department_id'],
                    'asset_id' => (int) $transfer['asset_id'],
                ]);
            }

            recordAudit(
                $pdo,
                $decision . ' transfer for ' . $transfer['asset_tag_id'],
                'asset_transfers',
                $transferId,
                [
                    'from_department_id' => (int) $transfer['from_department_id'],
                    'to_department_id' => (int) $transfer['to_department_id'],
                    'decision' => $decision,
                ]
            );
        } elseif ($action === 'assign_asset' && hasRole(['super_admin', 'admin_officer', 'stores_officer'])) {
            $assetId = filter_var($_POST['asset_id'] ?? null, FILTER_VALIDATE_INT);
            $assignedTo = filter_var($_POST['assigned_to_user'] ?? null, FILTER_VALIDATE_INT);
            if (!is_int($assetId) || $assetId < 1 || !is_int($assignedTo) || $assignedTo < 1) {
                throw new InvalidArgumentException('Choose a valid asset and active staff member.');
            }

            $assetQuery = $pdo->prepare('SELECT id, asset_tag_id, condition_status FROM assets WHERE id = :id FOR UPDATE');
            $assetQuery->execute(['id' => $assetId]);
            $asset = $assetQuery->fetch();
            if (!$asset) {
                throw new InvalidArgumentException('The selected asset was not found.');
            }
            if ($asset['condition_status'] === 'Unserviceable') {
                throw new InvalidArgumentException('Unserviceable assets cannot be assigned.');
            }
            $activeQuery = $pdo->prepare(
                "SELECT id FROM asset_assignments WHERE asset_id = :asset_id AND status = 'Active' LIMIT 1"
            );
            $activeQuery->execute(['asset_id' => $assetId]);
            if ($activeQuery->fetchColumn()) {
                throw new InvalidArgumentException('Return the current assignment before assigning this asset again.');
            }
            $userQuery = $pdo->prepare("SELECT id FROM users WHERE id = :id AND status = 'Active'");
            $userQuery->execute(['id' => $assignedTo]);
            if (!$userQuery->fetchColumn()) {
                throw new InvalidArgumentException('The selected user is not active.');
            }

            $insert = $pdo->prepare(
                "INSERT INTO asset_assignments (asset_id, assigned_to_user, assigned_by_user, date_assigned)
                 VALUES (:asset_id, :assigned_to_user, :assigned_by_user, CURRENT_DATE)"
            );
            $insert->execute([
                'asset_id' => $assetId,
                'assigned_to_user' => $assignedTo,
                'assigned_by_user' => $actorId,
            ]);
            $assignmentId = (int) $pdo->lastInsertId();
            $updateAsset = $pdo->prepare('UPDATE assets SET custodian_id = :custodian_id WHERE id = :asset_id');
            $updateAsset->execute(['custodian_id' => $assignedTo, 'asset_id' => $assetId]);
            recordAudit($pdo, 'Assigned asset ' . $asset['asset_tag_id'], 'asset_assignments', $assignmentId);
        } elseif ($action === 'return_asset' && hasRole(['super_admin', 'admin_officer', 'stores_officer'])) {
            $assignmentId = filter_var($_POST['assignment_id'] ?? null, FILTER_VALIDATE_INT);
            if (!is_int($assignmentId) || $assignmentId < 1) {
                throw new InvalidArgumentException('Choose a valid active assignment.');
            }

            $assignmentQuery = $pdo->prepare(
                'SELECT assignments.*, assets.asset_tag_id
                 FROM asset_assignments AS assignments
                 JOIN assets ON assets.id = assignments.asset_id
                 WHERE assignments.id = :id FOR UPDATE'
            );
            $assignmentQuery->execute(['id' => $assignmentId]);
            $assignment = $assignmentQuery->fetch();
            if (!$assignment || $assignment['status'] !== 'Active') {
                throw new InvalidArgumentException('This assignment has already been returned.');
            }

            $update = $pdo->prepare(
                "UPDATE asset_assignments SET status = 'Returned', return_date = CURRENT_DATE WHERE id = :id"
            );
            $update->execute(['id' => $assignmentId]);
            $updateAsset = $pdo->prepare(
                'UPDATE assets SET custodian_id = NULL WHERE id = :asset_id AND custodian_id = :custodian_id'
            );
            $updateAsset->execute([
                'asset_id' => (int) $assignment['asset_id'],
                'custodian_id' => (int) $assignment['assigned_to_user'],
            ]);
            recordAudit($pdo, 'Returned asset ' . $assignment['asset_tag_id'], 'asset_assignments', $assignmentId);
        } else {
            throw new InvalidArgumentException('This action is not available for your role.');
        }

        $pdo->commit();
        setFlashMessage('success', 'The allocation request was processed successfully.');
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

    redirectTo('/modules/allocation/index.php');
}

$departments = $pdo->query('SELECT id, department_name FROM departments ORDER BY department_name')->fetchAll();
$assets = $pdo->query('SELECT id, asset_tag_id, asset_name FROM assets ORDER BY asset_tag_id')->fetchAll();
$users = $pdo->query("SELECT id, full_name FROM users WHERE status = 'Active' ORDER BY full_name")->fetchAll();
$transfers = $pdo->query(
    'SELECT transfers.id, transfers.status, transfers.transfer_date, transfers.requested_by, assets.asset_tag_id,
            source.department_name AS from_department, destination.department_name AS to_department,
            requester.full_name AS requester_name, approver.full_name AS approver_name
     FROM asset_transfers AS transfers
     JOIN assets ON assets.id = transfers.asset_id
     JOIN departments AS source ON source.id = transfers.from_department_id
     JOIN departments AS destination ON destination.id = transfers.to_department_id
     JOIN users AS requester ON requester.id = transfers.requested_by
     LEFT JOIN users AS approver ON approver.id = transfers.approved_by
     ORDER BY transfers.status = \'Pending\' DESC, transfers.id DESC LIMIT 100'
)->fetchAll();
$assignments = $pdo->query(
    "SELECT assignments.id, assignments.asset_id, assignments.assigned_to_user, assets.asset_tag_id,
            assets.asset_name, users.full_name
     FROM asset_assignments AS assignments
     JOIN assets ON assets.id = assignments.asset_id
     JOIN users ON users.id = assignments.assigned_to_user
     WHERE assignments.status = 'Active' ORDER BY assignments.date_assigned DESC LIMIT 100"
)->fetchAll();

$pageTitle = 'Allocations & transfers';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-heading"><div><p class="eyebrow">Asset control</p><h1>Allocations &amp; transfers</h1>
        <p class="muted">Assign assets to staff or request an inter-department transfer.</p></div></div>
    <?php showFlashMessage(); ?>
    <?php if (hasRole(['super_admin', 'admin_officer', 'stores_officer'])): ?>
        <section class="content-grid">
            <article class="panel"><div class="panel-heading"><div><h2>Assign an asset</h2><p class="muted">Only one active assignment per asset.</p></div></div>
                <form method="post" class="data-form data-form-single">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>"><input type="hidden" name="action" value="assign_asset">
                    <label>Asset<select name="asset_id" required><option value="">Select asset</option><?php foreach ($assets as $asset): ?><option value="<?= (int) $asset['id'] ?>"><?= escapeHtml($asset['asset_tag_id'] . ' — ' . $asset['asset_name']) ?></option><?php endforeach; ?></select></label>
                    <label>Assign to<select name="assigned_to_user" required><option value="">Select staff member</option><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>"><?= escapeHtml($user['full_name']) ?></option><?php endforeach; ?></select></label>
                    <button class="button button-primary" type="submit">Assign asset</button>
                </form>
            </article>
            <article class="panel"><div class="panel-heading"><div><h2>Request a transfer</h2><p class="muted">A separate approving officer must decide.</p></div></div>
                <form method="post" class="data-form data-form-single">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>"><input type="hidden" name="action" value="request_transfer">
                    <label>Asset<select name="asset_id" required><option value="">Select asset</option><?php foreach ($assets as $asset): ?><option value="<?= (int) $asset['id'] ?>"><?= escapeHtml($asset['asset_tag_id'] . ' — ' . $asset['asset_name']) ?></option><?php endforeach; ?></select></label>
                    <label>Destination department<select name="to_department_id" required><option value="">Select department</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>"><?= escapeHtml($department['department_name']) ?></option><?php endforeach; ?></select></label>
                    <button class="button button-primary" type="submit">Submit for approval</button>
                </form>
            </article>
        </section>
    <?php endif; ?>
    <section class="panel table-panel">
        <div class="panel-heading"><div><h2>Transfer requests</h2><p class="muted">Latest requests and decisions</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Asset</th><th>From</th><th>To</th><th>Requested by</th><th>Status</th><th>Decision</th></tr></thead><tbody>
            <?php if ($transfers === []): ?><tr><td class="empty-state" colspan="6">No transfer requests yet.</td></tr>
            <?php else: foreach ($transfers as $transfer): ?><tr>
                <td><?= escapeHtml($transfer['asset_tag_id']) ?></td><td><?= escapeHtml($transfer['from_department']) ?></td>
                <td><?= escapeHtml($transfer['to_department']) ?></td><td><?= escapeHtml($transfer['requester_name']) ?></td>
                <td><span class="badge"><?= escapeHtml($transfer['status']) ?></span></td>
                <td><?php if ($transfer['status'] === 'Pending' && hasRole(['super_admin', 'approving_officer']) && (int) $transfer['requested_by'] !== $actorId): ?>
                    <form method="post" class="inline-actions">
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>"><input type="hidden" name="action" value="decide_transfer">
                        <input type="hidden" name="transfer_id" value="<?= (int) $transfer['id'] ?>">
                        <button class="button button-primary button-small" name="decision" value="Approved" type="submit">Approve</button>
                        <button class="button button-danger button-small" name="decision" value="Rejected" type="submit">Reject</button>
                    </form>
                <?php else: ?><?= escapeHtml($transfer['approver_name'] ?? 'Awaiting decision') ?><?php endif; ?></td>
            </tr><?php endforeach; endif; ?>
        </tbody></table></div>
    </section>
    <?php if (hasRole(['super_admin', 'admin_officer', 'stores_officer'])): ?>
        <section class="panel table-panel"><div class="panel-heading"><div><h2>Active assignments</h2><p class="muted">Record asset returns when equipment is handed back.</p></div></div>
            <div class="table-wrap"><table><thead><tr><th>Asset</th><th>Assigned to</th><th>Action</th></tr></thead><tbody>
                <?php if ($assignments === []): ?><tr><td class="empty-state" colspan="3">No active assignments.</td></tr>
                <?php else: foreach ($assignments as $assignment): ?><tr><td><?= escapeHtml($assignment['asset_tag_id'] . ' — ' . $assignment['asset_name']) ?></td>
                    <td><?= escapeHtml($assignment['full_name']) ?></td><td><form method="post" class="inline-actions">
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>"><input type="hidden" name="action" value="return_asset">
                        <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>"><button class="button button-quiet" type="submit">Record return</button>
                    </form></td></tr><?php endforeach; endif; ?>
            </tbody></table></div>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
