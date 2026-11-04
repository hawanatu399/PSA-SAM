<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
requireRole([
    'super_admin',
    'admin_officer',
    'stores_officer',
    'approving_officer',
]);

$pdo = Database::connection();
$actorId = (int) $_SESSION['user']['id'];
$actorRole = (string) $_SESSION['user']['role'];
$canApprove = in_array($actorRole, ['super_admin', 'approving_officer'], true);
$canIssue = in_array($actorRole, ['super_admin', 'admin_officer', 'stores_officer'], true);
$canChooseDepartment = in_array($actorRole, ['super_admin', 'admin_officer'], true);
$departmentId = $_SESSION['user']['department_id'] ?? null;
$canRequest = $canChooseDepartment || (is_int($departmentId) && $departmentId > 0);

if (isPostRequest()) {
    requireValidFormToken();
    $action = formString('action');

    try {
        $pdo->beginTransaction();

        if ($action === 'request_stock' && $canRequest) {
            $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
            $departmentInput = formString('department_id');
            $requestDepartmentId = $canChooseDepartment
                ? filter_var($departmentInput, FILTER_VALIDATE_INT)
                : filter_var($departmentId, FILTER_VALIDATE_INT);
            $quantity = formString('quantity');
            $purpose = formString('purpose');

            if (!is_int($itemId) || $itemId < 1
                || !is_int($requestDepartmentId) || $requestDepartmentId < 1
                || !is_string($quantity) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $quantity)
                || (float) $quantity <= 0
                || !is_string($purpose) || $purpose === '' || strlen($purpose) > 1000) {
                throw new InvalidArgumentException('Choose a department and item, enter a positive quantity, and explain the purpose in 1,000 characters or fewer.');
            }

            $departmentQuery = $pdo->prepare('SELECT id FROM departments WHERE id = :id');
            $departmentQuery->execute(['id' => $requestDepartmentId]);
            if (!$departmentQuery->fetchColumn()) {
                throw new InvalidArgumentException('The selected department was not found.');
            }
            if (!$canChooseDepartment && $requestDepartmentId !== $departmentId) {
                throw new InvalidArgumentException('You can only request stock for your assigned department.');
            }

            $itemQuery = $pdo->prepare('SELECT id FROM store_items WHERE id = :id');
            $itemQuery->execute(['id' => $itemId]);
            if (!$itemQuery->fetchColumn()) {
                throw new InvalidArgumentException('The selected store item was not found.');
            }

            $insert = $pdo->prepare(
                "INSERT INTO store_requisitions
                    (department_id, item_id, quantity, purpose, requested_by, status)
                 VALUES (:department_id, :item_id, :quantity, :purpose, :requested_by, 'Pending')"
            );
            $insert->execute([
                'department_id' => $requestDepartmentId,
                'item_id' => $itemId,
                'quantity' => $quantity,
                'purpose' => $purpose,
                'requested_by' => $actorId,
            ]);
            $requestId = (int) $pdo->lastInsertId();
            recordAudit($pdo, 'Submitted store requisition #' . $requestId, 'store_requisitions', $requestId, [
                'department_id' => $requestDepartmentId,
                'item_id' => $itemId,
                'quantity' => $quantity,
                'purpose' => $purpose,
            ]);
        } elseif ($action === 'decide_requisition' && $canApprove) {
            $requestId = filter_var($_POST['requisition_id'] ?? null, FILTER_VALIDATE_INT);
            $decision = formString('decision');
            $approvalNotes = formString('approval_notes');
            if (!is_int($requestId) || $requestId < 1
                || !in_array($decision, ['Approved', 'Rejected'], true)
                || !is_string($approvalNotes) || strlen($approvalNotes) > 1000
                || ($decision === 'Rejected' && $approvalNotes === '')) {
                throw new InvalidArgumentException('Choose a valid decision. A reason of 1,000 characters or fewer is required when rejecting.');
            }

            $requestQuery = $pdo->prepare(
                'SELECT id, requested_by, status FROM store_requisitions WHERE id = :id FOR UPDATE'
            );
            $requestQuery->execute(['id' => $requestId]);
            $requisition = $requestQuery->fetch();
            if (!$requisition || $requisition['status'] !== 'Pending') {
                throw new InvalidArgumentException('This requisition is no longer awaiting approval.');
            }
            if ((int) $requisition['requested_by'] === $actorId) {
                throw new InvalidArgumentException('You cannot approve or reject your own requisition.');
            }

            $decisionUpdate = $pdo->prepare(
                'UPDATE store_requisitions
                 SET status = :status, approved_by = :approved_by, approval_notes = :notes,
                     approved_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND status = :pending'
            );
            $decisionUpdate->execute([
                'status' => $decision,
                'approved_by' => $actorId,
                'notes' => $approvalNotes ?: null,
                'id' => $requestId,
                'pending' => 'Pending',
            ]);
            if ($decisionUpdate->rowCount() !== 1) {
                throw new InvalidArgumentException('This requisition has already been decided.');
            }
            recordAudit($pdo, $decision . ' store requisition #' . $requestId, 'store_requisitions', $requestId, [
                'decision' => $decision,
                'notes' => $approvalNotes ?: null,
            ]);
        } elseif ($action === 'issue_requisition' && $canIssue) {
            $requestId = filter_var($_POST['requisition_id'] ?? null, FILTER_VALIDATE_INT);
            if (!is_int($requestId) || $requestId < 1) {
                throw new InvalidArgumentException('Choose a valid requisition to issue.');
            }

            $requestQuery = $pdo->prepare(
                'SELECT requisition.id, requisition.item_id, requisition.quantity, requisition.purpose,
                        requisition.requested_by, requisition.approved_by, requisition.status, items.item_name
                 FROM store_requisitions AS requisition
                 JOIN store_items AS items ON items.id = requisition.item_id
                 WHERE requisition.id = :id FOR UPDATE'
            );
            $requestQuery->execute(['id' => $requestId]);
            $requisition = $requestQuery->fetch();
            if (!$requisition || $requisition['status'] !== 'Approved') {
                throw new InvalidArgumentException('Only approved requisitions can be issued.');
            }
            if ((int) $requisition['requested_by'] === $actorId
                || (int) $requisition['approved_by'] === $actorId) {
                throw new InvalidArgumentException('The requester and approver must not issue this requisition.');
            }

            $itemLock = $pdo->prepare(
                'SELECT id, current_stock FROM store_items WHERE id = :id FOR UPDATE'
            );
            $itemLock->execute(['id' => (int) $requisition['item_id']]);
            $item = $itemLock->fetch();
            if (!$item || (float) $item['current_stock'] < (float) $requisition['quantity']) {
                throw new InvalidArgumentException('There is insufficient stock to issue this requisition. Receive stock or contact the requester before issuing.');
            }

            $stockUpdate = $pdo->prepare(
                'UPDATE store_items
                 SET current_stock = current_stock - :quantity
                 WHERE id = :id AND current_stock >= :required_quantity'
            );
            $stockUpdate->execute([
                'quantity' => $requisition['quantity'],
                'id' => (int) $requisition['item_id'],
                'required_quantity' => $requisition['quantity'],
            ]);
            if ($stockUpdate->rowCount() !== 1) {
                throw new InvalidArgumentException('Stock changed before the issue could be completed. Refresh and try again.');
            }

            $transaction = $pdo->prepare(
                "INSERT INTO stock_transactions (item_id, transaction_type, quantity, performed_by, notes)
                 VALUES (:item_id, 'OUT', :quantity, :performed_by, :notes)"
            );
            $transaction->execute([
                'item_id' => (int) $requisition['item_id'],
                'quantity' => $requisition['quantity'],
                'performed_by' => $actorId,
                'notes' => 'Requisition #' . $requestId . ': ' . $requisition['purpose'],
            ]);
            $transactionId = (int) $pdo->lastInsertId();

            $issueUpdate = $pdo->prepare(
                "UPDATE store_requisitions
                 SET status = 'Issued', issued_by = :issued_by, issued_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND status = 'Approved'"
            );
            $issueUpdate->execute(['issued_by' => $actorId, 'id' => $requestId]);
            if ($issueUpdate->rowCount() !== 1) {
                throw new InvalidArgumentException('This requisition has already been issued.');
            }
            recordAudit(
                $pdo,
                'Issued requisition #' . $requestId . ' for ' . $requisition['item_name'],
                'store_requisitions',
                $requestId,
                [
                    'item_id' => (int) $requisition['item_id'],
                    'quantity' => $requisition['quantity'],
                    'stock_transaction_id' => $transactionId,
                    'requested_by' => (int) $requisition['requested_by'],
                    'approved_by' => (int) $requisition['approved_by'],
                    'issued_by' => $actorId,
                ]
            );
        } else {
            throw new InvalidArgumentException('You are not allowed to perform this requisition action.');
        }

        $pdo->commit();
        setFlashMessage('success', 'Requisition changes saved successfully.');
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

    redirectTo('/modules/requisitions/index.php');
}

