<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Provider;

Auth::startSession();
Auth::requirePermission('manage_providers');
$user = Auth::user();

$breadcrumbs = [['label' => 'Dashboard', 'url' => '/index.php'], ['label' => 'Providers', 'url' => null]];

$providerModel = new Provider();

$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$providers = $providerModel->list();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Providers - Kenya Veterinary Board</title>
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
        <h1>Providers</h1>
        <div class="action-row">
            <a href="/providers/import.php" class="btn btn-ghost">Import CSV</a>
            <a href="/providers/create.php" class="btn btn-primary">+ New Provider</a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card card-flush">
        <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Accreditation No.</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($providers as $p): ?>
                <tr class="<?= $p['active'] ? '' : 'inactive-row' ?>">
                    <td><code><?= htmlspecialchars($p['accreditation_no'], ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $p['active'] ? 'Active' : 'Deactivated' ?></td>
                    <td>
                        <a href="/providers/edit.php?id=<?= (int) $p['id'] ?>" class="btn btn-ghost btn-sm">Rename</a>
                        <form method="POST" action="/providers/toggle_active.php" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="active" value="<?= $p['active'] ? '0' : '1' ?>">
                            <button type="submit" class="btn btn-sm <?= $p['active'] ? 'btn-danger' : 'btn-success' ?>">
                                <?= $p['active'] ? 'Deactivate' : 'Reactivate' ?>
                            </button>
                        </form>
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
