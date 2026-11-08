<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;
use VetBoard\Participant;
use VetBoard\Registration;

Auth::startSession();
Auth::requirePermission('edit_attendee_details');

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

$regNo            = strtoupper(trim((string) $_GET['reg_no']));
$participantModel = new Participant();
$participant      = $participantModel->findByRegNo($regNo);

if (!$participant || !(new Registration())->find((int) $event['id'], $regNo)) {
    $_SESSION['error'] = 'That attendee is not registered for this event.';
    header('Location: /events/view.php?token=' . urlencode($event['view_token']));
    exit;
}

$error  = null;
$values = [
    'reg_no' => $participant['reg_no'],
    'name'   => $participant['name'],
    'email'  => $participant['email'],
    'phone'  => $participant['phone'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $values = [
        'reg_no' => strtoupper(trim((string) ($_POST['reg_no'] ?? ''))),
        'name'   => trim((string) ($_POST['name'] ?? '')),
        'email'  => strtolower(trim((string) ($_POST['email'] ?? ''))),
        'phone'  => trim((string) ($_POST['phone'] ?? '')),
    ];

    $result = $participantModel->updateDetails($regNo, $values, isset($user['id']) ? (int) $user['id'] : null, (int) $event['id']);

    if ($result['ok']) {
        $_SESSION['success'] = $result['message'];
        header('Location: /events/view.php?token=' . urlencode($event['view_token']));
        exit;
    }

    $error = $result['message'];
}

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/index.php'],
    ['label' => 'Events', 'url' => '/events/index.php'],
    ['label' => $event['title'], 'url' => '/events/view.php?token=' . urlencode($event['view_token'])],
    ['label' => 'Edit Attendee', 'url' => null],
];

require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Attendee - <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="app-page-hero">
        <div><h1>Edit Attendee</h1></div>
        <a href="/events/view.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-ghost">Back to Event</a>
    </div>

    <div class="app-card">
        <?php if ($error): ?>
            <p class="notice-box" style="background:var(--kvb-red-light);color:var(--kvb-red-dark);"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <p style="font-size:.85rem;margin-bottom:1rem;">
            Changing the registration number moves this attendee's registrations and points to the new number.
            Every change is recorded with your name.
        </p>

        <form method="POST" action="/events/edit_attendee.php?token=<?= urlencode($event['view_token']) ?>&amp;reg_no=<?= urlencode($regNo) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
                <label for="reg_no">Registration Number</label>
                <input type="text" id="reg_no" name="reg_no" required value="<?= htmlspecialchars($values['reg_no'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label for="name">Full Name</label>
                <input type="text" id="name" name="name" required value="<?= htmlspecialchars($values['name'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required value="<?= htmlspecialchars($values['email'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label for="phone">Phone</label>
                <input type="text" id="phone" name="phone" required value="<?= htmlspecialchars($values['phone'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label>ID Number (locked)</label>
                <input type="text" value="<?= htmlspecialchars((string) $participant['id_number'], ENT_QUOTES, 'UTF-8') ?>" readonly>
            </div>

            <button type="submit" class="btn btn-primary">Save Changes</button>
        </form>
    </div>
</div>
</body>
</html>
