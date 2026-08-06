<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Report;

Auth::startSession();
Auth::requireRole('admin', 'ceo');

$user         = Auth::user();
$reportModel  = new Report();

$totals             = $reportModel->totals();
$eventsByMode        = $reportModel->eventsByMode();
$participantsByCadre = $reportModel->participantsByCadre();
$monthlyTrend        = $reportModel->monthlyEventsTrend(6);
$flaggedByEvent       = $reportModel->flaggedByEvent(5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports — Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: 1100px; margin: 0 auto; }
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
.page-header h1 { font-size: 1.3rem; font-weight: 700; color: var(--kvb-green-dark); }
.stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
.stat-card { text-align: center; padding: 1.25rem; }
.stat-card .num { font-size: 1.8rem; font-weight: 700; color: var(--kvb-green-dark); }
.stat-card .label { font-size: .8rem; color: var(--text-muted); margin-top: .25rem; }
.stat-card.flagged .num { color: var(--kvb-red-dark); }
.chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem; }
.chart-card h2 { font-size: .95rem; font-weight: 600; color: var(--kvb-green-dark); margin-bottom: 1rem; }
@media (max-width: 800px) { .stat-grid, .chart-grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <h1>Reports Dashboard</h1>
        <a href="/index.php" class="btn btn-secondary">&larr; Back to Dashboard</a>
    </div>

    <div class="stat-grid">
        <div class="card stat-card">
            <div class="num"><?= $totals['total_events'] ?></div>
            <div class="label">Total Events</div>
        </div>
        <div class="card stat-card">
            <div class="num"><?= $totals['total_participants'] ?></div>
            <div class="label">Registered Vets</div>
        </div>
        <div class="card stat-card">
            <div class="num"><?= $totals['total_attendance'] ?></div>
            <div class="label">Attendance Records</div>
        </div>
        <div class="card stat-card flagged">
            <div class="num"><?= $totals['flagged_attendance'] ?></div>
            <div class="label">Flagged Records</div>
        </div>
    </div>

    <div class="chart-grid">
        <div class="card chart-card">
            <h2>Events by Mode</h2>
            <canvas id="modeChart"></canvas>
        </div>
        <div class="card chart-card">
            <h2>Participants by Cadre</h2>
            <canvas id="cadreChart"></canvas>
        </div>
    </div>

    <div class="chart-grid">
        <div class="card chart-card">
            <h2>Events Over Time (last 6 months)</h2>
            <canvas id="trendChart"></canvas>
        </div>
        <div class="card chart-card">
            <h2>Most Flagged Events</h2>
            <canvas id="flaggedChart"></canvas>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
const kvbGreen = '#1a7b32';
const kvbRed   = '#dc2626';
const kvbGold  = '#d4af37';
const kvbGreenDark = '#0f5132';

new Chart(document.getElementById('modeChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_map(fn($r) => EVENT_MODES[$r['event_mode']] ?? $r['event_mode'], $eventsByMode)) ?>,
        datasets: [{
            data: <?= json_encode(array_map(fn($r) => (int) $r['count'], $eventsByMode)) ?>,
            backgroundColor: [kvbGreen, kvbGold, kvbGreenDark],
        }]
    },
    options: { plugins: { legend: { position: 'bottom' } } }
});

new Chart(document.getElementById('cadreChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($r) => CADRE_LABELS[$r['cadre']] ?? $r['cadre'], $participantsByCadre)) ?>,
        datasets: [{
            label: 'Participants',
            data: <?= json_encode(array_map(fn($r) => (int) $r['count'], $participantsByCadre)) ?>,
            backgroundColor: kvbGreen,
        }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_map(fn($r) => $r['month'], $monthlyTrend)) ?>,
        datasets: [{
            label: 'Events',
            data: <?= json_encode(array_map(fn($r) => (int) $r['count'], $monthlyTrend)) ?>,
            borderColor: kvbGreenDark,
            backgroundColor: 'rgba(26,123,50,0.15)',
            fill: true,
            tension: .3,
        }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

new Chart(document.getElementById('flaggedChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($r) => $r['title'], $flaggedByEvent)) ?>,
        datasets: [{
            label: 'Flagged Records',
            data: <?= json_encode(array_map(fn($r) => (int) $r['flagged_count'], $flaggedByEvent)) ?>,
            backgroundColor: kvbRed,
        }]
    },
    options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }
});
</script>
</body>
</html>
