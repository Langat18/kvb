<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\CadreRepository;
use VetBoard\Report;

Auth::startSession();
Auth::requirePermission('view_reports');

$breadcrumbs = [['label' => 'Dashboard', 'url' => '/index.php'], ['label' => 'Reports', 'url' => null]];

$user        = Auth::user();
$report      = new Report();
$cadreLabels = (new CadreRepository())->labelMap();

$periods = [
    'year' => 'This year',
    '12m'  => 'Last 12 months',
    '90d'  => 'Last 90 days',
    'all'  => 'All time',
];
$period = array_key_exists($_GET['period'] ?? '', $periods) ? (string) $_GET['period'] : 'year';

$from = null;
$to   = null;
if ($period === 'year') {
    $from = date('Y') . '-01-01';
    $to   = date('Y') . '-12-31';
} elseif ($period === '12m') {
    $from = date('Y-m-d', strtotime('-12 months'));
} elseif ($period === '90d') {
    $from = date('Y-m-d', strtotime('-90 days'));
}

$summary   = $report->periodSummary($from, $to);
$methods   = $report->methodComparison($from, $to);
$quiz      = $report->quizPassRate($from, $to);
$showUp    = $report->showUpByMode($from, $to);
$monthly   = $report->monthlyByMethod($from, $to);
$byCadre   = $report->attendeesByCadre($from, $to);
$modes     = $report->eventsByModeInRange($from, $to);
$perMonth  = $report->eventsByMonth($from, $to);
$providers = $report->topProviders($from, $to, 5);
$noProv    = $report->eventsWithoutProvider($from, $to);
$flagged   = $report->flaggedByEventInRange($from, $to, 5);

$h   = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$num = static fn($n): string => number_format((float) $n, fmod((float) $n, 1.0) === 0.0 ? 0 : 1);
$pct = static fn(int $a, int $b): string => $b > 0 ? round($a / $b * 100) . '%' : '—';

$onsiteSignins  = $methods['onsite']['signins'];
$virtualSignins = $methods['virtual']['signins'];
$signinTotal    = $onsiteSignins + $virtualSignins;
$onsiteShare    = $signinTotal > 0 ? (int) round($onsiteSignins / $signinTotal * 100) : 0;
$virtualShare   = $signinTotal > 0 ? 100 - $onsiteShare : 0;

// Month series for the two-line trend.
$monthLabels = [];
$series      = ['onsite' => [], 'virtual' => []];
foreach ($monthly as $row) {
    $monthLabels[$row['month']] = true;
}
$monthLabels = array_keys($monthLabels);
foreach ($monthLabels as $m) {
    $series['onsite'][$m]  = 0;
    $series['virtual'][$m] = 0;
}
foreach ($monthly as $row) {
    $series[$row['method']][$row['month']] = (int) $row['people'];
}

// Cadre series, biggest first.
$cadreTotals = [];
$cadreSplit  = [];
foreach ($byCadre as $row) {
    $cadreTotals[$row['cadre']] = ($cadreTotals[$row['cadre']] ?? 0) + (int) $row['people'];
    $cadreSplit[$row['cadre']][$row['method']] = (int) $row['people'];
}
arsort($cadreTotals);
$cadreKeys = array_keys($cadreTotals);

