<?php
declare(strict_types=1);

define('APP_NAME', 'Public Service Academy - Stores & Asset Management');
$baseUrl = getenv('PSA_APP_BASE_URL') ?: '/PSA-SAMS';

if (!preg_match('#^/(?:[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*)?$#', $baseUrl) || str_contains($baseUrl, '..')) {
    throw new RuntimeException('PSA_APP_BASE_URL must be a local application path.');
}

define('APP_BASE_URL', rtrim($baseUrl, '/'));

if (PHP_SAPI !== 'cli') {
    set_exception_handler(static function (Throwable $exception): void {
        error_log('PSA-SAMS unhandled exception: ' . $exception->getMessage());

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Service unavailable | PSA-SAMS</title>'
            . '<link rel="stylesheet" href="' . APP_BASE_URL . '/assets/css/style.css">'
            . '</head><body class="login-page"><main class="login-card">'
            . '<p class="eyebrow">PSA-SAMS</p><h1>We could not load this page</h1>'
            . '<p class="muted">An unexpected error occurred. Please try again later. '
            . 'The technical details have been recorded for support.</p>'
            . '<a class="button button-primary" href="' . APP_BASE_URL . '/">Return to the home page</a>'
            . '</main></body></html>';
        exit;
    });
}

final class Database
{
    private static ?PDO $connection = null;

    private function __construct()
    {
    }

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = getenv('PSA_DB_HOST') ?: '127.0.0.1';
        $database = getenv('PSA_DB_NAME') ?: 'psa_sams_db';
        $username = getenv('PSA_DB_USER') ?: 'root';
        $password = getenv('PSA_DB_PASSWORD') ?: '';
        $port = getenv('PSA_DB_PORT') ?: '3306';
        if (!ctype_digit((string) $port) || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('PSA_DB_PORT must be a valid TCP port number.');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int) $port, $database);

        try {
            self::$connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            error_log('PSA-SAMS database connection failed: ' . $exception->getMessage());
            throw new RuntimeException('The application could not connect to its database.', 0, $exception);
        }

        return self::$connection;
    }
}