$items = $pdo->query(
    'SELECT id, item_name, unit, current_stock FROM store_items ORDER BY item_name'
)->fetchAll();
$departments = $pdo->query(
    'SELECT id, department_name FROM departments ORDER BY department_name'
)->fetchAll();
$departmentName = null;
if (is_int($departmentId)) {
    $assignedDepartment = $pdo->prepare('SELECT department_name FROM departments WHERE id = :id');
    $assignedDepartment->execute(['id' => $departmentId]);
    $departmentName = $assignedDepartment->fetchColumn();
}

$visibility = [];
$visibilityParameters = [];
if ($actorRole === 'super_admin' || $actorRole === 'approving_officer') {
    $visibility[] = '1 = 1';
} elseif ($canIssue) {
    $visibility[] = "(requisition.status IN ('Approved', 'Issued') OR requisition.requested_by = :actor_id)";
    $visibilityParameters['actor_id'] = $actorId;
} else {
    $visibility[] = 'requisition.requested_by = :actor_id';
    $visibilityParameters['actor_id'] = $actorId;
}
$requisitionQuery = $pdo->prepare(
    'SELECT requisition.id, requisition.quantity, requisition.purpose, requisition.status,
            requisition.approval_notes, requisition.requested_at, requisition.approved_at,
            requisition.issued_at, requisition.requested_by, requisition.approved_by,
            requisition.issued_by, items.item_name, items.unit,
            departments.department_name, requester.full_name AS requester_name,
            approver.full_name AS approver_name, issuer.full_name AS issuer_name
     FROM store_requisitions AS requisition
     JOIN store_items AS items ON items.id = requisition.item_id
     JOIN departments ON departments.id = requisition.department_id
     JOIN users AS requester ON requester.id = requisition.requested_by
     LEFT JOIN users AS approver ON approver.id = requisition.approved_by
     LEFT JOIN users AS issuer ON issuer.id = requisition.issued_by
     WHERE ' . implode(' AND ', $visibility) . '
     ORDER BY FIELD(requisition.status, \'Pending\', \'Approved\', \'Rejected\', \'Issued\'),
              requisition.requested_at DESC, requisition.id DESC
     LIMIT 200'
);
$requisitionQuery->execute($visibilityParameters);
$requisitions = $requisitionQuery->fetchAll();

$pageTitle = 'Store requisitions';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-heading">
        <div>
            <p class="eyebrow">Stock issue control</p>
            <h1>Store requisitions</h1>
            <p class="muted">Requests require a separate officer’s decision before stores can issue stock.</p>
        </div>
    </div>
    <?php showFlashMessage(); ?>
    <?php if ($canRequest): ?>
        <section class="panel form-panel">
            <div class="panel-heading"><div><h2>Request stock</h2><p class="muted">Stock is not deducted until an approved request is issued by stores.</p></div></div>
            <form method="post" class="data-form">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                <input type="hidden" name="action" value="request_stock">
                <?php if ($canChooseDepartment): ?>
                    <label>Department<select name="department_id" required><option value="">Select department</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>"><?= escapeHtml($department['department_name']) ?></option><?php endforeach; ?></select></label>
                <?php else: ?>
                    <label>Department<input value="<?= escapeHtml(is_string($departmentName) ? $departmentName : 'Your assigned department') ?>" disabled><span class="muted">Requests are assigned to your department.</span></label>
                    <input type="hidden" name="department_id" value="<?= (int) ($departmentId ?? 0) ?>">
                <?php endif; ?>
                <?php if (!$canRequest): ?>
                    <div class="alert alert-error" role="status">Your account is not assigned to a department, so you cannot submit a department requisition. Ask an administrator to assign your department.</div>
                <?php endif; ?>
                <label>Store item<select name="item_id" required><option value="">Select item</option><?php foreach ($items as $item): ?><option value="<?= (int) $item['id'] ?>"><?= escapeHtml($item['item_name'] . ' (' . $item['current_stock'] . ' ' . $item['unit'] . ' available)') ?></option><?php endforeach; ?></select></label>
                <label>Quantity<input name="quantity" type="number" min="0.01" step="0.01" required></label>
                <label class="form-field-wide">Purpose<textarea name="purpose" rows="3" maxlength="1000" required></textarea></label>
                <div class="form-actions"><button class="button button-primary" type="submit">Submit for approval</button></div>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel table-panel">
        <div class="panel-heading"><div><h2>Requisition register</h2><p class="muted">Latest 200 requests visible to your role.</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Request</th><th>Department / item</th><th>Quantity</th><th>Purpose</th><th>Requested by / date</th><th>Status / decision</th><th>Action</th></tr></thead>
                <tbody>
                <?php if ($requisitions === []): ?>
                    <tr><td class="empty-state" colspan="7">No requisitions are available.</td></tr>
                <?php else: foreach ($requisitions as $requisition): ?>
                    <tr>
                        <td>#<?= (int) $requisition['id'] ?></td>
                        <td><?= escapeHtml($requisition['department_name']) ?><br><strong><?= escapeHtml($requisition['item_name']) ?></strong></td>
                        <td><?= escapeHtml($requisition['quantity'] . ' ' . $requisition['unit']) ?></td>
                        <td><?= escapeHtml($requisition['purpose']) ?></td>
                        <td><?= escapeHtml($requisition['requester_name']) ?><br><span class="muted"><?= escapeHtml(date('M j, Y H:i', strtotime($requisition['requested_at']))) ?></span></td>
                        <td>
                            <span class="badge <?= $requisition['status'] === 'Rejected' ? 'badge-warning' : ($requisition['status'] === 'Issued' ? 'badge-success' : '') ?>"><?= escapeHtml($requisition['status']) ?></span>
                            <?php if ($requisition['approver_name'] !== null): ?><br><span class="muted">Decision by <?= escapeHtml($requisition['approver_name']) ?></span><?php endif; ?>
                            <?php if ($requisition['approval_notes'] !== null): ?><br><span><?= escapeHtml($requisition['approval_notes']) ?></span><?php endif; ?>
                            <?php if ($requisition['issuer_name'] !== null): ?><br><span class="muted">Issued by <?= escapeHtml($requisition['issuer_name']) ?></span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($canApprove && $requisition['status'] === 'Pending' && (int) $requisition['requested_by'] !== $actorId): ?>
                                <details><summary>Review request</summary>
                                    <form method="post" class="data-form data-form-single compact-form">
                                        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="decide_requisition">
                                        <input type="hidden" name="requisition_id" value="<?= (int) $requisition['id'] ?>">
                                        <label>Decision notes<textarea name="approval_notes" rows="2" maxlength="1000" placeholder="Required if rejecting"></textarea></label>
                                        <div class="row-actions">
                                            <button class="button button-primary button-small" name="decision" value="Approved" type="submit">Approve</button>
                                            <button class="button button-danger button-small" name="decision" value="Rejected" type="submit">Reject</button>
                                        </div>
                                    </form>
                                </details>
                            <?php elseif ($canIssue && $requisition['status'] === 'Approved'
                                && (int) $requisition['requested_by'] !== $actorId
                                && (int) $requisition['approved_by'] !== $actorId): ?>
                                <form method="post" class="inline-actions">
                                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="issue_requisition">
                                    <input type="hidden" name="requisition_id" value="<?= (int) $requisition['id'] ?>">
                                    <button class="button button-primary button-small" type="submit">Issue stock</button>
                                </form>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <p class="muted report-note">This local-demo baseline requires the requester, approver, and issuer to be different people. Academy finance thresholds, requisition forms, and department delegation rules require policy approval before operational use.</p>
    </section>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
