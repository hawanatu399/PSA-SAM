<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
requireRole(['super_admin', 'admin_officer', 'stores_officer']);

$pdo = Database::connection();
require_once __DIR__ . '/../../includes/code39.php';

if (isPostRequest()) {
    requireValidFormToken();
    $action = formString('action', 'create_asset');

    if ($action === 'update_asset') {
        requireRole(['super_admin', 'admin_officer']);
        $assetId = filter_var($_POST['asset_id'] ?? null, FILTER_VALIDATE_INT);
        $assetTag = formString('asset_tag_id');
        $assetName = formString('asset_name');
        $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
        $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
        $condition = formString('condition_status');
        $brand = formString('brand');
        $model = formString('model');
        $serialNumber = formString('serial_number');
        $location = formString('location');
        $acquisitionDate = formString('acquisition_date');
        $dateIsValid = $acquisitionDate === '' || (
            is_string($acquisitionDate)
            && DateTime::createFromFormat('!Y-m-d', $acquisitionDate) !== false
            && DateTime::getLastErrors() === false
        );

        if (!is_int($assetId) || $assetId < 1
            || !is_string($assetTag) || $assetTag === '' || strlen($assetTag) > 60
            || !is_string($assetName) || $assetName === '' || strlen($assetName) > 180
            || !is_int($categoryId) || $categoryId < 1
            || !is_int($departmentId) || $departmentId < 1
            || !in_array($condition, ['Good', 'Fair', 'Poor', 'Unserviceable'], true)
            || !is_string($brand) || strlen($brand) > 100
            || !is_string($model) || strlen($model) > 100
            || !is_string($serialNumber) || strlen($serialNumber) > 120
            || !is_string($location) || strlen($location) > 180
            || !$dateIsValid) {
            setFlashMessage('error', 'Enter valid asset details and try again.');
            redirectTo('/modules/assets/index.php');
        }

        try {
            $pdo->beginTransaction();
            $assetLock = $pdo->prepare(
                'SELECT asset_tag_id, asset_name, brand, model, serial_number, category_id,
                        department_id, location, condition_status, acquisition_date
                 FROM assets WHERE id = :id FOR UPDATE'
            );
            $assetLock->execute(['id' => $assetId]);
            $before = $assetLock->fetch();
            if (!$before) {
                throw new InvalidArgumentException('The asset was not found.');
            }
            if ($condition === 'Unserviceable') {
                $activeAssignment = $pdo->prepare(
                    "SELECT id FROM asset_assignments WHERE asset_id = :asset_id AND status = 'Active' LIMIT 1 FOR UPDATE"
                );
                $activeAssignment->execute(['asset_id' => $assetId]);
                if ($activeAssignment->fetchColumn()) {
                    throw new InvalidArgumentException('Record the active assignment return before marking this asset unserviceable.');
                }
            }
            $statement = $pdo->prepare(
                'UPDATE assets
                 SET asset_tag_id = :asset_tag, asset_name = :asset_name, brand = :brand,
                     model = :model, serial_number = :serial_number, category_id = :category_id,
                     department_id = :department_id,
                     location = :location,
                     condition_status = :condition_status, acquisition_date = :acquisition_date
                 WHERE id = :id'
            );
            $statement->execute([
                'asset_tag' => $assetTag,
                'asset_name' => $assetName,
                'brand' => $brand ?: null,
                'model' => $model ?: null,
                'serial_number' => $serialNumber ?: null,
                'category_id' => $categoryId,
                'department_id' => $departmentId,
                'location' => $location ?: null,
                'condition_status' => $condition,
                'acquisition_date' => $acquisitionDate ?: null,
                'id' => $assetId,
            ]);
            if ($statement->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT id FROM assets WHERE id = :id');
                $exists->execute(['id' => $assetId]);
                if (!$exists->fetchColumn()) {
                    throw new InvalidArgumentException('The asset was not found.');
                }
            }
            recordAudit($pdo, 'Updated asset ' . $assetTag, 'assets', $assetId, [
                'before' => $before,
                'after' => [
                    'asset_tag_id' => $assetTag,
                    'asset_name' => $assetName,
                    'brand' => $brand ?: null,
                    'model' => $model ?: null,
                    'serial_number' => $serialNumber ?: null,
                    'category_id' => $categoryId,
                    'department_id' => $departmentId,
                    'location' => $location ?: null,
                    'condition_status' => $condition,
                    'acquisition_date' => $acquisitionDate ?: null,
                ],
            ]);
            $pdo->commit();
            setFlashMessage('success', 'Asset details updated successfully.');
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

        redirectTo('/modules/assets/index.php');
    }

    if ($action !== 'create_asset') {
        http_response_code(400);
        exit('Invalid asset action.');
    }

    $assetTag = formString('asset_tag_id');
    $assetName = formString('asset_name');
    $condition = formString('condition_status', 'Good');
    $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
    $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
    $custodianInput = formString('custodian_id');
    $custodianId = $custodianInput === ''
        ? null
        : filter_var($custodianInput, FILTER_VALIDATE_INT);
    $acquisitionDate = formString('acquisition_date');
    $brand = formString('brand');
    $model = formString('model');
    $serialNumber = formString('serial_number');
    $location = formString('location');
    $dateIsValid = $acquisitionDate === '' || (
        is_string($acquisitionDate)
        && DateTime::createFromFormat('!Y-m-d', $acquisitionDate) !== false
        && DateTime::getLastErrors() === false
    );

    if (!is_string($assetTag) || $assetTag === '' || strlen($assetTag) > 60
        || !is_string($assetName) || $assetName === '' || strlen($assetName) > 180
        || !is_int($categoryId) || $categoryId < 1
        || !is_int($departmentId) || $departmentId < 1
        || ($custodianId !== null && (!is_int($custodianId) || $custodianId < 1))
        || !is_string($condition) || !in_array($condition, ['Good', 'Fair', 'Poor', 'Unserviceable'], true)
        || !is_string($brand) || strlen($brand) > 100
        || !is_string($model) || strlen($model) > 100
        || !is_string($serialNumber) || strlen($serialNumber) > 120
        || !is_string($location) || strlen($location) > 180
        || !$dateIsValid) {
        setFlashMessage('error', 'Enter a valid asset tag, name, category, department, custodian, and acquisition date.');
        redirectTo('/modules/assets/index.php');
    }

    try {
        if ($custodianId !== null) {
            $activeCustodian = $pdo->prepare("SELECT id FROM users WHERE id = :id AND status = 'Active'");
            $activeCustodian->execute(['id' => $custodianId]);
            if (!$activeCustodian->fetchColumn()) {
                throw new InvalidArgumentException('Choose an active custodian or leave the asset unassigned.');
            }
        }

        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            'INSERT INTO assets (
                asset_tag_id, asset_name, brand, model, serial_number, category_id, department_id,
                location, condition_status, custodian_id, acquisition_date, verification_status
             ) VALUES (
                :asset_tag, :asset_name, :brand, :model, :serial_number, :category_id, :department_id,
                :location, :condition_status, :custodian_id, :acquisition_date, :verification_status
             )'
        );
        $statement->execute([
            'asset_tag' => $assetTag,
            'asset_name' => $assetName,
            'brand' => $brand ?: null,
            'model' => $model ?: null,
            'serial_number' => $serialNumber ?: null,
            'category_id' => $categoryId,
            'department_id' => $departmentId,
            'location' => $location ?: null,
            'condition_status' => $condition,
            'custodian_id' => $custodianId,
            'acquisition_date' => $acquisitionDate ? $acquisitionDate : null,
            'verification_status' => 'Unverified',
        ]);
        $assetId = (int) $pdo->lastInsertId();
        recordAudit($pdo, 'Registered asset ' . $assetTag, 'assets', $assetId);
        if ($custodianId !== null) {
            $assignment = $pdo->prepare(
                'INSERT INTO asset_assignments (asset_id, assigned_to_user, assigned_by_user, date_assigned)
                 VALUES (:asset_id, :assigned_to_user, :assigned_by_user, CURRENT_DATE)'
            );
            $assignment->execute([
                'asset_id' => $assetId,
                'assigned_to_user' => $custodianId,
                'assigned_by_user' => (int) $_SESSION['user']['id'],
            ]);
            recordAudit($pdo, 'Assigned newly registered asset ' . $assetTag, 'asset_assignments', (int) $pdo->lastInsertId());
        }
        $pdo->commit();
        setFlashMessage('success', 'Asset registered successfully.');
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

    redirectTo('/modules/assets/index.php');
}