require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/style.css') ?>">
<style>
.rp { --ink:#1e293b; --muted:#64748b; --line:#e2e8f0; --soft:#f8fafc; --on:#0f5132; --vi:#2b6cb0; color:var(--ink); font-size:.9rem; line-height:1.5; }
.rp *, .rp *::before, .rp *::after { box-sizing:border-box; }
.rp-head { display:flex; justify-content:space-between; align-items:flex-end; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; }
.rp-head h1 { margin:0; font-size:1.5rem; font-weight:700; letter-spacing:-.01em; }
.rp-lead { margin:.2rem 0 0; color:var(--muted); font-size:.875rem; }
.rp-period select { min-height:38px; padding:.35rem .75rem; border:1px solid #cbd5e1; border-radius:8px; font:inherit; font-size:.85rem; background:#fff; }
.rp-summary { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); background:#fff; border:1px solid var(--line); border-radius:12px; margin-bottom:1rem; }
.rp-summary > div { padding:.8rem 1.1rem; border-right:1px solid var(--line); }
.rp-summary > div:last-child { border-right:0; }
.rp-summary b { display:block; font-size:1.35rem; font-weight:700; font-variant-numeric:tabular-nums; letter-spacing:-.01em; }
.rp-summary span { font-size:.78rem; color:var(--muted); }
.rp-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:1rem; margin-bottom:1rem; }
.rp-card { background:#fff; border:1px solid var(--line); border-radius:12px; box-shadow:0 1px 2px rgba(15,23,42,.04); padding:1.1rem 1.25rem; min-width:0; }
.rp-card.wide { grid-column:1 / -1; }
.rp-card h2 { margin:0 0 .15rem; font-size:.95rem; font-weight:600; }
.rp-card .sub { margin:0 0 .9rem; color:var(--muted); font-size:.8rem; }
.rp-table { width:100%; border-collapse:collapse; font-size:.85rem; }
.rp-table th { text-align:left; padding:.5rem .6rem; font-size:.7rem; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); border-bottom:1px solid var(--line); white-space:nowrap; }
.rp-table td { padding:.6rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.rp-table tr:last-child td { border-bottom:0; }
.rp-table .n { text-align:right; font-variant-numeric:tabular-nums; }
.rp-table th.n { text-align:right; }
.rp-scroll { overflow-x:auto; }
.rp-dot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:.4rem; }
.rp-dot.on { background:var(--on); } .rp-dot.vi { background:var(--vi); }
.rp-split { display:flex; height:10px; border-radius:999px; overflow:hidden; background:#e2e8f0; margin:.2rem 0 .5rem; }
.rp-split i { display:block; height:100%; }
.rp-split .on { background:var(--on); } .rp-split .vi { background:var(--vi); }
.rp-legend { display:flex; justify-content:space-between; gap:1rem; font-size:.8rem; color:var(--muted); margin-bottom:.9rem; flex-wrap:wrap; }
.rp-chart { position:relative; height:260px; }
.rp-empty { padding:1.5rem 0; text-align:center; color:var(--muted); font-size:.85rem; }
.rp-note { margin:.6rem 0 0; color:var(--muted); font-size:.78rem; }
.rp-rank { display:inline-flex; width:22px; height:22px; border-radius:50%; background:#f1f5f9; color:#475569; font-size:.72rem; font-weight:700; align-items:center; justify-content:center; margin-right:.5rem; }
.rp-bar { height:6px; border-radius:999px; background:#e2e8f0; min-width:70px; }
.rp-bar i { display:block; height:100%; border-radius:999px; background:var(--on); }
@media (max-width:900px) { .rp-grid { grid-template-columns:minmax(0,1fr); } }
@media (max-width:560px) { .rp-hide { display:none; } .rp-table th, .rp-table td { padding:.5rem .4rem; } .rp-head h1 { font-size:1.25rem; } .rp-summary { grid-template-columns:minmax(0,1fr); } .rp-summary > div { border-right:0; border-bottom:1px solid var(--line); } .rp-summary > div:last-child { border-bottom:0; } .rp-card { padding:1rem; } }
</style>
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
<div class="rp">

    <div class="rp-head">
        <div>
            <h1>Reports</h1>
            <p class="rp-lead">Events held in the period, and who attended them.</p>
        </div>
        <form class="rp-period" method="get" action="/reports/dashboard.php">
            <label class="sr-only" for="rp-period">Period</label>
            <select id="rp-period" name="period" onchange="this.form.submit()">
                <?php foreach ($periods as $k => $label): ?>
                    <option value="<?= $h($k) ?>" <?= $period === $k ? 'selected' : '' ?>><?= $h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-ghost btn-sm">Apply</button></noscript>
        </form>
    </div>

    <div class="rp-summary">
        <div><b><?= $num($summary['events']) ?></b><span>Events</span></div>
        <div><b><?= $num($summary['practitioners']) ?></b><span>Practitioners who attended</span></div>
        <div><b><?= $num($summary['points']) ?></b><span>CPD points awarded</span></div>
    </div>

    <div class="rp-grid">
        <section class="rp-card">
            <h2>Virtual and on-site</h2>
            <p class="sub">How practitioners joined, across all events in the period.</p>
            <?php if ($signinTotal === 0): ?>
                <div class="rp-empty">No attendance recorded in this period.</div>
            <?php else: ?>
                <div class="rp-split" role="img" aria-label="On-site <?= $onsiteShare ?> percent, virtual <?= $virtualShare ?> percent of sign-ins">
                    <i class="on" style="width:<?= $onsiteShare ?>%"></i><i class="vi" style="width:<?= $virtualShare ?>%"></i>
                </div>
                <div class="rp-legend">
                    <span><span class="rp-dot on"></span>On-site <?= $onsiteShare ?>% of sign-ins</span>
                    <span><span class="rp-dot vi"></span>Virtual <?= $virtualShare ?>% of sign-ins</span>
                </div>
                <div class="rp-scroll">
                <table class="rp-table">
                    <thead><tr><th>Measure</th><th class="n">On-site</th><th class="n">Virtual</th></tr></thead>
                    <tbody>
                        <tr><td>Practitioners</td><td class="n"><?= $num($methods['onsite']['practitioners']) ?></td><td class="n"><?= $num($methods['virtual']['practitioners']) ?></td></tr>
                        <tr><td>Session sign-ins</td><td class="n"><?= $num($onsiteSignins) ?></td><td class="n"><?= $num($virtualSignins) ?></td></tr>
                        <tr><td>CPD points awarded</td><td class="n"><?= $num($methods['onsite']['points']) ?></td><td class="n"><?= $num($methods['virtual']['points']) ?></td></tr>
                        <tr><td>Flagged sign-ins</td><td class="n"><?= $num($methods['onsite']['flagged']) ?></td><td class="n"><?= $num($methods['virtual']['flagged']) ?></td></tr>
                        <tr><td>Quiz pass rate</td><td class="n">—</td><td class="n"><?= $pct($quiz['passed'], $quiz['attempted']) ?></td></tr>
                    </tbody>
                </table>
                </div>
                <p class="rp-note">A practitioner who joined both ways is counted in both columns.</p>
            <?php endif; ?>
        </section>

        <section class="rp-card">
            <h2>Show-up rate</h2>
            <p class="sub">Registered practitioners who attended at least one session. Completed events only.</p>
            <?php if (!$showUp): ?>
                <div class="rp-empty">No completed events with registrations in this period.</div>
            <?php else: ?>
                <div class="rp-scroll">
                <table class="rp-table">
                    <thead><tr><th>Event type</th><th class="n">Registered</th><th class="n">Attended</th><th class="n">Show-up</th></tr></thead>
                    <tbody>
                    <?php foreach ($showUp as $row): ?>
                        <tr>
                            <td><?= $h($row['mode']) ?></td>
                            <td class="n"><?= $num($row['registered']) ?></td>
                            <td class="n"><?= $num($row['attended']) ?></td>
                            <td class="n"><strong><?= $pct((int) $row['attended'], (int) $row['registered']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="rp-card">
            <h2>Attendance over time</h2>
            <p class="sub">Practitioners who attended each month, by how they joined.</p>
            <?php if (!$monthLabels): ?>
                <div class="rp-empty">No attendance recorded in this period.</div>
            <?php else: ?>
                <div class="rp-chart"><canvas id="trendChart" role="img" aria-label="Practitioners per month, on-site and virtual"></canvas></div>
            <?php endif; ?>
        </section>

        <section class="rp-card">
            <h2>Attendance by cadre</h2>
            <p class="sub">Practitioners who attended, split by how they joined.</p>
            <?php if (!$cadreKeys): ?>
                <div class="rp-empty">No attendance recorded in this period.</div>
            <?php else: ?>
                <div class="rp-chart"><canvas id="cadreChart" role="img" aria-label="Attendees by cadre, on-site and virtual"></canvas></div>
            <?php endif; ?>
        </section>

        <section class="rp-card wide">
            <h2>Top providers</h2>
            <p class="sub">The five providers with the most activities in the period, including those coming up.</p>
            <?php if (!$providers): ?>
                <div class="rp-empty">No events with a provider in this period.</div>
            <?php else: ?>
                <?php $maxAct = max(array_map(static fn($p) => (int) $p['activities'], $providers)); ?>
                <div class="rp-scroll">
                <table class="rp-table">
                    <thead><tr><th>Provider</th><th class="n">Activities</th><th class="rp-hide"></th><th class="n">Coming up</th><th class="n">Attendees</th><th class="n">CPD points</th></tr></thead>
                    <tbody>
                    <?php foreach ($providers as $i => $p): ?>
                        <tr>
                            <td><span class="rp-rank"><?= $i + 1 ?></span><?= $h($p['name']) ?></td>
                            <td class="n"><strong><?= (int) $p['activities'] ?></strong></td>
                            <td class="rp-hide" style="width:22%"><div class="rp-bar"><i style="width:<?= $maxAct > 0 ? round((int) $p['activities'] / $maxAct * 100) : 0 ?>%"></i></div></td>
                            <td class="n"><?= (int) $p['upcoming'] ?></td>
                            <td class="n"><?= $num($p['attendees']) ?></td>
                            <td class="n"><?= $num($p['points']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($noProv > 0): ?><p class="rp-note"><?= (int) $noProv ?> event<?= $noProv === 1 ? ' has' : 's have' ?> no provider recorded, so <?= $noProv === 1 ? 'it is' : 'they are' ?> not counted here.</p><?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="rp-card">
            <h2>Events by type</h2>
            <p class="sub">On-site, virtual and hybrid events held.</p>
            <?php if (!$modes): ?>
                <div class="rp-empty">No events in this period.</div>
            <?php else: ?>
                <div class="rp-chart"><canvas id="modeChart" role="img" aria-label="Events by type"></canvas></div>
            <?php endif; ?>
        </section>

        <section class="rp-card">
            <h2>Events per month</h2>
            <p class="sub">Number of events starting each month.</p>
            <?php if (!$perMonth): ?>
                <div class="rp-empty">No events in this period.</div>
            <?php else: ?>
                <div class="rp-chart"><canvas id="monthChart" role="img" aria-label="Events per month"></canvas></div>
            <?php endif; ?>
        </section>

        <?php if ($flagged): ?>
        <section class="rp-card wide">
            <h2>Events with the most flagged sign-ins</h2>
            <p class="sub">Sign-ins outside the venue radius that still need review.</p>
            <div class="rp-chart" style="height:<?= 60 + count($flagged) * 36 ?>px"><canvas id="flaggedChart" role="img" aria-label="Flagged sign-ins by event"></canvas></div>
        </section>
        <?php endif; ?>
    </div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') { return; }
    var ON = '#0f5132', VI = '#2b6cb0', GREEN = '#1a7b32', RED = '#dc2626', GOLD = '#b58900';
    Chart.defaults.font.family = "'Poppins', system-ui, sans-serif";
    Chart.defaults.color = '#64748b';
    var common = { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } } };
    function el(id) { return document.getElementById(id); }

    if (el('trendChart')) {
        new Chart(el('trendChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode(array_values($monthLabels)) ?>,
                datasets: [
                    { label: 'On-site', data: <?= json_encode(array_values($series['onsite'])) ?>, borderColor: ON, backgroundColor: ON, tension: .3, pointRadius: 3 },
                    { label: 'Virtual', data: <?= json_encode(array_values($series['virtual'])) ?>, borderColor: VI, backgroundColor: VI, tension: .3, pointRadius: 3 }
                ]
            },
            options: Object.assign({}, common, { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } })
        });
    }

    if (el('cadreChart')) {
        new Chart(el('cadreChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(static fn($k) => $cadreLabels[$k] ?? ($k === 'other' ? 'Other' : $k), $cadreKeys)) ?>,
                datasets: [
                    { label: 'On-site', data: <?= json_encode(array_map(static fn($k) => $cadreSplit[$k]['onsite'] ?? 0, $cadreKeys)) ?>, backgroundColor: ON },
                    { label: 'Virtual', data: <?= json_encode(array_map(static fn($k) => $cadreSplit[$k]['virtual'] ?? 0, $cadreKeys)) ?>, backgroundColor: VI }
                ]
            },
            options: Object.assign({}, common, { scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } })
        });
    }

    if (el('modeChart')) {
        new Chart(el('modeChart'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_map(static fn($r) => $r['event_mode'], $modes)) ?>,
                datasets: [{ data: <?= json_encode(array_map(static fn($r) => (int) $r['count'], $modes)) ?>, backgroundColor: <?= json_encode(array_map(static fn($r) => stripos($r['event_mode'], 'virtual') !== false ? '#2b6cb0' : (stripos($r['event_mode'], 'hybrid') !== false ? '#b58900' : '#0f5132'), $modes)) ?> }]
            },
            options: common
        });
    }

    if (el('monthChart')) {
        new Chart(el('monthChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(static fn($r) => $r['month'], $perMonth)) ?>,
                datasets: [{ label: 'Events', data: <?= json_encode(array_map(static fn($r) => (int) $r['count'], $perMonth)) ?>, backgroundColor: GREEN }]
            },
            options: Object.assign({}, common, { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } })
        });
    }

    if (el('flaggedChart')) {
        new Chart(el('flaggedChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(static fn($r) => mb_strimwidth((string) $r['title'], 0, 38, '…'), $flagged)) ?>,
                datasets: [{ label: 'Flagged sign-ins', data: <?= json_encode(array_map(static fn($r) => (int) $r['flagged_count'], $flagged)) ?>, backgroundColor: RED }]
            },
            options: Object.assign({}, common, { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } })
        });
    }
})();
</script>
</body>
</html>
