<?php

declare(strict_types=1);

require_once __DIR__ . '/icons.php';

use VetBoard\Auth;


$sidebarActionGroups = (function (): array {
    $actionGroups = [
        'Events' => [
            ['perm' => 'create_events', 'url' => '/events/create.php', 'icon' => ui_icon('calendar', 18), 'label' => 'New Event',  'desc' => 'On-site, virtual, or hybrid'],
            ['perm' => 'view_events',   'url' => '/events/index.php',  'icon' => ui_icon('clipboard', 18), 'label' => 'All Events', 'desc' => 'Browse and manage'],
            ['perm' => 'manage_events', 'url' => '/pms/index.php', 'icon' => ui_icon('globe', 18), 'label' => 'PMS Activities', 'desc' => 'KVB-approved CPD activities'],
        ],
        'Insights' => [
            ['perm' => 'view_leaderboard', 'url' => '/points/leaderboard.php', 'icon' => ui_icon('trophy', 18), 'label' => 'Leaderboard', 'desc' => 'Points across participants'],
            ['perm' => 'view_reports',     'url' => '/reports/dashboard.php',  'icon' => ui_icon('chart', 18), 'label' => 'Reports',     'desc' => 'Trends and breakdowns'],
        ],
        'People & Access' => [
            ['perm' => 'manage_staff', 'url' => '/staff/index.php', 'icon' => ui_icon('user', 18), 'label' => 'Manage Staff',        'desc' => 'Accounts and roles'],
            ['perm' => 'manage_roles', 'url' => '/roles/index.php', 'icon' => ui_icon('lock', 18), 'label' => 'Roles & Permissions', 'desc' => 'Access control'],
        ],
        'Configuration' => [
            ['perm' => 'manage_departments', 'url' => '/departments/index.php', 'icon' => ui_icon('building', 18), 'label' => 'Departments',    'desc' => 'Manage department list'],
            ['perm' => 'manage_providers',   'url' => '/providers/index.php',   'icon' => ui_icon('org', 18), 'label' => 'Providers',      'desc' => 'CPD providers & accreditation'],
            ['perm' => 'manage_lookups',     'url' => '/lookups/index.php',     'icon' => ui_icon('sliders', 18), 'label' => 'Event Settings', 'desc' => 'Modes, cadres, session windows'],
            ['perm' => 'manage_lookups', 'url' => '/settings/signin.php', 'icon' => ui_icon('clock', 18), 'label' => 'Sign-in Defaults', 'desc' => 'Applied when a session sets none'],
        ],
    ];

    $groups = [];
    foreach ($actionGroups as $groupLabel => $groupActions) {
        $groups[$groupLabel] = array_values(array_filter($groupActions, fn($a) => Auth::can($a['perm'])));
    }

    return $groups;
})();

$useAppSidebar = true;
