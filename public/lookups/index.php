<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\CadreRepository;
use VetBoard\EventModeRepository;

const LOOKUP_TYPES = [
    'event-modes' => ['label' => 'Event Modes',     'repository' => EventModeRepository::class,    'has_time' => false],
    'cadres'      => ['label' => 'Cadres',           'repository' => CadreRepository::class,        'has_time' => false],
];

Auth::startSession();
Auth::requirePermission('manage_lookups');
$user = Auth::user();

$type = $_GET['type'] ?? 'event-modes';

if (!array_key_exists($type, LOOKUP_TYPES)) {
    http_response_code(404);
    die('Unknown lookup type.');
}

$config     = LOOKUP_TYPES[$type];
$repoClass  = $config['repository'];
$repo       = new $repoClass();

$breadcrumbs = [['label' => 'Dashboard', 'url' => '/index.php'], ['label' => $config['label'], 'url' => null]];

$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$items = $repo->list();

require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($config['label'], ENT_QUOTES, 'UTF-8') ?> - Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/style.css') ?>">
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="tabs">
        <?php foreach (LOOKUP_TYPES as $key => $cfg): ?>
            <a href="/lookups/<?= urlencode($key) ?>" class="<?= $key === $type ? 'active' : '' ?>">
                <?= htmlspecialchars($cfg['label'], ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="app-page-hero">
        <div>
            <h1><?= htmlspecialchars($config['label'], ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="sub">Manage event settings and lookup values</div>
        </div>
        <a href="/lookups/create.php?type=<?= urlencode($type) ?>" class="btn btn-primary">+ New</a>
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
                    <?php if ($config['has_time']): ?>
                        <th>Start</th>
                        <th>End</th>
                    <?php endif; ?>
                    <th>Order</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr class="<?= $item['active'] ? '' : 'inactive-row' ?>">
                    <td><code><?= htmlspecialchars($item['item_key'], ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></td>
                    <?php if ($config['has_time']): ?>
                        <td><?= htmlspecialchars(substr($item['start_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(substr($item['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                    <?php endif; ?>
                    <td><?= (int) $item['sort_order'] ?></td>
                    <td><?= $item['active'] ? 'Active' : 'Deactivated' ?></td>
                    <td>
                        <a href="/lookups/edit.php?type=<?= urlencode($type) ?>&id=<?= (int) $item['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
                        <form method="POST" action="/lookups/toggle_active.php" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <input type="hidden" name="active" value="<?= $item['active'] ? '0' : '1' ?>">
                            <button type="submit" class="btn btn-sm <?= $item['active'] ? 'btn-danger' : 'btn-success' ?>">
                                <?= $item['active'] ? 'Deactivate' : 'Reactivate' ?>
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
