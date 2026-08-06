<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;

Auth::startSession();
Auth::requireLogin();

$user       = Auth::user();
$eventModel = new Event();

$page       = max(1, (int) ($_GET['page']     ?? 1));
$perPage    = max(1, min(100, (int) ($_GET['per_page'] ?? 20)));
$offset     = ($page - 1) * $perPage;
$search     = trim($_GET['search']      ?? '');
$modeFilter = trim($_GET['event_mode']  ?? '');
$dateFilter = trim($_GET['date']        ?? '');

$filters = [];
if ($search !== '')     $filters['search']     = $search;
if ($modeFilter !== '') $filters['event_mode'] = $modeFilter;
if ($dateFilter !== '') $filters['date']       = $dateFilter;

$events     = $eventModel->list($filters, $perPage, $offset);
$total      = $eventModel->count($filters);
$totalPages = (int) ceil($total / $perPage);

$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Events — Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: 1000px; margin: 0 auto; }
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; }
.page-header h1 { font-size: 1.3rem; font-weight: 700; color: var(--kvb-green-dark); }
.filter-bar { display: flex; gap: .6rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1.25rem; }
.filter-bar .form-group { margin-bottom: 0; flex: 1; min-width: 160px; }
.filter-bar select, .filter-bar input { width: 100%; }
.list-table { width: 100%; border-collapse: collapse; }
.list-table th { text-align: left; padding: .6rem .5rem; font-size: .78rem; color: var(--text-muted); border-bottom: 1px solid #eee; }
.list-table td { padding: .6rem .5rem; font-size: .87rem; border-bottom: 1px solid #f2f2f2; }
.empty-state { color: var(--text-muted); font-size: .85rem; padding: 1.5rem 0; text-align: center; }
.pagination { display: flex; gap: .4rem; margin-top: 1rem; }
.pagination a, .pagination span { padding: .3rem .65rem; border-radius: 6px; text-decoration: none; font-size: .82rem; }
.pagination a { background: #eee; color: var(--text-main); }
.pagination span.active { background: var(--kvb-green-dark); color: #fff; }
</style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <h1>Events</h1>
        <div style="display:flex;gap:.6rem;">
            <a href="/events/create.php" class="btn btn-primary">+ New Event</a>
            <a href="/logout.php" class="btn btn-secondary">Log Out</a>
        </div>
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
                <?php foreach (EVENT_MODES as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $modeFilter === $key ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
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
        <div class="card"><p class="empty-state">No events found.</p></div>
    <?php else: ?>
        <div class="card" style="padding:0;">
            <table class="list-table">
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
                        <td><span class="pill"><?= htmlspecialchars(EVENT_MODES[$e['event_mode']] ?? $e['event_mode'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td>
                            <?= date('d M Y', strtotime($e['start_date'])) ?>
                            &ndash;
                            <?= date('d M Y', strtotime($e['end_date'])) ?>
                        </td>
                        <td><?= htmlspecialchars($e['venue'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <a href="/events/view.php?token=<?= urlencode($e['view_token']) ?>" class="btn btn-secondary" style="padding:.35rem .8rem;font-size:.8rem;">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php $qp = http_build_query(['page' => $p, 'per_page' => $perPage, 'search' => $search, 'event_mode' => $modeFilter, 'date' => $dateFilter]); ?>
                <?php if ($p === $page): ?>
                    <span class="active"><?= $p ?></span>
                <?php else: ?>
                    <a href="?<?= $qp ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
