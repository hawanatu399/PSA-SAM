<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_once __DIR__ . '/../../includes/code39.php';
requireRole(['super_admin', 'admin_officer', 'stores_officer']);

$assetId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($assetId) || $assetId < 1) {
    http_response_code(404);
    exit('Asset not found.');
}

$query = Database::connection()->prepare(
    'SELECT assets.id, assets.asset_tag_id, assets.asset_name, departments.department_name
     FROM assets
     JOIN departments ON departments.id = assets.department_id
     WHERE assets.id = :id'
);
$query->execute(['id' => $assetId]);
$asset = $query->fetch();
if (!$asset) {
    http_response_code(404);
    exit('Asset not found.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Asset label <?= escapeHtml($asset['asset_tag_id']) ?> | PSA-SAMS</title>
    <link rel="stylesheet" href="<?= APP_BASE_URL ?>/assets/css/style.css">
</head>
<body class="asset-label-page">
<main class="asset-label-sheet">
    <div class="asset-label no-print">
        <p class="eyebrow">PSA-SAMS asset label</p>
        <h1><?= escapeHtml($asset['asset_tag_id']) ?></h1>
        <p><?= escapeHtml($asset['asset_name']) ?></p>
        <p class="muted"><?= escapeHtml($asset['department_name']) ?></p>
        <?= renderAssetIdBarcode((int) $asset['id']) ?>
        <p class="asset-label-code">ID <?= (int) $asset['id'] ?></p>
        <button class="button button-primary" type="button" data-print-page>Print label</button>
    </div>
    <div class="asset-label asset-label-print">
        <p class="eyebrow">PSA-SAMS asset</p>
        <h1><?= escapeHtml($asset['asset_tag_id']) ?></h1>
        <p><?= escapeHtml($asset['asset_name']) ?></p>
        <p class="muted"><?= escapeHtml($asset['department_name']) ?></p>
        <?= renderAssetIdBarcode((int) $asset['id']) ?>
        <p class="asset-label-code">Scan barcode to look up this asset</p>
    </div>
</main>
<script src="<?= APP_BASE_URL ?>/assets/js/main.js" defer></script>
</body>
</html>
