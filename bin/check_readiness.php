<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$failures = 0;
$warnings = 0;

$report = static function (string $status, string $message) use (&$failures, &$warnings): void {
    fwrite(STDOUT, sprintf("[%s] %s\n", $status, $message));
    if ($status === 'FAIL') {
        $failures++;
    } elseif ($status === 'WARN') {
        $warnings++;
    }
};

$report(
    PHP_VERSION_ID >= 80000 ? 'PASS' : 'FAIL',
    'PHP ' . PHP_VERSION . (PHP_VERSION_ID >= 80000 ? ' meets the PHP 8.0 minimum.' : ' is below the PHP 8.0 minimum.')
);

$drivers = PDO::getAvailableDrivers();
$hasMysqlDriver = in_array('mysql', $drivers, true);
$report(
    $hasMysqlDriver ? 'PASS' : 'FAIL',
    $hasMysqlDriver ? 'PDO MySQL driver is available.' : 'PDO MySQL driver is missing; enable pdo_mysql.'
);

try {
    $httpsRequired = psaEnvironmentFlag('PSA_REQUIRE_HTTPS');
    $secureCookie = psaEnvironmentFlag('PSA_COOKIE_SECURE', $httpsRequired);
    psaEnvironmentFlag('PSA_HTTPS_TERMINATED_BY_TRUSTED_PROXY');
    if ($httpsRequired && !$secureCookie) {
        $report('FAIL', 'PSA_COOKIE_SECURE must be enabled when PSA_REQUIRE_HTTPS is enabled.');
    } else {
        $report(
            $httpsRequired && $secureCookie ? 'PASS' : 'WARN',
            $httpsRequired && $secureCookie
                ? 'HTTPS is required and session cookies are configured as Secure.'
                : 'HTTPS enforcement is disabled. Enable PSA_REQUIRE_HTTPS and PSA_COOKIE_SECURE in managed deployment configuration.'
        );
    }
} catch (RuntimeException $exception) {
    $report('FAIL', $exception->getMessage());
}

foreach ([
    '.htaccess' => dirname(__DIR__) . '/.htaccess',
    'config/.htaccess' => dirname(__DIR__) . '/config/.htaccess',
    'uploads/.htaccess' => dirname(__DIR__) . '/uploads/.htaccess',
    'uploads/evidence/.htaccess' => dirname(__DIR__) . '/uploads/evidence/.htaccess',
] as $label => $path) {
    $exists = is_file($path);
    $report(
        $exists ? 'PASS' : 'FAIL',
        $exists ? $label . ' protection file exists.' : $label . ' protection file is missing.'
    );
}

$rootAccessPath = dirname(__DIR__) . '/.htaccess';
$rootAccessRules = is_file($rootAccessPath) && is_readable($rootAccessPath)
    ? file_get_contents($rootAccessPath)
    : false;
$sqlFilesAreProtected = is_string($rootAccessRules)
    && str_contains($rootAccessRules, '<FilesMatch "\.(sql|sql\.example)$">')
    && str_contains($rootAccessRules, 'Require all denied');
$report(
    $sqlFilesAreProtected ? 'PASS' : 'FAIL',
    $sqlFilesAreProtected
        ? 'Direct web access to SQL files is denied.'
        : 'The root .htaccess must deny direct web access to SQL files.'
);

$evidenceDirectory = dirname(__DIR__) . '/uploads/evidence';
$evidenceDirectoryReady = is_dir($evidenceDirectory) && is_writable($evidenceDirectory);
$report(
    $evidenceDirectoryReady ? 'PASS' : 'FAIL',
    $evidenceDirectoryReady
        ? 'The protected verification evidence directory is writable.'
        : 'The protected uploads/evidence directory is missing or not writable by the application runtime.'
);

$hasFileinfo = extension_loaded('fileinfo');
$report(
    $hasFileinfo ? 'PASS' : 'FAIL',
    $hasFileinfo ? 'The fileinfo extension is available for upload type validation.' : 'Enable PHP fileinfo to validate verification image uploads.'
);

if (!$hasMysqlDriver) {
    fwrite(STDOUT, "\nReadiness result: FAIL (database checks skipped because pdo_mysql is unavailable).\n");
    exit(1);
}

try {
    $pdo = Database::connection();
    $report('PASS', 'Database connection succeeded.');
} catch (Throwable $exception) {
    error_log('PSA-SAMS readiness database check failed: ' . $exception->getMessage());
    $report('FAIL', 'Database connection failed. Check service status and PSA_DB_* settings; details are in the server error log.');
    fwrite(STDOUT, "\nReadiness result: FAIL.\n");
    exit(1);
}