$categories = $pdo->query('SELECT id, category_name FROM asset_categories ORDER BY category_name')->fetchAll();
$departments = $pdo->query('SELECT id, department_name FROM departments ORDER BY department_name')->fetchAll();
$users = $pdo->query("SELECT id, full_name FROM users WHERE status = 'Active' ORDER BY full_name")->fetchAll();
$searchInput = $_GET['search'] ?? '';
$assetSearch = is_string($searchInput) ? substr(trim($searchInput), 0, 180) : '';
$departmentInput = $_GET['department'] ?? '';
$departmentFilter = is_string($departmentInput) && $departmentInput !== ''
    ? filter_var($departmentInput, FILTER_VALIDATE_INT)
    : null;
$categoryInput = $_GET['category'] ?? '';
$categoryFilter = is_string($categoryInput) && $categoryInput !== ''
    ? filter_var($categoryInput, FILTER_VALIDATE_INT)
    : null;
$conditionInput = $_GET['condition'] ?? '';
$conditionFilter = is_string($conditionInput)
    && in_array($conditionInput, ['Good', 'Fair', 'Poor', 'Unserviceable'], true)
    ? $conditionInput
    : '';
$verificationInput = $_GET['verification'] ?? '';
$verificationFilter = is_string($verificationInput)
    && in_array($verificationInput, ['Verified', 'Unverified'], true)
    ? $verificationInput
    : '';
