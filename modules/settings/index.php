<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
requireRole('super_admin');

$pdo = Database::connection();

if (isPostRequest()) {
    requireValidFormToken();
    $action = formString('action');

    try {
        $pdo->beginTransaction();

        if (in_array($action, ['create_department', 'update_department'], true)) {
            $departmentId = null;
            if ($action === 'update_department') {
                $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
            }
            $name = formString('department_name');
            $code = formString('department_code');
            if (($action === 'update_department' && (!is_int($departmentId) || $departmentId < 1))
                || !is_string($name) || $name === '' || strlen($name) > 120
                || !is_string($code) || $code === '' || strlen($code) > 20) {
                throw new InvalidArgumentException('Enter a department name (up to 120 characters) and code (up to 20 characters).');
            }

            if ($action === 'create_department') {
                $statement = $pdo->prepare(
                    'INSERT INTO departments (department_name, code) VALUES (:name, :code)'
                );
                $statement->execute(['name' => $name, 'code' => $code]);
                $recordId = (int) $pdo->lastInsertId();
                recordAudit($pdo, 'Created department ' . $name, 'departments', $recordId, [
                    'department_name' => $name,
                    'code' => $code,
                ]);
            } else {
                $current = $pdo->prepare(
                    'SELECT department_name, code FROM departments WHERE id = :id FOR UPDATE'
                );
                $current->execute(['id' => $departmentId]);
                $before = $current->fetch();
                if (!$before) {
                    throw new InvalidArgumentException('The selected department was not found.');
                }
                $statement = $pdo->prepare(
                    'UPDATE departments SET department_name = :name, code = :code WHERE id = :id'
                );
                $statement->execute(['name' => $name, 'code' => $code, 'id' => $departmentId]);
                $recordId = $departmentId;
                recordAudit($pdo, 'Updated department ' . $name, 'departments', $recordId, [
                    'before' => $before,
                    'after' => ['department_name' => $name, 'code' => $code],
                ]);
            }
        } elseif (in_array($action, ['create_category', 'update_category'], true)) {
            $categoryId = null;
            if ($action === 'update_category') {
                $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
            }
            $name = formString('category_name');
            $description = formString('description');
            if (($action === 'update_category' && (!is_int($categoryId) || $categoryId < 1))
                || !is_string($name) || $name === '' || strlen($name) > 120
                || !is_string($description) || strlen($description) > 5000) {
                throw new InvalidArgumentException('Enter a category name (up to 120 characters) and description (up to 5,000 characters).');
            }

            if ($action === 'create_category') {
                $statement = $pdo->prepare(
                    'INSERT INTO asset_categories (category_name, description) VALUES (:name, :description)'
                );
                $statement->execute(['name' => $name, 'description' => $description ?: null]);
                $recordId = (int) $pdo->lastInsertId();
                recordAudit($pdo, 'Created asset category ' . $name, 'asset_categories', $recordId, [
                    'category_name' => $name,
                    'description' => $description ?: null,
                ]);
            } else {
                $current = $pdo->prepare(
                    'SELECT category_name, description FROM asset_categories WHERE id = :id FOR UPDATE'
                );
                $current->execute(['id' => $categoryId]);
                $before = $current->fetch();
                if (!$before) {
                    throw new InvalidArgumentException('The selected asset category was not found.');
                }
                $statement = $pdo->prepare(
                    'UPDATE asset_categories SET category_name = :name, description = :description WHERE id = :id'
                );
                $statement->execute([
                    'name' => $name,
                    'description' => $description ?: null,
                    'id' => $categoryId,
                ]);
                $recordId = $categoryId;
                recordAudit($pdo, 'Updated asset category ' . $name, 'asset_categories', $recordId, [
                    'before' => $before,
                    'after' => ['category_name' => $name, 'description' => $description ?: null],
                ]);
            }
        } else {
            throw new InvalidArgumentException('Choose a valid reference-data action.');
        }

        $pdo->commit();
        setFlashMessage('success', 'Reference data saved successfully.');
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', $exception->getMessage());
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception->getCode() === '23000') {
            setFlashMessage('error', 'That department name/code or category name is already in use.');
        } else {
            reportDatabaseFailure($exception);
        }
    }

    redirectTo('/modules/settings/index.php');
}

$departments = $pdo->query(
    'SELECT id, department_name, code FROM departments ORDER BY department_name'
)->fetchAll();
$categories = $pdo->query(
    'SELECT id, category_name, description FROM asset_categories ORDER BY category_name'
)->fetchAll();

