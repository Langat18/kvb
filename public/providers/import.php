<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Provider;

Auth::startSession();
Auth::requirePermission('manage_providers');

$error  = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please choose a CSV file to upload.';
    } else {
        try {
            $providerModel = new Provider();
            $result = $providerModel->importFromCsv($_FILES['csv_file']['tmp_name']);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import Providers - Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: 520px; margin: 0 auto; }
.container h1 { font-size: 1.2rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: 1rem; }
.result-list { font-size: .82rem; color: var(--text-muted); margin-top: .5rem; max-height: 180px; overflow-y: auto; }
</style>
</head>
<body>
<div class="container">
    <h1>Import Providers</h1>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($result): ?>
        <div class="alert alert-success">
            Imported <?= (int) $result['imported'] ?> new provider(s). Skipped <?= (int) $result['skipped'] ?> already-existing accreditation number(s).
        </div>
        <?php if (!empty($result['errors'])): ?>
            <div class="alert alert-danger">
                Some rows could not be imported:
                <div class="result-list">
                    <?php foreach ($result['errors'] as $err): ?>
                        <div><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="card">
        <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:1rem;">
            Upload a CSV file with two columns: accreditation number, provider name. A header row is fine and will be skipped automatically. Existing accreditation numbers are never overwritten - safe to re-run with an updated list.
        </p>
        <form method="POST" action="/providers/import.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
                <label for="csv_file">CSV File</label>
                <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Import</button>
        </form>
    </div>

    <div style="margin-top:1rem;">
        <a href="/providers/index.php" class="btn btn-secondary">&larr; Back to Providers</a>
    </div>
</div>
</body>
</html>