$assetConditions = [];
$assetParameters = [];
if ($assetSearch !== '') {
    $searchColumns = ['asset_tag_id', 'asset_name', 'brand', 'model', 'serial_number', 'location'];
    $searchConditions = [];
    foreach ($searchColumns as $index => $column) {
        $parameter = 'search_' . $index;
        $searchConditions[] = 'assets.' . $column . ' LIKE :' . $parameter;
        $assetParameters[$parameter] = '%' . $assetSearch . '%';
    }
    $assetConditions[] = '(' . implode(' OR ', $searchConditions) . ')';
}
if (is_int($departmentFilter) && $departmentFilter > 0) {
    $assetConditions[] = 'assets.department_id = :department_id';
    $assetParameters['department_id'] = $departmentFilter;
}
if (is_int($categoryFilter) && $categoryFilter > 0) {
    $assetConditions[] = 'assets.category_id = :category_id';
    $assetParameters['category_id'] = $categoryFilter;
}
if ($conditionFilter !== '') {
    $assetConditions[] = 'assets.condition_status = :condition_status';
    $assetParameters['condition_status'] = $conditionFilter;
}
if ($verificationFilter !== '') {
    $assetConditions[] = 'assets.verification_status = :verification_status';
    $assetParameters['verification_status'] = $verificationFilter;
}
$assetWhere = $assetConditions === [] ? '' : ' WHERE ' . implode(' AND ', $assetConditions);
$hasAssetFilters = $assetConditions !== [];
$countAssets = $pdo->prepare('SELECT COUNT(*) FROM assets' . $assetWhere);
$countAssets->execute($assetParameters);
$assetCount = (int) $countAssets->fetchColumn();
$assetQuery = $pdo->prepare(
    'SELECT assets.id, assets.asset_tag_id, assets.asset_name, assets.brand, assets.model,
            assets.serial_number, assets.location, assets.condition_status, assets.verification_status,
            assets.acquisition_date, assets.department_id, assets.category_id,
            departments.department_name, asset_categories.category_name
     FROM assets
     JOIN departments ON departments.id = assets.department_id
     JOIN asset_categories ON asset_categories.id = assets.category_id
     ' . $assetWhere . '
     ORDER BY assets.created_at DESC, assets.id DESC
     LIMIT 100'
);
$assetQuery->execute($assetParameters);
$assets = $assetQuery->fetchAll();
$scannedAsset = null;
$scanInput = $_GET['scan_asset_id'] ?? '';
if (is_string($scanInput) && $scanInput !== '') {
    $scannedAssetId = filter_var($scanInput, FILTER_VALIDATE_INT);
    if (!is_int($scannedAssetId) || $scannedAssetId < 1) {
        http_response_code(400);
        exit('Scan a valid asset barcode.');
    }
    $scanQuery = $pdo->prepare(
        'SELECT assets.asset_tag_id, assets.asset_name, assets.condition_status,
                assets.verification_status, departments.department_name, asset_categories.category_name
         FROM assets
         JOIN departments ON departments.id = assets.department_id
         JOIN asset_categories ON asset_categories.id = assets.category_id
         WHERE assets.id = :id'
    );
    $scanQuery->execute(['id' => $scannedAssetId]);
    $scannedAsset = $scanQuery->fetch();
}

