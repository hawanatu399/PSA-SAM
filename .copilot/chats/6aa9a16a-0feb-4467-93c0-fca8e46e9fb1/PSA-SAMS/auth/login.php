<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth_check.php';

if (isLoggedIn()) {
    header('Location: ' . APP_BASE_URL . '/modules/dashboard/index.php');
    exit;
}

$errorMessage = '';
$email = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $emailInput = $_POST['email'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $csrfInput = $_POST['csrf_token'] ?? null;

    if (!isValidCsrfToken($csrfInput)) {
        $errorMessage = 'Your session has expired. Please try again.';
    } elseif (!is_string($emailInput) || !is_string($passwordInput)) {
        $errorMessage = 'Enter a valid email address and password.';
    } else {
        $email = trim($emailInput);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $passwordInput === '') {
            $errorMessage = 'Enter a valid email address and password.';
        } elseif (strlen($passwordInput) > 72) {
            $errorMessage = 'The email or password was incorrect, or the account is inactive.';
        } else {
            try {
                $pdo = Database::connection();
                $ipHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                $emailHash = hash('sha256', strtolower($email));
                $attemptKey = hash('sha256', $ipHash . ':' . $emailHash);
                $lockName = hash('sha256', 'PSA-SAMS login rate:' . $attemptKey);
                $lockStatement = $pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
                $lockStatement->execute(['lock_name' => $lockName]);
                if ((int) $lockStatement->fetchColumn() !== 1) {
                    $errorMessage = 'Sign-in is temporarily unavailable. Please try again later.';
                } else {
                    try {
                        $rateLimit = $pdo->prepare(
                            'SELECT attempt_count
                             FROM login_attempts
                             WHERE attempt_key = :attempt_key
                               AND last_attempt_at >= CURRENT_TIMESTAMP - INTERVAL 15 MINUTE'
                        );
                        $rateLimit->execute(['attempt_key' => $attemptKey]);
                        $attemptCount = $rateLimit->fetchColumn();

                        if ($attemptCount !== false && (int) $attemptCount >= 10) {
                            $errorMessage = 'Sign-in is temporarily unavailable. Please try again later.';
                        } else {
                            $statement = $pdo->prepare(
                                'SELECT id, full_name, email, password_hash, role, department_id, status,
                                        auth_version, must_change_password
                                 FROM users WHERE email = :email LIMIT 1'
                            );
                            $statement->execute(['email' => $email]);
                            $user = $statement->fetch();
                            $passwordHash = $user['password_hash'] ?? '$2y$10$WF/BZ2NB1F2NOcBRqijG/.Z/gNJd8jdBmyktwb.j.VxNLoQ..DyVq';
                            $passwordMatches = password_verify($passwordInput, $passwordHash);

                            if ($user && $user['status'] === 'Active' && $passwordMatches) {
                                $clearAttempts = $pdo->prepare('DELETE FROM login_attempts WHERE attempt_key = :attempt_key');
                                $clearAttempts->execute(['attempt_key' => $attemptKey]);

                                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                                    $rehash = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
                                    $rehash->execute([
                                        'password_hash' => password_hash($passwordInput, PASSWORD_DEFAULT),
                                        'id' => (int) $user['id'],
                                    ]);
                                }

                                $audit = $pdo->prepare(
                                    'INSERT INTO audit_logs (user_id, action_performed, target_table, record_id)
                                     VALUES (:user_id, :action, :target_table, :record_id)'
                                );
                                $audit->execute([
                                    'user_id' => (int) $user['id'],
                                    'action' => 'Logged in',
                                    'target_table' => 'users',
                                    'record_id' => (int) $user['id'],
                                ]);

                                $releaseLock = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                                $releaseLock->execute(['lock_name' => $lockName]);
                                session_regenerate_id(true);
                                $_SESSION['user'] = [
                                    'id' => (int) $user['id'],
                                    'full_name' => $user['full_name'],
                                    'email' => $user['email'],
                                    'role' => $user['role'],
                                    'department_id' => $user['department_id'] === null ? null : (int) $user['department_id'],
                                ];
                                $_SESSION['auth_version'] = (int) $user['auth_version'];
                                $_SESSION['must_change_password'] = (bool) $user['must_change_password'];
                                $_SESSION['authenticated_at'] = time();
                                $_SESSION['last_activity_at'] = time();
                                unset($_SESSION['csrf_token']);

                                $destination = $_SESSION['must_change_password']
                                    ? '/modules/account/index.php'
                                    : '/modules/dashboard/index.php';
                                header('Location: ' . APP_BASE_URL . $destination);
                                exit;
                            }

                            $failedAttempt = $pdo->prepare(
                                'INSERT INTO login_attempts (attempt_key, ip_hash, email_hash, attempt_count, window_started_at, last_attempt_at)
                                 VALUES (:attempt_key, :ip_hash, :email_hash, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                                 ON DUPLICATE KEY UPDATE
                                    attempt_count = IF(last_attempt_at < CURRENT_TIMESTAMP - INTERVAL 15 MINUTE, 1, attempt_count + 1),
                                    window_started_at = IF(last_attempt_at < CURRENT_TIMESTAMP - INTERVAL 15 MINUTE, CURRENT_TIMESTAMP, window_started_at),
                                    last_attempt_at = CURRENT_TIMESTAMP'
                            );
                            $failedAttempt->execute([
                                'attempt_key' => $attemptKey,
                                'ip_hash' => $ipHash,
                                'email_hash' => $emailHash,
                            ]);
                            $pdo->exec('DELETE FROM login_attempts WHERE last_attempt_at < CURRENT_TIMESTAMP - INTERVAL 30 DAY');
                            $errorMessage = 'The email or password was incorrect, or the account is inactive.';
                        }
                    } finally {
                        $releaseLock = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                        $releaseLock->execute(['lock_name' => $lockName]);
                    }
                }
            } catch (PDOException | RuntimeException $exception) {
                error_log('PSA-SAMS authentication processing failed: ' . $exception->getMessage());
                $errorMessage = 'Sign-in is temporarily unavailable. Please try again later.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Sign in to the Public Service Academy Stores and Asset Management System">
    <title>Sign in | <?= escapeHtml(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= APP_BASE_URL ?>/assets/css/style.css">
</head>
<body class="login-page">
    <main class="login-card">
        <div class="brand-mark" aria-hidden="true">PSA</div>
        <p class="eyebrow">Public Service Academy</p>
        <h1>Welcome back</h1>
        <p class="muted">Sign in to manage stores, assets and approvals.</p>

        <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-error" role="alert"><?= escapeHtml($errorMessage) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= APP_BASE_URL ?>/auth/login.php" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" autocomplete="username" maxlength="190"
                   value="<?= escapeHtml($email) ?>" required autofocus>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <button type="submit" class="button button-primary button-full">Sign in</button>
        </form>
        <p class="login-help">Use your assigned Academy account. Contact an administrator if you need access.</p>
    </main>
</body>
</html>
