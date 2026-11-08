<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;
use VetBoard\Participant;
use VetBoard\Registration;

Auth::startSession();
Auth::requirePermission('record_manual_attendance');

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

function manualDetectSession(array $event, Event $eventModel): array
{
    return $eventModel->resolveSignInSession((int) $event['id']);
}

function manualOnsiteThreshold(array $window): int
{
    $startTs = strtotime(date('Y-m-d') . ' ' . $window['start_time']);

    if (isset($window['require_late_signin']) && !$window['require_late_signin']) {
        return $startTs;
    }

    $endTs = strtotime(date('Y-m-d') . ' ' . $window['end_time']);

    return max($endTs - (\VetBoard\SigninRules::lead($window, 'onsite') * 60), $startTs);
}

$token         = trim($_GET['token']);
$sessionLabels = array_column($eventModel->getSessions((int) $event['id']), 'label', 'id');
$sessionInfo   = manualDetectSession($event, $eventModel);
$today         = date('Y-m-d');
$dayEnabled    = (new Registration())->dayConfirmationEnabled((int) $event['id']);

$error   = $_SESSION['manual_error']   ?? null;
$success = $_SESSION['manual_success'] ?? null;
unset($_SESSION['manual_error'], $_SESSION['manual_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $regNo = strtoupper(trim($_POST['reg_no'] ?? ''));

    if (empty($regNo) || !Participant::isValidRegNo($regNo)) {
        $_SESSION['manual_error'] = 'Please enter a valid registration number.';
        header('Location: /events/attend_manual.php?token=' . urlencode($token));
        exit;
    }

    $registrationModel = new Registration();
    $registration       = $registrationModel->find((int) $event['id'], $regNo);

    if (!$registration) {
        $_SESSION['manual_error'] = $regNo . ' is not registered for this event.';
        header('Location: /events/attend_manual.php?token=' . urlencode($token));
        exit;
    }

    if (($_POST['action'] ?? '') === 'confirm_day') {
        $done = $registrationModel->confirmDay((int) $registration['id'], $today, 'staff', ['staff_id' => $user['id'] ?? null]);

        if ($done) {
            $_SESSION['manual_success'] = $regNo . ' confirmed present for today.';
        } else {
            $_SESSION['manual_error'] = $regNo . ' is already confirmed for today, or daily confirmation is not enabled for this event.';
        }

        header('Location: /events/attend_manual.php?token=' . urlencode($token));
        exit;
    }

    $sessionId = $sessionInfo['session_id'];
    if ($sessionId === null) {
        $_SESSION['manual_error'] = 'Attendance can only be recorded during a scheduled session window.';
        header('Location: /events/attend_manual.php?token=' . urlencode($token));
        exit;
    }

    if ($registrationModel->hasSignedSession((int) $registration['id'], $sessionId)) {
        $_SESSION['manual_error'] = $regNo . ' has already been signed for the ' . ($sessionLabels[$sessionId] ?? $sessionId) . ' session today.';
        header('Location: /events/attend_manual.php?token=' . urlencode($token));
        exit;
    }

    if ($sessionInfo['window'] !== null) {
        $onsiteThresholdTs = manualOnsiteThreshold($sessionInfo['window']);

        if (time() < $onsiteThresholdTs) {
            $_SESSION['manual_error'] = 'On-site attendance for the ' . ($sessionLabels[$sessionId] ?? $sessionId) .
                ' session opens at ' . date('g:i A', $onsiteThresholdTs) . '.';
            header('Location: /events/attend_manual.php?token=' . urlencode($token));
            exit;
        }
    }

    $lat = $_POST['geo_lat'] ?? '';
    $lng = $_POST['geo_lng'] ?? '';

    if ($lat === '' || $lng === '') {
        $_SESSION['manual_error'] = 'Location access is required. Please enable location and try again.';
        header('Location: /events/attend_manual.php?token=' . urlencode($token));
        exit;
    }

    $submittedLat = (float) $lat;
    $submittedLng = (float) $lng;

    try {
        $geoCheck    = $eventModel->isWithinGeofence($event, $submittedLat, $submittedLng);
        $geoVerified = $geoCheck['within'];
        $distanceM   = $geoCheck['distance_m'];
        $flagged      = false;
        $flagReason   = null;
        $pointsDenied = false;

        if (!$geoVerified) {
            $flagged = true;
            $excessM = $distanceM - (int) $event['geo_radius_m'];

            if ($excessM > OFFSITE_HARD_REJECT_EXCESS_M) {
                $pointsDenied = true;
                $flagReason   = 'Too far from venue to qualify for points (' . $excessM . 'm beyond radius) - not eligible for review';
            } else {
                $flagReason = 'Outside geofence radius (' . $distanceM . 'm from venue)';
            }
        }

        $registrationModel->recordAttendance([
            'registration_id'      => (int) $registration['id'],
            'session_id'           => $sessionId,
            'method'               => 'onsite',
            'geo_verified'         => $geoVerified,
            'submitted_lat'        => $submittedLat,
            'submitted_lng'        => $submittedLng,
            'distance_m'           => $distanceM,
            'flagged'              => $flagged,
            'flag_reason'          => $flagReason,
            'points_denied'        => $pointsDenied,
            'recorded_by_staff_id' => $user['id'] ?? null,
        ]);

        $_SESSION['manual_success'] = $regNo . ' recorded for the ' . ($sessionLabels[$sessionId] ?? $sessionId) . ' session.' .
            ($flagged ? ' Flagged for review - location was outside the venue radius.' : '');

    } catch (\Throwable $e) {
        error_log('Manual attendance error: ' . $e->getMessage());
        $_SESSION['manual_error'] = 'Failed to record attendance. Please try again.';
    }

    header('Location: /events/attend_manual.php?token=' . urlencode($token));
    exit;
}

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/index.php'],
    ['label' => 'Events', 'url' => '/events/index.php'],
    ['label' => $event['title'], 'url' => '/events/view.php?token=' . urlencode($event['view_token'])],
    ['label' => 'Record Manual Attendance', 'url' => null],
];

