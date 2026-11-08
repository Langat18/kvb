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
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="app-page-hero">
        <div>
            <h1>Record Manual Attendance</h1>
            <p class="sub">For attendees without a working device &mdash; uses your device's location, same rules as self check-in.</p>
        </div>
        <a href="/events/view.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-ghost">&larr; Back to Event</a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="app-card">
        <div class="section-label">Manual Check-In</div>

        <div style="text-align:center;margin-bottom:1rem;">
            <?php if ($sessionInfo['session_id']): ?>
                <span class="pill"><?= htmlspecialchars($sessionLabels[$sessionInfo['session_id']] ?? (string) $sessionInfo['session_id'], ENT_QUOTES, 'UTF-8') ?> Session</span>
                <?php if (!empty($sessionInfo['late'])): ?>
                    <p style="font-size:.8rem;color:var(--text-muted);margin:.5rem 0 0;">This session has ended. Late sign-in is open until <?= htmlspecialchars(date('g:i A', (int) $sessionInfo['closes_at']), ENT_QUOTES, 'UTF-8') ?>.</p>
                <?php endif; ?>
            <?php else: ?>
                <span class="pill" style="background:var(--kvb-red-light);color:var(--kvb-red-dark);">Outside session hours</span>
            <?php endif; ?>
        </div>

        <div id="geo-status" class="status-box pending">Checking location access&hellip;</div>

        <form method="POST" action="/events/attend_manual.php?token=<?= urlencode($token) ?>" id="manual-form" style="max-width:480px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="geo_lat" id="geo_lat">
            <input type="hidden" name="geo_lng" id="geo_lng">

            <div class="form-group">
                <label for="reg_no">Registration Number <span class="required">*</span></label>
                <input type="text" id="reg_no" name="reg_no" required placeholder="e.g. 1234 or VTA1234" autofocus>
            </div>

            <button type="submit" class="btn btn-primary btn-block" id="submit-btn" style="margin-top:.5rem;" disabled>
                Record Attendance
            </button>
        </form>

        <?php if ($dayEnabled): ?>
        <hr style="margin:1.5rem 0;border:none;border-top:1px solid #e5e8ec;">
        <div class="section-label">Confirm Presence for Today</div>
        <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:.75rem;max-width:480px;">
            Marks the attendee present on <?= htmlspecialchars(date('d M Y'), ENT_QUOTES, 'UTF-8') ?> without a session sign-in. No points are awarded.
        </p>
        <form method="POST" action="/events/attend_manual.php?token=<?= urlencode($token) ?>" style="max-width:480px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="confirm_day">

            <div class="form-group">
                <label for="day_reg_no">Registration Number <span class="required">*</span></label>
                <input type="text" id="day_reg_no" name="reg_no" required placeholder="e.g. 1234 or VTA1234">
            </div>

            <button type="submit" class="btn btn-secondary btn-block" style="margin-top:.5rem;">Confirm Presence</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
const geoStatus = document.getElementById('geo-status');
const submitBtn = document.getElementById('submit-btn');
const latInput  = document.getElementById('geo_lat');
const lngInput  = document.getElementById('geo_lng');

function onGeoSuccess(position) {
    latInput.value = position.coords.latitude;
    lngInput.value = position.coords.longitude;
    geoStatus.textContent = 'Location confirmed.';
    geoStatus.className = 'status-box granted';
    submitBtn.disabled = false;
}

function onGeoError() {
    geoStatus.textContent = 'Location access is required. Please enable location in your browser settings and reload.';
    geoStatus.className = 'status-box denied';
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
