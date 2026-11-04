<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/auth/auth_check.php';

$destination = isLoggedIn()
    ? APP_BASE_URL . '/modules/dashboard/index.php'
    : APP_BASE_URL . '/auth/login.php';

header('Location: ' . $destination);
exit;
