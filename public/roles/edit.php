<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Permission;
use VetBoard\Role;

Auth::startSession();
Auth::requirePermission('manage_roles');

$roleModel       = new Role();
$permissionModel = new Permission();

$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$role = $roleModel->findById($id);

if (!$role) {
    $_SESSION['error'] = 'Role not found.';
    header('Location: /roles/index.php');
    exit;
}

$allPermissions = $permissionModel->list();
$grantedKeys     = $roleModel->getPermissionKeys($id);


$permissionGroups = [
    'Events' => [
        'keys'      => ['create_events', 'view_events', 'manage_events', 'review_flagged_attendance', 'record_manual_attendance', 'print_attendance_register', 'edit_attendee_details'],
        'selectAll' => true,
    ],
    'Configuration' => [
        'keys'      => ['manage_departments', 'manage_lookups'],
        'selectAll' => true,
    ],
    'PMS points' => [
        'keys'      => ['send_pms_points'],
        'selectAll' => false,
    ],
    'Reporting' => [
        'keys'      => ['view_reports', 'view_leaderboard'],
        'selectAll' => true,
    ],
    'People' => [
        'keys'      => ['manage_staff', 'manage_roles'],
        'selectAll' => false,
    ],
];

$permissionsByKey = [];
foreach ($allPermissions as $perm) {
    $permissionsByKey[$perm['item_key']] = $perm;
}

// Any permission not covered by an explicit group above still renders,
// under a catch-all section, so nothing can silently go missing from
// the UI if a new permission is added before this grouping is updated.
$groupedKeys = [];
foreach ($permissionGroups as $group) {
    foreach ($group['keys'] as $k) {
        $groupedKeys[] = $k;
    }
}
$ungrouped = array_diff(array_keys($permissionsByKey), $groupedKeys);
if (!empty($ungrouped)) {
    $permissionGroups['Other'] = ['keys' => array_values($ungrouped), 'selectAll' => false];
}

