<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_once __DIR__ . '/../../includes/code39.php';
requireRole(['super_admin', 'admin_officer', 'stores_officer']);

$assetInputs = $_GET['asset_ids'] ?? [];
if (!is_array($assetInputs) || $assetInputs === [] || count($assetInputs) > 100) {
    http_response_code(400);
    exit('Select between 1 and 100 assets to print labels.');
}

$assetIds = [];
foreach ($assetInputs as $assetInput) {
    $assetId = filter_var($assetInput, FILTER_VALIDATE_INT);
    if (!is_int($assetId) || $assetId < 1) {
        http_response_code(400);
        exit('The selected asset list is invalid.');
    }
    $assetIds[] = $assetId;
}
$assetIds = array_values(array_unique($assetIds));
if ($assetIds === []) {
    http_response_code(400);
    exit('Select at least one asset to print labels.');
}

$placeholders = implode(',', array_fill(0, count($assetIds), '?'));
$query = Database::connection()->prepare(
    'SELECT assets.id, assets.asset_tag_id, assets.asset_name, departments.department_name
     FROM assets
     JOIN departments ON departments.id = assets.department_id
     WHERE assets.id IN (' . $placeholders . ')
     ORDER BY FIELD(assets.id, ' . $placeholders . ')'
);
$query->execute(array_merge($assetIds, $assetIds));
$assets = $query->fetchAll();
if (count($assets) !== count($assetIds)) {
    http_response_code(404);
    exit('One or more selected assets were not found.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Batch asset labels | PSA-SAMS</title>
    <link rel="stylesheet" href="<?= APP_BASE_URL ?>/assets/css/style.css">
</head>
<body class="asset-label-page">
<header class="batch-label-toolbar no-print">
    <p><?= number_format(count($assets)) ?> asset label<?= count($assets) === 1 ? '' : 's' ?> ready to print.</p>
    <button class="button button-primary" type="button" data-print-page>Print labels</button>
    <a class="button button-secondary" href="<?= APP_BASE_URL ?>/modules/assets/index.php">Return to assets</a>
</header>
<main class="batch-label-sheet">
    <?php foreach ($assets as $asset): ?>
        <article class="asset-label asset-label-print">
            <p class="eyebrow">PSA-SAMS asset</p>
            <h1><?= escapeHtml($asset['asset_tag_id']) ?></h1>
            <p><?= escapeHtml($asset['asset_name']) ?></p>
            <p class="muted"><?= escapeHtml($asset['department_name']) ?></p>
            <?= renderAssetIdBarcode((int) $asset['id']) ?>
            <p class="asset-label-code">Scan barcode to look up this asset</p>
        </article>
    <?php endforeach; ?>
</main>
<script src="<?= APP_BASE_URL ?>/assets/js/main.js" defer></script>
</body>
</html>
