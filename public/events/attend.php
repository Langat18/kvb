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

$token = trim($_GET['token'] ?? '');
if (empty($token)) {
    http_response_code(400);
    die('Invalid attendance link.');
}

$eventModel = new Event();
$event      = $eventModel->findByToken($token);

if (!$event) {
    http_response_code(404);
    die('Event not found or link is invalid.');
}

$today = date('Y-m-d');
if ($today < $event['start_date'] || $today > $event['end_date']) {
    http_response_code(403);
    $msg = $today < $event['start_date']
        ? 'This event has not started yet.'
        : 'This event has ended.';
    die($msg);
}

function detectSession(): array
{
    $now = date('H:i');

    if ($now >= MORNING_SESSION_START && $now <= MORNING_SESSION_END) {
        return ['session' => 'morning', 'flagged' => false, 'reason' => null];
    }

    if ($now >= MID_MORNING_SESSION_START && $now <= MID_MORNING_SESSION_END) {
        return ['session' => 'mid_morning', 'flagged' => false, 'reason' => null];
    }

    if ($now >= AFTERNOON_SESSION_START && $now <= AFTERNOON_SESSION_END) {
        return ['session' => 'afternoon', 'flagged' => false, 'reason' => null];
    }

    return ['session' => null, 'flagged' => true, 'reason' => 'Outside all session hours'];
}

