<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../templates/icons.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\CadreRepository;
use VetBoard\Event;

Auth::startSession();
Auth::requirePermission('view_events');

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

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/index.php'],
    ['label' => 'Events', 'url' => '/events/index.php'],
    ['label' => $event['title'], 'url' => null],
];

$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$cadreLabels   = (new CadreRepository())->labelMap();

$appUrl       = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');
$choiceUrl    = $appUrl . '/events/choice.php?token=' . urlencode($event['token']);
$startDate    = date('d M Y', strtotime($event['start_date']));
$endDate      = date('d M Y', strtotime($event['end_date']));
$participants = $eventModel->getParticipants((int) $event['id']);
$sessionPointsRows = $eventModel->getSessions((int) $event['id']);

$flaggedTotal = 0;
foreach ($participants as $p) {
    foreach ($p['attendance'] as $a) {
        if ($a['flagged']) {
            $flaggedTotal++;
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
<title><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?> - Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/style.css') ?>">
<style>
.page-top { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; }
.page-top h1 { font-size: 1.35rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: .35rem; }

.action-row .btn { font-size: .82rem; padding: .5rem 1rem; }

.grid-2 { display: grid; grid-template-columns: 1fr 300px; gap: 1.25rem; align-items: start; margin-bottom: 1.5rem; }

.detail-table { width: 100%; border-collapse: collapse; }
.detail-table th { text-align: left; padding: .45rem .5rem .45rem 0; color: var(--text-muted); font-size: .82rem; width: 32%; vertical-align: top; font-weight: 600; }
.detail-table td { padding: .45rem 0; font-size: .9rem; }

.qr-card { text-align: center; }
.qr-card #qrcode { display: inline-block; margin-bottom: .85rem; }
.qr-actions { display: flex; flex-direction: column; gap: .4rem; }
.qr-actions .btn { width: 100%; font-size: .8rem; }

.session-badges { display: flex; flex-wrap: wrap; gap: .3rem; max-width: 320px; }
.session-badges .pill { white-space: nowrap; font-size: .72rem; padding: .18rem .55rem; }

.points-breakdown { font-size: .8rem; color: var(--text-muted); }
.points-breakdown span { display: inline-block; margin-right: .75rem; }
</style>
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">

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
            <a href="/events/nominal_roll.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-ghost" target="_blank">Nominal Roll</a>
            <a href="/events/print.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-ghost" target="_blank">Attendance Register</a>
            <a href="/events/points_by_session.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-ghost" target="_blank">Points by Session</a>
            <?php if (Auth::can('manage_events')): ?>
                <a href="/events/pms_points.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-ghost">PMS Points</a>
            <?php endif; ?>
            <?php if (Auth::can('record_manual_attendance')): ?>
                <a href="/events/attend_manual.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-ghost">Record Manual Attendance</a>
            <?php endif; ?>
            <a href="/events/calendar.php?token=<?= urlencode($event['token']) ?>" class="btn btn-ghost">Add to Calendar</a>
            <?php if ($flaggedTotal > 0 && Auth::can('review_flagged_attendance')): ?>
                <a href="/events/flagged.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-danger">
                    Review Flagged (<?= $flaggedTotal ?>)
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid-2">
        <div class="app-card">
            <div class="section-label">Event Details</div>
            <table class="detail-table">
                <tr><th>Registration No.</th><td><?= htmlspecialchars($event['event_code'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Dates</th><td><?= $startDate ?> &ndash; <?= $endDate ?></td></tr>
                <tr><th>Venue</th><td><?= htmlspecialchars($event['venue'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Supervisor</th><td><?= htmlspecialchars($event['coordinator'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr>
                    <th>Provider</th>
                    <td>
                        <?php if (!empty($event['provider_name'])): ?>
                            <?= htmlspecialchars($event['provider_name'], ENT_QUOTES, 'UTF-8') ?>
                            <span style="color:var(--text-muted);font-size:.8rem;">(<?= htmlspecialchars($event['provider_accreditation_no'], ENT_QUOTES, 'UTF-8') ?>)</span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Points per Session</th>
                    <td class="points-breakdown">
                        <?php if (empty($sessionPointsRows) || array_sum(array_column($sessionPointsRows, 'points')) <= 0): ?>
                            —
                        <?php else: ?>
                            <?php foreach ($sessionPointsRows as $sw): ?>
                                <?php if ((float) $sw['points'] > 0): ?>
                                    <span><?= htmlspecialchars($sw['label'], ENT_QUOTES, 'UTF-8') ?>: <?= \VetBoard\Points::format($sw['points']) ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($event['event_mode'] !== 'virtual'): ?>
                <tr>
                    <th>Geofence</th>
                    <td>
                        <span title="Coordinates: <?= $event['geo_lat'] ?>, <?= $event['geo_lng'] ?>" style="cursor:help;border-bottom:1px dotted var(--text-muted);">
                            <?= ui_icon('pin', 14) ?> <?= (int) $event['geo_radius_m'] ?>m radius around venue
                        </span>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="app-card qr-card">
            <div class="section-label" style="text-align:center;">Attendance QR</div>
            <div id="qrcode"></div>
            <div class="qr-actions">
                <a href="/events/qr_print.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-ghost" target="_blank">Print QR</a>
                <button type="button" class="btn btn-ghost" onclick="copyLink(this)">Copy Link</button>
            </div>
        </div>
    </div>

    <div class="app-card">
        <div class="roster-head">
            <div class="section-label" style="margin:0;">Registered participants <span class="roster-count">(<span id="rs-shown"><?= count($participants) ?></span> of <?= count($participants) ?>)</span></div>
            <?php if (!empty($participants)): ?><input type="search" id="rs-q" class="roster-search" placeholder="Search name or reg no" aria-label="Filter participants" autocomplete="off"><?php endif; ?>
        </div>
        <?php if (empty($participants)): ?>
            <p class="empty-state">No participants have registered yet.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="data-table roster">
            <thead>
                <tr>
                    <th>Reg No</th>
                    <th>Name</th>
                    <th>Cadre</th>
                    <th>Sessions</th>
                    <th>Days present</th>
                    <th class="num">Points</th>
                    <?php $rowActions = Auth::can('edit_attendee_details') || Auth::can('award_manual_points') || Auth::can('delete_registrations'); ?><?php if ($rowActions): ?><th class="col-act"><span class="sr-only">Actions</span></th><?php endif; ?>
                </tr>
            </thead>
            <tbody id="rs-body">
                <?php foreach ($participants as $p): ?>
                <tr data-s="<?= htmlspecialchars(strtolower($p['reg_no'] . ' ' . $p['name']), ENT_QUOTES, 'UTF-8') ?>">
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
                                        <span class="chip bad" title="Flagged: <?= (int) $a['distance_m'] ?>m from venue"><?= htmlspecialchars($a['session_label'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php else: ?>
                                        <span class="chip ok" title="Verified"><?= htmlspecialchars($a['session_label'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (empty($p['days_present'])): ?>
                            &mdash;
                        <?php else: ?>
                            <div class="session-badges">
                                <?php foreach ($p['days_present'] as $day => $source): ?>
                                    <span class="chip day" title="<?= $source === 'signin' ? 'Signed in to a session' : ($source === 'staff' ? 'Confirmed by staff' : 'Confirmed by attendee') ?>"><?= htmlspecialchars(date('d M', strtotime((string) $day)), ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="num pts"><?= \VetBoard\Points::format($p['points_earned'] ?? 0) ?></td>
                    <?php if ($rowActions): ?><td class="col-act"><?php require __DIR__ . '/../templates/attendee_actions.php'; ?></td><?php endif; ?>
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

(function () {
    var q = document.getElementById('rs-q');
    if (!q) { return; }
    var rows = Array.prototype.slice.call(document.querySelectorAll('#rs-body tr'));
    var shown = document.getElementById('rs-shown');
    q.addEventListener('input', function () {
        var term = q.value.trim().toLowerCase(), n = 0;
        rows.forEach(function (tr) {
            var hit = term === '' || tr.getAttribute('data-s').indexOf(term) !== -1;
            tr.hidden = !hit;
            if (hit) { n++; }
        });
        shown.textContent = n;
    });
})();

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
