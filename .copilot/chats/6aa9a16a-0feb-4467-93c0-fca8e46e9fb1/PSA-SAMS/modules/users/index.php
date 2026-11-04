<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
requireRole('super_admin');

$pdo = Database::connection();
$actorId = (int) $_SESSION['user']['id'];

if (isPostRequest()) {
    requireValidFormToken();
    $action = formString('action');

    try {
        $pdo->beginTransaction();

        if ($action === 'create_user') {
            $fullName = formString('full_name');
            $email = formString('email');
            $role = formString('role');
            $password = formRawString('password');
            $departmentInput = formString('department_id');
            $departmentId = $departmentInput === '' ? null : filter_var($departmentInput, FILTER_VALIDATE_INT);
            $validRoles = ['super_admin', 'admin_officer', 'stores_officer', 'approving_officer', 'management'];
            if (!is_string($fullName) || $fullName === '' || strlen($fullName) > 150
                || !is_string($email) || strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
                || !in_array($role, $validRoles, true)
                || ($departmentId !== null && (!is_int($departmentId) || $departmentId < 1))
                || !is_string($password) || strlen($password) < 14 || strlen($password) > 72
                || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)
                || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
                throw new InvalidArgumentException('Enter valid user details and a 14-72 byte password with upper/lowercase, a number, and a symbol.');
            }

            $insert = $pdo->prepare(
                'INSERT INTO users (full_name, email, password_hash, role, department_id, status)
                 VALUES (:full_name, :email, :password_hash, :role, :department_id, \'Active\')'
            );
            $insert->execute([
                'full_name' => $fullName,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'department_id' => $departmentId,
            ]);
            $userId = (int) $pdo->lastInsertId();
            recordAudit($pdo, 'Created user ' . $email, 'users', $userId);
        } elseif ($action === 'toggle_status') {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $status = formString('status');
            if (!is_int($userId) || $userId < 1 || !in_array($status, ['Active', 'Inactive'], true)) {
                throw new InvalidArgumentException('Choose a valid user and account status.');
            }
            if ($userId === $actorId && $status === 'Inactive') {
                throw new InvalidArgumentException('You cannot deactivate your own account.');
            }

            $userQuery = $pdo->prepare('SELECT id, email, role, status FROM users WHERE id = :id FOR UPDATE');
            $userQuery->execute(['id' => $userId]);
            $target = $userQuery->fetch();
            if (!$target) {
                throw new InvalidArgumentException('The user account was not found.');
            }
            if ($target['role'] === 'super_admin' && $target['status'] === 'Active' && $status === 'Inactive') {
                $admins = $pdo->query(
                    "SELECT id FROM users WHERE role = 'super_admin' AND status = 'Active' FOR UPDATE"
                )->fetchAll();
                if (count($admins) <= 1) {
                    throw new InvalidArgumentException('The last active super administrator cannot be deactivated.');
                }
            }

            $update = $pdo->prepare('UPDATE users SET status = :status WHERE id = :id');
            $update->execute(['status' => $status, 'id' => $userId]);
            recordAudit(
                $pdo,
                'Set account status to ' . $status . ' for ' . $target['email'],
                'users',
                $userId,
                ['email' => $target['email'], 'before' => $target['status'], 'after' => $status]
            );
        } elseif ($action === 'reset_password') {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $password = formRawString('password');
            if (!is_int($userId) || $userId < 1 || !is_string($password)
                || strlen($password) < 14 || strlen($password) > 72
                || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)
                || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
                throw new InvalidArgumentException('Choose a user and enter a 14-72 byte password with upper/lowercase, a number, and a symbol.');
            }
            $userQuery = $pdo->prepare('SELECT email FROM users WHERE id = :id FOR UPDATE');
            $userQuery->execute(['id' => $userId]);
            $email = $userQuery->fetchColumn();
            if (!$email) {
                throw new InvalidArgumentException('The user account was not found.');
            }

            $update = $pdo->prepare(
                'UPDATE users SET password_hash = :password_hash, auth_version = auth_version + 1,
                                  must_change_password = 1 WHERE id = :id'
            );
            $update->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $userId,
            ]);
            recordAudit($pdo, 'Reset password for ' . $email, 'users', $userId);
        } else {
            throw new InvalidArgumentException('Choose a valid user administration action.');
        }

        $pdo->commit();
        setFlashMessage('success', 'User account changes saved successfully.');
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

    redirectTo('/modules/users/index.php');
}

