<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;
use VetBoard\Registration;

Auth::startSession();
Auth::requireRole('admin');

$user = Auth::user();

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

$registrationModel = new Registration();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $attendanceId = (int) ($_POST['attendance_id'] ?? 0);

    if ($attendanceId > 0) {
        $registrationModel->clearFlag($attendanceId);
        $_SESSION['success'] = 'Record cleared and approved.';
    }

    header('Location: /events/flagged.php?token=' . urlencode($event['view_token']));
    exit;
}

$flagged = $registrationModel->flaggedForEvent((int) $event['id']);
$success = $_SESSION['success'] ?? null;
unset($_SESSION['success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Flagged Attendance — <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: 900px; margin: 0 auto; }
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; }
.page-header h1 { font-size: 1.2rem; font-weight: 700; color: var(--kvb-green-dark); }
.list-table { width: 100%; border-collapse: collapse; }
.list-table th { text-align: left; padding: .6rem .5rem; font-size: .78rem; color: var(--text-muted); border-bottom: 1px solid #eee; }
.list-table td { padding: .6rem .5rem; font-size: .87rem; border-bottom: 1px solid #f2f2f2; vertical-align: middle; }
.distance-badge { color: var(--kvb-red-dark); font-weight: 600; font-size: .82rem; }
.approve-btn { padding: .35rem .8rem; font-size: .8rem; border-radius: 6px; border: none; cursor: pointer; background: var(--kvb-green-light); color: var(--kvb-green-dark); }
.empty-state { color: var(--text-muted); font-size: .85rem; padding: 1.5rem 0; text-align: center; }
</style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <h1>Flagged Attendance — <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <a href="/events/view.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-secondary">&larr; Back to Event</a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (empty($flagged)): ?>
        <div class="card"><p class="empty-state">No flagged records for this event.</p></div>
    <?php else: ?>
        <div class="card" style="padding:0;">
            <table class="list-table">
                <thead>
                    <tr>
                        <th>Reg No</th>
                        <th>Name</th>
                        <th>Session</th>
                        <th>Date</th>
                        <th>Distance</th>
                        <th>Signed At</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($flagged as $f): ?>
                    <tr>
                        <td><?= htmlspecialchars($f['reg_no'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(SESSION_LABELS[$f['session']] ?? $f['session'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= date('d M Y', strtotime($f['attendance_date'])) ?></td>
                        <td><span class="distance-badge"><?= (int) $f['distance_m'] ?>m from venue</span></td>
                        <td><?= date('d M Y H:i', strtotime($f['confirmed_at'])) ?></td>
                        <td>
                            <form method="POST" action="/events/flagged.php?token=<?= urlencode($event['view_token']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="attendance_id" value="<?= (int) $f['attendance_id'] ?>">
                                <button type="submit" class="approve-btn" onclick="return confirm('Approve this attendance record despite the location flag?')">Approve</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
