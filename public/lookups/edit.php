<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\CadreRepository;
use VetBoard\EventModeRepository;
use VetBoard\SessionWindowRepository;

const LOOKUP_TYPES = [
    'event-modes' => ['label' => 'Event Modes',     'repository' => EventModeRepository::class,    'has_time' => false],
    'cadres'      => ['label' => 'Cadres',           'repository' => CadreRepository::class,        'has_time' => false],
    'sessions'    => ['label' => 'Session Windows',  'repository' => SessionWindowRepository::class,'has_time' => true],
];

Auth::startSession();
Auth::requirePermission('manage_lookups');

$type = $_GET['type'] ?? $_POST['type'] ?? '';

if (!array_key_exists($type, LOOKUP_TYPES)) {
    http_response_code(404);
    die('Unknown lookup type.');
}

$config    = LOOKUP_TYPES[$type];
$repoClass = $config['repository'];
$repo      = new $repoClass();

$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$item = $repo->findById($id);

if (!$item) {
    $_SESSION['error'] = 'Item not found.';
    header('Location: /lookups/index.php?type=' . urlencode($type));
    exit;
}

$error = $_SESSION['error'] ?? null;
unset($_SESSION['error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $data = [
        'label'      => trim($_POST['label'] ?? ''),
        'sort_order' => (int) ($_POST['sort_order'] ?? 0),
    ];

    if ($config['has_time']) {
        $data['start_time'] = $_POST['start_time'] ?? '';
        $data['end_time']   = $_POST['end_time'] ?? '';
    }

    try {
        $repo->update($id, $data);
        $_SESSION['success'] = $config['label'] . ' item updated successfully.';
        header('Location: /lookups/index.php?type=' . urlencode($type));
        exit;

    } catch (\InvalidArgumentException $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: /lookups/edit.php?type=' . urlencode($type) . '&id=' . $id);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit <?= htmlspecialchars($config['label'], ENT_QUOTES, 'UTF-8') ?> Item — Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: 480px; margin: 0 auto; }
.container h1 { font-size: 1.2rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: 1rem; }
.form-group { margin-bottom: 1rem; }
</style>
</head>
<body>
<div class="container">
    <h1>Edit <?= htmlspecialchars($config['label'], ENT_QUOTES, 'UTF-8') ?> Item</h1>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="/lookups/edit.php?type=<?= urlencode($type) ?>&id=<?= (int) $item['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">

            <div class="form-group">
                <label>Key</label>
                <input type="text" value="<?= htmlspecialchars($item['item_key'], ENT_QUOTES, 'UTF-8') ?>" disabled>
                <small style="color:var(--text-muted);">Keys cannot be changed once created.</small>
            </div>

            <div class="form-group">
                <label for="label">Label <span class="required">*</span></label>
                <input type="text" id="label" name="label" required
                       value="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <?php if ($config['has_time']): ?>
                <div class="form-group">
                    <label for="start_time">Start Time <span class="required">*</span></label>
                    <input type="time" id="start_time" name="start_time" required
                           value="<?= htmlspecialchars(substr($item['start_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label for="end_time">End Time <span class="required">*</span></label>
                    <input type="time" id="end_time" name="end_time" required
                           value="<?= htmlspecialchars(substr($item['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>">
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="sort_order">Display Order</label>
                <input type="number" id="sort_order" name="sort_order" min="0"
                       value="<?= (int) $item['sort_order'] ?>">
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;">Save Changes</button>
        </form>
    </div>

    <div style="margin-top:1rem;">
        <a href="/lookups/index.php?type=<?= urlencode($type) ?>" class="btn btn-secondary">&larr; Back</a>
    </div>
</div>
</body>
</html>