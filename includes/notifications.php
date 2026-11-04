<?php
declare(strict_types=1);

function currentNotifications(PDO $pdo): array
{
    $notifications = [];
    $roles = $_SESSION['user']['role'] ?? '';

    if (in_array($roles, ['super_admin', 'admin_officer', 'stores_officer'], true)) {
        $campaigns = $pdo->query(
            "SELECT campaigns.id, campaigns.campaign_name, campaigns.end_date,
                    DATEDIFF(campaigns.end_date, CURRENT_DATE) AS days_remaining,
                    COUNT(DISTINCT assets.id) AS expected_count,
                    COUNT(DISTINCT verifications.asset_id) AS checked_count
             FROM verification_campaigns AS campaigns
             JOIN verification_campaign_departments AS assignments
               ON assignments.campaign_id = campaigns.id
             JOIN assets ON assets.department_id = assignments.department_id
             LEFT JOIN asset_verifications AS verifications
               ON verifications.campaign_id = campaigns.id
              AND verifications.asset_id = assets.id
             WHERE campaigns.status = 'Active'
               AND campaigns.end_date <= DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY)
             GROUP BY campaigns.id, campaigns.campaign_name, campaigns.end_date
             ORDER BY campaigns.end_date, campaigns.id
             LIMIT 100"
        )->fetchAll();

        foreach ($campaigns as $campaign) {
            $remaining = max(0, (int) $campaign['expected_count'] - (int) $campaign['checked_count']);
            if ($remaining === 0) {
                continue;
            }
            $daysRemaining = (int) $campaign['days_remaining'];
            $dueMessage = $daysRemaining < 0
                ? 'Overdue by ' . abs($daysRemaining) . ' day(s)'
                : ($daysRemaining === 0 ? 'Due today' : 'Due in ' . $daysRemaining . ' day(s)');
            $notifications[] = [
                'type' => 'Verification campaign due',
                'title' => $campaign['campaign_name'],
                'message' => $remaining . ' asset(s) remain unchecked · ' . $dueMessage,
                'url' => APP_BASE_URL . '/modules/verification/index.php',
                'severity' => $daysRemaining <= 0 ? 'critical' : 'warning',
            ];
        }

        $items = $pdo->query(
            'SELECT id, item_name, current_stock, reorder_level
             FROM store_items
             WHERE current_stock <= reorder_level
             ORDER BY current_stock ASC, item_name
             LIMIT 100'
        )->fetchAll();

        foreach ($items as $item) {
            $notifications[] = [
                'type' => 'Low stock',
                'title' => $item['item_name'],
                'message' => 'On hand: ' . $item['current_stock'] . '; reorder level: ' . $item['reorder_level'],
                'url' => APP_BASE_URL . '/modules/stores/index.php',
                'severity' => (float) $item['current_stock'] === 0.0 ? 'critical' : 'warning',
            ];
        }
    }

    if (in_array($roles, ['super_admin', 'approving_officer'], true)) {
        $transfers = $pdo->query(
            "SELECT transfers.id, assets.asset_tag_id, source.department_name AS from_department,
                    destination.department_name AS to_department
             FROM asset_transfers AS transfers
             JOIN assets ON assets.id = transfers.asset_id
             JOIN departments AS source ON source.id = transfers.from_department_id
             JOIN departments AS destination ON destination.id = transfers.to_department_id
             WHERE transfers.status = 'Pending'
             ORDER BY transfers.id DESC
             LIMIT 100"
        )->fetchAll();

        foreach ($transfers as $transfer) {
            $notifications[] = [
                'type' => 'Approval needed',
                'title' => 'Transfer ' . $transfer['asset_tag_id'],
                'message' => $transfer['from_department'] . ' to ' . $transfer['to_department'],
                'url' => APP_BASE_URL . '/modules/allocation/index.php',
                'severity' => 'warning',
            ];
        }

        $requisitions = $pdo->query(
            "SELECT requisition.id, items.item_name, requisition.quantity, departments.department_name
             FROM store_requisitions AS requisition
             JOIN store_items AS items ON items.id = requisition.item_id
             JOIN departments ON departments.id = requisition.department_id
             WHERE requisition.status = 'Pending'
             ORDER BY requisition.requested_at ASC, requisition.id ASC
             LIMIT 100"
        )->fetchAll();

        foreach ($requisitions as $requisition) {
            $notifications[] = [
                'type' => 'Requisition approval',
                'title' => 'Request #' . $requisition['id'] . ': ' . $requisition['item_name'],
                'message' => $requisition['department_name'] . ' · ' . $requisition['quantity'] . ' requested',
                'url' => APP_BASE_URL . '/modules/requisitions/index.php',
                'severity' => 'warning',
            ];
        }
    }

    if (in_array($roles, ['super_admin', 'admin_officer', 'stores_officer'], true)) {
        $readyRequisitions = $pdo->query(
            "SELECT COUNT(*) FROM store_requisitions WHERE status = 'Approved'"
        )->fetchColumn();
        if ((int) $readyRequisitions > 0) {
            $notifications[] = [
                'type' => 'Stock issue',
                'title' => number_format((int) $readyRequisitions) . ' approved requisition(s)',
                'message' => 'Approved stock requests are awaiting issue.',
                'url' => APP_BASE_URL . '/modules/requisitions/index.php',
                'severity' => 'warning',
            ];
        }

        $records = $pdo->query(
            "SELECT maintenance.id, assets.asset_tag_id, maintenance.status
             FROM maintenance_records AS maintenance
             JOIN assets ON assets.id = maintenance.asset_id
             WHERE maintenance.status IN ('Reported', 'In-Progress')
             ORDER BY maintenance.id DESC
             LIMIT 100"
        )->fetchAll();

        foreach ($records as $record) {
            $notifications[] = [
                'type' => 'Maintenance',
                'title' => 'Asset ' . $record['asset_tag_id'],
                'message' => 'Status: ' . $record['status'],
                'url' => APP_BASE_URL . '/modules/maintenance/index.php',
                'severity' => 'info',
            ];
        }

        $missingAssets = $pdo->query(
            "SELECT checks.asset_tag_snapshot, departments.department_name
             FROM asset_verifications AS checks
             JOIN departments ON departments.id = checks.department_id
             WHERE checks.result = 'Missing'
               AND NOT EXISTS (
                   SELECT 1 FROM asset_verifications AS newer
                   WHERE newer.asset_id = checks.asset_id
                     AND (newer.verified_at > checks.verified_at
                          OR (newer.verified_at = checks.verified_at AND newer.id > checks.id))
               )
             ORDER BY checks.verified_at DESC, checks.id DESC
             LIMIT 100"
        )->fetchAll();

        foreach ($missingAssets as $missingAsset) {
            $notifications[] = [
                'type' => 'Asset reported missing',
                'title' => $missingAsset['asset_tag_snapshot'],
                'message' => $missingAsset['department_name'] . ' · follow-up required',
                'url' => APP_BASE_URL . '/modules/verification/index.php',
                'severity' => 'critical',
            ];
        }
    }

    return $notifications;
}
