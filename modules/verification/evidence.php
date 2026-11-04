<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
requireRole(['super_admin', 'admin_officer', 'stores_officer']);

$evidenceId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($evidenceId) || $evidenceId < 1) {
    http_response_code(404);
    exit('Evidence not found.');
}

$query = Database::connection()->prepare(
    'SELECT file_name, mime_type, file_size, sha256
     FROM verification_evidence WHERE id = :id'
);
$query->execute(['id' => $evidenceId]);
$evidence = $query->fetch();
if (!$evidence || !preg_match('/^[a-f0-9]{64}\.(?:jpg|png|webp)$/', $evidence['file_name'])) {
    http_response_code(404);
    exit('Evidence not found.');
}

$extensions = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];
$extension = $extensions[$evidence['mime_type']] ?? null;
if ($extension === null || !str_ends_with($evidence['file_name'], '.' . $extension)) {
    http_response_code(404);
    exit('Evidence not found.');
}

$filePath = __DIR__ . '/../../uploads/evidence/' . $evidence['file_name'];
if (!is_file($filePath) || filesize($filePath) !== (int) $evidence['file_size']) {
    http_response_code(404);
    exit('Evidence file is unavailable.');
}
$actualHash = hash_file('sha256', $filePath);
if (!is_string($actualHash) || !hash_equals($evidence['sha256'], $actualHash)) {
    error_log('PSA-SAMS verification evidence checksum validation failed for evidence ID ' . $evidenceId);
    http_response_code(500);
    exit('Evidence integrity check failed.');
}

header('Content-Type: ' . $evidence['mime_type']);
header('Content-Length: ' . (int) $evidence['file_size']);
header('Content-Disposition: inline; filename="verification-evidence.' . $extension . '"');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($filePath);
