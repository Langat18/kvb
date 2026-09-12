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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Roles</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: 560px; margin: 0 auto; }
.container h1 { font-size: 1.2rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: 1rem; }
.form-group { margin-bottom: 1rem; }
.perm-list { display: flex; flex-direction: column; gap: .6rem; margin: .5rem 0 1.25rem; }
.perm-item { display: flex; align-items: flex-start; gap: .6rem; padding: .6rem .75rem; border: 1px solid #eee; border-radius: 10px; }
.perm-item input { width: auto; margin-top: .2rem; }
.perm-item .perm-label { font-weight: 600; font-size: .87rem; }
.perm-item .perm-desc { font-size: .78rem; color: var(--text-muted); margin-top: .1rem; }
</style>
</head>
<body>
<div class="container">
    <h1>Edit Role: <?= htmlspecialchars($role['label'], ENT_QUOTES, 'UTF-8') ?></h1>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="/roles/edit.php?id=<?= (int) $role['id'] ?>">
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
                <div class="perm-list">
                    <?php foreach ($allPermissions as $perm): ?>
                        <label class="perm-item">
                            <input type="checkbox" name="permissions[]" value="<?= (int) $perm['id'] ?>"
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
                <?php if ($role['is_system']): ?>
                    <small style="color:var(--text-muted);">This is a system role and always has every permission.</small>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;">Save Changes</button>
        </form>
    </div>

    <div style="margin-top:1rem;">
        <a href="/roles/index.php" class="btn btn-secondary">&larr; Back to Roles</a>
    </div>
</div>
</body>
</html>
