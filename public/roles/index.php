<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Role;

Auth::startSession();
Auth::requirePermission('manage_roles');
$user = Auth::user();

$breadcrumbs = [['label' => 'Dashboard', 'url' => '/index.php'], ['label' => 'Roles', 'url' => null]];

$roleModel = new Role();

$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$roles = $roleModel->list();

require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Roles</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/style.css') ?>">
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="app-page-hero">
        <div>
            <h1>Roles</h1>
            <div class="sub">Manage roles and permission assignments</div>
        </div>
        <a href="/roles/create.php" class="btn btn-primary">+ New Role</a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="app-card card-flush">
        <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Key</th>
                    <th>Label</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $role): ?>
                <tr class="<?= $role['active'] ? '' : 'inactive-row' ?>">
                    <td><code><?= htmlspecialchars($role['item_key'], ENT_QUOTES, 'UTF-8') ?></code><?= $role['is_system'] ? ' <span class="pill" style="font-size:.68rem;">system</span>' : '' ?></td>
                    <td><?= htmlspecialchars($role['label'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $role['active'] ? 'Active' : 'Deactivated' ?></td>
                    <td>
                        <a href="/roles/edit.php?id=<?= (int) $role['id'] ?>" class="btn btn-ghost btn-sm">Edit &amp; Permissions</a>
                        <?php if (!$role['is_system']): ?>
                            <form method="POST" action="/roles/toggle_active.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="id" value="<?= (int) $role['id'] ?>">
                                <input type="hidden" name="active" value="<?= $role['active'] ? '0' : '1' ?>">
                                <button type="submit" class="btn btn-sm <?= $role['active'] ? 'btn-danger' : 'btn-success' ?>">
                                    <?= $role['active'] ? 'Deactivate' : 'Reactivate' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
</body>
</html>
