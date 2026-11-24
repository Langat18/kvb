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
<div class="form-page wide">
    <div class="app-page-hero"><div><h1>Edit Role: <?= htmlspecialchars($role['label'], ENT_QUOTES, 'UTF-8') ?></h1></div></div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="/roles/edit.php?id=<?= (int) $role['id'] ?>" id="role-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="id" value="<?= (int) $role['id'] ?>">

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
                <label for="sort_order">Display Order</label>
                <input type="number" id="sort_order" name="sort_order" min="0" value="<?= (int) $role['sort_order'] ?>">
            </div>

            <div class="form-group">
                <label>Permissions</label>

                <?php foreach ($permissionGroups as $groupLabel => $group): ?>
                    <?php
                        $groupPerms = array_filter($group['keys'], fn($k) => isset($permissionsByKey[$k]));
                        if (empty($groupPerms)) continue;
                    ?>
                    <div class="perm-group">
                        <div class="perm-group-header">
                            <?php if ($group['selectAll'] && !$role['is_system']): ?>
                                <input type="checkbox" class="group-toggle" data-group="<?= htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') ?>">
                            <?php endif; ?>
                            <span><?= htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="perm-list">
                            <?php foreach ($groupPerms as $key): ?>
                                <?php $perm = $permissionsByKey[$key]; ?>
                                <label class="perm-item">
                                    <input type="checkbox" name="permissions[]" value="<?= (int) $perm['id'] ?>"
                                           class="perm-checkbox" data-group="<?= htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') ?>"
                                           <?= in_array($perm['item_key'], $grantedKeys, true) ? 'checked' : '' ?>
                                           <?= $role['is_system'] ? 'disabled' : '' ?>>
                                    <span>
                                        <div class="perm-label"><?= htmlspecialchars($perm['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (!empty($perm['description'])): ?>
                                            <div class="perm-desc"><?= htmlspecialchars($perm['description'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if ($role['is_system']): ?>
                    <small style="color:var(--text-muted);">This is a system role and always has every permission.</small>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
        </form>
    </div>

    <div style="margin-top:1rem;">
        <a href="/roles/index.php" class="btn btn-secondary">Back to Roles</a>
    </div>
</div>
</div>

<script>
document.querySelectorAll('.group-toggle').forEach(function (toggle) {
    const group = toggle.dataset.group;
    const checkboxes = document.querySelectorAll('.perm-checkbox[data-group="' + group + '"]');

    function syncToggleState() {
        const total   = checkboxes.length;
        const checked = Array.from(checkboxes).filter(cb => cb.checked).length;
        toggle.checked       = total > 0 && checked === total;
        toggle.indeterminate = checked > 0 && checked < total;
    }

    toggle.addEventListener('change', function () {
        checkboxes.forEach(cb => { cb.checked = toggle.checked; });
    });

    checkboxes.forEach(cb => cb.addEventListener('change', syncToggleState));
    syncToggleState();
});
</script>
</body>
</html>
