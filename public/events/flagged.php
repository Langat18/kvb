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

    if (in_array($action, ['approve_selected', 'deny_selected'], true)) {
        $ids = (array) ($_POST['ids'] ?? []);
        $res = $registrationModel->reviewFlaggedBatch((int) $event['id'], $ids, $action === 'approve_selected' ? 'approve' : 'deny', $staffId, $note);
        $verb = $action === 'approve_selected' ? 'Approved' : 'Denied';
        $_SESSION['success'] = $res['done'] === 0 && !$ids
            ? 'Nothing was selected.'
            : $verb . ' ' . $res['done'] . ' record' . ($res['done'] === 1 ? '' : 's') . '.'
              . ($res['skipped'] > 0 ? ' ' . $res['skipped'] . ' skipped (already reviewed or points already awarded).' : '');
        header('Location: /events/flagged.php?token=' . urlencode($event['view_token']));
        exit;
    }

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
<link rel="stylesheet" href="/assets/css/style.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/style.css') ?>">
<style>
.distance-badge { color: var(--kvb-red-dark); font-weight: 600; font-size: .82rem; }
.score-badge { color: var(--kvb-red-dark); font-weight: 600; font-size: .82rem; }
.section-heading { font-size: 1rem; font-weight: 700; color: var(--kvb-green-dark); margin: 1.75rem 0 .6rem; }
.section-heading:first-of-type { margin-top: 0; }
.review-actions { display: flex; gap: .4rem; flex-wrap: wrap; }
.review-actions form { display: inline; }
.fl-tools { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; padding:.7rem 1rem; border-bottom:1px solid #e2e8f0; font-size:.85rem; color:#475569; }
.fl-tools .btn { min-height:34px; }
.fl-tools .spacer { flex:1; }
.fl-bulk { position:sticky; bottom:0; display:none; gap:.6rem; align-items:center; flex-wrap:wrap; padding:.75rem 1rem; background:#0f5132; color:#fff; border-radius:0 0 12px 12px; }
.fl-bulk.on { display:flex; }
.fl-bulk input[type=text] { flex:1; min-width:180px; min-height:36px; padding:.3rem .7rem; border:0; border-radius:8px; font:inherit; font-size:.85rem; }
.fl-bulk .btn { min-height:38px; padding:.4rem 1rem; font-weight:600; border:0; opacity:1; }
.fl-bulk .btn.fl-yes { background:#fff; color:#0f5132; }
.fl-bulk .btn.fl-no { background:#dc2626; color:#fff; }
.fl-bulk .btn:hover { filter:brightness(.93); }
td.fl-cb, th.fl-cb { width:36px; text-align:center; }
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
        <div class="app-card card-flush" id="fl-card">
            <form id="fl-bulk" method="POST" action="/events/flagged.php?token=<?= urlencode($event['view_token']) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" id="fl-action" value="">
            <div class="fl-tools">
                <span><strong><?= count($flagged) ?></strong> awaiting review</span>
                <span class="spacer"></span>
                <span>Select:</span>
                <button type="button" class="btn btn-ghost btn-sm" data-sel="all">All</button>
                <button type="button" class="btn btn-ghost btn-sm" data-sel="100">Within 100 m</button>
                <button type="button" class="btn btn-ghost btn-sm" data-sel="250">Within 250 m</button>
                <button type="button" class="btn btn-ghost btn-sm" data-sel="none">None</button>
            </div>
            </form>
            <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="fl-cb"><input type="checkbox" id="fl-all" aria-label="Select all"></th>
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
                        <td class="fl-cb"><input type="checkbox" name="ids[]" form="fl-bulk" class="fl-row" value="<?= (int) $f['attendance_id'] ?>" data-dist="<?= (int) $f['distance_m'] ?>" aria-label="Select <?= htmlspecialchars($f['reg_no'], ENT_QUOTES, 'UTF-8') ?>"></td>
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
            <div class="fl-bulk" id="fl-bar">
                <strong id="fl-count">0 selected</strong>
                <input type="text" name="review_note" form="fl-bulk" maxlength="255" placeholder="Reason for all selected (optional)">
                <button type="submit" form="fl-bulk" class="btn fl-yes" data-do="approve_selected">Approve selected</button>
                <button type="submit" form="fl-bulk" class="btn fl-no" data-do="deny_selected">Deny selected</button>
            </div>
        </div>
        <script>
        (function () {
            var rows = [].slice.call(document.querySelectorAll('.fl-row')), all = document.getElementById('fl-all'),
                bar = document.getElementById('fl-bar'), cnt = document.getElementById('fl-count'),
                form = document.getElementById('fl-bulk'), act = document.getElementById('fl-action');
            function sync() {
                var n = rows.filter(function (r) { return r.checked; }).length;
                cnt.textContent = n + ' selected';
                bar.classList.toggle('on', n > 0);
                all.checked = n > 0 && n === rows.length;
                all.indeterminate = n > 0 && n < rows.length;
            }
            rows.forEach(function (r) { r.addEventListener('change', sync); });
            all.addEventListener('change', function () { rows.forEach(function (r) { r.checked = all.checked; }); sync(); });
            document.querySelectorAll('[data-sel]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var m = b.getAttribute('data-sel');
                    rows.forEach(function (r) { r.checked = m === 'all' ? true : m === 'none' ? false : (+r.getAttribute('data-dist') <= +m); });
                    sync();
                });
            });
            bar.querySelectorAll('[data-do]').forEach(function (b) {
                b.addEventListener('click', function (e) {
                    var n = rows.filter(function (r) { return r.checked; }).length, d = b.getAttribute('data-do') === 'approve_selected';
                    if (!n || !confirm((d ? 'Approve ' : 'Deny ') + n + ' record' + (n === 1 ? '' : 's') + '?' + (d ? ' Points post once other requirements are met.' : ' No points will be awarded.') + ' This is recorded against your account.')) { e.preventDefault(); return; }
                    act.value = b.getAttribute('data-do');
                });
            });
            sync();
        })();
        </script>
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
