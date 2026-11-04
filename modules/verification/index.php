<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
requireRole(['super_admin', 'admin_officer', 'stores_officer']);

$pdo = Database::connection();
$actorId = (int) $_SESSION['user']['id'];
$conditions = ['Good', 'Fair', 'Poor', 'Unserviceable'];

if (isPostRequest()) {
    requireValidFormToken();
    $action = formString('action');

    if (in_array($action, ['create_campaign', 'activate_campaign', 'close_campaign'], true)) {
        requireRole(['super_admin', 'admin_officer']);
        try {
            $pdo->beginTransaction();

            if ($action === 'create_campaign') {
                $campaignName = formString('campaign_name');
                $startDate = formString('start_date');
                $endDate = formString('end_date');
                $departmentInputs = $_POST['department_ids'] ?? [];
                $validDate = static function (?string $value): bool {
                    if (!is_string($value) || $value === '') {
                        return false;
                    }
                    $date = DateTime::createFromFormat('!Y-m-d', $value);
                    $errors = DateTime::getLastErrors();
                    return $date !== false
                        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                        && $date->format('Y-m-d') === $value;
                };
                if (!is_string($campaignName) || $campaignName === '' || strlen($campaignName) > 180
                    || !$validDate($startDate) || !$validDate($endDate)
                    || $startDate < date('Y-m-d') || $endDate < $startDate
                    || !is_array($departmentInputs) || $departmentInputs === []) {
                    throw new InvalidArgumentException('Enter a campaign name, a valid date range starting today or later, and at least one department.');
                }

                $departmentIds = [];
                foreach ($departmentInputs as $departmentInput) {
                    $departmentId = filter_var($departmentInput, FILTER_VALIDATE_INT);
                    if (!is_int($departmentId) || $departmentId < 1) {
                        throw new InvalidArgumentException('Choose valid campaign departments.');
                    }
                    $departmentIds[] = $departmentId;
                }
                $departmentIds = array_values(array_unique($departmentIds));

                $departmentQuery = $pdo->prepare(
                    'SELECT id FROM departments WHERE id IN (' . implode(',', array_fill(0, count($departmentIds), '?')) . ')'
                );
                $departmentQuery->execute($departmentIds);
                if (count($departmentQuery->fetchAll(PDO::FETCH_COLUMN)) !== count($departmentIds)) {
                    throw new InvalidArgumentException('One or more selected departments were not found.');
                }

                $status = $startDate === date('Y-m-d') ? 'Active' : 'Planned';
                $insertCampaign = $pdo->prepare(
                    'INSERT INTO verification_campaigns (campaign_name, start_date, end_date, status, created_by)
                     VALUES (:name, :start_date, :end_date, :status, :created_by)'
                );
                $insertCampaign->execute([
                    'name' => $campaignName,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => $status,
                    'created_by' => $actorId,
                ]);
                $campaignId = (int) $pdo->lastInsertId();
                $assignDepartment = $pdo->prepare(
                    'INSERT INTO verification_campaign_departments (campaign_id, department_id)
                     VALUES (:campaign_id, :department_id)'
                );
                foreach ($departmentIds as $departmentId) {
                    $assignDepartment->execute([
                        'campaign_id' => $campaignId,
                        'department_id' => $departmentId,
                    ]);
                }
                recordAudit($pdo, 'Created verification campaign ' . $campaignName, 'verification_campaigns', $campaignId, [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'department_ids' => $departmentIds,
                ]);
            } else {
                $campaignId = filter_var($_POST['campaign_id'] ?? null, FILTER_VALIDATE_INT);
                if (!is_int($campaignId) || $campaignId < 1) {
                    throw new InvalidArgumentException('Choose a valid verification campaign.');
                }
                $campaignQuery = $pdo->prepare(
                    'SELECT campaign_name, start_date, end_date, status
                     FROM verification_campaigns WHERE id = :id FOR UPDATE'
                );
                $campaignQuery->execute(['id' => $campaignId]);
                $campaign = $campaignQuery->fetch();
                if (!$campaign) {
                    throw new InvalidArgumentException('The verification campaign was not found.');
                }

                if ($action === 'activate_campaign') {
                    $today = date('Y-m-d');
                    if ($campaign['status'] !== 'Planned'
                        || $campaign['start_date'] > $today
                        || $campaign['end_date'] < $today) {
                        throw new InvalidArgumentException('A planned campaign can only be activated during its scheduled date range.');
                    }
                    $newStatus = 'Active';
                } else {
                    if ($campaign['status'] === 'Closed') {
                        throw new InvalidArgumentException('This campaign is already closed.');
                    }
                    $newStatus = 'Closed';
                }
                $updateCampaign = $pdo->prepare(
                    'UPDATE verification_campaigns SET status = :status WHERE id = :id'
                );
                $updateCampaign->execute(['status' => $newStatus, 'id' => $campaignId]);
                recordAudit(
                    $pdo,
                    $newStatus . ' verification campaign ' . $campaign['campaign_name'],
                    'verification_campaigns',
                    $campaignId,
                    ['before' => $campaign['status'], 'after' => $newStatus]
                );
            }

            $pdo->commit();
            setFlashMessage('success', 'Verification campaign changes saved.');
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
        redirectTo('/modules/verification/index.php');
    }

    if ($action !== 'record_verification') {
        setFlashMessage('error', 'Choose a valid physical verification action.');
        redirectTo('/modules/verification/index.php');
    }

    $assetId = filter_var($_POST['asset_id'] ?? null, FILTER_VALIDATE_INT);
    $campaignInput = formString('campaign_id');
    $campaignId = $campaignInput === '' ? null : filter_var($campaignInput, FILTER_VALIDATE_INT);
    $result = formString('result');
    $observedCondition = formString('observed_condition');
    $notes = formString('notes');

    if (!is_int($assetId) || $assetId < 1
        || ($campaignId !== null && (!is_int($campaignId) || $campaignId < 1))
        || !in_array($result, ['Found', 'Missing'], true)
        || !is_string($observedCondition)
        || ($result === 'Found' && !in_array($observedCondition, $conditions, true))
        || ($result === 'Missing' && $observedCondition !== '')
        || !is_string($notes) || strlen($notes) > 5000) {
        setFlashMessage('error', 'Choose an asset and result. Record the observed condition if found, and keep notes under 5,000 characters.');
        redirectTo('/modules/verification/index.php');
    }

    $evidenceUpload = $_FILES['evidence'] ?? null;
    $evidenceMime = null;
    $evidenceSize = 0;
    if ($evidenceUpload !== null) {
        if (!is_array($evidenceUpload) || !isset(
            $evidenceUpload['error'],
            $evidenceUpload['tmp_name'],
            $evidenceUpload['size']
        ) || !is_int($evidenceUpload['error']) || !is_string($evidenceUpload['tmp_name'])
            || !is_int($evidenceUpload['size'])) {
            setFlashMessage('error', 'The evidence upload was invalid.');
            redirectTo('/modules/verification/index.php');
        }
        if ($evidenceUpload['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($evidenceUpload['error'] !== UPLOAD_ERR_OK || $evidenceUpload['size'] < 1
                || $evidenceUpload['size'] > 4194304 || !is_uploaded_file($evidenceUpload['tmp_name'])) {
                setFlashMessage('error', 'Evidence must be a valid image no larger than 4 MB.');
                redirectTo('/modules/verification/index.php');
            }
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $evidenceMime = $fileInfo->file($evidenceUpload['tmp_name']);
            if (!in_array($evidenceMime, ['image/jpeg', 'image/png', 'image/webp'], true)
                || @getimagesize($evidenceUpload['tmp_name']) === false) {
                setFlashMessage('error', 'Evidence must be a readable JPEG, PNG, or WebP image.');
                redirectTo('/modules/verification/index.php');
            }
            $evidenceSize = $evidenceUpload['size'];
        }
    }

    $storedEvidencePath = null;
    try {
        $pdo->beginTransaction();
        $assetQuery = $pdo->prepare(
            'SELECT id, asset_tag_id, department_id, custodian_id, verification_status
             FROM assets WHERE id = :id FOR UPDATE'
        );
        $assetQuery->execute(['id' => $assetId]);
        $asset = $assetQuery->fetch();
        if (!$asset) {
            throw new InvalidArgumentException('The selected asset was not found.');
        }
        $campaign = null;
        if ($campaignId !== null) {
            $campaignQuery = $pdo->prepare(
                "SELECT campaign.id, campaign.campaign_name
                 FROM verification_campaigns AS campaign
                 JOIN verification_campaign_departments AS departments
                   ON departments.campaign_id = campaign.id
                 WHERE campaign.id = :campaign_id
                   AND departments.department_id = :department_id
                   AND campaign.status = 'Active'
                   AND CURRENT_DATE BETWEEN campaign.start_date AND campaign.end_date
                 FOR UPDATE"
            );
            $campaignQuery->execute([
                'campaign_id' => $campaignId,
                'department_id' => (int) $asset['department_id'],
            ]);
            $campaign = $campaignQuery->fetch();
            if (!$campaign) {
                throw new InvalidArgumentException('Choose an active campaign that includes the asset department and current date.');
            }
            $existingCheck = $pdo->prepare(
                'SELECT id FROM asset_verifications WHERE campaign_id = :campaign_id AND asset_id = :asset_id'
            );
            $existingCheck->execute(['campaign_id' => $campaignId, 'asset_id' => $assetId]);
            if ($existingCheck->fetchColumn()) {
                throw new InvalidArgumentException('This asset already has a check recorded for the selected campaign.');
            }
        }
        if ($asset['custodian_id'] !== null && (int) $asset['custodian_id'] === $actorId) {
            throw new InvalidArgumentException('A custodian cannot physically verify an asset assigned to themselves.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO asset_verifications
                (asset_id, campaign_id, asset_tag_snapshot, department_id, verified_by, result, observed_condition, notes)
             VALUES
                (:asset_id, :campaign_id, :asset_tag, :department_id, :verified_by, :result, :condition, :notes)'
        );
        $insert->execute([
            'asset_id' => $assetId,
            'campaign_id' => $campaignId,
            'asset_tag' => $asset['asset_tag_id'],
            'department_id' => (int) $asset['department_id'],
            'verified_by' => $actorId,
            'result' => $result,
            'condition' => $result === 'Found' ? $observedCondition : null,
            'notes' => $notes ?: null,
        ]);
        $verificationId = (int) $pdo->lastInsertId();

        if ($evidenceMime !== null && is_array($evidenceUpload)) {
            $extensions = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];
            $evidenceFileName = bin2hex(random_bytes(32)) . '.' . $extensions[$evidenceMime];
            $evidenceDirectory = __DIR__ . '/../../uploads/evidence';
            $storedEvidencePath = $evidenceDirectory . DIRECTORY_SEPARATOR . $evidenceFileName;
            if (!move_uploaded_file($evidenceUpload['tmp_name'], $storedEvidencePath)) {
                throw new RuntimeException('Could not securely store verification evidence.');
            }
            $evidenceHash = hash_file('sha256', $storedEvidencePath);
            if (!is_string($evidenceHash)) {
                throw new RuntimeException('Could not calculate the verification evidence checksum.');
            }
            $insertEvidence = $pdo->prepare(
                'INSERT INTO verification_evidence
                    (verification_id, file_name, mime_type, file_size, sha256, uploaded_by)
                 VALUES (:verification_id, :file_name, :mime_type, :file_size, :sha256, :uploaded_by)'
            );
            $insertEvidence->execute([
                'verification_id' => $verificationId,
                'file_name' => $evidenceFileName,
                'mime_type' => $evidenceMime,
                'file_size' => $evidenceSize,
                'sha256' => $evidenceHash,
                'uploaded_by' => $actorId,
            ]);
        }

        $verificationStatus = $result === 'Found' ? 'Verified' : 'Unverified';
        $updateAsset = $pdo->prepare(
            'UPDATE assets SET verification_status = :status WHERE id = :id'
        );
        $updateAsset->execute(['status' => $verificationStatus, 'id' => $assetId]);

        recordAudit(
            $pdo,
            'Physically verified asset ' . $asset['asset_tag_id'] . ': ' . $result,
            'asset_verifications',
            $verificationId,
            [
                'asset_id' => $assetId,
                'asset_tag_id' => $asset['asset_tag_id'],
                'campaign_id' => $campaignId,
                'campaign_name' => $campaign['campaign_name'] ?? null,
                'result' => $result,
                'observed_condition' => $result === 'Found' ? $observedCondition : null,
                'evidence_attached' => $evidenceMime !== null,
                'previous_verification_status' => $asset['verification_status'],
                'verification_status' => $verificationStatus,
                'notes' => $notes ?: null,
            ]
        );
        $pdo->commit();
        setFlashMessage('success', 'Physical verification recorded.');
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_string($storedEvidencePath) && is_file($storedEvidencePath)) {
            unlink($storedEvidencePath);
        }
        setFlashMessage('error', $exception->getMessage());
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_string($storedEvidencePath) && is_file($storedEvidencePath)) {
            unlink($storedEvidencePath);
        }
        reportDatabaseFailure($exception);
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_string($storedEvidencePath) && is_file($storedEvidencePath)) {
            unlink($storedEvidencePath);
        }
        error_log('PSA-SAMS verification evidence storage failed: ' . $exception->getMessage());
        setFlashMessage('error', 'The physical check could not be saved with its evidence. Please try again or contact support.');
    }

    redirectTo('/modules/verification/index.php');
}