$error = $_SESSION['error'] ?? null;
unset($_SESSION['error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    try {
        $roleModel->update($id, [
            'label'      => $_POST['label'] ?? '',
            'sort_order' => $_POST['sort_order'] ?? 0,
        ]);

        if (!$role['is_system']) {
            $permissionIds = array_map('intval', $_POST['permissions'] ?? []);
            $roleModel->setPermissions($id, $permissionIds);
        }

        $_SESSION['success'] = 'Role updated successfully.';
        header('Location: /roles/index.php');
        exit;

    } catch (\InvalidArgumentException $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: /roles/edit.php?id=' . $id);
        exit;
    }
}
$user = Auth::user();
$breadcrumbs = [['label' => 'Dashboard', 'url' => '/index.php'], ['label' => 'Roles', 'url' => '/roles/index.php'], ['label' => 'Edit Role', 'url' => null]];
require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Roles</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/style.css') ?>">
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
<div class="form-page roles-form">
    <div class="app-page-hero">
        <div>
            <h1>Edit role</h1>
            <p class="sub"><?= htmlspecialchars($role['label'], ENT_QUOTES, 'UTF-8') ?><?php if ($role['is_system']): ?> <span class="pill-note">System role</span><?php endif; ?></p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="POST" action="/roles/edit.php?id=<?= (int) $role['id'] ?>" id="role-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="id" value="<?= (int) $role['id'] ?>">

        <section class="role-card">
            <h2 class="role-card-title">Role details</h2>
            <div class="role-details">
                <div class="form-group">
                    <label>Key</label>
                    <input type="text" value="<?= htmlspecialchars($role['item_key'], ENT_QUOTES, 'UTF-8') ?>" disabled>
                </div>
                <div class="form-group">
                    <label for="label">Label <span class="required">*</span></label>
                    <input type="text" id="label" name="label" required
                           value="<?= htmlspecialchars($role['label'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="sort_order">Display order</label>
                    <input type="number" id="sort_order" name="sort_order" min="0" value="<?= (int) $role['sort_order'] ?>">
                </div>
            </div>
        </section>

        <section class="role-card">
            <div class="role-card-head">
                <div>
                    <h2 class="role-card-title">Permissions</h2>
                    <p class="role-card-sub">Choose what people with this role can do.</p>
                </div>
                <?php if (!$role['is_system']): ?>
                    <div class="perm-bulk">
                        <button type="button" class="btn btn-secondary btn-sm" id="perm-all">Select all</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="perm-none">Clear</button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($role['is_system']): ?>
                <div class="alert alert-info">This is a system role and always has every permission, so these cannot be changed.</div>
            <?php endif; ?>

            <?php foreach ($permissionGroups as $groupLabel => $group): ?>
                <?php
                    $groupPerms = array_filter($group['keys'], fn($k) => isset($permissionsByKey[$k]));
                    if (empty($groupPerms)) continue;
                    $g = htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8');
                ?>
                <div class="perm-group">
                    <div class="perm-group-header">
                        <span class="perm-group-name"><?= $g ?></span>
                        <span class="perm-count" data-count-for="<?= $g ?>"></span>
                        <?php if ($group['selectAll'] && !$role['is_system']): ?>
                            <label class="perm-group-all"><input type="checkbox" class="group-toggle" data-group="<?= $g ?>"> All</label>
                        <?php endif; ?>
                    </div>
                    <div class="perm-list">
                        <?php foreach ($groupPerms as $key): ?>
                            <?php $perm = $permissionsByKey[$key]; ?>
                            <label class="perm-item">
                                <input type="checkbox" name="permissions[]" value="<?= (int) $perm['id'] ?>"
                                       class="perm-checkbox" data-group="<?= $g ?>"
                                       <?= in_array($perm['item_key'], $grantedKeys, true) ? 'checked' : '' ?>
                                       <?= $role['is_system'] ? 'disabled' : '' ?>>
                                <span class="perm-text">
                                    <span class="perm-label"><?= htmlspecialchars($perm['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (!empty($perm['description'])): ?>
                                        <span class="perm-desc"><?= htmlspecialchars($perm['description'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <div class="form-actionbar">
            <span class="form-actionbar-note" id="perm-summary"></span>
            <a href="/roles/index.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
    </form>
</div>
</div>

<script>
(function () {
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.perm-checkbox'));
    var summary = document.getElementById('perm-summary');

    function refresh() {
        var on = boxes.filter(function (b) { return b.checked; }).length;
        if (summary) summary.textContent = on + ' of ' + boxes.length + ' permissions selected';
        document.querySelectorAll('.group-toggle').forEach(function (t) {
            var g = boxes.filter(function (b) { return b.dataset.group === t.dataset.group; });
            var n = g.filter(function (b) { return b.checked; }).length;
            t.checked = g.length > 0 && n === g.length;
            t.indeterminate = n > 0 && n < g.length;
        });
        document.querySelectorAll('.perm-count').forEach(function (c) {
            var g = boxes.filter(function (b) { return b.dataset.group === c.dataset.countFor; });
            c.textContent = g.filter(function (b) { return b.checked; }).length + ' of ' + g.length;
        });
    }

    function setAll(state) { boxes.forEach(function (b) { if (!b.disabled) b.checked = state; }); refresh(); }

    document.querySelectorAll('.group-toggle').forEach(function (t) {
        t.addEventListener('change', function () {
            boxes.forEach(function (b) { if (b.dataset.group === t.dataset.group && !b.disabled) b.checked = t.checked; });
            refresh();
        });
    });
    boxes.forEach(function (b) { b.addEventListener('change', refresh); });
    var all = document.getElementById('perm-all'), none = document.getElementById('perm-none');
    if (all) all.addEventListener('click', function () { setAll(true); });
    if (none) none.addEventListener('click', function () { setAll(false); });
    refresh();
})();
</script>
</body>
</html>
