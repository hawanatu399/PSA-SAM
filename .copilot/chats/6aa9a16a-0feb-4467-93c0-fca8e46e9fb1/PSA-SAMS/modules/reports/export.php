<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
requireRole(['super_admin', 'admin_officer', 'approving_officer', 'management']);

function csvSafeCell(mixed $value): string|int|float|null
{
    if (!is_string($value)) {
        return $value;
    }

    if (preg_match('/^[\x00-\x20]*[=+\-@]/', $value) === 1) {
        return "'" . $value;
    }

    return $value;
}

$typeInput = $_GET['type'] ?? '';
$type = is_string($typeInput) ? trim($typeInput) : '';
$reports = [
    'assets' => [
        'filename' => 'psa-assets.csv',
        'query' => 'SELECT assets.asset_tag_id, assets.asset_name, assets.brand, assets.model,
                           assets.serial_number, categories.category_name, departments.department_name,
                           assets.location, assets.condition_status, assets.verification_status,
                           assets.acquisition_date, assets.created_at
                    FROM assets
                    JOIN asset_categories AS categories ON categories.id = assets.category_id
                    JOIN departments ON departments.id = assets.department_id
                    ORDER BY assets.asset_tag_id LIMIT 5000',
    ],
    'stock' => [
        'filename' => 'psa-stock-transactions.csv',
        'query' => 'SELECT transactions.transaction_date, items.item_name, items.unit,
                           transactions.transaction_type, transactions.quantity,
                           users.full_name AS performed_by, transactions.notes
                    FROM stock_transactions AS transactions
                    JOIN store_items AS items ON items.id = transactions.item_id
                    JOIN users ON users.id = transactions.performed_by
                    ORDER BY transactions.transaction_date DESC, transactions.id DESC LIMIT 5000',
    ],
    'transfers' => [
        'filename' => 'psa-asset-transfers.csv',
        'query' => 'SELECT transfers.id, assets.asset_tag_id, source.department_name AS from_department,
                           destination.department_name AS to_department, requester.full_name AS requested_by,
                           approver.full_name AS approved_by, transfers.status, transfers.transfer_date
                    FROM asset_transfers AS transfers
                    JOIN assets ON assets.id = transfers.asset_id
                    JOIN departments AS source ON source.id = transfers.from_department_id
                    JOIN departments AS destination ON destination.id = transfers.to_department_id
                    JOIN users AS requester ON requester.id = transfers.requested_by
                    LEFT JOIN users AS approver ON approver.id = transfers.approved_by
                    ORDER BY transfers.id DESC LIMIT 5000',
    ],
    'maintenance' => [
        'filename' => 'psa-maintenance-records.csv',
        'query' => 'SELECT assets.asset_tag_id, assets.asset_name, maintenance.issue_description,
                           maintenance.status, maintenance.repair_cost, reporter.full_name AS reported_by,
                           maintenance.service_date
                    FROM maintenance_records AS maintenance
                    JOIN assets ON assets.id = maintenance.asset_id
                    JOIN users AS reporter ON reporter.id = maintenance.reported_by
                    ORDER BY maintenance.id DESC LIMIT 5000',
    ],
    'audit' => [
        'filename' => 'psa-audit-events.csv',
        'query' => 'SELECT logs.`timestamp`, users.full_name AS user_name, users.email,
                           logs.action_performed, logs.target_table, logs.record_id, logs.details
                    FROM audit_logs AS logs
                    LEFT JOIN users ON users.id = logs.user_id
                    ORDER BY logs.id DESC LIMIT 5000',
    ],
];

if (!isset($reports[$type])) {
    http_response_code(400);
    exit('Choose a valid report export.');
}

$statement = Database::connection()->query($reports[$type]['query']);
$filename = $reports[$type]['filename'];

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');

$output = fopen('php://output', 'wb');
if ($output === false) {
    throw new RuntimeException('Could not open the report output stream.');
}

fwrite($output, "\xEF\xBB\xBF");
$headersWritten = false;
while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
    if (!$headersWritten) {
        fputcsv($output, array_map('csvSafeCell', array_keys($row)), ',', '"', '');
        $headersWritten = true;
    }
    fputcsv($output, array_map('csvSafeCell', array_values($row)), ',', '"', '');
}

if (!$headersWritten) {
    fputcsv($output, ['No records found'], ',', '"', '');
}

fclose($output);