$assets = $pdo->query(
    'SELECT assets.id, assets.asset_tag_id, assets.asset_name, assets.custodian_id,
            departments.department_name
     FROM assets
     JOIN departments ON departments.id = assets.department_id
     ORDER BY departments.department_name, assets.asset_tag_id'
)->fetchAll();
$departments = $pdo->query('SELECT id, department_name FROM departments ORDER BY department_name')->fetchAll();
$campaigns = $pdo->query(
    'SELECT campaign.id, campaign.campaign_name, campaign.start_date, campaign.end_date,
            campaign.status, creator.full_name AS created_by_name,
            departments.id AS department_id, departments.department_name
     FROM verification_campaigns AS campaign
     JOIN users AS creator ON creator.id = campaign.created_by
     JOIN verification_campaign_departments AS assignments ON assignments.campaign_id = campaign.id
     JOIN departments ON departments.id = assignments.department_id
     ORDER BY campaign.start_date DESC, campaign.id DESC, departments.department_name'
)->fetchAll();
$campaignProgressRows = $pdo->query(
    'SELECT assignments.campaign_id, assignments.department_id,
            COUNT(DISTINCT assets.id) AS expected_count,
            COUNT(DISTINCT verifications.asset_id) AS checked_count,
            COUNT(DISTINCT CASE WHEN verifications.result = \'Found\' THEN verifications.asset_id END) AS found_count,
            COUNT(DISTINCT CASE WHEN verifications.result = \'Missing\' THEN verifications.asset_id END) AS missing_count
     FROM verification_campaign_departments AS assignments
     JOIN assets ON assets.department_id = assignments.department_id
     LEFT JOIN asset_verifications AS verifications
       ON verifications.campaign_id = assignments.campaign_id
      AND verifications.asset_id = assets.id
     GROUP BY assignments.campaign_id, assignments.department_id'
)->fetchAll();
$campaignProgress = [];
foreach ($campaignProgressRows as $progress) {
    $campaignProgress[(int) $progress['campaign_id']][(int) $progress['department_id']] = $progress;
}
$activeCampaignQuery = $pdo->query(
    "SELECT id, campaign_name, start_date, end_date
     FROM verification_campaigns
     WHERE status = 'Active' AND CURRENT_DATE BETWEEN start_date AND end_date
     ORDER BY end_date, campaign_name"
);
$activeCampaigns = $activeCampaignQuery->fetchAll();
$history = $pdo->query(
    'SELECT verifications.asset_tag_snapshot, verifications.result,
            verifications.observed_condition, verifications.notes, verifications.verified_at,
            departments.department_name, users.full_name AS verifier_name,
            campaigns.campaign_name, evidence.id AS evidence_id
     FROM asset_verifications AS verifications
     JOIN departments ON departments.id = verifications.department_id
     JOIN users ON users.id = verifications.verified_by
     LEFT JOIN verification_campaigns AS campaigns ON campaigns.id = verifications.campaign_id
     LEFT JOIN verification_evidence AS evidence ON evidence.verification_id = verifications.id
     ORDER BY verifications.verified_at DESC, verifications.id DESC
     LIMIT 200'
)->fetchAll();
$missingCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM asset_verifications WHERE result = 'Missing'"
)->fetchColumn();

$pageTitle = 'Physical verification';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-heading" data-print-date="<?= escapeHtml($printDate) ?>">
        <div>
            <p class="eyebrow">Asset accountability</p>
            <h1>Physical verification</h1>
            <p class="muted">Record department-based checks. Each submission is retained as a dated verification event.</p>
        </div>
        <button class="button button-secondary no-print" type="button" data-print-page>Print verification report</button>
    </div>
    <?php showFlashMessage(); ?>
    <section class="metric-grid" aria-label="Verification indicators">
        <article class="metric-card">
            <span class="metric-label">Assets in register</span>
            <strong class="metric-value"><?= number_format(count($assets)) ?></strong>
            <span class="metric-note">Select an asset and compare it with the physical item.</span>
        </article>
        <article class="metric-card">
            <span class="metric-label">Missing reports recorded</span>
            <strong class="metric-value"><?= number_format($missingCount) ?></strong>
            <span class="metric-note">Historical reports; follow up through Academy procedures.</span>
        </article>
    </section>
    <?php if (hasRole(['super_admin', 'admin_officer'])): ?>
        <section class="panel form-panel no-print">
            <div class="panel-heading"><div><h2>Schedule a verification campaign</h2><p class="muted">Select its date window and the departments to include.</p></div></div>
            <form method="post" class="data-form campaign-form">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                <input type="hidden" name="action" value="create_campaign">
                <label>Campaign name<input name="campaign_name" maxlength="180" required placeholder="e.g. Annual equipment check 2027"></label>
                <label>Start date<input type="date" name="start_date" min="<?= escapeHtml(date('Y-m-d')) ?>" value="<?= escapeHtml(date('Y-m-d')) ?>" required></label>
                <label>End date<input type="date" name="end_date" min="<?= escapeHtml(date('Y-m-d')) ?>" value="<?= escapeHtml(date('Y-m-d')) ?>" required></label>
                <label class="department-picker">Departments<select name="department_ids[]" multiple size="<?= min(5, max(3, count($departments))) ?>" required><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>"><?= escapeHtml($department['department_name']) ?></option><?php endforeach; ?></select><span class="muted">Use Ctrl to select more than one department.</span></label>
                <div class="form-actions"><button class="button button-primary" type="submit">Schedule campaign</button></div>
            </form>
        </section>
    <?php endif; ?>
    <section class="panel table-panel">
        <div class="panel-heading"><div><h2>Verification campaigns</h2><p class="muted">Coverage is calculated from assets currently assigned to each included department.</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Campaign</th><th>Department</th><th>Schedule / status</th><th>Coverage</th><th>Found / missing</th><th class="no-print">Action</th></tr></thead>
                <tbody>
                <?php if ($campaigns === []): ?>
                    <tr><td class="empty-state" colspan="6">No verification campaigns have been scheduled.</td></tr>
                <?php else: foreach ($campaigns as $campaign): ?>
                    <?php
                    $campaignId = (int) $campaign['id'];
                    $campaignDepartmentId = (int) $campaign['department_id'];
                    $progress = $campaignProgress[$campaignId][$campaignDepartmentId] ?? [
                        'expected_count' => 0,
                        'checked_count' => 0,
                        'found_count' => 0,
                        'missing_count' => 0,
                    ];
                    $expected = (int) $progress['expected_count'];
                    $checked = (int) $progress['checked_count'];
                    $percentage = $expected === 0 ? 0 : (int) floor($checked * 100 / $expected);
                    $campaignStatus = $campaign['status'] === 'Active' && $campaign['end_date'] < date('Y-m-d')
                        ? 'Ended'
                        : $campaign['status'];
                    ?>
                    <tr>
                        <td><strong><?= escapeHtml($campaign['campaign_name']) ?></strong><br><span class="muted">Created by <?= escapeHtml($campaign['created_by_name']) ?></span></td>
                        <td><?= escapeHtml($campaign['department_name']) ?></td>
                        <td><?= escapeHtml(date('M j, Y', strtotime($campaign['start_date'])) . ' – ' . date('M j, Y', strtotime($campaign['end_date']))) ?><br><span class="badge <?= $campaignStatus === 'Active' ? 'badge-success' : ($campaignStatus === 'Closed' || $campaignStatus === 'Ended' ? 'badge-warning' : '') ?>"><?= escapeHtml($campaignStatus) ?></span></td>
                        <td><?= number_format($checked) ?> / <?= number_format($expected) ?> assets (<?= number_format($percentage) ?>%)</td>
                        <td><?= number_format((int) $progress['found_count']) ?> found · <?= number_format((int) $progress['missing_count']) ?> missing</td>
                        <td class="no-print">
                            <?php if (hasRole(['super_admin', 'admin_officer']) && $campaign['status'] === 'Planned' && $campaign['start_date'] <= date('Y-m-d') && $campaign['end_date'] >= date('Y-m-d')): ?>
                                <form method="post" class="inline-actions">
                                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="activate_campaign">
                                    <input type="hidden" name="campaign_id" value="<?= $campaignId ?>">
                                    <button class="button button-primary button-small" type="submit">Activate</button>
                                </form>
                            <?php endif; ?>
                            <?php if (hasRole(['super_admin', 'admin_officer']) && $campaign['status'] !== 'Closed'): ?>
                                <form method="post" class="inline-actions">
                                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="close_campaign">
                                    <input type="hidden" name="campaign_id" value="<?= $campaignId ?>">
                                    <button class="button button-secondary button-small" type="submit">Close</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($campaign['status'] === 'Closed' || ($campaign['status'] === 'Active' && $campaign['end_date'] < date('Y-m-d'))): ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <p class="muted report-note">Campaign dates and departments are planning tools, not an approved Academy-wide verification schedule. Assets registered after a campaign is created affect its displayed coverage.</p>
    </section>
    <section class="panel form-panel">
        <div class="panel-heading">
            <div><h2>Record a physical check</h2><p class="muted">Do not mark an asset found unless it was physically sighted.</p></div>
        </div>
        <?php if ($assets === []): ?>
            <p class="empty-state">Register assets before starting physical checks.</p>
        <?php else: ?>
            <form method="post" enctype="multipart/form-data" class="data-form">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                <input type="hidden" name="action" value="record_verification">
                <label>Campaign<select name="campaign_id"><option value="">Ad hoc check (not in a campaign)</option><?php foreach ($activeCampaigns as $campaign): ?><option value="<?= (int) $campaign['id'] ?>"><?= escapeHtml($campaign['campaign_name'] . ' (' . date('M j', strtotime($campaign['start_date'])) . ' – ' . date('M j, Y', strtotime($campaign['end_date'])) . ')') ?></option><?php endforeach; ?></select><span class="muted">Select an active campaign to include this check in its progress.</span></label>
                <label>Asset<select name="asset_id" required><option value="">Select asset</option>
                    <?php $currentDepartment = null; foreach ($assets as $asset): ?>
                        <?php if ($currentDepartment !== $asset['department_name']): ?>
                            <?php if ($currentDepartment !== null): ?></optgroup><?php endif; ?>
                            <?php $currentDepartment = $asset['department_name']; ?>
                            <optgroup label="<?= escapeHtml($currentDepartment) ?>">
                        <?php endif; ?>
                        <option value="<?= (int) $asset['id'] ?>"><?= escapeHtml($asset['asset_tag_id'] . ' — ' . $asset['asset_name']) ?></option>
                    <?php endforeach; ?>
                    <?php if ($currentDepartment !== null): ?></optgroup><?php endif; ?>
                </select></label>
                <label>Physical result<select name="result" required>
                    <option value="">Select result</option><option value="Found">Found and sighted</option><option value="Missing">Not found</option>
                </select></label>
                <label>Observed condition<select name="observed_condition" data-required-when-found>
                    <option value="">Select if asset is found</option>
                    <?php foreach ($conditions as $condition): ?><option value="<?= escapeHtml($condition) ?>"><?= escapeHtml($condition) ?></option><?php endforeach; ?>
                </select></label>
                <label class="form-field-wide">Notes<textarea name="notes" rows="3" maxlength="5000" placeholder="Optional location or follow-up details"></textarea></label>
                <label>Photo evidence (optional)<input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" capture="environment"><span class="muted">JPEG, PNG, or WebP; maximum 4 MB. Avoid faces or sensitive documents.</span></label>
                <div class="form-actions"><button class="button button-primary" type="submit">Save verification</button></div>
            </form>
        <?php endif; ?>
        <p class="muted report-note">The system records the verifier and timestamp as an application sign-off; this is not a formal electronic signature. Optional image evidence is stored outside direct web access. Follow Academy privacy and evidence-retention rules. A custodian cannot verify an asset assigned to themselves.</p>
    </section>
    <section class="panel table-panel">
        <div class="panel-heading"><div><h2>Verification history</h2><p class="muted">Latest 200 recorded checks. Missing findings require follow-up.</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Date &amp; time</th><th>Campaign</th><th>Asset tag</th><th>Department</th><th>Result</th><th>Observed condition</th><th>Verifier</th><th>Evidence</th><th>Notes</th></tr></thead>
                <tbody>
                <?php if ($history === []): ?>
                    <tr><td class="empty-state" colspan="9">No physical verification checks have been recorded.</td></tr>
                <?php else: foreach ($history as $entry): ?>
                    <tr>
                        <td><?= escapeHtml(date('M j, Y H:i', strtotime($entry['verified_at']))) ?></td>
                        <td><?= escapeHtml($entry['campaign_name'] ?? 'Ad hoc') ?></td>
                        <td><?= escapeHtml($entry['asset_tag_snapshot']) ?></td>
                        <td><?= escapeHtml($entry['department_name']) ?></td>
                        <td><span class="badge <?= $entry['result'] === 'Missing' ? 'badge-warning' : 'badge-success' ?>"><?= escapeHtml($entry['result']) ?></span></td>
                        <td><?= escapeHtml($entry['observed_condition'] ?? '—') ?></td>
                        <td><?= escapeHtml($entry['verifier_name']) ?></td>
                        <td><?php if ($entry['evidence_id'] !== null): ?><a href="<?= APP_BASE_URL ?>/modules/verification/evidence.php?id=<?= (int) $entry['evidence_id'] ?>" target="_blank" rel="noopener">View image</a><?php else: ?>—<?php endif; ?></td>
                        <td><?= escapeHtml($entry['notes'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
