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

$type = $_GET['type'] ?? $_POST['type'] ?? '';

if (!array_key_exists($type, LOOKUP_TYPES)) {
    http_response_code(404);
    die('Unknown lookup type.');
}

$config    = LOOKUP_TYPES[$type];
$repoClass = $config['repository'];
$repo      = new $repoClass();

$error    = $_SESSION['error']     ?? null;
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['error'], $_SESSION['form_data']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $data = [
        'item_key'   => trim($_POST['item_key'] ?? ''),
        'label'      => trim($_POST['label'] ?? ''),
        'sort_order' => (int) ($_POST['sort_order'] ?? 0),
    ];

    if ($config['has_time']) {
        $data['start_time'] = $_POST['start_time'] ?? '';
        $data['end_time']   = $_POST['end_time'] ?? '';
    }

    try {
        $repo->create($data);
        $_SESSION['success'] = $config['label'] . ' item created successfully.';
        header('Location: /lookups/' . urlencode($type));
        exit;

    } catch (\InvalidArgumentException $e) {
        $_SESSION['error']     = $e->getMessage();
        $_SESSION['form_data'] = $data;
        header('Location: /lookups/create.php?type=' . urlencode($type));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New <?= htmlspecialchars($config['label'], ENT_QUOTES, 'UTF-8') ?> Item - Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/style.css') ?>">
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: 480px; margin: 0 auto; }
.container h1 { font-size: 1.2rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: 1rem; }
</style>
</head>
<body>
<div class="container">
    <h1>New <?= htmlspecialchars($config['label'], ENT_QUOTES, 'UTF-8') ?> Item</h1>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="/lookups/create.php?type=<?= urlencode($type) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
                <label for="item_key">Key <span class="required">*</span></label>
                <input type="text" id="item_key" name="item_key" required pattern="[a-z0-9_]+"
                       value="<?= htmlspecialchars($formData['item_key'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <small style="color:var(--text-muted);">Lowercase letters, numbers, underscores only. Cannot be changed later.</small>
            </div>

            <div class="form-group">
                <label for="label">Label <span class="required">*</span></label>
                <input type="text" id="label" name="label" required
                       value="<?= htmlspecialchars($formData['label'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <?php if ($config['has_time']): ?>
                <div class="form-group">
                    <label for="start_time">Start Time <span class="required">*</span></label>
                    <input type="time" id="start_time" name="start_time" required
                           value="<?= htmlspecialchars($formData['start_time'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label for="end_time">End Time <span class="required">*</span></label>
                    <input type="time" id="end_time" name="end_time" required
                           value="<?= htmlspecialchars($formData['end_time'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="sort_order">Display Order</label>
                <input type="number" id="sort_order" name="sort_order" min="0"
                       value="<?= htmlspecialchars((string) ($formData['sort_order'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <button type="submit" class="btn btn-primary btn-block">Create</button>
        </form>
    </div>

    <div style="margin-top:1rem;">
        <a href="/lookups/<?= urlencode($type) ?>" class="btn btn-secondary">Back</a>
    </div>
</div>
</body>
</html>