$requiredTables = [
    'asset_assignments',
    'asset_categories',
    'asset_transfers',
    'assets',
    'asset_verifications',
    'verification_evidence',
    'verification_campaign_departments',
    'verification_campaigns',
    'audit_logs',
    'departments',
    'login_attempts',
    'maintenance_records',
    'stock_transactions',
    'store_items',
    'store_requisitions',
    'users',
];
$tableQuery = $pdo->prepare(
    'SELECT TABLE_NAME, ENGINE
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()'
);
$tableQuery->execute();
$tableRows = $tableQuery->fetchAll();
$tableEngines = [];
foreach ($tableRows as $row) {
    $tableEngines[$row['TABLE_NAME']] = strtoupper((string) $row['ENGINE']);
}

$missingTables = array_values(array_diff($requiredTables, array_keys($tableEngines)));
$report(
    $missingTables === [] ? 'PASS' : 'FAIL',
    $missingTables === []
        ? 'All ' . count($requiredTables) . ' required application tables are present.'
        : 'Missing required tables: ' . implode(', ', $missingTables) . '. Import the reviewed schema before use.'
);

$nonInnoDbTables = [];
foreach (array_intersect($requiredTables, array_keys($tableEngines)) as $table) {
    if ($tableEngines[$table] !== 'INNODB') {
        $nonInnoDbTables[] = $table;
    }
}
$report(
    $nonInnoDbTables === [] ? 'PASS' : 'FAIL',
    $nonInnoDbTables === []
        ? 'All application tables use InnoDB.'
        : 'These tables are not InnoDB: ' . implode(', ', $nonInnoDbTables) . '. Transactions and row locking may not work correctly.'
);

$campaignColumnQuery = $pdo->prepare(
    'SELECT COUNT(*)
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = :table_name
       AND COLUMN_NAME = :column_name'
);
$campaignColumnQuery->execute([
    'table_name' => 'asset_verifications',
    'column_name' => 'campaign_id',
]);
$hasCampaignColumn = (int) $campaignColumnQuery->fetchColumn() === 1;
$report(
    $hasCampaignColumn ? 'PASS' : 'FAIL',
    $hasCampaignColumn
        ? 'Campaign-linked physical verification is available.'
        : 'asset_verifications.campaign_id is missing. Apply the verification campaign migration.'
);

$campaignUniqueIndexQuery = $pdo->prepare(
    'SELECT COUNT(*)
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = :table_name
       AND INDEX_NAME = :index_name
       AND NON_UNIQUE = 0'
);
$campaignUniqueIndexQuery->execute([
    'table_name' => 'asset_verifications',
    'index_name' => 'uq_asset_verifications_campaign_asset',
]);
$hasCampaignUniqueIndex = (int) $campaignUniqueIndexQuery->fetchColumn() === 2;
$report(
    $hasCampaignUniqueIndex ? 'PASS' : 'FAIL',
    $hasCampaignUniqueIndex
        ? 'Campaigns enforce one check per asset.'
        : 'The campaign-and-asset uniqueness constraint is missing. Apply the verification campaign migration.'
);

$activeAdminCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND status = 'Active'"
)->fetchColumn();
$report(
    $activeAdminCount > 0 ? 'PASS' : 'WARN',
    $activeAdminCount > 0
        ? 'An active super administrator exists.'
        : 'No active super administrator exists. Create one using bin\\create_admin.php before sign-in.'
);

$databaseUser = getenv('PSA_DB_USER') ?: 'root';
$isRootDatabaseUser = strcasecmp($databaseUser, 'root') === 0;
$report(
    $isRootDatabaseUser ? 'WARN' : 'PASS',
    $isRootDatabaseUser
        ? 'The runtime database user is root. This is acceptable only for local XAMPP development; use a least-privilege account on a managed server.'
        : 'A non-root runtime database user is configured.'
);

$appBaseUrl = getenv('PSA_APP_BASE_URL');
if ($appBaseUrl === false || $appBaseUrl === '') {
    $report('PASS', 'Default application path is configured for /PSA-SAMS.');
} else {
    $validBaseUrl = preg_match('#^/(?:[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*)?$#', $appBaseUrl) === 1
        && !str_contains($appBaseUrl, '..');
    $report(
        $validBaseUrl ? 'PASS' : 'FAIL',
        $validBaseUrl ? 'PSA_APP_BASE_URL is a valid local URL path.' : 'PSA_APP_BASE_URL must be a local application path.'
    );
}

fwrite(STDOUT, sprintf("\nReadiness result: %s (%d warning(s), %d failure(s)).\n", $failures > 0 ? 'FAIL' : 'PASS', $warnings, $failures));
if ($warnings > 0) {
    fwrite(STDOUT, "A PASS result is not production approval. Review deployment/README.md and complete Academy ICT and policy sign-off.\n");
}

exit($failures > 0 ? 1 : 0);
