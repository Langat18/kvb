<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;

Auth::startSession();
Auth::requireLogin();

$user = Auth::user();

if (empty($_GET['token'])) {
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

$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$appUrl       = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');
$choiceUrl    = $appUrl . '/events/choice.php?token=' . urlencode($event['token']);
$startDate    = date('d M Y', strtotime($event['start_date']));
$endDate      = date('d M Y', strtotime($event['end_date']));
$participants = $eventModel->getParticipants((int) $event['id']);

$flaggedTotal = 0;
foreach ($participants as $p) {
    foreach ($p['attendance'] as $a) {
        if ($a['flagged']) {
            $flaggedTotal++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?> — Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: 920px; margin: 0 auto; }
.grid-2 { display: grid; grid-template-columns: 1fr auto; gap: 1.5rem; align-items: start; margin-bottom: 1.5rem; }
.event-title { font-size: 1.25rem; font-weight: 700; margin-bottom: .5rem; color: var(--kvb-green-dark); }
.detail-table { width: 100%; border-collapse: collapse; margin-top: .75rem; }
.detail-table th { text-align: left; padding: .4rem .5rem .4rem 0; color: var(--text-muted); font-size: .82rem; width: 35%; vertical-align: top; font-weight: 600; }
.detail-table td { padding: .4rem 0; font-size: .9rem; }
.qr-card { text-align: center; min-width: 220px; }
.qr-card p.qr-label { font-weight: 600; margin-bottom: .75rem; color: var(--kvb-green-dark); }
.qr-url { font-size: .7rem; color: var(--text-muted); margin-top: .75rem; word-break: break-all; }
.empty-state { color: var(--text-muted); font-size: .85rem; padding: 1rem 0; }
h2.section-title { font-size: 1rem; font-weight: 600; margin-bottom: 1rem; color: var(--kvb-green-dark); }
</style>
</head>
<body>
<div class="container">

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="grid-2">
        <div class="card">
            <div class="event-title">
                <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?>
                <span class="pill"><?= htmlspecialchars(EVENT_MODES[$event['event_mode']] ?? $event['event_mode'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <table class="detail-table">
                <tr><th>Event Code</th><td><?= htmlspecialchars($event['event_code'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Dates</th><td><?= $startDate ?> &ndash; <?= $endDate ?></td></tr>
                <tr><th>Venue</th><td><?= htmlspecialchars($event['venue'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Department</th><td><?= htmlspecialchars($event['department'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Coordinator</th><td><?= htmlspecialchars($event['coordinator'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Program Assistant</th><td><?= htmlspecialchars($event['program_assistant'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td></tr>
                <?php if ($event['event_mode'] !== 'virtual'): ?>
                <tr><th>Geofence</th><td><?= $event['geo_lat'] ?>, <?= $event['geo_lng'] ?> &nbsp;(<?= (int) $event['geo_radius_m'] ?>m radius)</td></tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="card qr-card">
            <p class="qr-label">Attendance QR Code</p>
            <div id="qrcode" style="display:inline-block;"></div>
            <p class="qr-url"><?= htmlspecialchars($choiceUrl, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">Registered Participants (<?= count($participants) ?>)</h2>
        <?php if (empty($participants)): ?>
            <p class="empty-state">No participants have registered yet.</p>
        <?php else: ?>
        <table class="list-table">
            <thead>
                <tr>
                    <th>Reg No</th>
                    <th>Name</th>
                    <th>Cadre</th>
                    <th>Sessions Signed</th>
                    <th>Flagged</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($participants as $p): ?>
                <?php
                    $sessionsSigned = count($p['attendance']);
                    $flaggedCount   = count(array_filter($p['attendance'], fn($a) => $a['flagged']));
                ?>
                <tr>
                    <td><?= htmlspecialchars($p['reg_no'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(CADRE_LABELS[$p['cadre']] ?? $p['cadre'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $sessionsSigned ?></td>
                    <td>
                        <?php if ($flaggedCount > 0): ?>
                            <span class="pill" style="background:var(--kvb-red-light);color:var(--kvb-red-dark);">
                                &#9888; <?= $flaggedCount ?> flagged
                            </span>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div style="margin-top:1.5rem;display:flex;gap:.75rem;">
        <a href="/events/nominal_roll.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-primary" target="_blank">Nominal Roll</a>
        <a href="/events/calendar.php?token=<?= urlencode($event['token']) ?>" class="btn btn-secondary">Add to Calendar</a>
        <?php if ($flaggedTotal > 0): ?>
            <a href="/events/flagged.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-secondary" style="background:var(--kvb-red-light);color:var(--kvb-red-dark);">
                &#9888; Review Flagged (<?= $flaggedTotal ?>)
            </a>
        <?php endif; ?>
        <a href="/events/index.php" class="btn btn-secondary">&larr; Back to Events</a>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
new QRCode(document.getElementById("qrcode"), {
    text: <?= json_encode($choiceUrl) ?>,
    width: 180,
    height: 180,
    colorDark: "#0f5132",
    colorLight: "#ffffff",
    correctLevel: QRCode.CorrectLevel.H
});
</script>
</body>
</html>
