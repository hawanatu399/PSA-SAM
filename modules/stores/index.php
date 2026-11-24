<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
requireRole(['super_admin', 'admin_officer', 'stores_officer', 'approving_officer', 'management', 'department_staff']);

$pdo = Database::connection();
$actorId = (int) $_SESSION['user']['id'];
$actorRole = (string) $_SESSION['user']['role'];
$actorDepartmentId = $_SESSION['user']['department_id'] ?? null;
$isDepartmentStaff = !in_array($actorRole, ['super_admin', 'admin_officer', 'stores_officer'], true);
if ($isDepartmentStaff && (!is_int($actorDepartmentId) || $actorDepartmentId < 1)) {
    http_response_code(403);
    exit('A department must be assigned before recording department stock checks.');
}
$departments = $pdo->query('SELECT id, department_name FROM departments ORDER BY department_name')->fetchAll();
$quantityToCents = static function (string $quantity): int {
    [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
};

if (isPostRequest()) {
    requireValidFormToken();
    $action = formString('action');

    if ($isDepartmentStaff && !in_array($action, ['confirm_stock_check', 'record_stock_check'], true)) {
        http_response_code(403);
        exit('Your account can only record physical counts for its assigned department.');
    }

    try {
        $pdo->beginTransaction();

        if ($action === 'create_item') {
            $itemName = formString('item_name');
            $unit = formString('unit');
            $reorderLevel = formString('reorder_level', '0');
            if (!is_string($itemName) || $itemName === '' || strlen($itemName) > 180
                || !is_string($unit) || $unit === '' || strlen($unit) > 40
                || !is_string($reorderLevel) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $reorderLevel)) {
                throw new InvalidArgumentException('Enter an item name, unit, and valid non-negative reorder level.');
            }

            $insert = $pdo->prepare(
                'INSERT INTO store_items (item_name, unit, current_stock, reorder_level)
                 VALUES (:item_name, :unit, 0, :reorder_level)'
            );
            $insert->execute([
                'item_name' => $itemName,
                'unit' => $unit,
                'reorder_level' => $reorderLevel,
            ]);
            $itemId = (int) $pdo->lastInsertId();
            recordAudit($pdo, 'Created store item ' . $itemName, 'store_items', $itemId);
        } elseif (in_array($action, ['confirm_stock_check', 'record_stock_check'], true)) {
            $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
            $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
            $shift = formString('shift');
            $notes = formString('notes');
            $displayedStockInput = formString('expected_stock');
            $quantityInput = formString('quantity');
            if (!is_int($itemId) || $itemId < 1
                || !is_int($departmentId) || $departmentId < 1
                || ($isDepartmentStaff && $departmentId !== $actorDepartmentId)
                || !in_array($shift, ['Opening', 'Closing'], true)
                || !is_string($displayedStockInput)
                || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $displayedStockInput)
                || !is_string($notes) || strlen($notes) > 1000) {
                throw new InvalidArgumentException('Choose a valid item and count period, and keep notes under 1,000 characters.');
            }

            if ($action === 'record_stock_check'
                && (!is_string($quantityInput) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $quantityInput))) {
                throw new InvalidArgumentException('Enter the actual count as a non-negative quantity with up to two decimal places.');
            }

            $itemQuery = $pdo->prepare('SELECT id, item_name FROM store_items WHERE id = :id');
            $itemQuery->execute(['id' => $itemId]);
            $item = $itemQuery->fetch();
            if (!$item) {
                throw new InvalidArgumentException('The selected store item was not found.');
            }
            $departmentQuery = $pdo->prepare('SELECT department_name FROM departments WHERE id = :id');
            $departmentQuery->execute(['id' => $departmentId]);
            $departmentName = $departmentQuery->fetchColumn();
            if (!is_string($departmentName)) {
                throw new InvalidArgumentException('The selected department was not found.');
            }
            if ($isDepartmentStaff) {
                $attendanceCheck = $pdo->prepare(
                    'SELECT is_first_in, checked_out_at
                     FROM department_attendance
                     WHERE department_id = :department_id AND user_id = :user_id AND attendance_date = :attendance_date
                     FOR UPDATE'
                );
                $attendanceCheck->execute([
                    'department_id' => $departmentId,
                    'user_id' => $actorId,
                    'attendance_date' => (string) $pdo->query('SELECT CURRENT_DATE')->fetchColumn(),
                ]);
                $staffAttendance = $attendanceCheck->fetch();
                if (!$staffAttendance || $staffAttendance['checked_out_at'] !== null) {
                    throw new InvalidArgumentException('Check in to your department before recording its stock count.');
                }
                if ($shift === 'Opening' && (int) $staffAttendance['is_first_in'] !== 1) {
                    throw new InvalidArgumentException('Only the first person checked in can record the Opening stock count.');
                }
                if ($shift === 'Closing') {
                    $otherCheckedIn = $pdo->prepare(
                        'SELECT COUNT(*) FROM department_attendance
                         WHERE department_id = :department_id AND attendance_date = :attendance_date
                           AND user_id <> :user_id AND checked_out_at IS NULL'
                    );
                    $otherCheckedIn->execute([
                        'department_id' => $departmentId,
                        'attendance_date' => (string) $pdo->query('SELECT CURRENT_DATE')->fetchColumn(),
                        'user_id' => $actorId,
                    ]);
                    if ((int) $otherCheckedIn->fetchColumn() !== 0) {
                        throw new InvalidArgumentException('Only the last person still checked in can record the Closing stock count.');
                    }
                }
            }

            $ensureDepartmentStock = $pdo->prepare(
                'INSERT INTO department_stock (department_id, item_id, current_stock)
                 VALUES (:department_id, :item_id, 0)
                 ON DUPLICATE KEY UPDATE item_id = VALUES(item_id)'
            );
            $ensureDepartmentStock->execute(['department_id' => $departmentId, 'item_id' => $itemId]);
            $stockQuery = $pdo->prepare(
                'SELECT current_stock FROM department_stock
                 WHERE department_id = :department_id AND item_id = :item_id FOR UPDATE'
            );
            $stockQuery->execute(['department_id' => $departmentId, 'item_id' => $itemId]);
            $currentDepartmentStock = $stockQuery->fetchColumn();
            if ($currentDepartmentStock === false) {
                throw new RuntimeException('The department stock balance could not be loaded.');
            }

            $expectedStock = (string) $currentDepartmentStock;
            $expectedCents = $quantityToCents($expectedStock);
            if ($quantityToCents($displayedStockInput) !== $expectedCents) {
                throw new InvalidArgumentException('Recorded stock changed while this page was open. Refresh and count again.');
            }
            $actualCents = $action === 'confirm_stock_check'
                ? $expectedCents
                : $quantityToCents((string) $quantityInput);
            $actualStock = sprintf('%d.%02d', intdiv($actualCents, 100), $actualCents % 100);
            $differenceCents = $actualCents - $expectedCents;
            $result = $differenceCents === 0 ? 'Confirmed' : 'Adjusted';
            $checkDate = (string) $pdo->query('SELECT CURRENT_DATE')->fetchColumn();

            if ($differenceCents !== 0) {
                $stockUpdate = $pdo->prepare(
                    'UPDATE department_stock
                     SET current_stock = :actual_stock
                     WHERE department_id = :department_id AND item_id = :item_id'
                );
                $stockUpdate->execute([
                    'actual_stock' => $actualStock,
                    'department_id' => $departmentId,
                    'item_id' => $itemId,
                ]);
                if ($stockUpdate->rowCount() !== 1) {
                    throw new InvalidArgumentException('The stock quantity could not be updated. Refresh and try again.');
                }
            }

            $check = $pdo->prepare(
                'INSERT INTO stock_checks
                    (department_id, item_id, shift, check_date, expected_stock, actual_stock, result, notes, checked_by)
                 VALUES
                    (:department_id, :item_id, :shift, :check_date, :expected_stock, :actual_stock, :result, :notes, :checked_by)'
            );
            $check->execute([
                'department_id' => $departmentId,
                'item_id' => $itemId,
                'shift' => $shift,
                'check_date' => $checkDate,
                'expected_stock' => $expectedStock,
                'actual_stock' => $actualStock,
                'result' => $result,
                'notes' => $notes !== '' ? $notes : null,
                'checked_by' => $actorId,
            ]);
            $checkId = (int) $pdo->lastInsertId();
            recordAudit(
                $pdo,
                ($result === 'Confirmed' ? 'Confirmed' : 'Adjusted') . ' ' . strtolower($shift)
                    . ' stock count for ' . $item['item_name'] . ' in ' . $departmentName,
                'stock_checks',
                $checkId,
                [
                    'item_id' => $itemId,
                    'department_id' => $departmentId,
                    'shift' => $shift,
                    'check_date' => $checkDate,
                    'expected_stock' => $expectedStock,
                    'actual_stock' => $actualStock,
                    'notes' => $notes ?: null,
                ]
            );
            $successMessage = $result === 'Confirmed'
                ? $shift . ' count confirmed for ' . $item['item_name'] . '.'
                : 'Count saved and ' . $item['item_name'] . ' updated for the selected department.';
        } elseif ($action === 'stock_transaction') {
            $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
            $transactionType = formString('transaction_type');
            $quantity = formString('quantity');
            $notes = formString('notes');
            if (!is_int($itemId) || $itemId < 1
                || $transactionType !== 'IN'
                || !is_string($quantity) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $quantity)
                || (float) $quantity <= 0
                || !is_string($notes) || strlen($notes) > 5000) {
                throw new InvalidArgumentException('Enter a valid item, movement type, positive quantity, and notes under 5,000 characters.');
            }

            $itemQuery = $pdo->prepare('SELECT id, item_name FROM store_items WHERE id = :id FOR UPDATE');
            $itemQuery->execute(['id' => $itemId]);
            $item = $itemQuery->fetch();
            if (!$item) {
                throw new InvalidArgumentException('The selected store item was not found.');
            }

            $stockUpdate = $pdo->prepare(
                'UPDATE store_items SET current_stock = current_stock + :quantity WHERE id = :id'
            );
            $stockUpdate->execute(['quantity' => $quantity, 'id' => $itemId]);
            if ($stockUpdate->rowCount() !== 1) {
                throw new InvalidArgumentException('Insufficient stock for this issue.');
            }

            $transaction = $pdo->prepare(
                'INSERT INTO stock_transactions (item_id, transaction_type, quantity, performed_by, notes)
                 VALUES (:item_id, :transaction_type, :quantity, :performed_by, :notes)'
            );
            $transaction->execute([
                'item_id' => $itemId,
                'transaction_type' => $transactionType,
                'quantity' => $quantity,
                'performed_by' => $actorId,
                'notes' => $notes ?: null,
            ]);
            recordAudit(
                $pdo,
                ($transactionType === 'IN' ? 'Received ' : 'Issued ') . $quantity . ' ' . $item['item_name'],
                'stock_transactions',
                (int) $pdo->lastInsertId(),
                [
                    'item_id' => $itemId,
                    'transaction_type' => $transactionType,
                    'quantity' => $quantity,
                    'notes' => $notes ?: null,
                ]
            );
        } else {
            throw new InvalidArgumentException('Choose a valid inventory action.');
        }

        $pdo->commit();
        setFlashMessage('success', $successMessage ?? 'Inventory changes saved successfully.');
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

    redirectTo('/modules/stores/index.php');
}

