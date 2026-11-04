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
        <div class="topbar-user">
            <span class="user-name"><?= escapeHtml($_SESSION['user']['full_name']) ?></span>
            <a class="button button-quiet notification-link" href="<?= APP_BASE_URL ?>/modules/notifications/index.php"
               aria-label="Notifications<?= $headerNotifications === [] ? '' : ', ' . count($headerNotifications) . ' active' ?>">
                <span class="notification-label">Notifications</span><?php if ($headerNotifications !== []): ?>
                    <span class="notification-count"><?= count($headerNotifications) > 99 ? '99+' : count($headerNotifications) ?></span>
                <?php endif; ?>
            </a>
            <a class="button button-quiet" href="<?= APP_BASE_URL ?>/modules/account/index.php">My account</a>
            <form method="post" action="<?= APP_BASE_URL ?>/auth/logout.php">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                <button class="button button-quiet" type="submit">Sign out</button>
            </form>
        </div>
    </header>
