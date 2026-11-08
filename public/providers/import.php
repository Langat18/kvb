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

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/index.php'],
    ['label' => 'Providers', 'url' => '/providers/index.php'],
    ['label' => 'Import CSV', 'url' => null],
];

$error  = null;
$result = null;

const MAX_CSV_BYTES = 2 * 1024 * 1024;
const ALLOWED_CSV_MIMES = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please choose a CSV file to upload.';
    } elseif ($_FILES['csv_file']['size'] > MAX_CSV_BYTES) {
        $error = 'File is too large. Maximum size is 2MB.';
    } elseif (strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $error = 'Please upload a file with a .csv extension.';
    } else {
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $_FILES['csv_file']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, ALLOWED_CSV_MIMES, true)) {
            $error = 'This does not appear to be a valid CSV file.';
        } else {
            try {
                $providerModel = new Provider();
                $result        = $providerModel->importFromCsv($_FILES['csv_file']['tmp_name']);
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
    }
}

require __DIR__ . '/../templates/sidebar_data.php';
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
.result-list { font-size: .82rem; color: var(--text-muted); margin-top: .5rem; max-height: 180px; overflow-y: auto; }
</style>
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar" style="max-width: 520px;">
    <div class="app-page-hero">
        <div><h1>Import Providers</h1></div>
    </div>

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

    <div class="app-card">
        <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:1rem;">
            Upload a CSV with up to five columns, in order: No (accreditation number), Name, Contact Person, Phone No, Email. A header row is fine and will be skipped automatically. Contact Person, Phone No, and Email are optional. Existing accreditation numbers are never overwritten — safe to re-run with an updated list. Maximum file size 2MB.
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
        <a href="/providers/index.php" class="btn btn-secondary">Back to Providers</a>
    </div>
</div>
</body>
</html>
