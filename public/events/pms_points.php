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

if ($fractional > 0) {
    $checks[] = ['warn', $fractional . ' record(s) have fractional points. Confirm with KVB that decimals are accepted.'];
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

    if ($act === 'resolve') {
        $left = $sender->resolve($regList);
        $_SESSION['pp_flash'] = $left === 0
            ? ['ok', 'All practitioners have been checked against PMS.']
            : ['warn', $left . ' practitioner(s) are still unchecked. Press the button again to continue.'];
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
if ($cnt['unchecked'] > 0) { $sendBlockers[] = 'Check the practitioners against PMS first.'; }
if ($cnt['ready'] === 0) { $sendBlockers[] = 'There is nothing left to send.'; }

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
$normTitle = static fn(string $t): string => (string) preg_replace('/[^a-z0-9]/', '', strtolower($t));
$hasFlags = (bool) array_filter($rows, static fn($r) => fmod((float) $r['points'], 1.0) !== 0.0);
$pmsTitleDiffers = $activity !== '' && $normTitle($activity) !== $normTitle((string) $title);
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
.pp { --ink:#1e293b; --muted:#64748b; --line:#e2e8f0; --soft:#f8fafc; --ok:#15803d; --ok-bg:#ecfdf3; --warn:#a16207; --warn-bg:#fffbeb; --warn-line:#fde68a; --brand:var(--kvb-green-dark,#14532d); color:var(--ink); font-size:.9rem; line-height:1.5; }
.pp *, .pp *::before, .pp *::after { box-sizing:border-box; }
.pp-head { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap; margin-bottom:1.25rem; }
.pp-head h1 { margin:0; font-size:1.5rem; font-weight:700; letter-spacing:-.01em; }
.pp-head .pp-lead { margin:.25rem 0 0; color:#334155; font-size:.875rem; max-width:62ch; }
.pp-card { background:#fff; border:1px solid var(--line); border-radius:12px; box-shadow:0 1px 2px rgba(15,23,42,.04); margin-bottom:1rem; overflow:hidden; }
.pp-card-h { display:flex; justify-content:space-between; align-items:center; gap:.75rem; flex-wrap:wrap; padding:.85rem 1.1rem; border-bottom:1px solid var(--line); background:var(--soft); }
.pp-card-h h2 { margin:0; font-size:.78rem; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
.pp-card-b { padding:1rem 1.1rem; }
.pp-mono { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; }
.pp-ctx { margin:0 0 1rem; color:#334155; font-size:.85rem; }
.pp-ctx b { color:var(--ink); font-weight:600; }
.pp-list { list-style:none; margin:0; padding:0; }
.pp-row { display:flex; gap:.7rem; align-items:flex-start; padding:.65rem 1.1rem; border-bottom:1px solid var(--line); }
.pp-row:last-child { border-bottom:0; }
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
.pp-summary { padding:0; }
.pp-status { display:flex; gap:.75rem; align-items:center; padding:1rem 1.25rem; border-bottom:1px solid var(--line); }
.pp-status strong { display:block; font-size:.95rem; line-height:1.3; }
.pp-status span { color:var(--muted); font-size:.82rem; }
.pp-status.ok { background:var(--ok-bg); } .pp-status.ok .pp-ico { color:var(--ok); }
.pp-status.warn { background:var(--warn-bg); } .pp-status.warn .pp-ico { color:var(--warn); }
.pp-ico { flex:none; width:22px; height:22px; }
.pp-row .pp-ico { width:18px; height:18px; margin-top:2px; }
.pp-row.ok .pp-ico { color:var(--ok); } .pp-row.warn .pp-ico { color:var(--warn); }
.pp-facts { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); margin:0; }
.pp-facts > div { padding:.9rem 1.25rem; border-right:1px solid var(--line); }
.pp-facts > div:last-child { border-right:0; }
.pp-facts dt { font-size:.7rem; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
.pp-facts dd { margin:.2rem 0 0; font-size:1.2rem; font-weight:700; font-variant-numeric:tabular-nums; letter-spacing:-.01em; overflow-wrap:anywhere; }
.pp-facts dd.pp-mono { font-size:.95rem; font-weight:600; line-height:1.75; }
.pp-ctx { display:flex; flex-wrap:wrap; gap:.25rem 1.5rem; margin:0; padding:.7rem 1.25rem; border-top:1px solid var(--line); background:var(--soft); color:var(--muted); font-size:.8rem; }
.pp-ctx b { color:var(--ink); font-weight:600; }
.pp details.pp-checks { padding:0; }
.pp-facts dd.pp-mono { white-space:nowrap; overflow-wrap:normal; }
.pp-checks summary { display:flex; justify-content:space-between; align-items:center; gap:.75rem; padding:.85rem 1.25rem; cursor:pointer; list-style:none; font-size:.78rem; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
.pp-checks summary::-webkit-details-marker { display:none; }
.pp-checks summary::after { content:""; flex:none; width:7px; height:7px; margin-left:.25rem; border-right:2px solid currentColor; border-bottom:2px solid currentColor; transform:rotate(45deg); transition:transform .15s; }
.pp-checks[open] summary::after { transform:rotate(-135deg); }
.pp-checks summary > span:first-child { flex:1; }
.pp-checks[open] summary { border-bottom:1px solid var(--line); }
.pp-checks summary:focus-visible { outline:2px solid var(--brand); outline-offset:-2px; }
.pp-row { padding:.7rem 1.25rem; }
.pp-flash { padding:.75rem 1rem; border-radius:10px; margin-bottom:1rem; font-size:.87rem; border:1px solid var(--line); }
.pp-flash.ok { background:var(--ok-bg); color:var(--ok); } .pp-flash.warn { background:var(--warn-bg); border-color:var(--warn-line); color:var(--warn); }
.pp-facts-s { grid-template-columns:repeat(4,minmax(0,1fr)); border:1px solid var(--line); border-radius:10px; overflow:hidden; }
.pp-badge.st-sent, .pp-badge.st-ready { background:var(--ok-bg); border-color:#bbf7d0; color:var(--ok); }
.pp-badge.st-excluded { background:#fef2f2; border-color:#fecaca; color:#b91c1c; }
@media (max-width:900px) { .pp-facts { grid-template-columns:repeat(2,minmax(0,1fr)); } .pp-facts > div:nth-child(2) { border-right:0; } .pp-facts > div:nth-child(n+3) { border-top:1px solid var(--line); } }
@media (max-width:640px) { .pp-facts dd.pp-mono { font-size:.82rem; } .pp-head h1 { font-size:1.25rem; } .pp-table th, .pp-table td { padding:.5rem .65rem; } .pp-table .idx { display:none; } .pp-actions { width:100%; } .pp-search { flex:1; min-width:0; width:auto; } .pp-card-h { padding:.8rem 1rem; } }
@media print { .pp-checks:not([open]) .pp-list { display:block; } }
@media print { .pp-actions, .pp-search, .pp details, .app-page-hero a { display:none !important; } .pp-scroll { max-height:none; overflow:visible; } .pp-card { box-shadow:none; break-inside:avoid; } }
</style>
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
<div class="pp">

    <div class="pp-head">
        <div>
            <h1>PMS Points</h1>
            <p class="pp-lead">Points for this event, matched against the KVB register and sent to PMS once you confirm.</p>
        </div>
        <div class="pp-actions"><a href="/reports/pms_unsent.php" class="pp-btn">Not-uploaded report</a><a href="/events/view.php?token=<?= $viewToken ?>" class="pp-btn">Back to event</a></div>
    </div>

    <section class="pp-card pp-summary" aria-label="Summary">
        <div class="pp-status <?= $attention === 0 ? 'ok' : 'warn' ?>" role="status">
            <?php if ($attention === 0): ?>
            <svg class="pp-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m8 12.5 3 3 5-6"/></svg>
            <div><strong>Ready for submission</strong><span>All <?= count($checks) ?> checks passed</span></div>
            <?php else: ?>
            <svg class="pp-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>
            <div><strong>Needs attention</strong><span><?= $attention ?> of <?= count($checks) ?> checks to review</span></div>
            <?php endif; ?>
        </div>
        <dl class="pp-facts">
            <div><dt>Practitioners</dt><dd><?= number_format(count($rows)) ?></dd></div>
            <div><dt>CPD points</dt><dd><?= $h($fmtPoints($totalPts)) ?></dd></div>
            <div><dt>Activity number</dt><dd class="pp-mono"><?= $h($code !== '' ? $code : '—') ?></dd></div>
            <div><dt>Date</dt><dd><?= $h(date('d M Y', strtotime($endDate) ?: time())) ?></dd></div>
        </dl>
        <?php if ($provider !== '' || $pmsTitleDiffers): ?>
        <div class="pp-ctx">
            <?php if ($provider !== ''): ?><span>Provider: <b><?= $h($provider) ?></b></span><?php endif; ?>
            <?php if ($pmsTitleDiffers): ?><span>Listed in PMS as: <b><?= $h($activity) ?></b></span><?php endif; ?>
        </div>
        <?php endif; ?>
    </section>

    <details class="pp-card pp-checks" aria-labelledby="pp-checks"<?= $attention > 0 ? ' open' : '' ?>>
        <summary>
            <span id="pp-checks">Readiness checks</span>
            <span class="pp-sum"><?= $passed ?> passed<?= $attention > 0 ? ' &middot; ' . $attention . ' to review' : '' ?></span>
        </summary>
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
    </details>

    <?php if ($flash): ?>
    <div class="pp-flash <?= $h($flash[0]) ?>" role="status"><?= $h($flash[1]) ?></div>
    <?php endif; ?>

    <section class="pp-card" aria-label="Send to PMS">
        <div class="pp-card-b">
            <dl class="pp-facts pp-facts-s">
                <div><dt>Ready to send</dt><dd><?= $cnt['ready'] ?></dd></div>
                <div><dt>Already sent</dt><dd><?= $cnt['sent'] ?></dd></div>
                <div><dt>Not in PMS</dt><dd><?= $cnt['excluded'] ?></dd></div>
                <div><dt>Unchecked / held</dt><dd><?= $cnt['unchecked'] + $cnt['held'] ?></dd></div>
            </dl>
            <form method="post" class="pp-actions" style="margin-top:1rem">
                <input type="hidden" name="csrf_token" value="<?= $h(Auth::csrfToken()) ?>">
                <?php if ($cnt['unchecked'] > 0): ?>
                <button class="pp-btn" name="action" value="resolve">Check <?= $cnt['unchecked'] ?> against PMS</button>
                <span class="pp-note">About a second each. Press again if it stops part-way.</span>
                <?php endif; ?>
                <?php if ($cnt['excluded'] > 0): ?>
                <a class="pp-btn" href="<?= $h($self) ?>&amp;export=excluded">Download the <?= $cnt['excluded'] ?> not matched</a>
                <?php endif; ?>
                <?php if ($cnt['held'] > 0): ?>
                <button class="pp-btn" name="action" value="release" onclick="return confirm('Only do this after checking in PMS that these points were not credited. Continue?')">Release <?= $cnt['held'] ?> unclear for retry</button>
                <?php endif; ?>
            </form>
            <form method="post" style="margin-top:1rem">
                <input type="hidden" name="csrf_token" value="<?= $h(Auth::csrfToken()) ?>">
                <input type="hidden" name="action" value="send">
                <label class="pp-note" style="display:block;margin-bottom:.6rem"><input type="checkbox" name="confirm" value="1"<?= $sendBlockers ? ' disabled' : '' ?>> I have reviewed this list. Send <?= $cnt['ready'] ?> record(s) to PMS. Points already sent cannot be taken back from here.</label>
                <button class="pp-btn primary" <?= $sendBlockers ? 'disabled' : '' ?>>Send to PMS</button>
                <?php foreach ($sendBlockers as $b): ?><div class="pp-note"><?= $h($b) ?></div><?php endforeach; ?>
            </form>
        </div>
    </section>

    <section class="pp-card" aria-labelledby="pp-records">
        <div class="pp-card-h">
            <h2 id="pp-records">Records <span class="pp-sum">(<span id="pp-shown"><?= count($rows) ?></span> of <?= count($rows) ?>)</span></h2>
            <div class="pp-actions">
                <?php if ($rows): ?><input type="search" id="pp-q" class="pp-search" placeholder="Search name or reg no" aria-label="Filter records" autocomplete="off"><?php endif; ?>
            </div>
        </div>

        <?php if (!$rows): ?>
            <div class="pp-empty">No points have been awarded for this event yet.</div>
        <?php else: ?>
        <div class="pp-scroll">
        <table class="pp-table">
            <thead>
                <tr><th class="idx">#</th><th>Reg No</th><th>Name</th><th class="n">Hours</th><th class="n">Points</th><th>PMS</th></tr>
            </thead>
            <tbody id="pp-body">
                <?php foreach ($rows as $i => $r): ?>
                <tr data-s="<?= $h(strtolower($r['reg_no'] . ' ' . $r['name'])) ?>">
                    <td class="idx"><?= $i + 1 ?></td>
                    <td class="pp-mono"><?= $h($r['reg_no']) ?></td>
                    <td><?= $h($r['name']) ?></td>
                    <td class="n"><?= $h($fmtHours((float) $r['hours'])) ?></td>
                    <td class="n pts"><?= $h($fmtPoints((float) $r['points'])) ?></td>
                    <td><?php
                        $lab = ['sent' => 'Sent', 'held' => 'Outcome unclear', 'unchecked' => 'Not checked', 'excluded' => 'Not in PMS', 'ready' => 'Ready'][$r['state']];
                        echo '<span class="pp-badge st-' . $h($r['state']) . '">' . $h($lab) . '</span>';
                        if ($r['pms_reg'] && $r['pms_reg'] !== $r['reg_no']) { echo ' <span class="pp-note">as ' . $h($r['pms_reg']) . '</span>'; }
                        if (!empty($r['note'])) { echo ' <span class="pp-note">last try: ' . $h($r['note']) . '</span>'; }
                    ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td></td><td></td><td>Total (<?= number_format(count($rows)) ?> practitioners)</td><td class="n"></td><td class="n"><?= $h($fmtPoints($totalPts)) ?></td><td></td></tr>
            </tfoot>
        </table>
        <div class="pp-empty" id="pp-none" hidden>No records match your search.</div>
        </div>
        <div class="pp-foot">
            <span>Nothing is sent until you confirm above.</span>
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
