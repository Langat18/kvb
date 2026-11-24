<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;
use VetBoard\EventModeRepository;

Auth::startSession();
Auth::requirePermission('view_events');

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/index.php'],
    ['label' => 'Events',    'url' => null],
];

$user          = Auth::user();
$eventModel    = new Event();
$eventModeRepo = new EventModeRepository();
$eventModes    = $eventModeRepo->list(true);
$modeLabels    = $eventModeRepo->labelMap();

$search     = trim($_GET['search']      ?? '');
$modeFilter = trim($_GET['event_mode']  ?? '');
$dateFilter = trim($_GET['date']        ?? '');

$filters = [];
if ($search !== '')     $filters['search']     = $search;
if ($modeFilter !== '') $filters['event_mode'] = $modeFilter;
if ($dateFilter !== '') $filters['date']       = $dateFilter;

$total      = $eventModel->count($filters);
$pager      = new \VetBoard\Paginator($total, $_GET);
$events     = $eventModel->list($filters, $pager->perPage, $pager->offset);

$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);

require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Events - Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/style.css') ?>">
<style>
.filter-bar { display: flex; gap: .6rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: var(--space-4); }
.filter-bar .form-group { margin-bottom: 0; flex: 1; min-width: 160px; }
.filter-bar select, .filter-bar input { width: 100%; }
</style>
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="app-page-hero">
        <div>
            <h1>Events</h1>
            <div class="sub">Browse and manage all events</div>
        </div>
        <a href="/events/create.php" class="btn btn-primary">+ New Event</a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="GET" action="/events/index.php" class="filter-bar">
        <div class="form-group">
            <label>Search</label>
            <input type="text" name="search" placeholder="Event title..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="form-group">
            <label>Mode</label>
            <select name="event_mode">
                <option value="">All modes</option>
                <?php foreach ($eventModes as $mode): ?>
                    <option value="<?= htmlspecialchars($mode['item_key'], ENT_QUOTES, 'UTF-8') ?>" <?= $modeFilter === $mode['item_key'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($mode['label'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Date</label>
            <input type="date" name="date" value="<?= htmlspecialchars($dateFilter, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="form-group" style="flex:0;">
            <button type="submit" class="btn btn-secondary">Filter</button>
        </div>
    </form>

    <?php if (empty($events)): ?>
        <div class="app-card"><p class="empty-state">No events found.</p></div>
    <?php else: ?>
        <div class="app-card card-flush">
            <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Mode</th>
                        <th>Dates</th>
                        <th>Venue</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $e): ?>
                    <tr>
                        <td><?= htmlspecialchars($e['title'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="pill"><?= htmlspecialchars($e['event_mode_label'] ?? $modeLabels[$e['event_mode']] ?? $e['event_mode'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td>
                            <?= date('d M Y', strtotime($e['start_date'])) ?>
                            &ndash;
                            <?= date('d M Y', strtotime($e['end_date'])) ?>
                        </td>
                        <td><?= htmlspecialchars($e['venue'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <a href="/events/view.php?token=<?= urlencode($e['view_token']) ?>" class="btn btn-secondary btn-sm">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php $pagerNoun = 'events'; require __DIR__ . '/../templates/pager.php'; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
