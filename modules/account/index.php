<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
requireRole(['super_admin', 'admin_officer', 'stores_officer', 'approving_officer', 'management']);

$pdo = Database::connection();
$userId = (int) $_SESSION['user']['id'];
$errorMessage = '';

if (isPostRequest()) {
    requireValidFormToken();
    $currentPassword = formRawString('current_password');
    $newPassword = formRawString('new_password');
    $confirmPassword = formRawString('confirm_password');

    if (!is_string($currentPassword) || !is_string($newPassword) || !is_string($confirmPassword)
        || strlen($newPassword) < 14 || strlen($newPassword) > 72
        || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword)
        || !preg_match('/[0-9]/', $newPassword) || !preg_match('/[^A-Za-z0-9]/', $newPassword)
        || !hash_equals($newPassword, $confirmPassword)) {
        $errorMessage = 'Use matching passwords of 14-72 bytes with uppercase, lowercase, a number, and a symbol.';
    } else {
        try {
            $pdo->beginTransaction();
            $query = $pdo->prepare(
                'SELECT password_hash FROM users WHERE id = :id AND status = \'Active\' FOR UPDATE'
            );
            $query->execute(['id' => $userId]);
            $user = $query->fetch();
            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                throw new InvalidArgumentException('Your current password was not correct.');
            }
            if (password_verify($newPassword, $user['password_hash'])) {
                throw new InvalidArgumentException('Choose a password different from your current password.');
            }

            $update = $pdo->prepare(
                'UPDATE users
                 SET password_hash = :password_hash, auth_version = auth_version + 1,
                     must_change_password = 0
                 WHERE id = :id'
            );
            $update->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => $userId,
            ]);
            recordAudit($pdo, 'Changed own password', 'users', $userId);
            $pdo->commit();

            session_regenerate_id(true);
            $_SESSION['auth_version']++;
            $_SESSION['must_change_password'] = false;
            setFlashMessage('success', 'Your password has been changed. Other active sessions have been invalidated.');
            redirectTo('/modules/account/index.php');
        } catch (InvalidArgumentException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            reportDatabaseFailure($exception);
            redirectTo('/modules/account/index.php');
        }
    }
}

$pageTitle = 'My account';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-heading"><div><p class="eyebrow">Account security</p><h1>My account</h1>
        <p class="muted">Update your password. Changing it invalidates other active sessions for this account.</p></div></div>
    <?php showFlashMessage(); ?>
    <?php if ($errorMessage !== ''): ?><div class="alert alert-error" role="alert"><?= escapeHtml($errorMessage) ?></div><?php endif; ?>
    <section class="panel form-panel">
        <div class="panel-heading"><div><h2>Change password</h2><p class="muted">Use a unique password that you do not use elsewhere.</p></div></div>
        <form method="post" class="data-form data-form-single account-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <label>Current password<input name="current_password" type="password" autocomplete="current-password" required></label>
            <label>New password<input name="new_password" type="password" autocomplete="new-password" minlength="14" maxlength="72" required></label>
            <label>Confirm new password<input name="confirm_password" type="password" autocomplete="new-password" minlength="14" maxlength="72" required></label>
            <button class="button button-primary" type="submit">Change password</button>
        </form>
    </section>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
