<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/notifications.php';

if (!isLoggedIn()) {
    header('Location: ' . APP_BASE_URL . '/auth/login.php');
    exit;
}

$pageTitle = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'Dashboard';
$headerNotifications = currentNotifications(Database::connection());
$printDate = date('Y-m-d H:i T');
$profileName = trim((string) ($_SESSION['user']['full_name'] ?? 'Account'));
$profileWords = preg_split('/\s+/', $profileName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$profileInitials = strtoupper(
    substr($profileWords[0] ?? 'A', 0, 1)
    . (count($profileWords) > 1 ? substr($profileWords[count($profileWords) - 1], 0, 1) : '')
);
$profileRole = ucwords(str_replace('_', ' ', (string) ($_SESSION['user']['role'] ?? 'user')));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="PSA Stores and Asset Management System">
    <title><?= escapeHtml($pageTitle) ?> | PSA-SAMS</title>
    <link rel="stylesheet" href="<?= APP_BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <button class="menu-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false"
                aria-controls="app-sidebar">☰</button>
        <a class="topbar-brand" href="<?= APP_BASE_URL ?>/modules/dashboard/index.php" aria-label="Public Service Academy Stores and Asset Management System dashboard">
            <img src="<?= APP_BASE_URL ?>/assets/images/psa-logo.jpg" alt="" width="42" height="42">
            <span>PSA-SAMS</span>
        </a>
        <?php if (hasRole(['super_admin', 'admin_officer', 'stores_officer'])): ?>
            <form class="global-search" method="get" action="<?= APP_BASE_URL ?>/modules/assets/index.php" role="search">
                <label class="sr-only" for="global-asset-search">Search assets</label>
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" focusable="false">
                    <circle cx="10.8" cy="10.8" r="6.8" stroke="currentColor" stroke-width="1.8"/>
                    <path d="m16 16 4.2 4.2" stroke="currentColor" stroke-linecap="round" stroke-width="1.8"/>
                </svg>
                <input id="global-asset-search" type="search" name="search" maxlength="180"
                       placeholder="Search assets by tag, name or serial">
                <button type="submit">Search</button>
            </form>
        <?php endif; ?>
        <div class="topbar-user">
            <a class="user-profile" href="<?= APP_BASE_URL ?>/modules/account/index.php"
               aria-label="My account: <?= escapeHtml($profileName) ?>, <?= escapeHtml($profileRole) ?>">
                <span class="user-avatar" aria-hidden="true"><?= escapeHtml($profileInitials) ?></span>
                <span class="user-profile-details">
                    <span class="user-name"><?= escapeHtml($profileName) ?></span>
                    <span class="user-role"><?= escapeHtml($profileRole) ?></span>
                </span>
            </a>
            <a class="button button-quiet notification-link" href="<?= APP_BASE_URL ?>/modules/notifications/index.php"
               aria-label="Notifications<?= $headerNotifications === [] ? '' : ', ' . count($headerNotifications) . ' active' ?>">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" focusable="false">
                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"/>
                    <path d="M10 21h4" stroke="currentColor" stroke-linecap="round" stroke-width="1.8"/>
                </svg><?php if ($headerNotifications !== []): ?>
                    <span class="notification-count"><?= count($headerNotifications) > 99 ? '99+' : count($headerNotifications) ?></span>
                <?php endif; ?>
            </a>
            <form method="post" action="<?= APP_BASE_URL ?>/auth/logout.php">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                <button class="button button-quiet" type="submit">Sign out</button>
            </form>
        </div>
    </header>