$items = $pdo->query(
    'SELECT id, item_name, unit, current_stock, reorder_level
     FROM store_items ORDER BY item_name'
)->fetchAll();
$departmentInput = $_GET['department'] ?? '';
$selectedDepartmentId = $isDepartmentStaff
    ? $actorDepartmentId
    : (is_string($departmentInput) && $departmentInput !== ''
        ? filter_var($departmentInput, FILTER_VALIDATE_INT)
        : ($_SESSION['user']['department_id'] ?? null));
if (!is_int($selectedDepartmentId) || $selectedDepartmentId < 1
    || !in_array($selectedDepartmentId, array_map(
        static fn (array $department): int => (int) $department['id'],
        $departments
    ), true)) {
    $selectedDepartmentId = isset($departments[0]) ? (int) $departments[0]['id'] : null;
}
$selectedDepartmentName = '';
foreach ($departments as $department) {
    if ((int) $department['id'] === $selectedDepartmentId) {
        $selectedDepartmentName = (string) $department['department_name'];
        break;
    }
}
$shiftInput = $_GET['shift'] ?? 'Opening';
$selectedShift = is_string($shiftInput) && in_array($shiftInput, ['Opening', 'Closing'], true)
    ? $shiftInput
    : 'Opening';
$today = (string) $pdo->query('SELECT CURRENT_DATE')->fetchColumn();
$dailyChecksQuery = $pdo->prepare(
    'SELECT checks.item_id, checks.expected_stock, checks.actual_stock, checks.result,
            checks.checked_at, users.full_name
     FROM stock_checks AS checks
     JOIN (
         SELECT item_id, MAX(id) AS latest_id
         FROM stock_checks
         WHERE check_date = :check_date AND shift = :shift AND department_id = :department_id
         GROUP BY item_id
     ) AS latest ON latest.latest_id = checks.id
     JOIN users ON users.id = checks.checked_by'
);
$dailyChecksQuery->execute([
    'check_date' => $today,
    'shift' => $selectedShift,
    'department_id' => $selectedDepartmentId,
]);
$dailyChecks = [];
foreach ($dailyChecksQuery->fetchAll() as $dailyCheck) {
    $dailyChecks[(int) $dailyCheck['item_id']] = $dailyCheck;
}
$departmentItems = [];
if (is_int($selectedDepartmentId)) {
    $departmentItemsQuery = $pdo->prepare(
        'SELECT items.id, items.item_name, items.unit,
                COALESCE(stock.current_stock, 0.00) AS current_stock
         FROM store_items AS items
         LEFT JOIN department_stock AS stock
           ON stock.item_id = items.id AND stock.department_id = :department_id
         ORDER BY items.item_name'
    );
    $departmentItemsQuery->execute(['department_id' => $selectedDepartmentId]);
    $departmentItems = $departmentItemsQuery->fetchAll();
}
$recentChecksSql = 'SELECT checks.shift, checks.check_date, checks.expected_stock, checks.actual_stock,
            checks.result, checks.notes, checks.checked_at, items.item_name, items.unit,
            checks.department_id, departments.department_name, users.full_name
     FROM stock_checks AS checks
     JOIN store_items AS items ON items.id = checks.item_id
     JOIN departments ON departments.id = checks.department_id
     JOIN users ON users.id = checks.checked_by';
