<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;
use VetBoard\Report;

Auth::startSession();
Auth::requireLogin();

$user = Auth::user();

$eventModel  = new Event();
$reportModel = new Report();

$totals       = $reportModel->totals();
$recentEvents = $eventModel->list([], 5, 0);

function firstNameOnly(string $fullName): string
{
    $cleaned = preg_replace('/^(Mr|Mrs|Ms|Miss|Dr|Prof|Eng|Rev)\.?\s+/i', '', trim($fullName));
    $parts   = explode(' ', $cleaned);

    return $parts[0] !== '' ? $parts[0] : $fullName;
}
$hour       = (int) date('H');
$greeting   = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 0; }

.page-body { max-width: 1100px; margin: 0 auto; padding: 2rem 2.5rem; }

.hero { margin-bottom: 1.75rem; }
.hero h1 { font-size: 1.4rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: .3rem; }
.hero p { font-size: .9rem; color: var(--text-muted); }

.action-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.75rem; }
.action-card {
    display: block; text-decoration: none; padding: 1.1rem;
    border: 1px solid #e8e6df; border-radius: var(--card-radius);
    transition: var(--transition); background: #fff;
}
.action-card:hover { border-color: var(--kvb-green); box-shadow: var(--shadow-sm); transform: translateY(-1px); }
.action-card .icon { font-size: 1.4rem; margin-bottom: .5rem; display: block; }
.action-card .title { font-weight: 600; font-size: .92rem; color: var(--text-main); margin-bottom: .2rem; }
.action-card .desc { font-size: .78rem; color: var(--text-muted); }
</style>
</head>
<body>
<?php require __DIR__ . '/templates/nav.php'; ?>

<div class="page-body">

    <div class="hero">
        <h1><?= $greeting ?>, <?= htmlspecialchars(firstNameOnly($user['name']), ENT_QUOTES, 'UTF-8') ?></h1>
        <p>Here's what's happening across the Board's events.</p>
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

    <div class="section-label">Quick Actions</div>
    <div class="action-grid">
        <a href="/events/create.php" class="action-card">
            <span class="icon">&#128197;</span>
            <div class="title">New Event</div>
            <div class="desc">Create an on-site, virtual, or hybrid event</div>
        </a>
        <a href="/events/index.php" class="action-card">
            <span class="icon">&#128203;</span>
            <div class="title">All Events</div>
            <div class="desc">Browse, search, and manage events</div>
        </a>
        <a href="/points/leaderboard.php" class="action-card">
            <span class="icon">&#127942;</span>
            <div class="title">Points Leaderboard</div>
            <div class="desc">See accumulated points across participants</div>
        </a>
        <?php if (Auth::can('view_reports')): ?>
        <a href="/reports/dashboard.php" class="action-card">
            <span class="icon">&#128202;</span>
            <div class="title">Reports</div>
            <div class="desc">Organisation-wide trends and breakdowns</div>
        </a>
        <?php endif; ?>
        <?php if (Auth::can('manage_staff')): ?>
        <a href="/staff/index.php" class="action-card">
            <span class="icon">&#128100;</span>
            <div class="title">Manage Staff</div>
            <div class="desc">Add accounts, assign roles, deactivate access</div>
        </a>
        <?php endif; ?>
        <?php if (Auth::can('manage_roles')): ?>
        <a href="/roles/index.php" class="action-card">
            <span class="icon">&#128272;</span>
            <div class="title">Roles &amp; Permissions</div>
            <div class="desc">Create roles and control what each can access</div>
        </a>
        <?php endif; ?>
        <?php if (Auth::can('manage_departments')): ?>
        <a href="/departments/index.php" class="action-card">
            <span class="icon">&#127970;</span>
            <div class="title">Departments</div>
            <div class="desc">Manage the list of departments</div>
        </a>
        <?php endif; ?>
        <?php if (Auth::can('manage_lookups')): ?>
        <a href="/lookups/index.php" class="action-card">
            <span class="icon">&#128203;</span>
            <div class="title">Event Settings</div>
            <div class="desc">Manage event modes, cadres, and session windows</div>
        </a>
        <?php endif; ?>
    </div>

    <div class="section-label">Recent Events</div>
    <div class="card card-flush">
        <?php if (empty($recentEvents)): ?>
            <p class="empty-state">No events created yet.</p>
        <?php else: ?>
            <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Mode</th>
                        <th>Dates</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentEvents as $e): ?>
                    <tr>
                        <td><?= htmlspecialchars($e['title'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="pill"><?= htmlspecialchars($e['event_mode_label'] ?? $e['event_mode'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= date('d M Y', strtotime($e['start_date'])) ?> &ndash; <?= date('d M Y', strtotime($e['end_date'])) ?></td>
                        <td><a href="/events/view.php?token=<?= urlencode($e['view_token']) ?>" class="btn btn-ghost btn-sm">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
