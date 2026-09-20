<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;
use VetBoard\Registration;
use VetBoard\SessionWindowRepository;

Auth::startSession();
Auth::requirePermission('review_flagged_attendance');

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

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/index.php'],
    ['label' => 'Events', 'url' => '/events/index.php'],
    ['label' => $event['title'], 'url' => '/events/view.php?token=' . urlencode($event['view_token'])],
    ['label' => 'Pending Approvals', 'url' => null],
];

$registrationModel = new Registration();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $action       = $_POST['action'] ?? '';
    $attendanceId = (int) ($_POST['attendance_id'] ?? 0);

    if ($attendanceId > 0 && $action === 'approve_flag') {
        $registrationModel->approveFlagged($attendanceId);
        $_SESSION['success'] = 'Record approved. Points will post automatically once any other requirements (e.g. a session quiz) are met.';
    } elseif ($attendanceId > 0 && $action === 'override_quiz') {
        $registrationModel->approveQuizOverride($attendanceId, $user['id'] ?? null);
        $_SESSION['success'] = 'Quiz requirement overridden. Points have been released for this check-in.';
    }

    header('Location: /events/flagged.php?token=' . urlencode($event['view_token']));
    exit;
}

$flagged       = $registrationModel->flaggedForEvent((int) $event['id']);
$quizFailed    = $registrationModel->quizFailedForEvent((int) $event['id']);
$sessionLabels = (new SessionWindowRepository())->labelMap();
$success       = $_SESSION['success'] ?? null;
unset($_SESSION['success']);

require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pending Approvals - <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.distance-badge { color: var(--kvb-red-dark); font-weight: 600; font-size: .82rem; }
.score-badge { color: var(--kvb-red-dark); font-weight: 600; font-size: .82rem; }
.section-heading { font-size: 1rem; font-weight: 700; color: var(--kvb-green-dark); margin: 1.75rem 0 .6rem; }
.section-heading:first-of-type { margin-top: 0; }
</style>
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="app-page-hero">
        <div><h1>Pending Approvals</h1></div>
        <a href="/events/view.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-ghost">&larr; Back to Event</a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <h2 class="section-heading">Flagged — Outside Geofence</h2>
    <?php if (empty($flagged)): ?>
        <div class="app-card"><p class="empty-state">No flagged records for this event.</p></div>
    <?php else: ?>
        <div class="app-card card-flush">
            <table class="data-table">
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
                        <td><?= htmlspecialchars($sessionLabels[$f['session']] ?? $f['session'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= date('d M Y', strtotime($f['attendance_date'])) ?></td>
                        <td><span class="distance-badge"><?= (int) $f['distance_m'] ?>m from venue</span></td>
                        <td><?= date('d M Y H:i', strtotime($f['confirmed_at'])) ?></td>
                        <td>
                            <form method="POST" action="/events/flagged.php?token=<?= urlencode($event['view_token']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="approve_flag">
                                <input type="hidden" name="attendance_id" value="<?= (int) $f['attendance_id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Approve this attendance record despite the location flag? Points will be released if all other requirements (e.g. a session quiz) are already met.')">Approve</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2 class="section-heading">Quiz Failed — Both Attempts Used</h2>
    <?php if (empty($quizFailed)): ?>
        <div class="app-card"><p class="empty-state">No unresolved quiz failures for this event.</p></div>
    <?php else: ?>
        <div class="app-card card-flush">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Reg No</th>
                        <th>Name</th>
                        <th>Session</th>
                        <th>Date</th>
                        <th>Best Score</th>
                        <th>Signed At</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quizFailed as $q): ?>
                    <tr>
                        <td><?= htmlspecialchars($q['reg_no'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($q['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($sessionLabels[$q['session']] ?? $q['session'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= date('d M Y', strtotime($q['attendance_date'])) ?></td>
                        <td><span class="score-badge"><?= $q['best_score'] !== null ? number_format((float) $q['best_score'], 0) . '%' : '&mdash;' ?></span></td>
                        <td><?= date('d M Y H:i', strtotime($q['confirmed_at'])) ?></td>
                        <td>
                            <form method="POST" action="/events/flagged.php?token=<?= urlencode($event['view_token']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="override_quiz">
                                <input type="hidden" name="attendance_id" value="<?= (int) $q['attendance_id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Override the quiz requirement and release points for this check-in despite the failed quiz? This is recorded against your account.')">Override &amp; Award</button>
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
