<?php

declare(strict_types=1);

use VetBoard\Auth;


$sidebarActionGroups = (function (): array {
    $actionGroups = [
        'Events' => [
            ['perm' => 'create_events', 'url' => '/events/create.php', 'icon' => '&#128197;', 'label' => 'New Event',  'desc' => 'On-site, virtual, or hybrid'],
            ['perm' => 'view_events',   'url' => '/events/index.php',  'icon' => '&#128203;', 'label' => 'All Events', 'desc' => 'Browse and manage'],
        ],
        'Insights' => [
            ['perm' => 'view_leaderboard', 'url' => '/points/leaderboard.php', 'icon' => '&#127942;', 'label' => 'Leaderboard', 'desc' => 'Points across participants'],
            ['perm' => 'view_reports',     'url' => '/reports/dashboard.php',  'icon' => '&#128202;', 'label' => 'Reports',     'desc' => 'Trends and breakdowns'],
        ],
        'People & Access' => [
            ['perm' => 'manage_staff', 'url' => '/staff/index.php', 'icon' => '&#128100;', 'label' => 'Manage Staff',        'desc' => 'Accounts and roles'],
            ['perm' => 'manage_roles', 'url' => '/roles/index.php', 'icon' => '&#128272;', 'label' => 'Roles & Permissions', 'desc' => 'Access control'],
        ],
        'Configuration' => [
            ['perm' => 'manage_departments', 'url' => '/departments/index.php', 'icon' => '&#127970;', 'label' => 'Departments',    'desc' => 'Manage department list'],
            ['perm' => 'manage_providers',   'url' => '/providers/index.php',   'icon' => '&#127973;', 'label' => 'Providers',      'desc' => 'CPD providers & accreditation'],
            ['perm' => 'manage_lookups',     'url' => '/lookups/index.php',     'icon' => '&#128203;', 'label' => 'Event Settings', 'desc' => 'Modes, cadres, session windows'],
            ['perm' => 'manage_lookups', 'url' => '/settings/signin.php', 'icon' => '&#9201;', 'label' => 'Sign-in Defaults', 'desc' => 'Applied when a session sets none'],
        ],
    ];

    $groups = [];
    foreach ($actionGroups as $groupLabel => $groupActions) {
        $groups[$groupLabel] = array_values(array_filter($groupActions, fn($a) => Auth::can($a['perm'])));
    }

    return $groups;
})();

$useAppSidebar = true;
