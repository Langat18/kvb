<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;
use VetBoard\Participant;
use VetBoard\Registration;
use VetBoard\SessionWindowRepository;

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
    $now     = date('H:i:s');
    $windows = (new SessionWindowRepository())->list(true);

    foreach ($windows as $w) {
        if ($now >= $w['start_time'] && $now <= $w['end_time']) {
            return ['session' => $w['item_key'], 'flagged' => false, 'reason' => null];
        }
    }

    return ['session' => null, 'flagged' => true, 'reason' => 'Outside all session hours'];
}

$sessionLabels = (new SessionWindowRepository())->labelMap();
$sessionInfo   = detectSession();
$eventMode     = $event['event_mode'];
$isPureOnsite  = $eventMode === 'onsite';
$isHybrid      = $eventMode === 'hybrid';
$error         = $_SESSION['attend_error'] ?? null;
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

    if ($isHybrid) {
        $method = $_POST['attendance_method'] ?? '';
        if (!in_array($method, ['onsite', 'virtual'], true)) {
            $_SESSION['attend_error'] = 'Please select whether you are attending on-site or virtually.';
            header('Location: /events/attend.php?token=' . urlencode($token));
            exit;
        }
    } else {
        $method = $isPureOnsite ? 'onsite' : 'virtual';
    }

    $isOnsiteSubmission = $method === 'onsite';

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
        $_SESSION['attend_error'] = 'You have already signed for the ' . ($sessionLabels[$session] ?? $session) . ' session today.';
        header('Location: /events/attend.php?token=' . urlencode($token));
        exit;
    }

    $geoVerified  = null;
    $submittedLat = null;
    $submittedLng = null;
    $distanceM    = null;
    $flagged      = false;
    $flagReason   = null;

    if ($isOnsiteSubmission) {
        $lat = $_POST['geo_lat'] ?? '';
        $lng = $_POST['geo_lng'] ?? '';

        if ($lat === '' || $lng === '') {
            $_SESSION['attend_error'] = 'Location access is required to sign attendance on-site.';
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

        $pointsStatus = $registrationModel->getPointsStatus((int) $registration['id'], $session);

        if ($pointsStatus === 'pending_quiz') {
            $_SESSION['quiz_context'] = [
                'token'            => $token,
                'session'          => $session,
                'event_id'         => (int) $event['id'],
                'registration_id'  => (int) $registration['id'],
                'reg_no'           => $regNo,
            ];
            header('Location: /events/quiz.php?token=' . urlencode($token) . '&session=' . urlencode($session));
            exit;
        }

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
<title>Attendance - <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.method-choice { display: flex; gap: .6rem; margin-bottom: 1rem; }
.method-choice label {
    flex: 1; display: flex; align-items: center; justify-content: center; gap: .5rem;
    font-weight: 500; white-space: nowrap; text-align: center;
    padding: .75rem .5rem; border: 1.5px solid #d8dde3; border-radius: 10px; cursor: pointer; font-size: .88rem;
    transition: var(--transition);
}
.method-choice input[type="radio"] { accent-color: var(--kvb-green-dark); width: 16px; height: 16px; margin: 0; flex-shrink: 0; }
.method-choice label:hover { border-color: var(--kvb-green); }
.method-choice label.selected { border-color: var(--kvb-green-dark); background: var(--kvb-green-light); font-weight: 600; }
#submit-btn:disabled { opacity: .5; cursor: not-allowed; }
</style>
</head>
<body>
<div class="public-page">
<div class="public-shell">
    <div class="public-header">
        <h1><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($event['venue'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <?php if ($attendSuccess): ?>
        <div class="card" style="text-align:center;padding:2rem;">
            <div style="font-size:3rem;">&#10003;</div>
            <h2 style="color:var(--kvb-green-dark);margin:.5rem 0;">Attendance Recorded</h2>
            <p>Your <?= htmlspecialchars($sessionLabels[$attendSession] ?? $attendSession, ENT_QUOTES, 'UTF-8') ?> session attendance has been saved.</p>
            <?php if ($attendFlagged): ?>
                <p style="font-size:.82rem;color:var(--kvb-red-dark);margin-top:.75rem;">
                    Your location was outside the expected event radius. This record has been flagged for admin review before points are released.
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>

        <div style="text-align:center;margin-bottom:1rem;">
            <?php if ($sessionInfo['session']): ?>
                <span class="pill"><?= htmlspecialchars($sessionLabels[$sessionInfo['session']] ?? $sessionInfo['session'], ENT_QUOTES, 'UTF-8') ?> Session</span>
            <?php else: ?>
                <span class="pill" style="background:var(--kvb-red-light);color:var(--kvb-red-dark);">Outside session hours</span>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="card">
            <div id="geo-status" class="status-box <?= $isPureOnsite ? 'pending' : '' ?>">Checking location access&hellip;</div>

            <form method="POST" action="/events/attend.php?token=<?= urlencode($token) ?>" id="attend-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="geo_lat" id="geo_lat">
                <input type="hidden" name="geo_lng" id="geo_lng">

                <?php if ($isHybrid): ?>
                    <div class="method-choice" id="method-choice">
                        <label for="method-onsite">
                            <input type="radio" name="attendance_method" id="method-onsite" value="onsite"> On-site
                        </label>
                        <label for="method-virtual">
                            <input type="radio" name="attendance_method" id="method-virtual" value="virtual"> Virtual
                        </label>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="reg_no">Registration Number <span class="required">*</span></label>
                    <input type="text" id="reg_no" name="reg_no" required placeholder="e.g. 1234 or VTA1234">
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="submit-btn" style="margin-top:.5rem;" <?= ($isPureOnsite || $isHybrid) ? 'disabled' : '' ?>>
                    Sign Attendance
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>
</div>

<?php if (!$attendSuccess): ?>
<script>
const geoStatus  = document.getElementById('geo-status');
const submitBtn  = document.getElementById('submit-btn');
const latInput   = document.getElementById('geo_lat');
const lngInput   = document.getElementById('geo_lng');
const isHybrid   = <?= $isHybrid ? 'true' : 'false' ?>;
const isPureOnsite = <?= $isPureOnsite ? 'true' : 'false' ?>;

function onGeoSuccess(position) {
    latInput.value = position.coords.latitude;
    lngInput.value = position.coords.longitude;
    geoStatus.textContent = 'Location confirmed.';
    geoStatus.className = 'status-box granted';
    submitBtn.disabled = false;
}

function onGeoError() {
    geoStatus.textContent = 'Location access is required to sign attendance on-site. Please enable location in your browser settings and reload this page.';
    geoStatus.className = 'status-box denied';
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

if (isPureOnsite) {
    geoStatus.className = 'status-box pending';
    if (navigator.permissions) {
        navigator.permissions.query({ name: 'geolocation' }).then(status => {
            requestLocation();
            status.onchange = requestLocation;
        }).catch(requestLocation);
    } else {
        requestLocation();
    }
}

if (isHybrid) {
    const radios = document.querySelectorAll('input[name="attendance_method"]');
    radios.forEach(radio => {
        radio.addEventListener('change', () => {
            document.querySelectorAll('.method-choice label').forEach(l => l.classList.remove('selected'));
            radio.closest('label').classList.add('selected');

            if (radio.value === 'virtual') {
                geoStatus.className = '';
                submitBtn.disabled = false;
            } else {
                geoStatus.className = 'status-box pending';
                geoStatus.textContent = 'Checking location access…';
                submitBtn.disabled = true;
                requestLocation();
            }
        });
    });
}
</script>
<?php endif; ?>
</body>
</html>
