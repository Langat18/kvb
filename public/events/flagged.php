<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;
use VetBoard\Registration;

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

function flaggedLog(string $action, int $attendanceId, $user, bool $ok): void
{
    error_log(sprintf(
        '[flagged] action=%s attendance_id=%d staff_id=%s result=%s',
        $action,
        $attendanceId,
        $user['id'] ?? 'none',
        $ok ? 'ok' : 'failed'
    ));
}

$registrationModel = new Registration();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $action       = $_POST['action'] ?? '';
    $attendanceId = (int) ($_POST['attendance_id'] ?? 0);
    $note         = trim((string) ($_POST['review_note'] ?? ''));
    $note         = $note === '' ? null : mb_substr($note, 0, 255);
    $staffId      = isset($user['id']) ? (int) $user['id'] : null;

    if ($attendanceId <= 0 || !in_array($action, ['approve_flag', 'override_quiz', 'deny_flag', 'deny_quiz', 'reopen_review'], true)) {
        error_log(sprintf('[flagged] ignored request action=%s attendance_id=%d', $action, $attendanceId));
        $_SESSION['success'] = 'Nothing was changed: the request was incomplete. Please try again.';
    } elseif ($action === 'reopen_review') {
        $result = $registrationModel->reopenReview($attendanceId);
        flaggedLog($action, $attendanceId, $user, $result['ok']);
        $_SESSION['success'] = $result['message'];
    } elseif ($action === 'override_quiz') {
        $ok = $registrationModel->approveQuizOverride($attendanceId, $staffId);
        flaggedLog($action, $attendanceId, $user, (bool) $ok);
        $_SESSION['success'] = $ok
            ? 'Quiz requirement overridden. Points post once any other requirements are met.'
            : 'Could not override: the record no longer exists.';
    } else {
        $decision = $action === 'approve_flag' ? 'approve' : 'deny';
        $result   = $registrationModel->reviewAttendance($attendanceId, $decision, $staffId, $note);
        flaggedLog($action, $attendanceId, $user, $result['ok']);
        $_SESSION['success'] = $result['message'];
    }

    header('Location: /events/flagged.php?token=' . urlencode($event['view_token']));
    exit;
}

$flagged       = $registrationModel->flaggedForEvent((int) $event['id']);
$quizFailed    = $registrationModel->quizFailedForEvent((int) $event['id']);
$recentlyReviewed = $registrationModel->recentlyReviewedForEvent((int) $event['id']);
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
.review-actions { display: flex; gap: .4rem; flex-wrap: wrap; }
.review-actions form { display: inline; }
</style>
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="app-page-hero">
        <div><h1>Pending Approvals</h1></div>
        <a href="/events/view.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-ghost">Back to Event</a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <h2 class="section-heading">Flagged — Outside Geofence</h2>
    <?php if (empty($flagged)): ?>
        <div class="app-card"><p class="empty-state">No flagged records for this event.</p></div>
    <?php else: ?>
        <div class="app-card card-flush">
            <div class="table-scroll">
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
                        <td><?= htmlspecialchars($f['session_label'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= date('d M Y', strtotime($f['attendance_date'])) ?></td>
                        <td><span class="distance-badge"><?= (int) $f['distance_m'] ?>m from venue</span><?php if (in_array($f['points_denied'] ?? false, [true, 't', 1, '1'], true)): ?><br><small>Auto-rejected: too far from venue</small><?php endif; ?></td>
                        <td><?= date('d M Y H:i', strtotime($f['confirmed_at'])) ?></td>
                        <td>
                            <div class="review-actions">
                            <form method="POST" action="/events/flagged.php?token=<?= urlencode($event['view_token']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="approve_flag">
                                <input type="hidden" name="review_note" value="">
                                <input type="hidden" name="attendance_id" value="<?= (int) $f['attendance_id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm" onclick="var n = prompt('Approve this record? Points post once other requirements are met.\n\nReason (optional):'); if (n === null) { return false; } this.form.review_note.value = n; return true;">Approve</button>
                            </form>
                            <form method="POST" action="/events/flagged.php?token=<?= urlencode($event['view_token']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="deny_flag">
                                <input type="hidden" name="review_note" value="">
                                <input type="hidden" name="attendance_id" value="<?= (int) $f['attendance_id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" onclick="var n = prompt('Deny this record? No points will be awarded.\n\nReason (optional):'); if (n === null) { return false; } this.form.review_note.value = n; return true;">Deny</button>
                            </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>

    <h2 class="section-heading">Recently Reviewed</h2>
    <?php if (empty($recentlyReviewed)): ?>
        <div class="app-card"><p class="empty-state">No reviewed records yet.</p></div>
    <?php else: ?>
        <div class="app-card card-flush">
            <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Reg No</th>
                        <th>Name</th>
                        <th>Session</th>
                        <th>Date</th>
                        <th>Decision</th>
                        <th>Reviewed</th>
                        <th>Reason</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentlyReviewed as $r):
                        $wasDenied = in_array($r['points_denied'], [true, 't', 1, '1'], true);
                        $hasPoints = in_array($r['has_points'], [true, 't', 1, '1'], true);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($r['reg_no'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($r['session_label'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= date('d M Y', strtotime($r['attendance_date'])) ?></td>
                        <td><?= $wasDenied ? 'Denied' : 'Approved' ?></td>
                        <td><?= date('d M Y H:i', strtotime($r['reviewed_at'])) ?> (staff #<?= (int) $r['reviewed_by'] ?>)</td>
                        <td><?= htmlspecialchars((string) ($r['review_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($hasPoints): ?>
                                <small>Points awarded</small>
                            <?php else: ?>
                            <form method="POST" action="/events/flagged.php?token=<?= urlencode($event['view_token']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="reopen_review">
                                <input type="hidden" name="attendance_id" value="<?= (int) $r['attendance_id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" onclick="return confirm('Return this record to the pending list?')">Undo</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>

    <h2 class="section-heading">Quiz Failed — Both Attempts Used</h2>
    <?php if (empty($quizFailed)): ?>
        <div class="app-card"><p class="empty-state">No unresolved quiz failures for this event.</p></div>
    <?php else: ?>
        <div class="app-card card-flush">
            <div class="table-scroll">
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
                        <td><?= htmlspecialchars($q['session_label'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= date('d M Y', strtotime($q['attendance_date'])) ?></td>
                        <td><span class="score-badge"><?= $q['best_score'] !== null ? number_format((float) $q['best_score'], 0) . '%' : '&mdash;' ?></span></td>
                        <td><?= date('d M Y H:i', strtotime($q['confirmed_at'])) ?></td>
                        <td>
                            <div class="review-actions">
                            <form method="POST" action="/events/flagged.php?token=<?= urlencode($event['view_token']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="override_quiz">
                                <input type="hidden" name="attendance_id" value="<?= (int) $q['attendance_id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Override the quiz requirement and release points for this check-in despite the failed quiz? This is recorded against your account.')">Override &amp; Award</button>
                            </form>
                            <form method="POST" action="/events/flagged.php?token=<?= urlencode($event['view_token']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="deny_quiz">
                                <input type="hidden" name="attendance_id" value="<?= (int) $q['attendance_id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Deny this record? No points will be awarded for this check-in.')">Deny</button>
                            </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
