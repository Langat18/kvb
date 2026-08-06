<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Staff;

Auth::startSession();
Auth::requireRole('admin');

$user       = Auth::user();
$staffModel = new Staff();

$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$staffList = $staffModel->list();

$roleLabels = ['admin' => 'Admin', 'staff' => 'Staff', 'ceo' => 'CEO'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Accounts — Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: 900px; margin: 0 auto; }
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; }
.page-header h1 { font-size: 1.3rem; font-weight: 700; color: var(--kvb-green-dark); }
.list-table { width: 100%; border-collapse: collapse; }
.list-table th { text-align: left; padding: .6rem .5rem; font-size: .78rem; color: var(--text-muted); border-bottom: 1px solid #eee; }
.list-table td { padding: .6rem .5rem; font-size: .87rem; border-bottom: 1px solid #f2f2f2; vertical-align: middle; }
.inactive-row { opacity: .55; }
.role-select { padding: .3rem .5rem; font-size: .82rem; border-radius: 6px; border: 1px solid #d8dde3; }
.action-btn { padding: .3rem .7rem; font-size: .78rem; border-radius: 6px; border: none; cursor: pointer; }
</style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <h1>Staff Accounts</h1>
        <a href="/staff/create.php" class="btn btn-primary">+ New Staff</a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card" style="padding:0;">
        <table class="list-table">
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
                            <?= htmlspecialchars($roleLabels[$s['role']] ?? $s['role'], ENT_QUOTES, 'UTF-8') ?>
                            <span style="color:var(--text-muted);font-size:.75rem;">(you)</span>
                        <?php else: ?>
                            <form method="POST" action="/staff/update_role.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                <select name="role" class="role-select" onchange="this.form.submit()">
                                    <?php foreach ($roleLabels as $key => $label): ?>
                                        <option value="<?= $key ?>" <?= $s['role'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td><?= $s['last_login_at'] ? date('d M Y H:i', strtotime($s['last_login_at'])) : 'Never' ?></td>
                    <td><?= $s['is_active'] ? 'Active' : 'Deactivated' ?></td>
                    <td>
                        <?php if ((int) $s['id'] !== (int) $user['id']): ?>
                            <form method="POST" action="/staff/toggle_active.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                <input type="hidden" name="active" value="<?= $s['is_active'] ? '0' : '1' ?>">
                                <button type="submit" class="action-btn" style="background:<?= $s['is_active'] ? 'var(--kvb-red-light)' : 'var(--kvb-green-light)' ?>;color:<?= $s['is_active'] ? 'var(--kvb-red-dark)' : 'var(--kvb-green-dark)' ?>;">
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

    <div style="margin-top:1.5rem;">
        <a href="/index.php" class="btn btn-secondary">&larr; Back to Dashboard</a>
    </div>
</div>
</body>
</html>
