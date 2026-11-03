<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;
use VetBoard\RegistrationPurge;

Auth::startSession();
Auth::requirePermission('delete_registrations');

$user = Auth::user();

if (empty($_GET['token']) || empty($_GET['reg_no'])) {
    header('Location: /events/index.php');
    exit;
}

$eventModel = new Event();
$event      = $eventModel->findByViewToken(trim($_GET['token']));

if (!$event) {
    $_SESSION['error'] = 'Event not found.';
    header('Location: /events/index.php');
    exit;
}

$regNo   = strtoupper(trim((string) $_GET['reg_no']));
$viewUrl = '/events/view.php?token=' . urlencode($event['view_token']);
$purge   = new RegistrationPurge();
$summary = $purge->summary((int) $event['id'], $regNo);

if ($summary === null) {
    $_SESSION['error'] = 'That attendee is not registered for this event.';
    header('Location: ' . $viewUrl);
    exit;
}

$selfUrl = '/events/delete_registration.php?token=' . urlencode($event['view_token']) . '&reg_no=' . urlencode($regNo);
$error   = null;
$reason  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $reason = trim((string) ($_POST['reason'] ?? ''));
    $result = $purge->delete((int) $event['id'], $regNo, $reason, isset($user['id']) ? (int) $user['id'] : null);

    if ($result['ok']) {
        $_SESSION['success'] = $result['message'];
        header('Location: ' . $viewUrl);
        exit;
    }

    $error = $result['message'];
}

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/index.php'],
    ['label' => 'Events', 'url' => '/events/index.php'],
    ['label' => $event['title'], 'url' => $viewUrl],
    ['label' => 'Delete Registration', 'url' => null],
];

require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Delete Registration - <?= $h($event['title']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="app-page-hero">
        <div><h1>Delete Registration</h1></div>
        <a href="<?= $h($viewUrl) ?>" class="btn btn-ghost">&larr; Back to Event</a>
    </div>

    <div class="app-card">
        <?php if ($error): ?>
            <p class="notice-box" style="background:var(--kvb-red-light);color:var(--kvb-red-dark);"><?= $h($error) ?></p>
        <?php endif; ?>

        <p class="notice-box" style="background:var(--kvb-red-light);color:var(--kvb-red-dark);">
            This permanently removes the attendee from this event. It cannot be undone.
        </p>

        <table class="data-table" style="margin:1rem 0;">
            <tr><th>Name</th><td><?= $h($summary['name']) ?></td></tr>
            <tr><th>Registration No.</th><td><?= $h($regNo) ?></td></tr>
            <tr><th>Sign-ins to be deleted</th><td><?= $summary['signins'] ?></td></tr>
            <tr><th>Points to be removed</th><td><?= $summary['points'] ?></td></tr>
        </table>

        <p style="font-size:.85rem;margin-bottom:1rem;">
            Only this event is affected. The participant record and other events are kept.
            The deletion is recorded with your name and the reason below.
        </p>

        <form method="POST" action="<?= $h($selfUrl) ?>">
            <input type="hidden" name="csrf_token" value="<?= $h(Auth::csrfToken()) ?>">

            <div class="form-group">
                <label for="reason">Reason</label>
                <input type="text" id="reason" name="reason" required minlength="3" maxlength="255"
                       placeholder="e.g. Registered with the wrong registration number" value="<?= $h($reason) ?>">
            </div>

            <button type="submit" class="btn btn-danger"
                    onclick="return confirm('Permanently remove this registration?');">Delete Registration</button>
            <a href="<?= $h($viewUrl) ?>" class="btn btn-ghost">Cancel</a>
        </form>
    </div>
</div>
</body>
</html>