$sessionInfo = detectSession();
$isOnsite    = $event['event_mode'] !== 'virtual';
$error       = $_SESSION['attend_error'] ?? null;
unset($_SESSION['attend_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $regNo = strtoupper(trim($_POST['reg_no'] ?? ''));

    if (empty($regNo) || !Participant::isValidRegNo($regNo)) {
        $_SESSION['attend_error'] = 'Please enter a valid registration number.';
        header('Location: /events/attend.php?token=' . urlencode($token));
        exit;
    }

    $registrationModel = new Registration();
    $registration       = $registrationModel->find((int) $event['id'], $regNo);

    if (!$registration) {
        $_SESSION['attend_error'] = 'You are not registered for this event. Please register first.';
        header('Location: /events/register.php?token=' . urlencode($token));
        exit;
    }

    $session = $sessionInfo['session'];
    if ($session === null) {
        $_SESSION['attend_error'] = 'Attendance can only be signed during a scheduled session window.';
        header('Location: /events/attend.php?token=' . urlencode($token));
        exit;
    }

    if ($registrationModel->hasSignedSession((int) $registration['id'], $session)) {
        $_SESSION['attend_error'] = 'You have already signed for the ' . SESSION_LABELS[$session] . ' session today.';
        header('Location: /events/attend.php?token=' . urlencode($token));
        exit;
    }

    $method      = $isOnsite ? 'onsite' : 'virtual';
    $geoVerified = null;
    $submittedLat = null;
    $submittedLng = null;
    $distanceM   = null;
    $flagged     = false;
    $flagReason  = null;

    if ($isOnsite) {
        $lat = $_POST['geo_lat'] ?? '';
        $lng = $_POST['geo_lng'] ?? '';

        if ($lat === '' || $lng === '') {
            $_SESSION['attend_error'] = 'Location access is required to sign attendance for this on-site event.';
            header('Location: /events/attend.php?token=' . urlencode($token));
            exit;
        }

        $submittedLat = (float) $lat;
        $submittedLng = (float) $lng;

        $geoCheck = $eventModel->isWithinGeofence($event, $submittedLat, $submittedLng);
        $geoVerified = $geoCheck['within'];
        $distanceM   = $geoCheck['distance_m'];

        if (!$geoVerified) {
            $flagged    = true;
            $flagReason = 'Outside geofence radius (' . $distanceM . 'm from venue)';
        }
    }

    try {
        $registrationModel->recordAttendance([
            'registration_id' => (int) $registration['id'],
            'session'         => $session,
            'method'          => $method,
            'geo_verified'    => $geoVerified,
            'submitted_lat'   => $submittedLat,
            'submitted_lng'   => $submittedLng,
            'distance_m'      => $distanceM,
            'flagged'         => $flagged,
            'flag_reason'     => $flagReason,
        ]);

        $_SESSION['attend_success'] = true;
        $_SESSION['attend_session'] = $session;
        $_SESSION['attend_flagged'] = $flagged;
        header('Location: /events/attend.php?token=' . urlencode($token) . '&done=1');
        exit;

    } catch (\Throwable $e) {
        error_log('Attendance error: ' . $e->getMessage());
        $_SESSION['attend_error'] = 'Failed to record attendance. Please try again.';
        header('Location: /events/attend.php?token=' . urlencode($token));
        exit;
    }
}

$attendSuccess = $_SESSION['attend_success'] ?? false;
$attendSession = $_SESSION['attend_session'] ?? '';
$attendFlagged = $_SESSION['attend_flagged'] ?? false;
unset($_SESSION['attend_success'], $_SESSION['attend_session'], $_SESSION['attend_flagged']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance — <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 1.5rem; }
.attend-wrap { max-width: 480px; margin: 1.5rem auto; }
.attend-header { text-align: center; margin-bottom: 1.5rem; }
.attend-header h1 { font-size: 1.1rem; font-weight: 700; color: var(--kvb-green-dark); margin: 0; }
.attend-header p { font-size: .85rem; color: var(--text-muted); margin: .3rem 0 0; }
.session-pill { text-align: center; margin-bottom: 1rem; }
#geo-status { font-size: .8rem; padding: .6rem .75rem; border-radius: 8px; margin-bottom: 1rem; }
#geo-status.pending { background: #f0f0f0; color: var(--text-muted); }
#geo-status.granted { background: var(--kvb-green-light); color: var(--kvb-green-dark); }
#geo-status.denied  { background: var(--kvb-red-light); color: var(--kvb-red-dark); }
#submit-btn:disabled { opacity: .5; cursor: not-allowed; }
</style>
</head>
<body>
<div class="attend-wrap">
    <div class="attend-header">
        <h1><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($event['venue'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <?php if ($attendSuccess): ?>
        <div class="card" style="text-align:center;padding:2rem;">
            <div style="font-size:3rem;">&#10003;</div>
            <h2 style="color:var(--kvb-green-dark);margin:.5rem 0;">Attendance Recorded</h2>
            <p>Your <?= htmlspecialchars(SESSION_LABELS[$attendSession] ?? $attendSession, ENT_QUOTES, 'UTF-8') ?> session attendance has been saved.</p>
            <?php if ($attendFlagged): ?>
                <p style="font-size:.82rem;color:var(--kvb-red-dark);margin-top:.75rem;">
                    Your location was outside the expected event radius. This record has been flagged for admin review.
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>

        <div class="session-pill">
            <?php if ($sessionInfo['session']): ?>
                <span class="pill"><?= htmlspecialchars(SESSION_LABELS[$sessionInfo['session']], ENT_QUOTES, 'UTF-8') ?> Session</span>
            <?php else: ?>
                <span class="pill" style="background:var(--kvb-red-light);color:var(--kvb-red-dark);">Outside session hours</span>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="card">
            <?php if ($isOnsite): ?>
                <div id="geo-status" class="pending">Checking location access…</div>
            <?php endif; ?>

            <form method="POST" action="/events/attend.php?token=<?= urlencode($token) ?>" id="attend-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="geo_lat" id="geo_lat">
                <input type="hidden" name="geo_lng" id="geo_lng">

                <div class="form-group">
                    <label for="reg_no">Registration Number <span class="required">*</span></label>
                    <input type="text" id="reg_no" name="reg_no" required placeholder="e.g. 1234 or VTA1234">
                </div>

                <button type="submit" class="btn btn-primary" id="submit-btn" style="width:100%;margin-top:.5rem;" <?= $isOnsite ? 'disabled' : '' ?>>
                    Sign Attendance
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php if ($isOnsite && !$attendSuccess): ?>
<script>
const geoStatus = document.getElementById('geo-status');
const submitBtn = document.getElementById('submit-btn');
const latInput  = document.getElementById('geo_lat');
const lngInput  = document.getElementById('geo_lng');

function onGeoSuccess(position) {
    latInput.value = position.coords.latitude;
    lngInput.value = position.coords.longitude;
    geoStatus.textContent = 'Location confirmed.';
    geoStatus.className = 'granted';
    submitBtn.disabled = false;
}

function onGeoError() {
    geoStatus.textContent = 'Location access is required to sign attendance. Please enable location in your browser settings and reload this page.';
    geoStatus.className = 'denied';
    submitBtn.disabled = true;
}

function requestLocation() {
    if (!navigator.geolocation) {
        onGeoError();
        return;
    }
    navigator.geolocation.getCurrentPosition(onGeoSuccess, onGeoError, {
        enableHighAccuracy: true,
        timeout: 10000,
    });
}

if (navigator.permissions) {
    navigator.permissions.query({ name: 'geolocation' }).then(status => {
        requestLocation();
        status.onchange = requestLocation;
    }).catch(requestLocation);
} else {
    requestLocation();
}
</script>
<?php endif; ?>
</body>
</html>