$departments = $pdo->query('SELECT id, department_name FROM departments ORDER BY department_name')->fetchAll();
$users = $pdo->query(
    'SELECT users.id, users.full_name, users.email, users.role, users.status, departments.department_name
     FROM users LEFT JOIN departments ON departments.id = users.department_id
     ORDER BY users.created_at DESC, users.id DESC LIMIT 200'
)->fetchAll();

$pageTitle = 'User administration';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-heading"><div><p class="eyebrow">System administration</p><h1>User administration</h1>
        <p class="muted">Create accounts, reset passwords, and deactivate access when required.</p></div></div>
    <?php showFlashMessage(); ?>
    <section class="panel form-panel"><div class="panel-heading"><div><h2>Create a user</h2><p class="muted">Set an initial password and share it with the account holder through an approved secure channel.</p></div></div>
        <form method="post" class="data-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>"><input type="hidden" name="action" value="create_user">
            <label>Full name<input name="full_name" maxlength="150" required></label>
            <label>Email address<input name="email" type="email" maxlength="190" required></label>
            <label>Role<select name="role" required><?php foreach (['admin_officer', 'stores_officer', 'approving_officer', 'management', 'super_admin'] as $role): ?><option value="<?= escapeHtml($role) ?>"><?= escapeHtml(ucwords(str_replace('_', ' ', $role))) ?></option><?php endforeach; ?></select></label>
            <label>Department<select name="department_id"><option value="">No department</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>"><?= escapeHtml($department['department_name']) ?></option><?php endforeach; ?></select></label>
            <label>Initial password<input name="password" type="password" autocomplete="new-password" minlength="14" maxlength="72" required></label>
            <div class="form-actions"><button class="button button-primary" type="submit">Create account</button></div>
        </form>
    </section>
    <section class="panel table-panel"><div class="panel-heading"><div><h2>User accounts</h2><p class="muted">Showing the latest 200 accounts</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            <?php if ($users === []): ?><tr><td class="empty-state" colspan="6">No user accounts found.</td></tr>
            <?php else: foreach ($users as $user): ?><tr>
                <td><?= escapeHtml($user['full_name']) ?></td><td><?= escapeHtml($user['email']) ?></td>
                <td><?= escapeHtml(ucwords(str_replace('_', ' ', $user['role']))) ?></td>
                <td><?= escapeHtml($user['department_name'] ?? 'Not assigned') ?></td>
                <td><span class="badge <?= $user['status'] === 'Active' ? 'badge-success' : 'badge-warning' ?>"><?= escapeHtml($user['status']) ?></span></td>
                <td><div class="row-actions">
                    <?php if ((int) $user['id'] !== $actorId): ?>
                        <form method="post" class="inline-actions"><input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                            <input type="hidden" name="action" value="toggle_status"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                            <input type="hidden" name="status" value="<?= $user['status'] === 'Active' ? 'Inactive' : 'Active' ?>">
                            <button class="button button-quiet button-small" type="submit"><?= $user['status'] === 'Active' ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    <?php endif; ?>
                    <details><summary>Reset password</summary><form method="post" class="inline-actions">
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>"><input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                        <label class="sr-only" for="reset-password-<?= (int) $user['id'] ?>">New password</label>
                        <input id="reset-password-<?= (int) $user['id'] ?>" name="password" type="password" minlength="14" maxlength="72" autocomplete="new-password" required>
                        <button class="button button-primary button-small" type="submit">Set password</button>
                    </form></details>
                </div></td>
            </tr><?php endforeach; endif; ?>
        </tbody></table></div>
    </section>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
