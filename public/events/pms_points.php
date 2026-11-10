<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Database;
use VetBoard\Event;
use VetBoard\PmsClient;
use VetBoard\PmsPointsSender;

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
// The nightly copy of PMS activities answers this without a live call; PMS is asked only if the copy has no match.
$stmt = $pdo->prepare('SELECT title, activity_type, provider_name AS name_of_provider, schedules FROM pms_activities WHERE upper(trim(activity_number)) = upper(:c) AND removed_at IS NULL LIMIT 1');
$stmt->execute([':c' => $code]);
$local = $code !== '' ? $stmt->fetch() : false;

if ($local) {
    $local['schedules'] = json_decode((string) $local['schedules'], true) ?: [];
    $pms     = ['status' => PmsClient::FOUND, 'data' => $local];
} else {
    $pms     = $code !== '' ? (new PmsClient(null, null, 6, 3))->activity($code) : null;
}
$pmsData = $pms !== null && $pms['status'] === PmsClient::FOUND ? $pms['data'] : [];

$num = static fn(float $v) => fmod($v, 1.0) === 0.0 ? (int) $v : round($v, 1);

$records    = [];
$rows       = [];
$totalPts   = 0.0;
$totalHrs   = 0.0;

foreach ($people as $p) {
    $points = (float) $p['points'];
    $hours  = (float) ($hoursBy[$p['reg_no']] ?? 0);

    $totalPts += $points;
    $totalHrs += $hours;

    $record = [
        'record_sn'           => 'CPD-' . $eventId . '-' . $p['reg_no'],
        'kvb_reg_number'      => $p['reg_no'],
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


// ---- Matching against PMS, sending and the ledger of what was sent ----
$sender   = new PmsPointsSender();
$canSend  = $sender->enabled();
$activityOk = $formatOk && $pms !== null && $pms['status'] === PmsClient::FOUND;
$regList  = array_column($rows, 'reg_no');

$flash = $_SESSION['pp_flash'] ?? null;
unset($_SESSION['pp_flash']);
$self = '/events/pms_points.php?token=' . urlencode((string) $event['view_token']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if (!Auth::validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['pp_flash'] = ['warn', 'The page expired. Please try again.'];
        header('Location: ' . $self);
        exit;
    }

    if ($act === 'resolve' || $act === 'recheck') {
        header('Content-Type: application/json');
        if ($act === 'recheck') {
            $sender->recheck($regList);
        }
        $left = $sender->resolve($regList);
        echo json_encode(['left' => $left, 'total' => count($regList)]);
        exit;
    } elseif ($act === 'release') {
        $n = $sender->releaseUncertain($eventId);
        $_SESSION['pp_flash'] = ['ok', $n . ' record(s) released for retry.'];
    } elseif ($act === 'send') {
        $res  = $sender->resolutions($regList);
        $done = $sender->sentFor($eventId);
        $items = [];
        foreach ($rows as $r) {
            $x = $res[$r['reg_no']] ?? null;
            if ($x && $x['status'] === 'found' && (!isset($done[$r['reg_no']]) || $done[$r['reg_no']]['status'] === 'failed')) {
                $items[] = ['reg_no' => $r['reg_no'], 'pms_reg_no' => (string) $x['pms_reg_no'], 'points' => (float) $r['points'], 'hours' => (float) $r['hours']];
            }
        }

        if (!$canSend) {
            $_SESSION['pp_flash'] = ['warn', 'Sending to PMS is switched off.'];
        } elseif (!$activityOk) {
            $_SESSION['pp_flash'] = ['warn', 'The activity number has not been confirmed with PMS, so nothing was sent.'];
        } elseif (empty($_POST['confirm'])) {
            $_SESSION['pp_flash'] = ['warn', 'Tick the confirmation box before sending.'];
        } elseif (!$items) {
            $_SESSION['pp_flash'] = ['warn', 'There is nothing left to send.'];
        } else {
            $out = $sender->send($eventId, $code, $endDate, (string) ($pmsData['title'] ?? $title), $provider, $items, (int) ($_SESSION['user_id'] ?? 0) ?: null);
            $_SESSION['pp_flash'] = [($out['failed'] + $out['uncertain']) === 0 ? 'ok' : 'warn',
                'Sent ' . $out['sent'] . ', rejected ' . $out['failed'] . ', outcome unclear ' . $out['uncertain'] . '.' . ($out['message'] !== '' ? ' PMS said: ' . $out['message'] : '')];
        }
    }
    header('Location: ' . $self);
    exit;
}

$res  = $sender->resolutions($regList);
$done = $sender->sentFor($eventId);
$cnt  = ['ready' => 0, 'sent' => 0, 'excluded' => 0, 'unchecked' => 0, 'held' => 0, 'failed' => 0];

foreach ($rows as &$r) {
    $x = $res[$r['reg_no']] ?? null;
    $d = $done[$r['reg_no']] ?? null;
    $r['pms_reg'] = $x['pms_reg_no'] ?? null;
    $r['detail']  = $x['detail'] ?? null;

    if ($d && $d['status'] === 'sent') {
        $r['state'] = 'sent';  $cnt['sent']++;
    } elseif ($d && in_array($d['status'], ['pending', 'uncertain'], true)) {
        $r['state'] = 'held';  $cnt['held']++;
    } elseif (!$x) {
        $r['state'] = 'unchecked'; $cnt['unchecked']++;
    } elseif ($x['status'] === 'not_found') {
        $r['state'] = 'excluded'; $cnt['excluded']++;
    } else {
        $r['state'] = 'ready'; $cnt['ready']++;
        if ($d && $d['status'] === 'failed') {
            $r['note'] = (string) $d['response'];
            $cnt['failed']++;
        }
    }
}
unset($r);

if (($_GET['export'] ?? '') === 'excluded') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="pms-not-matched-' . preg_replace('/[^A-Za-z0-9_-]/', '', $code) . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Reg No', 'Name', 'Hours', 'Points', 'Reason']);
    foreach ($rows as $r) {
        if ($r['state'] === 'excluded') {
            fputcsv($o, [$r['reg_no'], $r['name'], $r['hours'], $r['points'], 'Not found in PMS']);
        }
    }
    fclose($o);
    exit;
}

$sendBlockers = [];
if (!$canSend) { $sendBlockers[] = 'Sending to PMS is switched off.'; }
if (!$activityOk) { $sendBlockers[] = 'The activity number is missing or not confirmed by PMS.'; }
if ($today < $endDate) { $sendBlockers[] = 'The event has not ended yet (' . $endDate . ').'; }
if ($cnt['unchecked'] > 0) { $sendBlockers[] = 'Check the practitioners against PMS first.'; }
if ($cnt['ready'] === 0) { $sendBlockers[] = 'There is nothing left to send.'; }

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$fmtPoints = static fn(float $v): string => number_format($v, fmod($v, 1.0) === 0.0 ? 0 : 1);
$fmtHours  = static fn(float $v): string => number_format($v, 1);
$viewToken = urlencode((string) $event['view_token']);
$activity  = (string) ($pmsData['title'] ?? '');
$total     = count($rows);
$readyPts  = array_sum(array_map(static fn($r) => $r['state'] === 'ready' ? (float) $r['points'] : 0.0, $rows));
$checked   = $total - $cnt['unchecked'];
$labels    = ['sent' => 'Sent', 'held' => 'Outcome unclear', 'unchecked' => 'Not checked', 'excluded' => 'Not in PMS', 'ready' => 'Ready'];

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/index.php'],
    ['label' => 'Events', 'url' => '/events/index.php'],
    ['label' => $title, 'url' => '/events/view.php?token=' . $viewToken],
    ['label' => 'PMS Points', 'url' => null],
];

