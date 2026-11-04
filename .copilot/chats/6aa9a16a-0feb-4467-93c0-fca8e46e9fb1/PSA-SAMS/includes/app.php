<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/auth_check.php';

function isPostRequest(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function requireValidFormToken(): void
{
    if (!isPostRequest() || !isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form submission. Refresh the page and try again.');
    }
}

function formString(string $name, string $default = ''): ?string
{
    $value = $_POST[$name] ?? $default;
    return is_string($value) ? trim($value) : null;
}

function formRawString(string $name): ?string
{
    $value = $_POST[$name] ?? null;
    return is_string($value) ? $value : null;
}

function setFlashMessage(string $type, string $message): void
{
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consumeFlashMessage(): ?array
{
    $message = $_SESSION['flash_message'] ?? null;
    unset($_SESSION['flash_message']);

    return is_array($message)
        && isset($message['type'], $message['message'])
        && is_string($message['type'])
        && is_string($message['message'])
        ? $message
        : null;
}

function recordAudit(PDO $pdo, string $action, string $table, ?int $recordId, ?array $details = null): void
{
    $statement = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, action_performed, target_table, record_id, details)
         VALUES (:user_id, :action, :target_table, :record_id, :details)'
    );
    $statement->execute([
        'user_id' => (int) $_SESSION['user']['id'],
        'action' => $action,
        'target_table' => $table,
        'record_id' => $recordId,
        'details' => $details === null ? null : json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]);
}

function redirectTo(string $path): void
{
    header('Location: ' . APP_BASE_URL . $path);
    exit;
}

function showFlashMessage(): void
{
    $flash = consumeFlashMessage();
    if ($flash === null) {
        return;
    }

    $class = $flash['type'] === 'success' ? 'alert-success' : 'alert-error';
    echo '<div class="alert ' . $class . '" role="status">'
        . escapeHtml($flash['message']) . '</div>';
}

function reportDatabaseFailure(PDOException $exception): void
{
    error_log('PSA-SAMS database operation failed: ' . $exception->getMessage());
    setFlashMessage('error', 'The request could not be completed. Check the submitted information and try again.');
}
