<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Database;

Auth::startSession();
Auth::requirePermission('manage_events');

// Events are due for upload this many days after they end (the agreed 72 hours).
const PMS_UPLOAD_DUE_DAYS = 3;

$pdo = Database::getInstance()->getPdo();

$stmt = $pdo->prepare(
    "SELECT e.id AS event_id, e.title, e.event_code, e.end_date, e.view_token,
            ppl.reg_no, p.name,
            COALESCE(pts.points, 0) AS points,
            ppl.flagged, ppl.denied,
            s.status AS send_status, s.response AS send_response,
            pr.status AS pms_match
     FROM events e
     JOIN (
         SELECT r.event_id, r.reg_no,
                bool_or(a.flagged AND NOT a.points_denied) AS flagged,
                bool_or(a.points_denied) AS denied
         FROM registrations r JOIN attendance a ON a.registration_id = r.id
         GROUP BY r.event_id, r.reg_no
     ) ppl ON ppl.event_id = e.id
     LEFT JOIN participants p ON p.reg_no = ppl.reg_no
     LEFT JOIN (
         SELECT event_id, reg_no, SUM(points) AS points FROM points_ledger GROUP BY event_id, reg_no
     ) pts ON pts.event_id = e.id AND pts.reg_no = ppl.reg_no
     LEFT JOIN (
         SELECT DISTINCT ON (event_id, reg_no) event_id, reg_no, status, response
         FROM pms_points_sends ORDER BY event_id, reg_no, (status <> 'failed') DESC, id DESC
     ) s ON s.event_id = e.id AND s.reg_no = ppl.reg_no
     LEFT JOIN pms_resolutions pr ON pr.reg_no = ppl.reg_no
     WHERE e.end_date + CAST(:due AS integer) <= CURRENT_DATE
     ORDER BY e.end_date DESC, e.id, p.name NULLS LAST, ppl.reg_no"
);
$stmt->execute([':due' => PMS_UPLOAD_DUE_DAYS]);

$items = [];
$byReason = [];
$events = [];

foreach ($stmt->fetchAll() as $r) {
    if ($r['send_status'] === 'sent') {
        continue;
    }

    $points = (float) $r['points'];
    $code   = trim((string) $r['event_code']);

    if ($points > 0) {
        if ($code === '') {
            $why = 'The event has no PMS activity number';
        } elseif ($r['send_status'] === 'failed') {
            $why = 'Rejected by PMS: ' . ($r['send_response'] ?: 'no reason given');
        } elseif (in_array($r['send_status'], ['pending', 'uncertain'], true)) {
            $why = 'Sent, but PMS gave no clear answer. Check PMS before retrying';
        } elseif ($r['pms_match'] === 'not_found') {
            $why = 'Registration number not found in PMS';
        } elseif ($r['pms_match'] === null) {
            $why = 'Not yet checked against PMS or uploaded';
        } else {
            $why = 'Matched in PMS but not yet uploaded';
        }
    } elseif ($r['denied']) {
        $why = 'Points denied by a reviewer';
    } elseif ($r['flagged']) {
        $why = 'Attendance flagged and still waiting for review';
    } else {
        $why = 'No points earned (attendance requirement not met)';
    }

    $items[]          = ['event_id' => $r['event_id'], 'event' => $r['title'], 'code' => $code, 'end' => $r['end_date'], 'token' => $r['view_token'], 'reg_no' => $r['reg_no'], 'name' => (string) $r['name'], 'points' => $points, 'why' => $why];
    $byReason[$why]   = ($byReason[$why] ?? 0) + 1;
    $events[$r['event_id']] = true;
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="pms-not-uploaded-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Event', 'Activity number', 'Event ended', 'Reg No', 'Name', 'Points', 'Why not uploaded']);
    foreach ($items as $i) {
        fputcsv($o, [$i['event'], $i['code'], $i['end'], $i['reg_no'], $i['name'], $i['points'], $i['why']]);
    }
    fclose($o);
    exit;
}

arsort($byReason);
$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$breadcrumbs = [['label' => 'Dashboard', 'url' => '/index.php'], ['label' => 'Reports', 'url' => '/reports/dashboard.php'], ['label' => 'PMS upload follow-up', 'url' => null]];
$user = Auth::user();
require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PMS upload follow-up</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/style.css') ?>">
<style>
.uf { color:#1e293b; font-size:.9rem; line-height:1.5; }
.uf h1 { margin:0 0 .25rem; font-size:1.5rem; font-weight:700; }
.uf .lead { margin:0 0 1.25rem; color:#475569; max-width:70ch; }
.uf .card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; margin-bottom:1rem; overflow:hidden; }
.uf .card h2 { margin:0; padding:.8rem 1.1rem; font-size:.78rem; letter-spacing:.06em; text-transform:uppercase; color:#64748b; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; gap:1rem; }
.uf table { width:100%; border-collapse:collapse; }
.uf th, .uf td { padding:.55rem 1rem; text-align:left; border-bottom:1px solid #f1f5f9; vertical-align:top; }
.uf th { font-size:.7rem; text-transform:uppercase; letter-spacing:.06em; color:#64748b; }
.uf .n { text-align:right; font-variant-numeric:tabular-nums; }
.uf .btn { display:inline-block; padding:.45rem .9rem; border:1px solid #cbd5e1; border-radius:8px; color:#1e293b; text-decoration:none; font-weight:600; font-size:.82rem; background:#fff; }
.uf .empty { padding:2rem; text-align:center; color:#64748b; }
.uf .mono { font-family:ui-monospace,Menlo,Consolas,monospace; }
.uf .wrap { overflow-x:auto; }
</style>
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
<div class="uf">
    <h1>PMS upload follow-up</h1>
    <p class="lead">Practitioners from events that ended more than <?= PMS_UPLOAD_DUE_DAYS * 24 ?> hours ago whose points have not reached PMS, with the reason for each.</p>

    <?php if (!$items): ?>
    <div class="card"><div class="empty">Nothing outstanding. Every attendee of an event past the upload window has been sent, or earned no points.</div></div>
    <?php else: ?>
    <section class="card">
        <h2><span>Reasons</span><a class="btn" href="?export=csv">Download all <?= count($items) ?> as CSV</a></h2>
        <div class="wrap"><table>
            <thead><tr><th>Why not uploaded</th><th class="n">People</th></tr></thead>
            <tbody>
            <?php foreach ($byReason as $why => $n): ?><tr><td><?= $h($why) ?></td><td class="n"><?= $n ?></td></tr><?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <section class="card">
        <h2><span><?= count($items) ?> people across <?= count($events) ?> event(s)</span></h2>
        <div class="wrap"><table>
            <thead><tr><th>Event</th><th>Reg No</th><th>Name</th><th class="n">Points</th><th>Why</th></tr></thead>
            <tbody>
            <?php foreach ($items as $i): ?>
            <tr>
                <td><a href="/events/pms_points.php?token=<?= $h(urlencode((string) $i['token'])) ?>"><?= $h($i['event']) ?></a><br><span class="mono" style="color:#64748b;font-size:.78rem"><?= $h($i['code'] !== '' ? $i['code'] : 'no activity number') ?> &middot; ended <?= $h($i['end']) ?></span></td>
                <td class="mono"><?= $h($i['reg_no']) ?></td>
                <td><?= $h($i['name']) ?></td>
                <td class="n"><?= $h(rtrim(rtrim(number_format($i['points'], 2), '0'), '.')) ?></td>
                <td><?= $h($i['why']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>
</div>
</div>
</body>
</html>