$user = Auth::user();
require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PMS Points - <?= $h($title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/style.css') ?>">
<style>
.pp { --ink:#1e293b; --muted:#64748b; --line:#e2e8f0; --soft:#f8fafc; --ok:#15803d; --ok-bg:#ecfdf3; --warn:#a16207; --warn-bg:#fffbeb; --warn-line:#fde68a; --bad:#b91c1c; --bad-bg:#fef2f2; --brand:var(--kvb-green-dark,#14532d); color:var(--ink); font-size:.9rem; line-height:1.5; max-width:1100px; }
.pp *, .pp *::before, .pp *::after { box-sizing:border-box; }
.pp-head { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap; margin-bottom:1.25rem; }
.pp-head h1 { margin:0; font-size:1.5rem; font-weight:700; letter-spacing:-.01em; }
.pp-sub { margin:.2rem 0 0; color:var(--muted); font-size:.85rem; }
.pp-sub b { color:var(--ink); font-weight:600; }
.pp-card { background:#fff; border:1px solid var(--line); border-radius:12px; margin-bottom:1rem; overflow:hidden; }
.pp-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.5rem .95rem; border-radius:8px; border:1px solid var(--line); background:#fff; color:var(--ink); font:inherit; font-size:.85rem; font-weight:600; text-decoration:none; cursor:pointer; }
.pp-btn:hover { background:var(--soft); }
.pp-btn.primary { background:var(--brand); border-color:var(--brand); color:#fff; }
.pp-btn.primary:hover { filter:brightness(1.1); }
.pp-btn[disabled] { opacity:.5; cursor:not-allowed; }
.pp-note { color:var(--muted); font-size:.8rem; }
.pp-flash { padding:.75rem 1rem; border-radius:10px; margin-bottom:1rem; border:1px solid var(--line); }
.pp-flash.ok { background:var(--ok-bg); color:var(--ok); } .pp-flash.warn { background:var(--warn-bg); border-color:var(--warn-line); color:var(--warn); }
.pp-steps { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); }
.pp-step { padding:1.1rem 1.25rem; border-right:1px solid var(--line); display:flex; flex-direction:column; gap:.5rem; }
.pp-step:last-child { border-right:0; }
.pp-step .k { font-size:.7rem; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
.pp-step .big { font-size:1.35rem; font-weight:700; font-variant-numeric:tabular-nums; line-height:1.2; }
.pp-step .actions { margin-top:auto; display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
.pp-bar { height:6px; background:#e2e8f0; border-radius:99px; overflow:hidden; } .pp-bar > i { display:block; height:100%; background:var(--brand); width:0; transition:width .2s; }
.pp-confirm { display:flex; gap:.5rem; align-items:flex-start; font-size:.8rem; color:#334155; }
.pp-confirm { font-weight:400; } .pp-confirm span { font-weight:400; } .pp-confirm input { margin-top:.2rem; width:auto; flex:none; }
.pp-blockers { margin:0; padding:0; list-style:none; color:var(--warn); font-size:.8rem; }
.pp-bar-t { display:flex; gap:.75rem; flex-wrap:wrap; align-items:center; justify-content:space-between; padding:.8rem 1.1rem; border-bottom:1px solid var(--line); background:var(--soft); }
.pp-chips { display:flex; gap:.4rem; flex-wrap:wrap; }
.pp-chip { padding:.3rem .7rem; border-radius:99px; border:1px solid var(--line); background:#fff; font:inherit; font-size:.78rem; font-weight:600; color:var(--muted); cursor:pointer; }
.pp-chip.on { background:var(--brand); border-color:var(--brand); color:#fff; }
.pp-search { width:min(260px,100%); padding:.42rem .7rem; border:1px solid var(--line); border-radius:8px; font:inherit; font-size:.85rem; }
.pp-scroll { max-height:560px; overflow:auto; }
.pp-table { width:100%; border-collapse:separate; border-spacing:0; font-size:.86rem; }
.pp-table th { position:sticky; top:0; background:#fff; text-align:left; padding:.55rem 1rem; font-size:.7rem; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); border-bottom:1px solid var(--line); }
.pp-table td { padding:.5rem 1rem; border-bottom:1px solid #f1f5f9; }
.pp-table .n { text-align:right; font-variant-numeric:tabular-nums; }
.pp-mono { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; }
.pp-badge { display:inline-block; padding:.1rem .55rem; border-radius:99px; font-size:.72rem; font-weight:600; background:var(--warn-bg); color:var(--warn); }
.pp-badge.st-ready, .pp-badge.st-sent { background:var(--ok-bg); color:var(--ok); } .pp-badge.st-excluded { background:var(--bad-bg); color:var(--bad); }
.pp-empty { padding:2rem; text-align:center; color:var(--muted); }
@media (max-width:760px) { .pp-steps { grid-template-columns:1fr; } .pp-step { border-right:0; border-bottom:1px solid var(--line); } .pp-table th:nth-child(3), .pp-table td:nth-child(3) { display:none; } }
@media print { .pp-steps, .pp-search, .pp-chips, .pp-head a { display:none; } .pp-scroll { max-height:none; } }
</style>
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
<div class="pp">

    <div class="pp-head">
        <div>
            <h1>PMS Points</h1>
            <p class="pp-sub"><b><?= $h($title) ?></b> &middot; <span class="pp-mono"><?= $h($code !== '' ? $code : 'no activity number') ?></span> &middot; <?= $h(date('d M Y', strtotime($endDate) ?: time())) ?><?= $provider !== '' ? ' &middot; ' . $h($provider) : '' ?></p>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <a href="/events/view.php?token=<?= $viewToken ?>" class="pp-btn">Back to event</a>
        </div>
    </div>

    <?php if ($flash): ?><div class="pp-flash <?= $h($flash[0]) ?>" role="status"><?= $h($flash[1]) ?></div><?php endif; ?>

    <section class="pp-card" aria-label="Steps">
        <div class="pp-steps">
            <div class="pp-step">
                <span class="k">1 &middot; Check with PMS</span>
                <span class="big" id="pp-checked"><?= $checked ?> of <?= $total ?></span>
                <div class="pp-bar"><i id="pp-prog" style="width:<?= $total ? round($checked / $total * 100) : 0 ?>%"></i></div>
                <div class="actions">
                    <?php if ($cnt['unchecked'] > 0): ?>
                    <button type="button" class="pp-btn primary" id="pp-run" data-act="resolve">Check <?= $cnt['unchecked'] ?> now</button>
                    <?php elseif ($cnt['excluded'] > 0): ?>
                    <button type="button" class="pp-btn" id="pp-run" data-act="recheck">Check the <?= $cnt['excluded'] ?> not found again</button>
                    <?php else: ?><span class="pp-note">Everyone has been checked.</span><?php endif; ?>
                    <span class="pp-note" id="pp-msg"></span>
                </div>
            </div>
            <div class="pp-step">
                <span class="k">2 &middot; Review</span>
                <span class="big"><?= $cnt['ready'] ?> ready</span>
                <span class="pp-note"><?= $cnt['sent'] ?> already sent &middot; <?= $cnt['excluded'] ?> not in PMS<?= $cnt['held'] ? ' &middot; ' . $cnt['held'] . ' unclear' : '' ?></span>
                <?php if ($flaggedPending > 0): ?><span class="pp-note" style="color:var(--warn)"><?= $flaggedPending ?> flagged sign-in(s) still unreviewed, not included</span><?php endif; ?>
                <div class="actions">
                    <?php if ($cnt['excluded'] > 0): ?><a class="pp-btn" href="<?= $h($self) ?>&amp;export=excluded">Download not-in-PMS list</a><?php endif; ?>
                    <?php if ($cnt['held'] > 0): ?>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= $h(Auth::csrfToken()) ?>"><input type="hidden" name="action" value="release">
                    <button class="pp-btn" onclick="return confirm('Only do this after checking in PMS that these points were not credited. Continue?')">Release <?= $cnt['held'] ?> unclear</button></form>
                    <?php endif; ?>
                </div>
            </div>
            <form method="post" class="pp-step">
                <span class="k">3 &middot; Send</span>
                <span class="big"><?= $fmtPoints($readyPts) ?> points</span>
                <input type="hidden" name="csrf_token" value="<?= $h(Auth::csrfToken()) ?>"><input type="hidden" name="action" value="send">
                <label class="pp-confirm"><input type="checkbox" name="confirm" value="1"<?= $sendBlockers ? ' disabled' : '' ?>> <span>I have reviewed the list. Send <?= $cnt['ready'] ?> record(s) to PMS. This cannot be undone from here.</span></label>
                <div class="actions"><button class="pp-btn primary"<?= $sendBlockers ? ' disabled' : '' ?>>Send to PMS</button></div>
                <?php if ($sendBlockers): ?><ul class="pp-blockers"><?php foreach ($sendBlockers as $b): ?><li><?= $h($b) ?></li><?php endforeach; ?></ul><?php endif; ?>
            </form>
        </div>
    </section>


    <section class="pp-card" aria-label="Records">
        <?php if (!$rows): ?>
        <div class="pp-empty">No points have been awarded for this event yet.</div>
        <?php else: ?>
        <div class="pp-bar-t">
            <div class="pp-chips" id="pp-chips">
                <button type="button" class="pp-chip on" data-f="all">All <?= $total ?></button>
                <?php foreach (['ready', 'sent', 'excluded', 'unchecked', 'held'] as $k): if ($k === 'ready' || $cnt[$k] > 0): ?>
                <button type="button" class="pp-chip" data-f="<?= $k ?>"><?= $h($labels[$k]) ?> <?= $cnt[$k] ?></button>
                <?php endif; endforeach; ?>
            </div>
            <input type="search" id="pp-q" class="pp-search" placeholder="Search name or reg no" aria-label="Filter records" autocomplete="off">
        </div>
        <div class="pp-scroll">
        <table class="pp-table">
            <thead><tr><th>Reg No</th><th>Name</th><th class="n">Hours</th><th class="n">Points</th><th>Status</th></tr></thead>
            <tbody id="pp-body">
            <?php foreach ($rows as $r): ?>
            <tr data-st="<?= $h($r['state']) ?>" data-s="<?= $h(strtolower($r['reg_no'] . ' ' . $r['name'])) ?>">
                <td class="pp-mono"><?= $h($r['reg_no']) ?></td>
                <td><?= $h($r['name']) ?></td>
                <td class="n"><?= $h($fmtHours((float) $r['hours'])) ?></td>
                <td class="n"><b><?= $h($fmtPoints((float) $r['points'])) ?></b></td>
                <td><span class="pp-badge st-<?= $h($r['state']) ?>"><?= $h($labels[$r['state']]) ?></span><?php
                    if ($r['pms_reg'] && $r['pms_reg'] !== $r['reg_no']) { echo ' <span class="pp-note">as ' . $h($r['pms_reg']) . '</span>'; }
                    if ($r['state'] === 'excluded' && $r['detail']) { echo ' <span class="pp-note">' . $h($r['detail']) . '</span>'; }
                    if (!empty($r['note'])) { echo ' <span class="pp-note">last try: ' . $h($r['note']) . '</span>'; }
                ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="pp-empty" id="pp-none" hidden>No records match.</div>
        </div>
        <?php endif; ?>
    </section>

</div>
</div>
<script>
(function () {
    var csrf = <?= json_encode(Auth::csrfToken()) ?>;
    var run = document.getElementById('pp-run');
    if (run) {
        run.addEventListener('click', function () {
            var msg = document.getElementById('pp-msg'), prog = document.getElementById('pp-prog'), lab = document.getElementById('pp-checked');
            var act = run.getAttribute('data-act'), first = true, stalls = 0, lastLeft = -1;
            run.disabled = true;
            function step() {
                fetch(location.href, { method: 'POST', body: new URLSearchParams({ csrf_token: csrf, action: first ? act : 'resolve' }) })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        first = false;
                        var done = d.total - d.left;
                        prog.style.width = (d.total ? Math.round(done / d.total * 100) : 100) + '%';
                        lab.textContent = done + ' of ' + d.total;
                        stalls = d.left === lastLeft ? stalls + 1 : 0; lastLeft = d.left;
                        if (d.left === 0) { location.reload(); }
                        else if (stalls >= 3) { msg.textContent = 'PMS is not answering for ' + d.left + ' practitioner(s). Try again in a few minutes.'; run.disabled = false; }
                        else { step(); }
                    })
                    .catch(function () { msg.textContent = 'Stopped. Reload the page and try again.'; run.disabled = false; });
            }
            msg.textContent = 'Checking…';
            step();
        });
    }

    var q = document.getElementById('pp-q');
    if (!q) { return; }
    var rows = Array.prototype.slice.call(document.querySelectorAll('#pp-body tr')), none = document.getElementById('pp-none'), f = 'all';
    function apply() {
        var term = q.value.trim().toLowerCase(), n = 0;
        rows.forEach(function (tr) {
            var hit = (f === 'all' || tr.getAttribute('data-st') === f) && (term === '' || tr.getAttribute('data-s').indexOf(term) !== -1);
            tr.hidden = !hit; if (hit) { n++; }
        });
        none.hidden = n !== 0;
    }
    q.addEventListener('input', apply);
    document.getElementById('pp-chips').addEventListener('click', function (e) {
        var b = e.target.closest('.pp-chip'); if (!b) { return; }
        f = b.getAttribute('data-f');
        Array.prototype.forEach.call(document.querySelectorAll('.pp-chip'), function (c) { c.classList.toggle('on', c === b); });
        apply();
    });
})();
</script>
</body>
</html>