$recentChecksParameters = [];
if ($isDepartmentStaff) {
    $recentChecksSql .= ' WHERE checks.department_id = :department_id';
    $recentChecksParameters['department_id'] = $actorDepartmentId;
}
$recentChecksSql .= ' ORDER BY checks.checked_at DESC, checks.id DESC LIMIT 50';
$recentChecksQuery = $pdo->prepare($recentChecksSql);
$recentChecksQuery->execute($recentChecksParameters);
$recentChecks = $recentChecksQuery->fetchAll();
$transactions = $pdo->query(
    'SELECT transactions.transaction_type, transactions.quantity, transactions.transaction_date,
            transactions.notes, items.item_name, items.unit, users.full_name
     FROM stock_transactions AS transactions
     JOIN store_items AS items ON items.id = transactions.item_id
     JOIN users ON users.id = transactions.performed_by
     ORDER BY transactions.transaction_date DESC, transactions.id DESC LIMIT 50'
)->fetchAll();

$pageTitle = 'Stores & inventory';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-heading"><div><p class="eyebrow">Inventory control</p><h1>Stores &amp; inventory</h1>
        <p class="muted"><?= $isDepartmentStaff ? 'Record your department opening and closing consumable stock counts.' : 'Record stock receipts. Stock issues are tied to separately approved requisitions.' ?></p></div></div>
    <?php showFlashMessage(); ?>
    <?php if (!$isDepartmentStaff): ?>
    <section class="content-grid">
        <article class="panel"><div class="panel-heading"><div><h2>Create a store item</h2><p class="muted">New items begin with zero stock.</p></div></div>
            <form method="post" class="data-form data-form-single">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>"><input type="hidden" name="action" value="create_item">
                <label>Item name<input name="item_name" maxlength="180" required></label>
                <label>Unit (e.g. box, ream)<input name="unit" maxlength="40" required></label>
                <label>Reorder level<input name="reorder_level" type="number" min="0" step="0.01" value="0" required></label>
                <button class="button button-primary" type="submit">Add item</button>
            </form>
        </article>
        <article class="panel"><div class="panel-heading"><div><h2>Record stock receipt</h2><p class="muted">Use a requisition for each stock issue.</p></div></div>
            <form method="post" class="data-form data-form-single">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>"><input type="hidden" name="action" value="stock_transaction">
                <label>Item<select name="item_id" required><option value="">Select item</option><?php foreach ($items as $item): ?><option value="<?= (int) $item['id'] ?>"><?= escapeHtml($item['item_name'] . ' (' . $item['current_stock'] . ' ' . $item['unit'] . ')') ?></option><?php endforeach; ?></select></label>
                <input type="hidden" name="transaction_type" value="IN">
                <label>Movement<input value="Stock received (IN)" disabled></label>
                <label>Quantity<input name="quantity" type="number" min="0.01" step="0.01" required></label>
                <label>Notes<textarea name="notes" rows="3" maxlength="5000"></textarea></label>
                <button class="button button-primary" type="submit">Record receipt</button>
            </form>
        </article>
    </section>
    <?php endif; ?>
    <section class="panel table-panel">
        <div class="panel-heading"><div><h2>Daily physical stock check</h2>
            <p class="muted">Check the selected department's consumable stock at opening or closing. Its stock is separate from central Stores inventory. The first staff member in should complete Opening; an authorized Stores/Admin officer can record it if needed. The last staff member out completes Closing. For <?= escapeHtml(date('l, F j, Y', strtotime($today))) ?>.</p></div></div>
        <form method="get" class="stock-check-period">
            <?php if ($isDepartmentStaff): ?>
                <input type="hidden" name="department" value="<?= (int) $selectedDepartmentId ?>">
                <span class="muted">Department: <strong><?= escapeHtml($selectedDepartmentName) ?></strong></span>
            <?php else: ?>
            <label for="stock-check-department">Department</label>
            <select id="stock-check-department" name="department" required>
                <?php foreach ($departments as $department): ?>
                    <option value="<?= (int) $department['id'] ?>" <?= (int) $department['id'] === $selectedDepartmentId ? 'selected' : '' ?>><?= escapeHtml($department['department_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <label for="stock-check-shift">Count period</label>
            <select id="stock-check-shift" name="shift">
                <option value="Opening" <?= $selectedShift === 'Opening' ? 'selected' : '' ?>>Opening (first person in or authorized recovery)</option>
                <option value="Closing" <?= $selectedShift === 'Closing' ? 'selected' : '' ?>>Closing (last person out)</option>
            </select>
            <button class="button button-secondary" type="submit">Show department count</button>
        </form>
        <?php if ($departments === []): ?>
            <div class="alert alert-error" role="status">Add a department in Reference data before recording department counts.</div>
        <?php else: ?>
        <p class="muted">Department: <strong><?= escapeHtml($selectedDepartmentName) ?></strong>. Confirm matching quantities with one click, or enter the physical count and save it to reconcile a difference. Notes are recommended for adjustments.</p>
        <div class="table-wrap"><table><thead><tr><th>Item</th><th>Department stock</th><th>Today's <?= escapeHtml(strtolower($selectedShift)) ?> count</th><th>Physical quantity</th><th>Action</th></tr></thead><tbody>
            <?php if ($departmentItems === []): ?><tr><td class="empty-state" colspan="5">Add store items before recording a physical count.</td></tr>
            <?php else: foreach ($departmentItems as $item): $dailyCheck = $dailyChecks[(int) $item['id']] ?? null; ?><tr>
                <td><?= escapeHtml($item['item_name']) ?> <span class="muted">(<?= escapeHtml($item['unit']) ?>)</span></td>
                <td><?= escapeHtml($item['current_stock']) ?></td>
                <td><?php if ($dailyCheck): ?>
                    <span class="badge <?= $dailyCheck['result'] === 'Confirmed' ? 'badge-success' : 'badge-warning' ?>"><?= escapeHtml($dailyCheck['result']) ?></span>
                    <span class="muted"><?= escapeHtml($dailyCheck['actual_stock']) ?> at <?= escapeHtml(date('H:i', strtotime($dailyCheck['checked_at']))) ?> by <?= escapeHtml($dailyCheck['full_name']) ?></span>
                <?php else: ?><span class="muted">Not checked</span><?php endif; ?></td>
                <td colspan="2">
                    <form method="post" class="stock-check-actions">
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                        <input type="hidden" name="department_id" value="<?= (int) $selectedDepartmentId ?>">
                        <input type="hidden" name="shift" value="<?= escapeHtml($selectedShift) ?>">
                        <input type="hidden" name="expected_stock" value="<?= escapeHtml($item['current_stock']) ?>">
                        <label class="sr-only" for="actual-stock-<?= (int) $item['id'] ?>">Actual physical quantity for <?= escapeHtml($item['item_name']) ?></label>
                        <input id="actual-stock-<?= (int) $item['id'] ?>" name="quantity" type="number" min="0" max="9999999999.99" step="0.01" value="<?= escapeHtml($item['current_stock']) ?>" required aria-label="Actual physical quantity for <?= escapeHtml($item['item_name']) ?>">
                        <input name="notes" type="text" maxlength="1000" placeholder="Note about a difference (optional)" aria-label="Optional note for <?= escapeHtml($item['item_name']) ?>">
                        <div class="stock-check-buttons">
                            <button class="button button-primary button-small" type="submit" name="action" value="confirm_stock_check">Confirm count</button>
                            <button class="button button-secondary button-small" type="submit" name="action" value="record_stock_check">Save actual count</button>
                        </div>
                    </form>
                </td>
            </tr><?php endforeach; endif; ?>
        </tbody></table></div>
        <?php endif; ?>
    </section>
    <?php if (!$isDepartmentStaff): ?>
    <section class="panel table-panel"><div class="panel-heading"><div><h2>Current stock</h2><p class="muted">Items at or below reorder level are highlighted.</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Item</th><th>Unit</th><th>On hand</th><th>Reorder level</th><th>Status</th></tr></thead><tbody>
            <?php if ($items === []): ?><tr><td class="empty-state" colspan="5">No store items have been created.</td></tr>
            <?php else: foreach ($items as $item): $isLow = (float) $item['current_stock'] <= (float) $item['reorder_level']; ?><tr>
                <td><?= escapeHtml($item['item_name']) ?></td><td><?= escapeHtml($item['unit']) ?></td>
                <td><?= escapeHtml($item['current_stock']) ?></td><td><?= escapeHtml($item['reorder_level']) ?></td>
                <td><span class="badge <?= $isLow ? 'badge-warning' : 'badge-success' ?>"><?= $isLow ? 'Reorder required' : 'In stock' ?></span></td>
            </tr><?php endforeach; endif; ?>
        </tbody></table></div>
    </section>
    <section class="panel table-panel"><div class="panel-heading"><div><h2>Recent physical counts</h2><p class="muted">Latest 50 opening and closing checks across departments</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Date and time</th><th>Period</th><th>Department</th><th>Item</th><th>Recorded quantity</th><th>Physical count</th><th>Result</th><th>Checked by</th><th>Notes</th></tr></thead><tbody>
            <?php if ($recentChecks === []): ?><tr><td class="empty-state" colspan="9">No physical counts have been recorded.</td></tr>
            <?php else: foreach ($recentChecks as $check): ?><tr>
                <td><?= escapeHtml(date('M j, Y H:i', strtotime($check['checked_at']))) ?></td>
                <td><?= escapeHtml($check['shift']) ?></td><td><?= escapeHtml($check['department_name']) ?></td><td><?= escapeHtml($check['item_name']) ?></td>
                <td><?= escapeHtml($check['expected_stock'] . ' ' . $check['unit']) ?></td>
                <td><?= escapeHtml($check['actual_stock'] . ' ' . $check['unit']) ?></td>
                <td><span class="badge <?= $check['result'] === 'Confirmed' ? 'badge-success' : 'badge-warning' ?>"><?= escapeHtml($check['result']) ?></span></td>
                <td><?= escapeHtml($check['full_name']) ?></td><td><?= escapeHtml($check['notes'] ?? '') ?></td>
            </tr><?php endforeach; endif; ?>
        </tbody></table></div>
    </section>
    <section class="panel table-panel"><div class="panel-heading"><div><h2>Recent stock movements</h2><p class="muted">Latest 50 inventory transactions</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Date</th><th>Item</th><th>Movement</th><th>Quantity</th><th>Performed by</th><th>Notes</th></tr></thead><tbody>
            <?php if ($transactions === []): ?><tr><td class="empty-state" colspan="6">No stock movements have been recorded.</td></tr>
            <?php else: foreach ($transactions as $transaction): ?><tr>
                <td><?= escapeHtml(date('M j, Y H:i', strtotime($transaction['transaction_date']))) ?></td>
                <td><?= escapeHtml($transaction['item_name']) ?></td><td><span class="badge"><?= escapeHtml($transaction['transaction_type']) ?></span></td>
                <td><?= escapeHtml($transaction['quantity'] . ' ' . $transaction['unit']) ?></td>
                <td><?= escapeHtml($transaction['full_name']) ?></td><td><?= escapeHtml($transaction['notes'] ?? '') ?></td>
            </tr><?php endforeach; endif; ?>
        </tbody></table></div>
    </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
