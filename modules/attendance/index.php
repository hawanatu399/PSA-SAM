<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
requireRole([
    'super_admin',
    'admin_officer',
    'stores_officer',
    'approving_officer',
    'management',
    'department_staff',
]);

$pdo = Database::connection();
$actorId = (int) $_SESSION['user']['id'];
$actorDepartmentId = $_SESSION['user']['department_id'] ?? null;
$roleCanViewAll = hasRole(['super_admin', 'admin_officer', 'stores_officer', 'approving_officer', 'management']);
$today = (string) $pdo->query('SELECT CURRENT_DATE')->fetchColumn();
$attendanceMessage = '';

if (isPostRequest()) {
    requireValidFormToken();
    $action = formString('action');
    if (!in_array($action, ['check_in', 'check_out'], true)) {
        http_response_code(400);
        exit('Choose a valid attendance action.');
    }
    if (!is_int($actorDepartmentId) || $actorDepartmentId < 1) {
        setFlashMessage('error', 'Your account needs an assigned department before you can record attendance.');
        redirectTo('/modules/attendance/index.php');
    }

    try {
        $pdo->beginTransaction();
        $departmentLock = $pdo->prepare('SELECT id FROM departments WHERE id = :id FOR UPDATE');
        $departmentLock->execute(['id' => $actorDepartmentId]);
        if (!$departmentLock->fetchColumn()) {
            throw new InvalidArgumentException('Your assigned department could not be found.');
        }

        $attendanceQuery = $pdo->prepare(
            'SELECT id, checked_in_at, checked_out_at
             FROM department_attendance
             WHERE department_id = :department_id AND user_id = :user_id AND attendance_date = :attendance_date
             FOR UPDATE'
        );
        $attendanceQuery->execute([
            'department_id' => $actorDepartmentId,
            'user_id' => $actorId,
            'attendance_date' => $today,
        ]);
        $attendance = $attendanceQuery->fetch();

        if ($action === 'check_in') {
            if ($attendance) {
                throw new InvalidArgumentException('Your attendance is already recorded for today.');
            }
            $openingCountOutstanding = false;

            $departmentAttendanceCount = $pdo->prepare(
                'SELECT COUNT(*) FROM department_attendance
                 WHERE department_id = :department_id AND attendance_date = :attendance_date'
            );
            $departmentAttendanceCount->execute([
                'department_id' => $actorDepartmentId,
                'attendance_date' => $today,
            ]);
            $isFirstIn = (int) $departmentAttendanceCount->fetchColumn() === 0;

            if (!$isFirstIn) {
                $openingCounts = $pdo->prepare(
                    "SELECT COUNT(DISTINCT checks.item_id)
                     FROM stock_checks AS checks
                     JOIN department_attendance AS first_in
                       ON first_in.department_id = checks.department_id
                      AND first_in.attendance_date = checks.check_date
                      AND first_in.is_first_in = 1
                      AND checks.checked_by = first_in.user_id
                      AND checks.checked_at > first_in.checked_in_at
                     WHERE checks.department_id = :department_id
                       AND checks.check_date = :check_date AND checks.shift = 'Opening'"
                );
                $openingCounts->execute([
                    'department_id' => $actorDepartmentId,
                    'check_date' => $today,
                ]);
                $itemCount = (int) $pdo->query('SELECT COUNT(*) FROM store_items')->fetchColumn();
                if ((int) $openingCounts->fetchColumn() < $itemCount) {
                    $openingCountOutstanding = true;
                }
            }

            $insert = $pdo->prepare(
                'INSERT INTO department_attendance
                    (department_id, user_id, attendance_date, is_first_in)
                 VALUES (:department_id, :user_id, :attendance_date, :is_first_in)'
            );
            $insert->execute([
                'department_id' => $actorDepartmentId,
                'user_id' => $actorId,
                'attendance_date' => $today,
                'is_first_in' => $isFirstIn ? 1 : 0,
            ]);
            $attendanceId = (int) $pdo->lastInsertId();
            recordAudit(
                $pdo,
                'Checked in to ' . ($isFirstIn ? 'open' : 'work in') . ' department',
                'department_attendance',
                $attendanceId,
                ['department_id' => $actorDepartmentId, 'attendance_date' => $today, 'first_in' => $isFirstIn]
            );
            $attendanceMessage = $isFirstIn
                ? 'You are the first person checked in today. Please complete the department Opening stock count.'
                : ($openingCountOutstanding
                    ? 'Your check-in has been recorded. The Opening stock count is still outstanding; the first person in or an authorized Stores/Admin officer should complete it.'
                    : 'Your department check-in has been recorded.');
        } else {
            if (!$attendance || $attendance['checked_out_at'] !== null) {
                throw new InvalidArgumentException('You need an active check-in before you can check out.');
            }

            $otherCheckedIn = $pdo->prepare(
                'SELECT COUNT(*) FROM department_attendance
                 WHERE department_id = :department_id AND attendance_date = :attendance_date
                   AND user_id <> :user_id AND checked_out_at IS NULL'
            );
            $otherCheckedIn->execute([
                'department_id' => $actorDepartmentId,
                'attendance_date' => $today,
                'user_id' => $actorId,
            ]);
            $isLastOut = (int) $otherCheckedIn->fetchColumn() === 0;

            if ($isLastOut) {
                $closingCounts = $pdo->prepare(
                    "SELECT COUNT(DISTINCT checks.item_id)
                     FROM stock_checks AS checks
                     WHERE checks.department_id = :department_id
                       AND checks.check_date = :check_date AND checks.shift = 'Closing'
                       AND checks.checked_by = :checked_by
                       AND checks.checked_at > :checked_in_at
                       AND checks.checked_at > COALESCE(
                           (SELECT MAX(previous.checked_out_at)
                            FROM department_attendance AS previous
                            WHERE previous.department_id = :previous_department_id
                              AND previous.attendance_date = :previous_date
                              AND previous.user_id <> :previous_user_id
                              AND previous.checked_out_at IS NOT NULL),
                           :checked_in_baseline
                       )"
                );
                $closingCounts->execute([
                    'department_id' => $actorDepartmentId,
                    'check_date' => $today,
                    'checked_by' => $actorId,
                    'checked_in_at' => $attendance['checked_in_at'],
                    'previous_department_id' => $actorDepartmentId,
                    'previous_date' => $today,
                    'previous_user_id' => $actorId,
                    'checked_in_baseline' => $attendance['checked_in_at'],
                ]);
                $itemCount = (int) $pdo->query('SELECT COUNT(*) FROM store_items')->fetchColumn();
                if ((int) $closingCounts->fetchColumn() < $itemCount) {
                    throw new InvalidArgumentException('As the last person checked in, complete the department Closing stock count after the most recent staff departure before you check out.');
                }
            }

            $update = $pdo->prepare(
                'UPDATE department_attendance
                 SET checked_out_at = CURRENT_TIMESTAMP, is_last_out = :is_last_out
                 WHERE id = :id AND checked_out_at IS NULL'
            );
            $update->execute([
                'is_last_out' => $isLastOut ? 1 : 0,
                'id' => (int) $attendance['id'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new InvalidArgumentException('Your attendance was already checked out. Refresh and try again.');
            }
            recordAudit(
                $pdo,
                'Checked out of department',
                'department_attendance',
                (int) $attendance['id'],
                ['department_id' => $actorDepartmentId, 'attendance_date' => $today, 'last_out' => $isLastOut]
            );
            $attendanceMessage = $isLastOut
                ? 'Closing check recorded. You were the last person checked out of the department today.'
                : 'Your department check-out has been recorded.';
        }

        $pdo->commit();
        setFlashMessage('success', $attendanceMessage);
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
    redirectTo('/modules/attendance/index.php');
}

$departments = $pdo->query('SELECT id, department_name FROM departments ORDER BY department_name')->fetchAll();
$departmentInput = $_GET['department'] ?? '';
$selectedDepartmentId = is_int($actorDepartmentId) ? $actorDepartmentId : null;
if ($roleCanViewAll && is_string($departmentInput) && $departmentInput !== '') {
    $requestedDepartmentId = filter_var($departmentInput, FILTER_VALIDATE_INT);
    if (is_int($requestedDepartmentId) && $requestedDepartmentId > 0) {
        $selectedDepartmentId = $requestedDepartmentId;
    }
} elseif ($roleCanViewAll && $selectedDepartmentId === null && isset($departments[0])) {
    $selectedDepartmentId = (int) $departments[0]['id'];
}

$selectedDepartmentName = '';
foreach ($departments as $department) {
    if ((int) $department['id'] === $selectedDepartmentId) {
        $selectedDepartmentName = (string) $department['department_name'];
        break;
    }
}
if ($selectedDepartmentName === '') {
    $selectedDepartmentId = null;
}

$myAttendance = null;
if (is_int($actorDepartmentId)) {
    $myAttendanceQuery = $pdo->prepare(
        'SELECT checked_in_at, checked_out_at, is_first_in, is_last_out
         FROM department_attendance
         WHERE user_id = :user_id AND department_id = :department_id AND attendance_date = :attendance_date'
    );
    $myAttendanceQuery->execute([
        'user_id' => $actorId,
        'department_id' => $actorDepartmentId,
        'attendance_date' => $today,
    ]);
    $myAttendance = $myAttendanceQuery->fetch() ?: null;
}

$departmentAttendance = [];
$checkedInCount = 0;
if (is_int($selectedDepartmentId)) {
    $attendanceListQuery = $pdo->prepare(
        'SELECT attendance.checked_in_at, attendance.checked_out_at,
                attendance.is_first_in, attendance.is_last_out, users.full_name
         FROM department_attendance AS attendance
         JOIN users ON users.id = attendance.user_id
         WHERE attendance.department_id = :department_id AND attendance.attendance_date = :attendance_date
         ORDER BY attendance.checked_in_at, attendance.id'
    );
    $attendanceListQuery->execute([
        'department_id' => $selectedDepartmentId,
        'attendance_date' => $today,
    ]);
    $departmentAttendance = $attendanceListQuery->fetchAll();
    foreach ($departmentAttendance as $record) {
        if ($record['checked_out_at'] === null) {
            $checkedInCount++;
        }
    }
}

$myDepartmentActiveCount = 0;
$myOpeningCount = 0;
$myItemCount = (int) $pdo->query('SELECT COUNT(*) FROM store_items')->fetchColumn();
if (is_int($actorDepartmentId)) {
    $activeCountQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM department_attendance
         WHERE department_id = :department_id AND attendance_date = :attendance_date AND checked_out_at IS NULL'
    );
    $activeCountQuery->execute(['department_id' => $actorDepartmentId, 'attendance_date' => $today]);
    $myDepartmentActiveCount = (int) $activeCountQuery->fetchColumn();
    $openingCountQuery = $pdo->prepare(
        "SELECT COUNT(DISTINCT item_id) FROM stock_checks
         WHERE department_id = :department_id AND check_date = :check_date AND shift = 'Opening'"
    );
    $openingCountQuery->execute(['department_id' => $actorDepartmentId, 'check_date' => $today]);
    $myOpeningCount = (int) $openingCountQuery->fetchColumn();
}
$stockCheckShift = $myAttendance !== null
    && $myAttendance['checked_out_at'] === null
    && $myDepartmentActiveCount === 1
    && (int) $myAttendance['is_first_in'] !== 1
    ? 'Closing'
    : 'Opening';
if ($myAttendance !== null && $myAttendance['checked_out_at'] === null
    && (int) $myAttendance['is_first_in'] === 1
    && $myOpeningCount >= $myItemCount
    && $myDepartmentActiveCount === 1) {
    $stockCheckShift = 'Closing';
}

$pageTitle = 'Department attendance';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-heading"><div><p class="eyebrow">Daily department operations</p><h1>Department attendance</h1>
        <p class="muted">Record your own arrival and departure using your personal account. First-in and last-out identification is accurate only if everyone present checks in and out. This operational log is not a payroll timesheet.</p></div></div>
    <?php showFlashMessage(); ?>
    <section class="content-grid">
        <article class="panel">
            <div class="panel-heading"><div><h2>My attendance today</h2><p class="muted"><?= escapeHtml(date('l, F j, Y', strtotime($today))) ?></p></div></div>
            <?php if (!is_int($actorDepartmentId) || $actorDepartmentId < 1): ?>
                <p class="muted">Your account is not assigned to a department. Ask an administrator to assign your department before using check-in/check-out.</p>
            <?php elseif ($myAttendance === null): ?>
                <p class="muted">You have not checked in today. Check in when you arrive at your assigned department.</p>
                <form method="post" class="inline-actions">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                    <input type="hidden" name="action" value="check_in">
                    <button class="button button-primary" type="submit">Check in</button>
                </form>
            <?php else: ?>
                <p><strong>Checked in:</strong> <?= escapeHtml(date('H:i', strtotime($myAttendance['checked_in_at']))) ?></p>
                <?php if ((int) $myAttendance['is_first_in'] === 1): ?>
                    <p><span class="badge badge-success">First person in</span></p>
                    <?php if ($myOpeningCount < $myItemCount): ?><p class="muted">The Opening stock count is still outstanding. Complete it as soon as possible; other staff can check in if needed.</p><?php endif; ?>
                <?php endif; ?>
                <?php if ($myAttendance['checked_out_at'] === null): ?>
                    <p class="muted"><?= $myDepartmentActiveCount === 1 ? 'You are currently the last person checked in. Complete the Closing stock count before checking out.' : 'Complete the Closing stock check when you are the last person to leave.' ?></p>
                    <form method="post" class="inline-actions">
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                        <input type="hidden" name="action" value="check_out">
                        <button class="button button-secondary" type="submit">Check out</button>
                    </form>
                <?php else: ?>
                    <p><strong>Checked out:</strong> <?= escapeHtml(date('H:i', strtotime($myAttendance['checked_out_at']))) ?></p>
                    <?php if ((int) $myAttendance['is_last_out'] === 1): ?><p><span class="badge badge-success">Last person out</span></p><?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </article>
        <article class="panel">
            <div class="panel-heading"><div><h2>Department stock handover</h2><p class="muted">Opening: first person in. Closing: last person out.</p></div></div>
            <p class="muted">The first person in should complete the Opening count before colleagues check in. If they cannot, an authorized Stores/Admin officer can record it; attendance remains available so the department is not locked out. The last person still checked in completes Closing before checking out.</p>
            <?php if (is_int($actorDepartmentId)): ?>
                <?php if ($myAttendance !== null && $myAttendance['checked_out_at'] === null): ?>
                    <a class="button button-primary" href="<?= APP_BASE_URL ?>/modules/stores/index.php?department=<?= $actorDepartmentId ?>&amp;shift=<?= escapeHtml($stockCheckShift) ?>">Open department <?= escapeHtml(strtolower($stockCheckShift)) ?> stock count</a>
                <?php endif; ?>
            <?php endif; ?>
        </article>
    </section>
    <?php if ($roleCanViewAll && $departments !== []): ?>
        <section class="panel table-panel">
            <div class="panel-heading"><div><h2>Department attendance register</h2><p class="muted">Today's recorded arrivals and departures</p></div></div>
            <form method="get" class="stock-check-period">
                <label for="attendance-department">Department</label>
                <select id="attendance-department" name="department">
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['id'] ?>" <?= (int) $department['id'] === $selectedDepartmentId ? 'selected' : '' ?>><?= escapeHtml($department['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button button-secondary" type="submit">Show attendance</button>
            </form>
            <p class="muted"><?= escapeHtml($selectedDepartmentName) ?> · <?= $checkedInCount ?> currently checked in</p>
            <div class="table-wrap"><table><thead><tr><th>Staff member</th><th>Check-in</th><th>Check-out</th><th>Attendance status</th></tr></thead><tbody>
                <?php if ($departmentAttendance === []): ?><tr><td class="empty-state" colspan="4">No attendance has been recorded for this department today.</td></tr>
                <?php else: foreach ($departmentAttendance as $record): ?><tr>
                    <td><?= escapeHtml($record['full_name']) ?></td>
                    <td><?= escapeHtml(date('H:i', strtotime($record['checked_in_at']))) ?><?= (int) $record['is_first_in'] === 1 ? ' · First in' : '' ?></td>
                    <td><?= $record['checked_out_at'] === null ? '—' : escapeHtml(date('H:i', strtotime($record['checked_out_at']))) ?><?= (int) $record['is_last_out'] === 1 ? ' · Last out' : '' ?></td>
                    <td><span class="badge <?= $record['checked_out_at'] === null ? 'badge-success' : '' ?>"><?= $record['checked_out_at'] === null ? 'Checked in' : 'Checked out' ?></span></td>
                </tr><?php endforeach; endif; ?>
            </tbody></table></div>
        </section>
    <?php elseif (is_int($selectedDepartmentId)): ?>
        <section class="panel table-panel">
            <div class="panel-heading"><div><h2>Today's department attendance</h2><p class="muted"><?= escapeHtml($selectedDepartmentName) ?> · <?= $checkedInCount ?> currently checked in</p></div></div>
            <div class="table-wrap"><table><thead><tr><th>Staff member</th><th>Check-in</th><th>Check-out</th><th>Status</th></tr></thead><tbody>
                <?php if ($departmentAttendance === []): ?><tr><td class="empty-state" colspan="4">No attendance has been recorded for your department today.</td></tr>
                <?php else: foreach ($departmentAttendance as $record): ?><tr>
                    <td><?= escapeHtml($record['full_name']) ?></td>
                    <td><?= escapeHtml(date('H:i', strtotime($record['checked_in_at']))) ?><?= (int) $record['is_first_in'] === 1 ? ' · First in' : '' ?></td>
                    <td><?= $record['checked_out_at'] === null ? '—' : escapeHtml(date('H:i', strtotime($record['checked_out_at']))) ?><?= (int) $record['is_last_out'] === 1 ? ' · Last out' : '' ?></td>
                    <td><?= $record['checked_out_at'] === null ? 'Checked in' : 'Checked out' ?></td>
                </tr><?php endforeach; endif; ?>
            </tbody></table></div>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
