<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/auth_check.php';

$roleNavigation = [
    'assets' => [
        'label' => 'Assets',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer'],
    ],
    'stores' => [
        'label' => 'Stores & inventory',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer'],
    ],
    'allocation' => [
        'label' => 'Allocations & transfers',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer', 'approving_officer'],
    ],
    'maintenance' => [
        'label' => 'Maintenance',
        'roles' => ['super_admin', 'admin_officer', 'stores_officer'],
    ],
    'reports' => [
        'label' => 'Reports & audit',
        'roles' => ['super_admin', 'admin_officer', 'approving_officer', 'management'],
    ],
    'users' => [
        'label' => 'User administration',
        'roles' => ['super_admin'],
    ],
];
?>
<aside class="sidebar" id="app-sidebar">
    <div class="sidebar-section-label">Workspace</div>
    <nav aria-label="Main navigation">
        <a class="nav-link" href="<?= APP_BASE_URL ?>/modules/dashboard/index.php">Overview</a>
        <?php foreach ($roleNavigation as $module => $item): ?>
            <?php if (hasRole($item['roles'])): ?>
                <a class="nav-link" href="<?= APP_BASE_URL ?>/modules/<?= escapeHtml($module) ?>/index.php">
                    <?= escapeHtml($item['label']) ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-note">
        <span class="sidebar-section-label">Signed in as</span>
        <strong><?= escapeHtml(str_replace('_', ' ', $_SESSION['user']['role'])) ?></strong>
    </div>
</aside>
