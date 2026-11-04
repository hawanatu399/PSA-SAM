<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/auth_check.php';

$roleNavigation = [
    'assets' => [
        'label' => 'Assets',
        'icon' => 'M4 7 12 3l8 4v10l-8 4-8-4V7Zm0 0 8 4 8-4M12 11v10',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer'],
    ],
    'stores' => [
        'label' => 'Stores & inventory',
        'icon' => 'M4 6 12 3l8 3-8 3-8-3Zm0 6 8 3 8-3M4 18l8 3 8-3M4 6v12m16-12v12m-8-9v12',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer'],
    ],
    'requisitions' => [
        'label' => 'Store requisitions',
        'icon' => 'M8 4h8l2 2h3v15H3V6h3l2-2Zm0 0v3h8V4M7 12h10M7 16h10',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer', 'approving_officer'],
    ],
    'verification' => [
        'label' => 'Physical verification',
        'icon' => 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Zm-4-10 3 3 6-6',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer'],
    ],
    'allocation' => [
        'label' => 'Allocations & transfers',
        'icon' => 'M4 7h15l-3-3m3 3-3 3M20 17H5l3 3m-3-3 3-3',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer', 'approving_officer'],
    ],
    'maintenance' => [
        'label' => 'Maintenance',
        'icon' => 'M14 6a5 5 0 0 0-6 6l-5 5a2 2 0 0 0 3 3l5-5a5 5 0 0 0 6-6l-3 3-3-3 3-3Z',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer'],
    ],
    'reports' => [
        'label' => 'Reports & audit',
        'icon' => 'M4 20V4m0 16h17M8 16v-5m5 5V7m5 9V3',
        'roles' => ['super_admin', 'admin_officer', 'approving_officer', 'management'],
    ],
    'users' => [
        'label' => 'User administration',
        'icon' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m7-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8-7a4 4 0 0 1 0 8m5 9v-2a4 4 0 0 0-3-3.87',
        'roles' => ['super_admin'],
    ],
    'settings' => [
        'label' => 'Reference data',
        'icon' => 'M4 6h16M4 12h16M4 18h16M8 4v4m8 2v4m-5 2v4',
        'roles' => ['super_admin'],
    ],
];
?>
<aside class="sidebar" id="app-sidebar">
    <div class="sidebar-section-label">Workspace</div>
    <nav aria-label="Main navigation">
        <a class="nav-link<?= str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/modules/dashboard/') ? ' is-active' : '' ?>"
           href="<?= APP_BASE_URL ?>/modules/dashboard/index.php">
            <svg class="nav-link-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" focusable="false">
                <path d="M3 3h8v8H3zM13 3h8v5h-8zM13 10h8v11h-8zM3 13h8v8H3z" stroke="currentColor" stroke-linejoin="round" stroke-width="1.7"/>
            </svg>
            <span>Overview</span>
        </a>
        <?php foreach ($roleNavigation as $module => $item): ?>
            <?php if (hasRole($item['roles'])): ?>
                <a class="nav-link<?= str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/modules/' . $module . '/') ? ' is-active' : '' ?>"
                   href="<?= APP_BASE_URL ?>/modules/<?= escapeHtml($module) ?>/index.php">
                    <svg class="nav-link-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" focusable="false">
                        <path d="<?= escapeHtml($item['icon']) ?>" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"/>
                    </svg>
                    <span><?= escapeHtml($item['label']) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-note">
        <span class="sidebar-section-label">Signed in as</span>
        <strong><?= escapeHtml(str_replace('_', ' ', $_SESSION['user']['role'])) ?></strong>
    </div>
</aside>