require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Record Manual Attendance - <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/style.css') ?>">
<style>
.ma { --ink:#1e293b; --muted:#64748b; --line:#e2e8f0; --soft:#f8fafc; --ok:#15803d; --warn:#a16207; --bad:#b91c1c; --brand:var(--kvb-green-dark,#14532d); color:var(--ink); font-size:.9rem; line-height:1.5; }
.ma *, .ma *::before, .ma *::after { box-sizing:border-box; }
.ma-head { display:flex; justify-content:space-between; align-items:flex-end; gap:1rem; flex-wrap:wrap; margin-bottom:1.25rem; }
.ma-head h1 { margin:0; font-size:1.5rem; font-weight:700; letter-spacing:-.01em; }
.ma-head p { margin:.2rem 0 0; color:var(--muted); font-size:.875rem; }
.ma-grid { display:grid; grid-template-columns:minmax(0,3fr) minmax(0,2fr); gap:1rem; align-items:start; }
.ma-grid.single { grid-template-columns:minmax(0,640px); }
.ma-card { background:#fff; border:1px solid var(--line); border-radius:12px; box-shadow:0 1px 2px rgba(15,23,42,.04); overflow:hidden; }
.ma-card-h { display:flex; justify-content:space-between; align-items:center; gap:.75rem; flex-wrap:wrap; padding:.85rem 1.25rem; border-bottom:1px solid var(--line); background:var(--soft); }
.ma-card-h h2 { margin:0; font-size:.78rem; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
.ma-card-b { padding:1.25rem; }
.ma-tag { display:inline-block; padding:.1rem .6rem; border-radius:999px; font-size:.75rem; font-weight:600; background:#ecfdf3; color:var(--ok); border:1px solid #bbf7d0; }
.ma-tag.off { background:#fffbeb; color:var(--warn); border-color:#fde68a; }
.ma-loc { display:flex; align-items:center; gap:.55rem; margin-bottom:1.1rem; font-size:.85rem; color:var(--muted); }
.ma-dot { flex:none; width:9px; height:9px; border-radius:50%; background:#f59e0b; }
.ma-loc.granted { color:var(--ok); } .ma-loc.granted .ma-dot { background:#16a34a; }
.ma-loc.denied { color:var(--bad); } .ma-loc.denied .ma-dot { background:#dc2626; }
.ma label { display:block; margin-bottom:.35rem; font-size:.85rem; font-weight:600; }
.ma .req { color:var(--bad); }
.ma-row { display:flex; gap:.6rem; }
.ma-row input { flex:1; min-width:0; min-height:44px; padding:.55rem .8rem; border:1px solid #cbd5e1; border-radius:8px; font:inherit; font-size:.95rem; background:#fff; color:var(--ink); }
.ma-row input:focus { outline:2px solid var(--brand); outline-offset:1px; border-color:transparent; }
.ma-btn { flex:none; min-height:44px; padding:.55rem 1.2rem; border-radius:8px; border:1px solid var(--brand); background:var(--brand); color:#fff; font:inherit; font-size:.9rem; font-weight:600; cursor:pointer; white-space:nowrap; }
.ma-btn:hover:not([disabled]) { filter:brightness(1.12); }
.ma-btn.alt { background:#fff; color:var(--brand); }
.ma-btn.alt:hover:not([disabled]) { background:var(--soft); filter:none; }
.ma-btn[disabled] { opacity:.5; cursor:not-allowed; }
.ma-note { margin:.7rem 0 0; font-size:.8rem; color:var(--muted); }
.ma-text { margin:0 0 1rem; font-size:.85rem; color:#334155; }
@media (max-width:900px) { .ma-grid { grid-template-columns:minmax(0,1fr); } .ma-grid.single { grid-template-columns:minmax(0,1fr); } }
@media (max-width:520px) {
  .ma-head h1 { font-size:1.25rem; }
  .ma-card-b { padding:1rem; }
  .ma-row { flex-direction:column; }
  .ma-btn { width:100%; }
}
</style>
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
<div class="ma">
    <div class="ma-head">
        <div>
            <h1>Record Manual Attendance</h1>
            <p>For attendees without a device. Uses your location and the same rules as self check-in.</p>
        </div>
        <a href="/events/view.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-ghost">Back to event</a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="ma-grid<?= $dayEnabled ? '' : ' single' ?>">
        <section class="ma-card">
            <div class="ma-card-h">
                <h2>Session check-in</h2>
                <?php if ($sessionInfo['session_id']): ?>
                    <span class="ma-tag"><?= htmlspecialchars($sessionLabels[$sessionInfo['session_id']] ?? (string) $sessionInfo['session_id'], ENT_QUOTES, 'UTF-8') ?> session</span>
                <?php else: ?>
                    <span class="ma-tag off">No session open</span>
                <?php endif; ?>
            </div>
            <div class="ma-card-b">
                <div id="geo-status" class="ma-loc"><span class="ma-dot"></span><span id="geo-text">Checking location&hellip;</span></div>

                <form method="POST" action="/events/attend_manual.php?token=<?= urlencode($token) ?>" id="manual-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="geo_lat" id="geo_lat">
                    <input type="hidden" name="geo_lng" id="geo_lng">

                    <label for="reg_no">Registration number <span class="req">*</span></label>
                    <div class="ma-row">
                        <input type="text" id="reg_no" name="reg_no" required placeholder="e.g. 1234 or VTA1234" autocomplete="off" autocapitalize="characters" autofocus>
                        <button type="submit" class="ma-btn" id="submit-btn" disabled>Record attendance</button>
                    </div>
                </form>

                <?php if ($sessionInfo['session_id']): ?>
                    <?php if (!empty($sessionInfo['late'])): ?>
                        <p class="ma-note">This session has ended. Late sign-in is open until <?= htmlspecialchars(date('g:i A', (int) $sessionInfo['closes_at']), ENT_QUOTES, 'UTF-8') ?>.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="ma-note">Check-in opens when a session starts. Reload the page then.</p>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($dayEnabled): ?>
        <section class="ma-card">
            <div class="ma-card-h"><h2>Confirm presence today</h2></div>
            <div class="ma-card-b">
                <p class="ma-text">Marks the attendee present for <?= htmlspecialchars(date('d M Y'), ENT_QUOTES, 'UTF-8') ?>. No session sign-in and no points.</p>
                <form method="POST" action="/events/attend_manual.php?token=<?= urlencode($token) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="confirm_day">

                    <label for="day_reg_no">Registration number <span class="req">*</span></label>
                    <div class="ma-row">
                        <input type="text" id="day_reg_no" name="reg_no" required placeholder="e.g. 1234 or VTA1234" autocomplete="off" autocapitalize="characters">
                        <button type="submit" class="ma-btn alt">Confirm</button>
                    </div>
                </form>
            </div>
        </section>
        <?php endif; ?>
    </div>
</div>
</div>

<script>
const geoStatus = document.getElementById('geo-status');
const geoText   = document.getElementById('geo-text');
const submitBtn = document.getElementById('submit-btn');
const latInput  = document.getElementById('geo_lat');
const lngInput  = document.getElementById('geo_lng');
const hasSession = <?= $sessionInfo['session_id'] ? 'true' : 'false' ?>;

function onGeoSuccess(position) {
    latInput.value = position.coords.latitude;
    lngInput.value = position.coords.longitude;
    geoText.textContent = 'Location confirmed';
    geoStatus.className = 'ma-loc granted';
    submitBtn.disabled = !hasSession;
}

function onGeoError() {
    geoText.textContent = 'Location is off. Allow location in your browser settings, then reload.';
    geoStatus.className = 'ma-loc denied';
    submitBtn.disabled = true;
}

if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(onGeoSuccess, onGeoError, { enableHighAccuracy: true, timeout: 10000 });
} else {
    onGeoError();
}
</script>
</body>
</html>
