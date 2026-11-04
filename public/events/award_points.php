<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;
use VetBoard\ManualPoints;
use VetBoard\Participant;
use VetBoard\Registration;

Auth::startSession();
Auth::requirePermission('award_manual_points');

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

$regNo       = strtoupper(trim((string) $_GET['reg_no']));
$participant = (new Participant())->findByRegNo($regNo);
$viewUrl     = '/events/view.php?token=' . urlencode($event['view_token']);

if (!$participant || !(new Registration())->find((int) $event['id'], $regNo)) {
    $_SESSION['error'] = 'That attendee is not registered for this event.';
    header('Location: ' . $viewUrl);
    exit;
}

$manual  = new ManualPoints();
$selfUrl = '/events/award_points.php?token=' . urlencode($event['view_token']) . '&reg_no=' . urlencode($regNo);
$staffId = isset($user['id']) ? (int) $user['id'] : null;
$error   = null;
$values  = ['session_id' => '', 'date' => '', 'points' => '', 'reason' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    if (($_POST['action'] ?? '') === 'remove') {
        $result = $manual->remove((int) $event['id'], $regNo, (int) ($_POST['ledger_id'] ?? 0), $staffId);
    } else {
        $values = [
            'session_id' => (string) ($_POST['session_id'] ?? ''),
            'date'       => trim((string) ($_POST['date'] ?? '')),
            'points'     => (string) ($_POST['points'] ?? ''),
            'reason'     => trim((string) ($_POST['reason'] ?? '')),
        ];

        $result = $manual->award(
            (int) $event['id'],
            $regNo,
            (int) $values['session_id'],
            $values['date'],
            $values['points'],
            $values['reason'],
            $staffId
        );
    }

    if ($result['ok']) {
        $_SESSION['success'] = $result['message'];
        header('Location: ' . $viewUrl);
        exit;
    }

    $error = $result['message'];
}

$sessions = $eventModel->getSessions((int) $event['id']);
$awards   = $manual->listFor((int) $event['id'], $regNo);
$today    = date('Y-m-d');

if ($values['date'] === '') {
    $values['date'] = ($today >= $event['start_date'] && $today <= $event['end_date']) ? $today : $event['start_date'];
}

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/index.php'],
    ['label' => 'Events', 'url' => '/events/index.php'],
    ['label' => $event['title'], 'url' => $viewUrl],
    ['label' => 'Add Points', 'url' => null],
];

require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Points - <?= $h($event['title']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="app-page-hero">
        <div><h1>Add Points</h1></div>
        <a href="<?= $h($viewUrl) ?>" class="btn btn-ghost">&larr; Back to Event</a>
    </div>

    <div class="app-card">
        <?php if ($error): ?>
            <p class="notice-box" style="background:var(--kvb-red-light);color:var(--kvb-red-dark);"><?= $h($error) ?></p>
        <?php endif; ?>

        <p style="margin-bottom:1rem;"><strong><?= $h((string) $participant['name']) ?></strong> &middot; <?= $h($regNo) ?></p>

        <form method="POST" action="<?= $h($selfUrl) ?>">
            <input type="hidden" name="csrf_token" value="<?= $h(Auth::csrfToken()) ?>">
            <input type="hidden" name="action" value="award">

            <div class="form-group">
                <label for="session_id">Session</label>
                <select id="session_id" name="session_id" required>
                    <option value="">Select a session</option>
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"
                                data-date="<?= $h((string) ($s['session_date'] ?? '')) ?>"
                                data-points="<?= \VetBoard\Points::format($s['points']) ?>"
                                <?= (string) $values['session_id'] === (string) $s['id'] ? 'selected' : '' ?>>
                            <?= $h((string) $s['label']) ?> (<?= $s['session_date'] ? $h(date('d M', strtotime($s['session_date']))) : 'every day' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="date">Date</label>
                <input type="date" id="date" name="date" required
                       min="<?= $h((string) $event['start_date']) ?>" max="<?= $h((string) $event['end_date']) ?>"
                       value="<?= $h($values['date']) ?>">
            </div>
            <div class="form-group">
                <label for="points">Points (maximum <?= ManualPoints::DAILY_CAP ?> per day)</label>
                <input type="number" id="points" name="points" min="0.1" max="<?= ManualPoints::DAILY_CAP ?>" step="0.1" required
                       value="<?= $h($values['points']) ?>">
            </div>
            <div class="form-group">
                <label for="reason">Reason</label>
                <input type="text" id="reason" name="reason" required minlength="3" maxlength="150"
                       placeholder="e.g. Attended, signed in late" value="<?= $h($values['reason']) ?>">
            </div>

            <button type="submit" class="btn btn-primary">Add Points</button>
        </form>

        <?php if ($awards): ?>
            <div class="section-label" style="margin-top:1.5rem;">Manual awards for this attendee</div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr><th>Session</th><th>Date</th><th>Points</th><th>Reason</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($awards as $a): ?>
                        <tr>
                            <td><?= $h((string) ($a['label'] ?? '')) ?></td>
                            <td><?= $h(date('d M Y', strtotime($a['day']))) ?></td>
                            <td><?= \VetBoard\Points::format($a['points']) ?></td>
                            <td><?= $h((string) $a['reason']) ?></td>
                            <td>
                                <form method="POST" action="<?= $h($selfUrl) ?>" onsubmit="return confirm('Remove these points?');">
                                    <input type="hidden" name="csrf_token" value="<?= $h(Auth::csrfToken()) ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="ledger_id" value="<?= (int) $a['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    var select = document.getElementById('session_id');
    var date = document.getElementById('date');
    var points = document.getElementById('points');

    select.addEventListener('change', function () {
        var option = select.options[select.selectedIndex];

        if (option.dataset.date) { date.value = option.dataset.date; }
        if (option.dataset.points && option.dataset.points !== '0') { points.value = option.dataset.points; }
    });
})();
</script>
</body>
</html>