$pageTitle = 'Asset register';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-heading">
        <div><p class="eyebrow">Asset management</p><h1>Asset register</h1>
            <p class="muted">Register assets, search the full register, and filter records.</p></div>
    </div>
    <?php showFlashMessage(); ?>
    <section class="panel scan-panel no-print">
        <div class="panel-heading"><div><h2>Scan an asset label</h2><p class="muted">Use a keyboard-wedge barcode scanner, or enter the printed numeric asset ID.</p></div></div>
        <form method="get" class="scan-form" data-scan-form>
            <label for="scan-asset-id">Asset ID barcode</label>
            <div class="scan-input-row"><input id="scan-asset-id" type="text" name="scan_asset_id" inputmode="numeric" pattern="[0-9]+" autocomplete="off" required autofocus aria-describedby="scan-help"><button class="button button-primary" type="submit">Look up</button></div>
            <span class="muted" id="scan-help">The scanner should send Enter after reading the Code 39 barcode.</span>
        </form>
        <?php if (is_string($scanInput) && $scanInput !== ''): ?>
            <?php if ($scannedAsset): ?>
                <div class="scan-result alert-success" role="status"><strong><?= escapeHtml($scannedAsset['asset_tag_id']) ?></strong> — <?= escapeHtml($scannedAsset['asset_name']) ?> · <?= escapeHtml($scannedAsset['department_name']) ?> · <?= escapeHtml($scannedAsset['condition_status']) ?> · <?= escapeHtml($scannedAsset['verification_status']) ?><br><a class="text-link" href="<?= APP_BASE_URL ?>/modules/verification/index.php">Open physical verification</a></div>
            <?php else: ?>
                <div class="scan-result alert-error" role="status">No asset matches scanned ID <?= escapeHtml($scanInput) ?>.</div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <section class="panel form-panel">
        <div class="panel-heading"><div><h2>Register an asset</h2><p class="muted">New items begin as unverified.</p></div></div>
        <form method="post" class="data-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <input type="hidden" name="action" value="create_asset">
            <label>Asset tag<input name="asset_tag_id" maxlength="60" required></label>
            <label>Asset name<input name="asset_name" maxlength="180" required></label>
            <label>Category<select name="category_id" required><option value="">Select category</option>
                <?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= escapeHtml($category['category_name']) ?></option><?php endforeach; ?>
            </select></label>
            <label>Department<select name="department_id" required><option value="">Select department</option>
                <?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>"><?= escapeHtml($department['department_name']) ?></option><?php endforeach; ?>
            </select></label>
            <label>Custodian<select name="custodian_id"><option value="">Unassigned</option>
                <?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>"><?= escapeHtml($user['full_name']) ?></option><?php endforeach; ?>
            </select></label>
            <label>Condition<select name="condition_status">
                <?php foreach (['Good', 'Fair', 'Poor', 'Unserviceable'] as $condition): ?><option value="<?= escapeHtml($condition) ?>"><?= escapeHtml($condition) ?></option><?php endforeach; ?>
            </select></label>
            <label>Brand<input name="brand" maxlength="100"></label>
            <label>Model<input name="model" maxlength="100"></label>
            <label>Serial number<input name="serial_number" maxlength="120"></label>
            <label>Location<input name="location" maxlength="180"></label>
            <label>Acquisition date<input name="acquisition_date" type="date"></label>
            <div class="form-actions"><button class="button button-primary" type="submit">Register asset</button></div>
        </form>
    </section>
    <section class="panel table-panel">
        <div class="panel-heading"><div><h2>Registered assets</h2><p class="muted"><?= number_format($assetCount) ?> matching records<?= $assetCount > count($assets) ? ' — showing the 100 most recent' : '' ?></p></div></div>
        <form method="get" class="data-form asset-filters">
            <label>Search<input type="search" name="search" value="<?= escapeHtml($assetSearch) ?>" placeholder="Tag, name, brand, serial or location"></label>
            <label>Department<select name="department"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= $departmentFilter === (int) $department['id'] ? 'selected' : '' ?>><?= escapeHtml($department['department_name']) ?></option><?php endforeach; ?></select></label>
            <label>Category<select name="category"><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= $categoryFilter === (int) $category['id'] ? 'selected' : '' ?>><?= escapeHtml($category['category_name']) ?></option><?php endforeach; ?></select></label>
            <label>Condition<select name="condition"><option value="">Any condition</option><?php foreach (['Good', 'Fair', 'Poor', 'Unserviceable'] as $condition): ?><option value="<?= escapeHtml($condition) ?>" <?= $conditionFilter === $condition ? 'selected' : '' ?>><?= escapeHtml($condition) ?></option><?php endforeach; ?></select></label>
            <label>Verification<select name="verification"><option value="">Any status</option><?php foreach (['Verified', 'Unverified'] as $verification): ?><option value="<?= escapeHtml($verification) ?>" <?= $verificationFilter === $verification ? 'selected' : '' ?>><?= escapeHtml($verification) ?></option><?php endforeach; ?></select></label>
            <div class="filter-actions"><button class="button button-primary" type="submit">Apply filters</button><a class="button button-secondary" href="<?= APP_BASE_URL ?>/modules/assets/index.php">Clear</a></div>
        </form>
        <div class="table-wrap"><table><thead><tr><th>Asset tag</th><th>Name</th><th>Category</th><th>Department</th><th>Condition</th><th>Verification</th><th>Actions</th></tr></thead>
            <tbody><?php if ($assets === []): ?><tr><td class="empty-state" colspan="7"><?= $hasAssetFilters ? 'No matching assets found.' : 'No assets registered yet.' ?></td></tr>
                <?php else: foreach ($assets as $asset): ?><tr>
                    <td><?= escapeHtml($asset['asset_tag_id']) ?></td><td><?= escapeHtml($asset['asset_name']) ?></td>
                    <td><?= escapeHtml($asset['category_name']) ?></td><td><?= escapeHtml($asset['department_name']) ?></td>
                    <td><span class="badge"><?= escapeHtml($asset['condition_status']) ?></span></td>
                    <td><span class="badge"><?= escapeHtml($asset['verification_status']) ?></span></td>
                    <td><div class="row-actions">
                        <a class="text-link" href="<?= APP_BASE_URL ?>/modules/assets/label.php?id=<?= (int) $asset['id'] ?>" target="_blank" rel="noopener">Print barcode</a>
                        <?php if (hasRole(['super_admin', 'admin_officer'])): ?>
                            <details><summary>Edit asset</summary>
                                <form method="post" class="data-form data-form-single compact-form">
                                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="update_asset">
                                    <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">
                                    <label>Asset tag<input name="asset_tag_id" maxlength="60" value="<?= escapeHtml($asset['asset_tag_id']) ?>" required></label>
                                    <label>Name<input name="asset_name" maxlength="180" value="<?= escapeHtml($asset['asset_name']) ?>" required></label>
                                    <label>Category<select name="category_id" required><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (int) $category['id'] === (int) $asset['category_id'] ? 'selected' : '' ?>><?= escapeHtml($category['category_name']) ?></option><?php endforeach; ?></select></label>
                                    <label>Department<select name="department_id" required><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= (int) $department['id'] === (int) $asset['department_id'] ? 'selected' : '' ?>><?= escapeHtml($department['department_name']) ?></option><?php endforeach; ?></select></label>
                                    <label>Brand<input name="brand" maxlength="100" value="<?= escapeHtml($asset['brand'] ?? '') ?>"></label>
                                    <label>Model<input name="model" maxlength="100" value="<?= escapeHtml($asset['model'] ?? '') ?>"></label>
                                    <label>Serial number<input name="serial_number" maxlength="120" value="<?= escapeHtml($asset['serial_number'] ?? '') ?>"></label>
                                    <label>Location<input name="location" maxlength="180" value="<?= escapeHtml($asset['location'] ?? '') ?>"></label>
                                    <label>Condition<select name="condition_status"><?php foreach (['Good', 'Fair', 'Poor', 'Unserviceable'] as $condition): ?><option value="<?= escapeHtml($condition) ?>" <?= $asset['condition_status'] === $condition ? 'selected' : '' ?>><?= escapeHtml($condition) ?></option><?php endforeach; ?></select></label>
                                    <label>Acquisition date<input name="acquisition_date" type="date" value="<?= escapeHtml($asset['acquisition_date'] ?? '') ?>"></label>
                                    <button class="button button-primary button-small" type="submit">Save changes</button>
                                </form>
                            </details>
                            <a class="text-link" href="<?= APP_BASE_URL ?>/modules/verification/index.php">Record physical check</a>
                        <?php else: ?>View only<?php endif; ?>
                    </div></td>
                </tr><?php endforeach; endif; ?>
            </tbody></table></div>
        <?php if ($assets !== []): ?>
            <form method="get" action="<?= APP_BASE_URL ?>/modules/assets/batch_labels.php" target="_blank" class="batch-label-actions no-print">
                <?php foreach ($assets as $asset): ?>
                    <input type="hidden" name="asset_ids[]" value="<?= (int) $asset['id'] ?>">
                <?php endforeach; ?>
                <span class="muted">Print labels for the <?= number_format(count($assets)) ?> assets shown in this list.</span>
                <button class="button button-secondary" type="submit">Print shown labels</button>
            </form>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
