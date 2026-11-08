<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Role;
use VetBoard\Staff;

Auth::startSession();
Auth::requirePermission('manage_staff');

$breadcrumbs = [['label' => 'Dashboard', 'url' => '/index.php'], ['label' => 'Staff', 'url' => null]];

$user       = Auth::user();
$staffModel = new Staff();
$roleModel  = new Role();

$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$perPage     = 20;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$totalStaff  = $staffModel->count();
$totalPages  = max(1, (int) ceil($totalStaff / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset      = ($currentPage - 1) * $perPage;

$staffList = $staffModel->list([], $perPage, $offset);
$roles     = $roleModel->list(true);

require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Accounts</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.role-select { padding: .3rem .5rem; font-size: .82rem; border-radius: 6px; border: 1px solid #d8dde3; }
</style>
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="app-page-hero">
        <div>
            <h1>Staff Accounts</h1>
            <div class="sub">Manage accounts, roles, and access</div>
        </div>
        <a href="/staff/create.php" class="btn btn-primary">+ New Staff</a>
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
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Last Login</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staffList as $s): ?>
                <tr class="<?= $s['is_active'] ? '' : 'inactive-row' ?>">
                    <td><?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($s['email'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ((int) $s['id'] === (int) $user['id']): ?>
                            <?= htmlspecialchars($s['role_label'], ENT_QUOTES, 'UTF-8') ?>
                            <span style="color:var(--text-muted);font-size:.75rem;">(you)</span>
                        <?php else: ?>
                            <form method="POST" action="/staff/update_role.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                <select name="role_id" class="role-select" onchange="this.form.submit()">
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= (int) $role['id'] ?>" <?= (int) $s['role_id'] === (int) $role['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($role['label'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td><?= $s['last_login_at'] ? date('d M Y H:i', strtotime($s['last_login_at'])) : 'Never' ?></td>
                    <td><?= $s['is_active'] ? 'Active' : 'Deactivated' ?></td>
                    <td>
                        <?php if ((int) $s['id'] !== (int) $user['id']): ?>
                            <a href="/staff/reset_password.php?id=<?= (int) $s['id'] ?>" class="btn btn-ghost btn-sm">Reset Password</a>
                            <form method="POST" action="/staff/toggle_active.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                <input type="hidden" name="active" value="<?= $s['is_active'] ? '0' : '1' ?>">
                                <button type="submit" class="btn btn-sm <?= $s['is_active'] ? 'btn-danger' : 'btn-success' ?>">
                                    <?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <span>Page <?= $currentPage ?> of <?= $totalPages ?> (<?= $totalStaff ?> staff)</span>
                <div class="pagination-links">
                    <a href="?page=<?= $currentPage - 1 ?>" class="<?= $currentPage <= 1 ? 'disabled' : '' ?>">Previous</a>
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <?php if ($p === $currentPage): ?>
                            <span class="current"><?= $p ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $p ?>"><?= $p ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <a href="?page=<?= $currentPage + 1 ?>" class="<?= $currentPage >= $totalPages ? 'disabled' : '' ?>">Next</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
