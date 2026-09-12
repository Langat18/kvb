<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Department;

Auth::startSession();
Auth::requirePermission('manage_departments');
$user = Auth::user();

$breadcrumbs = [['label' => 'Dashboard', 'url' => '/index.php'], ['label' => 'Departments', 'url' => null]];

$deptModel = new Department();

$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$departments = $deptModel->list();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Departments</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: 900px; margin: 0 auto; }
</style>
</head>
<body>
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="container">
    <div class="page-header">
        <h1>Departments</h1>
        <a href="/departments/create.php" class="btn btn-primary">+ New Department</a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card card-flush">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($departments as $d): ?>
                <tr class="<?= $d['active'] ? '' : 'inactive-row' ?>">
                    <td><?= htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $d['active'] ? 'Active' : 'Deactivated' ?></td>
                    <td>
                        <a href="/departments/edit.php?id=<?= (int) $d['id'] ?>" class="btn btn-ghost btn-sm">Rename</a>
                        <form method="POST" action="/departments/toggle_active.php" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                            <input type="hidden" name="active" value="<?= $d['active'] ? '0' : '1' ?>">
                            <button type="submit" class="btn btn-sm <?= $d['active'] ? 'btn-danger' : 'btn-success' ?>">
                                <?= $d['active'] ? 'Deactivate' : 'Reactivate' ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
