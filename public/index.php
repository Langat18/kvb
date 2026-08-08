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

$roleLabels = ['admin' => 'Admin', 'staff' => 'Staff', 'ceo' => 'CEO'];
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

.navbar {
    background: #fff;
    border-bottom: 1px solid #e8e6df;
    padding: .85rem 2.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.navbar-brand { display: flex; align-items: center; gap: .6rem; }
.navbar-brand img { height: 34px; }
.navbar-brand span { font-weight: 700; font-size: .95rem; color: var(--kvb-green-dark); }
.navbar-user { display: flex; align-items: center; gap: .85rem; font-size: .85rem; }
.navbar-user .role-badge {
    padding: .2rem .6rem; border-radius: 12px; font-size: .72rem; font-weight: 600;
    background: var(--kvb-green-light); color: var(--kvb-green-dark);
}
.navbar-user a { color: var(--text-muted); text-decoration: none; }
.navbar-user a:hover { color: var(--kvb-red-dark); }

.page-body { max-width: 1100px; margin: 0 auto; padding: 2rem 2.5rem; }

.hero { margin-bottom: 1.75rem; }
.hero h1 { font-size: 1.4rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: .3rem; }
.hero p { font-size: .9rem; color: var(--text-muted); }

.stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.75rem; }
.stat-card { text-align: center; padding: 1.1rem; }
.stat-card .num { font-size: 1.7rem; font-weight: 700; color: var(--kvb-green-dark); }
.stat-card .label { font-size: .78rem; color: var(--text-muted); margin-top: .2rem; }
.stat-card.flagged .num { color: var(--kvb-red-dark); }

.section-label { font-size: .78rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); margin-bottom: .75rem; }

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

.event-list { width: 100%; border-collapse: collapse; }
.event-list th { text-align: left; padding: .6rem .6rem; font-size: .78rem; color: var(--text-muted); border-bottom: 1px solid #eee; background: #faf9f7; }
.event-list td { padding: .6rem .6rem; font-size: .87rem; border-bottom: 1px solid #f2f2f2; }
.event-list tr:hover td { background: #f7faf8; }
.empty-state { color: var(--text-muted); font-size: .85rem; padding: 1.5rem 0; text-align: center; }

@media (max-width: 780px) { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
</style>
</head>
<body>

<div class="navbar">
    <div class="navbar-brand">
        <img src="/assets/img/kvb_logo.png" alt="Kenya Veterinary Board">
    </div>
    <div class="navbar-user">
        <span><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="role-badge"><?= htmlspecialchars($roleLabels[$user['role']] ?? $user['role'], ENT_QUOTES, 'UTF-8') ?></span>
        <a href="/account/change_password.php">Change Password</a>
        <a href="/logout.php">Log Out</a>
    </div>
</div>

<div class="page-body">

    <div class="hero">
        <h1><?= $greeting ?>, <?= htmlspecialchars(explode(' ', $user['name'])[0], ENT_QUOTES, 'UTF-8') ?></h1>
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
        <?php if (in_array($user['role'], ['admin', 'ceo'], true)): ?>
        <a href="/reports/dashboard.php" class="action-card">
            <span class="icon">&#128202;</span>
            <div class="title">Reports</div>
            <div class="desc">Organisation-wide trends and breakdowns</div>
        </a>
        <?php endif; ?>
        <?php if ($user['role'] === 'admin'): ?>
        <a href="/staff/index.php" class="action-card">
            <span class="icon">&#128100;</span>
            <div class="title">Manage Staff</div>
            <div class="desc">Add accounts, assign roles, deactivate access</div>
        </a>
        <?php endif; ?>
    </div>

    <div class="section-label">Recent Events</div>
    <div class="card" style="padding:0;">
        <?php if (empty($recentEvents)): ?>
            <p class="empty-state">No events created yet.</p>
        <?php else: ?>
            <table class="event-list">
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
                        <td><span class="pill"><?= htmlspecialchars(EVENT_MODES[$e['event_mode']] ?? $e['event_mode'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= date('d M Y', strtotime($e['start_date'])) ?> &ndash; <?= date('d M Y', strtotime($e['end_date'])) ?></td>
                        <td><a href="/events/view.php?token=<?= urlencode($e['view_token']) ?>" class="btn btn-ghost" style="padding:.3rem .7rem;font-size:.78rem;">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
