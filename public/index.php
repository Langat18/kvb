<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;

Auth::startSession();
Auth::requireLogin();

$user = Auth::user();

$eventModel = new Event();

$recentEvents = $eventModel->list([], 5, 0);

function firstNameOnly(string $fullName): string
{
    $cleaned = preg_replace('/^(Mr|Mrs|Ms|Miss|Dr|Prof|Eng|Rev)\.?\s+/i', '', trim($fullName));
    $parts   = explode(' ', $cleaned);

    return $parts[0] !== '' ? $parts[0] : $fullName;
}

$hour     = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

require __DIR__ . '/templates/sidebar_data.php';
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
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/templates/nav.php'; ?>

<div class="page-with-sidebar">

    <div class="dash-hero">
        <h1><?= $greeting ?>, <b><?= htmlspecialchars(firstNameOnly($user['name']), ENT_QUOTES, 'UTF-8') ?></b></h1>
    </div>

    <div class="dash-main-card">
        <div class="section-label">Recent Events</div>
        <?php if (empty($recentEvents)): ?>
            <p class="empty-state">No events created yet.</p>
        <?php else: ?>
            <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr><th>Title</th><th>Mode</th><th>Dates</th><th></th></tr>
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
            <?php if (Auth::can('view_events')): ?>
                <div style="text-align: right; margin-top: var(--space-3);">
                    <a href="/events/index.php" class="btn btn-ghost btn-sm">View All Events</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