$pageTitle = 'Reference data';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-heading">
        <div>
            <p class="eyebrow">System administration</p>
            <h1>Reference data</h1>
            <p class="muted">Maintain the departments and asset categories used throughout the register.</p>
        </div>
    </div>
    <?php showFlashMessage(); ?>
    <section class="content-grid">
        <article class="panel">
            <div class="panel-heading"><div><h2>Add a department</h2><p class="muted">Departments already used by records cannot be deleted.</p></div></div>
            <form method="post" class="data-form data-form-single">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                <input type="hidden" name="action" value="create_department">
                <label>Department name<input name="department_name" maxlength="120" required></label>
                <label>Department code<input name="department_code" maxlength="20" required></label>
                <button class="button button-primary" type="submit">Add department</button>
            </form>
        </article>
        <article class="panel">
            <div class="panel-heading"><div><h2>Add an asset category</h2><p class="muted">Categories already used by assets cannot be deleted.</p></div></div>
            <form method="post" class="data-form data-form-single">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                <input type="hidden" name="action" value="create_category">
                <label>Category name<input name="category_name" maxlength="120" required></label>
                <label>Description<textarea name="description" rows="3" maxlength="5000"></textarea></label>
                <button class="button button-primary" type="submit">Add category</button>
            </form>
        </article>
    </section>
    <section class="content-grid">
        <article class="panel table-panel">
            <div class="panel-heading"><div><h2>Departments</h2><p class="muted"><?= number_format(count($departments)) ?> configured</p></div></div>
            <div class="table-wrap"><table>
                <thead><tr><th>Name</th><th>Code</th><th>Action</th></tr></thead>
                <tbody>
                <?php if ($departments === []): ?>
                    <tr><td colspan="3" class="muted">No departments are configured.</td></tr>
                <?php else: ?>
                    <?php foreach ($departments as $department): ?>
                        <?php $formId = 'department-form-' . (int) $department['id']; ?>
                        <tr>
                            <td>
                                <form id="<?= escapeHtml($formId) ?>" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="update_department">
                                    <input type="hidden" name="department_id" value="<?= (int) $department['id'] ?>">
                                </form>
                                <label class="sr-only" for="department-name-<?= (int) $department['id'] ?>">Department name</label>
                                <input form="<?= escapeHtml($formId) ?>" id="department-name-<?= (int) $department['id'] ?>" name="department_name" maxlength="120" value="<?= escapeHtml($department['department_name']) ?>" required>
                            </td>
                            <td><label class="sr-only" for="department-code-<?= (int) $department['id'] ?>">Department code</label>
                                <input form="<?= escapeHtml($formId) ?>" id="department-code-<?= (int) $department['id'] ?>" name="department_code" maxlength="20" value="<?= escapeHtml($department['code']) ?>" required></td>
                            <td><button form="<?= escapeHtml($formId) ?>" class="button button-secondary button-small" type="submit">Save</button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table></div>
        </article>
        <article class="panel table-panel">
            <div class="panel-heading"><div><h2>Asset categories</h2><p class="muted"><?= number_format(count($categories)) ?> configured</p></div></div>
            <div class="table-wrap"><table>
                <thead><tr><th>Name</th><th>Description</th><th>Action</th></tr></thead>
                <tbody>
                <?php if ($categories === []): ?>
                    <tr><td colspan="3" class="muted">No asset categories are configured.</td></tr>
                <?php else: ?>
                    <?php foreach ($categories as $category): ?>
                        <?php $formId = 'category-form-' . (int) $category['id']; ?>
                        <tr>
                            <td>
                                <form id="<?= escapeHtml($formId) ?>" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="update_category">
                                    <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                                </form>
                                <label class="sr-only" for="category-name-<?= (int) $category['id'] ?>">Category name</label>
                                <input form="<?= escapeHtml($formId) ?>" id="category-name-<?= (int) $category['id'] ?>" name="category_name" maxlength="120" value="<?= escapeHtml($category['category_name']) ?>" required>
                            </td>
                            <td><label class="sr-only" for="category-description-<?= (int) $category['id'] ?>">Description</label>
                                <textarea form="<?= escapeHtml($formId) ?>" id="category-description-<?= (int) $category['id'] ?>" name="description" rows="2" maxlength="5000"><?= escapeHtml($category['description'] ?? '') ?></textarea></td>
                            <td><button form="<?= escapeHtml($formId) ?>" class="button button-secondary button-small" type="submit">Save</button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table></div>
        </article>
    </section>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
