<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Database;
use VetBoard\Event;
use VetBoard\PmsClient;

Auth::startSession();
Auth::requirePermission('manage_events');

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

$pdo      = Database::getInstance()->getPdo();
$eventId  = (int) $event['id'];
$today    = date('Y-m-d');
$endDate  = (string) $event['end_date'];
$code     = trim((string) ($event['event_code'] ?? ''));
$title    = (string) $event['title'];
$provider = trim((string) ($event['provider_name'] ?? ''));

$stmt = $pdo->prepare(
    'SELECT pl.reg_no, p.name, SUM(pl.points) AS points
     FROM points_ledger pl
     JOIN participants p ON p.reg_no = pl.reg_no
     WHERE pl.event_id = :event
     GROUP BY pl.reg_no, p.name
     HAVING SUM(pl.points) > 0
     ORDER BY p.name, pl.reg_no'
);
$stmt->execute([':event' => $eventId]);
$people = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT pl.reg_no, ROUND((SUM(EXTRACT(EPOCH FROM (es.end_time - es.start_time))) / 3600)::numeric, 1) AS hours
     FROM points_ledger pl
     JOIN attendance a ON a.id = pl.attendance_id
     JOIN event_sessions es ON es.id = a.session_id
     WHERE pl.event_id = :event AND pl.kind = 'attendance' AND pl.points > 0
     GROUP BY pl.reg_no"
);
$stmt->execute([':event' => $eventId]);
$hoursBy = array_column($stmt->fetchAll(), 'hours', 'reg_no');

$stmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM attendance a
     JOIN registrations r ON r.id = a.registration_id
     WHERE r.event_id = :event AND a.flagged AND NOT a.points_denied'
);
$stmt->execute([':event' => $eventId]);
$flaggedPending = (int) $stmt->fetchColumn();

$formatOk = (bool) preg_match('#^REG/CPD-A/\d{4}/\d{4}$#', $code);
$pms      = $code !== '' ? (new PmsClient(null, null, 8, 3))->activity($code) : null;
$pmsData  = $pms !== null && $pms['status'] === PmsClient::FOUND ? $pms['data'] : [];

$num = static fn(float $v) => fmod($v, 1.0) === 0.0 ? (int) $v : round($v, 1);

$records    = [];
$rows       = [];
$totalPts   = 0.0;
$totalHrs   = 0.0;
$zeroHours  = 0;
$fractional = 0;

foreach ($people as $p) {
    $points = (float) $p['points'];
    $hours  = (float) ($hoursBy[$p['reg_no']] ?? 0);

    $totalPts += $points;
    $totalHrs += $hours;
    $zeroHours  += $hours <= 0 ? 1 : 0;
    $fractional += fmod($points, 1.0) !== 0.0 ? 1 : 0;

    $record = [
        'record_sn'           => 'CPD-' . $eventId . '-' . $p['reg_no'],
        'kvb_reg_number'      => $p['reg_no'],
        'registration_number' => $p['reg_no'],
        'activity_number'     => $code,
        'date_of_activity'    => $endDate,
        'activity_title'      => $pmsData['title'] ?? $title,
        'cpd_hours'           => $num($hours),
        'cpd_points'          => $num($points),
    ];

    if (!empty($pmsData['activity_type'])) {
        $record['activity_type'] = $pmsData['activity_type'];
    }

    $records[] = $record;
    $rows[]    = ['reg_no' => $p['reg_no'], 'name' => $p['name'], 'hours' => $hours, 'points' => $points];
}


$checks = [];

$checks[] = $today >= $endDate
    ? ['ok', 'The event has reached its final day (' . $endDate . ').']
    : ['warn', 'The event ends on ' . $endDate . '. Points can still change until then.'];

if ($code === '') {
    $checks[] = ['warn', 'This event has no activity number. KVB needs one for every record.'];
} else {
    if (!$formatOk) {
        $checks[] = ['warn', 'The activity number "' . $code . '" does not look like REG/CPD-A/NNNN/YYYY.'];
    }

    if ($pms['status'] === PmsClient::FOUND) {
        $checks[] = ['ok', 'KVB has this activity: "' . ($pmsData['title'] ?? '') . '"' . (!empty($pmsData['name_of_provider']) ? ' by ' . $pmsData['name_of_provider'] : '') . '.'];

        $overlaps = false;
        foreach ($pmsData['schedules'] ?? [] as $s) {
            if (($s['start_date'] ?? '9999') <= $endDate && ($s['end_date'] ?? '0000') >= (string) $event['start_date']) {
                $overlaps = true;
            }
        }
        $checks[] = $overlaps
            ? ['ok', 'The event dates overlap a PMS schedule for this activity.']
            : ['warn', 'The event dates do not overlap any PMS schedule for this activity. Check the number is the right one.'];
    } elseif ($pms['status'] === PmsClient::NOT_FOUND) {
        $checks[] = ['warn', 'KVB has no activity with the number "' . $code . '".'];
    } else {
        $checks[] = ['warn', 'KVB could not be reached just now, so the activity number was not checked.'];
    }
}

