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
        } elseif ($action === 'stock_transaction') {
            $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
            $transactionType = formString('transaction_type');
            $quantity = formString('quantity');
            $notes = formString('notes');
            if (!is_int($itemId) || $itemId < 1
                || !in_array($transactionType, ['IN', 'OUT'], true)
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

            if ($transactionType === 'IN') {
                $stockUpdate = $pdo->prepare(
                    'UPDATE store_items SET current_stock = current_stock + :quantity WHERE id = :id'
                );
            } else {
                $stockUpdate = $pdo->prepare(
                    'UPDATE store_items SET current_stock = current_stock - :quantity
                     WHERE id = :id AND current_stock >= :required_quantity'
                );
            }
            $parameters = ['quantity' => $quantity, 'id' => $itemId];
            if ($transactionType === 'OUT') {
                $parameters['required_quantity'] = $quantity;
            }
            $stockUpdate->execute($parameters);
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
        setFlashMessage('success', 'Inventory changes saved successfully.');
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
        <p class="muted">Record stock receipts and issues. Stock changes and history are saved together.</p></div></div>
    <?php showFlashMessage(); ?>
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
        <article class="panel"><div class="panel-heading"><div><h2>Record stock movement</h2><p class="muted">Issues are blocked when stock is insufficient.</p></div></div>
            <form method="post" class="data-form data-form-single">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>"><input type="hidden" name="action" value="stock_transaction">
                <label>Item<select name="item_id" required><option value="">Select item</option><?php foreach ($items as $item): ?><option value="<?= (int) $item['id'] ?>"><?= escapeHtml($item['item_name'] . ' (' . $item['current_stock'] . ' ' . $item['unit'] . ')') ?></option><?php endforeach; ?></select></label>
                <label>Movement<select name="transaction_type" required><option value="IN">Stock received (IN)</option><option value="OUT">Stock issued (OUT)</option></select></label>
                <label>Quantity<input name="quantity" type="number" min="0.01" step="0.01" required></label>
                <label>Notes<textarea name="notes" rows="3" maxlength="5000"></textarea></label>
                <button class="button button-primary" type="submit">Save movement</button>
            </form>
        </article>
    </section>
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
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
