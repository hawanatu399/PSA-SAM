<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$httpsTerminatedByTrustedProxy = psaEnvironmentFlag('PSA_HTTPS_TERMINATED_BY_TRUSTED_PROXY');
$httpsRequest = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off')
    || $httpsTerminatedByTrustedProxy;
$httpsRequired = psaEnvironmentFlag('PSA_REQUIRE_HTTPS');
if (PHP_SAPI !== 'cli' && $httpsRequired && !$httpsRequest) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    exit('HTTPS is required to access PSA-SAMS. Use the approved secure application address.');
}

$secureCookie = psaEnvironmentFlag('PSA_COOKIE_SECURE', $httpsRequest || $httpsRequired);
if ($httpsRequired && !$secureCookie) {
    throw new RuntimeException('PSA_COOKIE_SECURE must be enabled when PSA_REQUIRE_HTTPS is enabled.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '28800');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_name('PSASAMSSESSID');

    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:");
        if ($httpsRequest && $httpsRequired) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => defined('APP_BASE_URL') ? APP_BASE_URL . '/' : '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    $now = time();
    if (isset($_SESSION['user'])) {
        $authenticatedAt = $_SESSION['authenticated_at'] ?? 0;
        $lastActivityAt = $_SESSION['last_activity_at'] ?? 0;
        if (!is_int($authenticatedAt) || !is_int($lastActivityAt)
            || $now - $lastActivityAt > 1800 || $now - $authenticatedAt > 28800) {
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            $_SESSION['last_activity_at'] = $now;
        }
    }

}

function isLoggedIn(): bool
{
    return isset($_SESSION['user']) && is_array($_SESSION['user'])
        && isset($_SESSION['user']['id'], $_SESSION['user']['role'])
        && is_int($_SESSION['user']['id'])
        && is_string($_SESSION['user']['role']);
}

function hasRole(array|string $roles): bool
{
    if (!isLoggedIn()) {
        return false;
    }

    $allowedRoles = is_array($roles) ? $roles : [$roles];
    return in_array($_SESSION['user']['role'], $allowedRoles, true);
}

function requireRole(array|string $roles): void
{
    if (!isLoggedIn()) {
        header('Location: ' . APP_BASE_URL . '/auth/login.php');
        exit;
    }

    try {
        $accountQuery = Database::connection()->prepare(
            'SELECT role, status, auth_version, must_change_password
             FROM users WHERE id = :id LIMIT 1'
        );
        $accountQuery->execute(['id' => (int) $_SESSION['user']['id']]);
        $account = $accountQuery->fetch();
    } catch (PDOException | RuntimeException $exception) {
        error_log('PSA-SAMS session validation failed: ' . $exception->getMessage());
        throw new RuntimeException('The application could not validate the current account.', 0, $exception);
    }

    if (!$account || $account['status'] !== 'Active' || $account['role'] !== $_SESSION['user']['role']
        || !isset($_SESSION['auth_version'])
        || (int) $account['auth_version'] !== (int) $_SESSION['auth_version']) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: ' . APP_BASE_URL . '/auth/login.php');
        exit;
    }

    $_SESSION['must_change_password'] = (bool) $account['must_change_password'];
    if ($_SESSION['must_change_password']) {
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $allowedPaths = [
            APP_BASE_URL . '/modules/account/index.php',
            APP_BASE_URL . '/auth/logout.php',
        ];
        if (!in_array($requestPath, $allowedPaths, true)) {
            header('Location: ' . APP_BASE_URL . '/modules/account/index.php');
            exit;
        }
    }

    if (!hasRole($roles)) {
        http_response_code(403);
        exit('403 Forbidden: You do not have permission to access this page.');
    }
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function isValidCsrfToken(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function escapeHtml(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