$checks[] = $flaggedPending > 0
    ? ['warn', $flaggedPending . ' flagged attendance record(s) are still unreviewed. Their points are not included.']
    : ['ok', 'No flagged attendance is waiting for review.'];

if ($zeroHours > 0) {
    $checks[] = ['warn', $zeroHours . ' record(s) have no session hours (for example presenter bonuses). KVB treats zero hours as pointless.'];
}

if ($fractional > 0) {
    $checks[] = ['warn', $fractional . ' record(s) have fractional points. Confirm with KVB that decimals are accepted.'];
}

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/index.php'],
    ['label' => 'Events', 'url' => '/events/index.php'],
    ['label' => $title, 'url' => '/events/view.php?token=' . urlencode($event['view_token'])],
    ['label' => 'PMS Points', 'url' => null],
];

$user = Auth::user();
require __DIR__ . '/../templates/sidebar_data.php';
?>
<?php
$attention  = count(array_filter($checks, static fn($c) => $c[0] !== 'ok'));
$passed     = count($checks) - $attention;
$fmtPoints  = static fn(float $v): string => number_format($v, fmod($v, 1.0) === 0.0 ? 0 : 1);
$fmtHours   = static fn(float $v): string => number_format($v, 1);
$viewToken  = urlencode((string) $event['view_token']);
$activity   = (string) ($pmsData['title'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PMS Points - <?= $h($title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.pp { --ink:#1e293b; --muted:#64748b; --line:#e2e8f0; --soft:#f8fafc; --ok:#15803d; --ok-bg:#ecfdf3; --warn:#a16207; --warn-bg:#fffbeb; --warn-line:#fde68a; --brand:var(--kvb-green-dark,#14532d); color:var(--ink); font-size:.9rem; line-height:1.5; }
.pp *, .pp *::before, .pp *::after { box-sizing:border-box; }
.pp-head { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap; margin-bottom:1.25rem; }
.pp-head h1 { margin:0; font-size:1.5rem; font-weight:700; letter-spacing:-.01em; }
.pp-head .pp-lead { margin:.25rem 0 0; color:#334155; font-size:.875rem; max-width:62ch; }
.pp-card { background:#fff; border:1px solid var(--line); border-radius:12px; box-shadow:0 1px 2px rgba(15,23,42,.04); margin-bottom:1rem; overflow:hidden; }
.pp-card-h { display:flex; justify-content:space-between; align-items:center; gap:.75rem; flex-wrap:wrap; padding:.85rem 1.1rem; border-bottom:1px solid var(--line); background:var(--soft); }
.pp-card-h h2 { margin:0; font-size:.78rem; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
.pp-card-b { padding:1rem 1.1rem; }
.pp-banner { display:flex; gap:.85rem; align-items:flex-start; padding:1rem 1.1rem; border-radius:12px; border:1px solid; margin-bottom:1rem; }
.pp-banner.ok { background:var(--ok-bg); border-color:#bbf7d0; }
.pp-banner.warn { background:var(--warn-bg); border-color:var(--warn-line); }
.pp-banner strong { display:block; font-size:.95rem; }
.pp-banner span.t { color:var(--muted); font-size:.85rem; }
.pp-ico { flex:none; width:22px; height:22px; margin-top:1px; }
.pp-banner.ok .pp-ico, .pp-row.ok .pp-ico { color:var(--ok); }
.pp-banner.warn .pp-ico, .pp-row.warn .pp-ico { color:var(--warn); }
.pp-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:.75rem; margin-bottom:1rem; }
.pp-stat { background:#fff; border:1px solid var(--line); border-radius:12px; padding:.8rem 1rem; }
.pp-stat .k { font-size:.7rem; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
.pp-stat .v { margin-top:.15rem; font-size:1.45rem; font-weight:700; font-variant-numeric:tabular-nums; letter-spacing:-.01em; }
.pp-stat .v.mono { font-size:.95rem; font-weight:600; line-height:1.9; }
.pp-mono { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; }
.pp-ctx { margin:0 0 1rem; color:#334155; font-size:.85rem; }
.pp-ctx b { color:var(--ink); font-weight:600; }
.pp-list { list-style:none; margin:0; padding:0; }
.pp-row { display:flex; gap:.7rem; align-items:flex-start; padding:.65rem 1.1rem; border-bottom:1px solid var(--line); }
.pp-row:last-child { border-bottom:0; }
.pp-row .pp-ico { width:18px; height:18px; margin-top:2px; }
.pp-sum { font-size:.8rem; font-weight:500; color:var(--muted); text-transform:none; letter-spacing:0; }
.pp-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.5rem .95rem; border-radius:8px; border:1px solid var(--line); background:#fff; color:var(--ink); font:inherit; font-size:.85rem; font-weight:600; text-decoration:none; cursor:pointer; }
.pp-btn:hover { background:var(--soft); border-color:#cbd5e1; }
.pp-btn.primary { background:var(--brand); border-color:var(--brand); color:#fff; }
.pp-btn.primary:hover { filter:brightness(1.1); }
.pp-btn[disabled] { opacity:.55; cursor:not-allowed; }
.pp-actions { display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; }
.pp-note { color:var(--muted); font-size:.8rem; }
.pp-search { width:min(280px,100%); padding:.45rem .7rem; border:1px solid var(--line); border-radius:8px; font:inherit; font-size:.85rem; background:#fff; color:var(--ink); }
.pp-search:focus { outline:2px solid var(--brand); outline-offset:1px; border-color:transparent; }
.pp-scroll { max-height:620px; overflow:auto; }
.pp-table { width:100%; border-collapse:separate; border-spacing:0; font-size:.86rem; }
.pp-table th { position:sticky; top:0; z-index:1; background:var(--soft); text-align:left; padding:.6rem 1rem; font-size:.7rem; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); border-bottom:1px solid var(--line); white-space:nowrap; }
.pp-table td { padding:.55rem 1rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.pp-table tbody tr:hover td { background:#f8fafc; }
.pp-table .n { text-align:right; font-variant-numeric:tabular-nums; }
.pp-table .idx { width:3.2rem; color:var(--muted); font-variant-numeric:tabular-nums; }
.pp-table td.pts { font-weight:600; }
.pp-table tfoot td { position:sticky; bottom:0; background:var(--soft); border-top:1px solid var(--line); border-bottom:0; font-weight:700; font-variant-numeric:tabular-nums; }
.pp-badge { display:inline-block; padding:.1rem .5rem; border-radius:999px; background:var(--warn-bg); border:1px solid var(--warn-line); color:var(--warn); font-size:.72rem; font-weight:600; white-space:nowrap; }
.pp-empty { padding:2rem 1.1rem; text-align:center; color:var(--muted); }
.pp-foot { display:flex; justify-content:space-between; gap:.75rem; flex-wrap:wrap; padding:.6rem 1.1rem; border-top:1px solid var(--line); color:var(--muted); font-size:.78rem; }
.pp details { padding:.85rem 1.1rem; }
.pp summary { cursor:pointer; font-weight:600; font-size:.85rem; }
.pp pre { margin:.75rem 0 0; padding:.85rem 1rem; background:#0f172a; color:#e2e8f0; border-radius:8px; overflow:auto; font-size:.78rem; line-height:1.55; }
@media (max-width:640px) { .pp-head h1 { font-size:1.25rem; } .pp-stat:nth-child(n+4) { grid-column:1 / -1; } .pp-table th, .pp-table td { padding:.5rem .65rem; } }
@media print { .pp-actions, .pp-search, .pp details, .app-page-hero a { display:none !important; } .pp-scroll { max-height:none; overflow:visible; } .pp-card, .pp-stat { box-shadow:none; break-inside:avoid; } }
</style>
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
<div class="pp">

    <div class="pp-head">
        <div>
            <h1>PMS Points</h1>
            <p class="pp-lead">Preview of the CPD points that would be submitted to KVB for this event. This page is read-only and sends nothing.</p>
        </div>
        <a href="/events/view.php?token=<?= $viewToken ?>" class="pp-btn">&larr; Back to event</a>
    </div>

    <p class="pp-ctx">
        <b><?= $h($title) ?></b><?= $provider !== '' ? ' &middot; ' . $h($provider) : '' ?>
        <?php if ($activity !== '' && $activity !== $title): ?><br>PMS title: <?= $h($activity) ?><?php endif; ?>
    </p>

    <?php if ($attention === 0): ?>
    <div class="pp-banner ok" role="status">
        <svg class="pp-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m8 12.5 3 3 5-6"/></svg>
        <div><strong>All <?= count($checks) ?> checks passed</strong><span class="t">This event is ready for submission once sending is switched on.</span></div>
    </div>
    <?php else: ?>
    <div class="pp-banner warn" role="status">
        <svg class="pp-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>
        <div><strong><?= $attention ?> of <?= count($checks) ?> checks need attention</strong><span class="t">Review the items below before this event's points are submitted.</span></div>
    </div>
    <?php endif; ?>

    <div class="pp-stats">
        <div class="pp-stat"><div class="k">Practitioners</div><div class="v"><?= number_format(count($rows)) ?></div></div>
        <div class="pp-stat"><div class="k">CPD points</div><div class="v"><?= $h($fmtPoints($totalPts)) ?></div></div>
        <div class="pp-stat"><div class="k">CPD hours</div><div class="v"><?= $h($fmtHours($totalHrs)) ?></div></div>
        <div class="pp-stat"><div class="k">Activity number</div><div class="v mono pp-mono"><?= $h($code !== '' ? $code : '—') ?></div></div>
        <div class="pp-stat"><div class="k">Date of activity</div><div class="v mono pp-mono"><?= $h($endDate) ?></div></div>
    </div>

    <section class="pp-card" aria-labelledby="pp-checks">
        <div class="pp-card-h">
            <h2 id="pp-checks">Readiness checks</h2>
            <span class="pp-sum"><?= $passed ?> passed<?= $attention > 0 ? ' &middot; ' . $attention . ' to review' : '' ?></span>
        </div>
        <ul class="pp-list">
            <?php foreach ($checks as [$level, $text]): ?>
            <li class="pp-row <?= $level === 'ok' ? 'ok' : 'warn' ?>">
                <?php if ($level === 'ok'): ?>
                <svg class="pp-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-label="Passed"><circle cx="12" cy="12" r="10"/><path d="m8 12.5 3 3 5-6"/></svg>
                <?php else: ?>
                <svg class="pp-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-label="Needs attention"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>
                <?php endif; ?>
                <span><?= $h($text) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="pp-card" aria-labelledby="pp-records">
        <div class="pp-card-h">
            <h2 id="pp-records">Records <span class="pp-sum">(<span id="pp-shown"><?= count($rows) ?></span> of <?= count($rows) ?>)</span></h2>
            <div class="pp-actions">
                <?php if ($rows): ?><input type="search" id="pp-q" class="pp-search" placeholder="Search name or reg no" aria-label="Filter records" autocomplete="off"><?php endif; ?>
                <button type="button" class="pp-btn" disabled title="Sending is not switched on yet">Send to KVB</button>
            </div>
        </div>

        <?php if (!$rows): ?>
            <div class="pp-empty">No points have been awarded for this event yet.</div>
        <?php else: ?>
        <div class="pp-scroll">
        <table class="pp-table">
            <thead>
                <tr><th class="idx">#</th><th>Reg No</th><th>Name</th><th class="n">Hours</th><th class="n">Points</th><th>Flags</th></tr>
            </thead>
            <tbody id="pp-body">
                <?php foreach ($rows as $i => $r): ?>
                <tr data-s="<?= $h(strtolower($r['reg_no'] . ' ' . $r['name'])) ?>">
                    <td class="idx"><?= $i + 1 ?></td>
                    <td class="pp-mono"><?= $h($r['reg_no']) ?></td>
                    <td><?= $h($r['name']) ?></td>
                    <td class="n"><?= $h($fmtHours((float) $r['hours'])) ?></td>
                    <td class="n pts"><?= $h($fmtPoints((float) $r['points'])) ?></td>
                    <td>
                        <?php if ($r['hours'] <= 0): ?><span class="pp-badge">No session hours</span><?php endif; ?>
                        <?php if (fmod((float) $r['points'], 1.0) !== 0.0): ?><span class="pp-badge">Fractional points</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td></td><td></td><td>Total (<?= number_format(count($rows)) ?> practitioners)</td><td class="n"><?= $h($fmtHours($totalHrs)) ?></td><td class="n"><?= $h($fmtPoints($totalPts)) ?></td><td></td></tr>
            </tfoot>
        </table>
        <div class="pp-empty" id="pp-none" hidden>No records match your search.</div>
        </div>
        <div class="pp-foot">
            <span>Sending to KVB is not switched on yet.</span>
        </div>
        <?php endif; ?>
    </section>

</div>
</div>
<script>
(function () {
    var q = document.getElementById('pp-q');
    if (!q) { return; }
    var rows = Array.prototype.slice.call(document.querySelectorAll('#pp-body tr'));
    var shown = document.getElementById('pp-shown');
    var none = document.getElementById('pp-none');
    q.addEventListener('input', function () {
        var term = q.value.trim().toLowerCase();
        var n = 0;
        rows.forEach(function (tr) {
            var hit = term === '' || tr.getAttribute('data-s').indexOf(term) !== -1;
            tr.hidden = !hit;
            if (hit) { n++; }
        });
        shown.textContent = n;
        none.hidden = n !== 0;
    });
})();
</script>
</body>
</html>
