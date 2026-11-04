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
    'requisitions' => [
        'filename' => 'psa-store-requisitions.csv',
        'query' => 'SELECT requisition.id, requisition.requested_at, departments.department_name,
                           items.item_name, items.unit, requisition.quantity, requisition.purpose,
                           requester.full_name AS requested_by, requisition.status,
                           approver.full_name AS approved_by, requisition.approved_at,
                           requisition.approval_notes, issuer.full_name AS issued_by, requisition.issued_at
                    FROM store_requisitions AS requisition
                    JOIN departments ON departments.id = requisition.department_id
                    JOIN store_items AS items ON items.id = requisition.item_id
                    JOIN users AS requester ON requester.id = requisition.requested_by
                    LEFT JOIN users AS approver ON approver.id = requisition.approved_by
                    LEFT JOIN users AS issuer ON issuer.id = requisition.issued_by
                    ORDER BY requisition.requested_at DESC, requisition.id DESC LIMIT 5000',
    ],
    'verification' => [
        'filename' => 'psa-asset-verifications.csv',
        'query' => 'SELECT verifications.verified_at, campaigns.campaign_name,
                           verifications.asset_tag_snapshot AS asset_tag,
                           departments.department_name, verifications.result,
                           verifications.observed_condition, users.full_name AS verified_by,
                           IF(evidence.id IS NULL, \'No\', \'Yes\') AS evidence_attached,
                           verifications.notes
                    FROM asset_verifications AS verifications
                    JOIN departments ON departments.id = verifications.department_id
                    JOIN users ON users.id = verifications.verified_by
                    LEFT JOIN verification_campaigns AS campaigns ON campaigns.id = verifications.campaign_id
                    LEFT JOIN verification_evidence AS evidence ON evidence.verification_id = verifications.id
                    ORDER BY verifications.verified_at DESC, verifications.id DESC LIMIT 5000',
    ],
    'verification_campaigns' => [
        'filename' => 'psa-verification-campaigns.csv',
        'query' => 'SELECT campaigns.campaign_name, campaigns.start_date, campaigns.end_date,
                           campaigns.status, departments.department_name,
                           COUNT(DISTINCT assets.id) AS expected_assets,
                           COUNT(DISTINCT verifications.asset_id) AS checked_assets,
                           COUNT(DISTINCT CASE WHEN verifications.result = \'Found\' THEN verifications.asset_id END) AS found_assets,
                           COUNT(DISTINCT CASE WHEN verifications.result = \'Missing\' THEN verifications.asset_id END) AS missing_assets
                    FROM verification_campaigns AS campaigns
                    JOIN verification_campaign_departments AS assignments ON assignments.campaign_id = campaigns.id
                    JOIN departments ON departments.id = assignments.department_id
                    LEFT JOIN assets ON assets.department_id = departments.id
                    LEFT JOIN asset_verifications AS verifications
                      ON verifications.campaign_id = campaigns.id AND verifications.asset_id = assets.id
                    GROUP BY campaigns.id, campaigns.campaign_name, campaigns.start_date, campaigns.end_date,
                             campaigns.status, departments.id, departments.department_name
                    ORDER BY campaigns.start_date DESC, campaigns.id DESC, departments.department_name
                    LIMIT 5000',
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
