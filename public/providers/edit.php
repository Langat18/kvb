<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Provider;

Auth::startSession();
Auth::requirePermission('manage_providers');

$providerModel = new Provider();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$provider = $providerModel->findById($id);

if (!$provider) {
    $_SESSION['error'] = 'Provider not found.';
    header('Location: /providers/index.php');
    exit;
}

$error = $_SESSION['error'] ?? null;
unset($_SESSION['error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    try {
        $providerModel->rename($id, $_POST['name'] ?? '');
        $_SESSION['success'] = 'Provider renamed successfully.';
        header('Location: /providers/index.php');
        exit;

    } catch (\InvalidArgumentException $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: /providers/edit.php?id=' . $id);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rename Provider - Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: 480px; margin: 0 auto; }
.container h1 { font-size: 1.2rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: 1rem; }
</style>
</head>
<body>
<div class="container">
    <h1>Rename Provider</h1>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="/providers/edit.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="id" value="<?= (int) $provider['id'] ?>">

            <div class="form-group">
                <label>Accreditation Number</label>
                <input type="text" value="<?= htmlspecialchars($provider['accreditation_no'], ENT_QUOTES, 'UTF-8') ?>" disabled>
                <small style="color:var(--text-muted);">Cannot be changed once created.</small>
            </div>

            <div class="form-group">
                <label for="name">Provider Name <span class="required">*</span></label>
                <input type="text" id="name" name="name" required value="<?= htmlspecialchars($provider['name'], ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
        </form>
    </div>

    <div style="margin-top:1rem;">
        <a href="/providers/index.php" class="btn btn-secondary">&larr; Back to Providers</a>
    </div>
</div>
</body>
</html>
