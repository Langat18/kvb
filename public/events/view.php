<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\CadreRepository;
use VetBoard\Event;
use VetBoard\SessionWindowRepository;

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

$cadreLabels   = (new CadreRepository())->labelMap();
$sessionLabels = (new SessionWindowRepository())->labelMap();

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
<title><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?>Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 2rem 2.5rem; }
.container { max-width: 1000px; margin: 0 auto; }

.breadcrumb { font-size: .8rem; color: var(--text-muted); margin-bottom: .75rem; }
.breadcrumb a { color: var(--text-muted); text-decoration: none; }
.breadcrumb a:hover { color: var(--kvb-green-dark); }

.page-top { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; }
.page-top h1 { font-size: 1.35rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: .35rem; }

.action-row { display: flex; gap: .5rem; flex-wrap: wrap; }
.action-row .btn { font-size: .82rem; padding: .5rem 1rem; }
.btn-ghost { background: #fff; border: 1px solid #d8dde3; color: var(--text-main); }
.btn-danger-outline { background: var(--kvb-red-light); color: var(--kvb-red-dark); }

.section-label { font-size: .78rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); margin-bottom: .6rem; }

.grid-2 { display: grid; grid-template-columns: 1fr 300px; gap: 1.25rem; align-items: start; margin-bottom: 1.5rem; }
@media (max-width: 780px) { .grid-2 { grid-template-columns: 1fr; } }

.detail-table { width: 100%; border-collapse: collapse; }
.detail-table th { text-align: left; padding: .45rem .5rem .45rem 0; color: var(--text-muted); font-size: .82rem; width: 32%; vertical-align: top; font-weight: 600; }
.detail-table td { padding: .45rem 0; font-size: .9rem; }

.qr-card { text-align: center; }
.qr-card #qrcode { display: inline-block; margin-bottom: .85rem; }
.qr-actions { display: flex; flex-direction: column; gap: .4rem; }
.qr-actions .btn { width: 100%; font-size: .8rem; }

.table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.list-table { width: 100%; border-collapse: collapse; min-width: 560px; }
.list-table th { text-align: left; padding: .65rem .6rem; font-size: .78rem; color: var(--text-muted); border-bottom: 1px solid #eee; background: #faf9f7; }
.list-table td { padding: .65rem .6rem; font-size: .87rem; border-bottom: 1px solid #f2f2f2; vertical-align: middle; }
.list-table tr:hover td { background: #f7faf8; }

.session-badges { display: flex; flex-wrap: wrap; gap: .3rem; max-width: 320px; }
.session-badges .pill { white-space: nowrap; font-size: .72rem; padding: .18rem .55rem; }

.empty-state { color: var(--text-muted); font-size: .85rem; padding: 1.5rem 0; text-align: center; }
</style>
</head>
<?php require __DIR__ . '/../templates/nav.php'; ?>
<body>
<div class="container">

    <div class="breadcrumb">
        <a href="/index.php">Dashboard</a> &rsaquo; <a href="/events/index.php">Events</a> &rsaquo; <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="page-top">
        <div>
            <h1>
                <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?>
                <span class="pill"><?= htmlspecialchars($event['event_mode_label'] ?? $event['event_mode'], ENT_QUOTES, 'UTF-8') ?></span>
            </h1>
        </div>
        <div class="action-row">
            <?php $canEdit = Auth::can('manage_events') || (int) $user['id'] === (int) ($event['created_by'] ?? 0); ?>
            <?php if ($canEdit): ?>
                <a href="/events/edit.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-ghost">Edit Event</a>
            <?php endif; ?>
            <a href="/events/nominal_roll.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-primary" target="_blank">Nominal Roll</a>
            <a href="/events/print.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-ghost" target="_blank">Attendance Register</a>
            <a href="/events/calendar.php?token=<?= urlencode($event['token']) ?>" class="btn btn-ghost">Add to Calendar</a>
            <?php if ($flaggedTotal > 0 && Auth::can('review_flagged_attendance')): ?>
                <a href="/events/flagged.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-danger-outline">
                    &#9888; Review Flagged (<?= $flaggedTotal ?>)
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid-2">
        <div class="card">
            <div class="section-label">Event Details</div>
            <table class="detail-table">
                <tr><th>Event Code</th><td><?= htmlspecialchars($event['event_code'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Dates</th><td><?= $startDate ?> &ndash; <?= $endDate ?></td></tr>
                <tr><th>Venue</th><td><?= htmlspecialchars($event['venue'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Department</th><td><?= htmlspecialchars($event['department_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Coordinator</th><td><?= htmlspecialchars($event['coordinator'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Program Assistant</th><td><?= htmlspecialchars($event['program_assistant'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td></tr>
                <?php if ($event['event_mode'] !== 'virtual'): ?>
                <tr>
                    <th>Geofence</th>
                    <td>
                        <span title="Coordinates: <?= $event['geo_lat'] ?>, <?= $event['geo_lng'] ?>" style="cursor:help;border-bottom:1px dotted var(--text-muted);">
                            &#128205; <?= (int) $event['geo_radius_m'] ?>m radius around venue
                        </span>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="card qr-card">
            <div class="section-label" style="text-align:center;">Attendance QR</div>
            <div id="qrcode"></div>
            <div class="qr-actions">
                <a href="/events/qr_print.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-ghost" target="_blank">Print QR</a>
                <button type="button" class="btn btn-ghost" onclick="copyLink(this)">Copy Link</button>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="section-label">Registered Participants (<?= count($participants) ?>)</div>
        <?php if (empty($participants)): ?>
            <p class="empty-state">No participants have registered yet.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="list-table">
            <thead>
                <tr>
                    <th>Reg No</th>
                    <th>Name</th>
                    <th>Cadre</th>
                    <th>Sessions</th>
                    <th>Points</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($participants as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['reg_no'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($cadreLabels[$p['cadre']] ?? $p['cadre'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if (empty($p['attendance'])): ?>
                            &mdash;
                        <?php else: ?>
                            <div class="session-badges">
                                <?php foreach ($p['attendance'] as $a): ?>
                                    <?php if ($a['flagged']): ?>
                                        <span class="pill" style="background:var(--kvb-red-light);color:var(--kvb-red-dark);" title="<?= (int) $a['distance_m'] ?>m from venue">
                                            &#9888; <?= htmlspecialchars($sessionLabels[$a['session']] ?? $a['session'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="pill" style="background:var(--kvb-green-light);color:var(--kvb-green-dark);" title="Verified">
                                            &#10003; <?= htmlspecialchars($sessionLabels[$a['session']] ?? $a['session'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:600;color:var(--kvb-green-dark);"><?= (int) ($p['points_earned'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
new QRCode(document.getElementById("qrcode"), {
    text: <?= json_encode($choiceUrl) ?>,
    width: 170,
    height: 170,
    colorDark: "#0f5132",
    colorLight: "#ffffff",
    correctLevel: QRCode.CorrectLevel.H
});

function copyLink(btn) {
    navigator.clipboard.writeText(<?= json_encode($choiceUrl) ?>).then(() => {
        const original = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = original; }, 1500);
    });
}
</script>
</body>
</html>