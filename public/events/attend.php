<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../templates/icons.php';
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
    $msg = $today < $event['start_date'] ? 'This event has not started yet.' : 'This event has ended.';
    die($msg);
}

function detectSession(array $event, Event $eventModel): array
{
    return $eventModel->resolveSignInSession((int) $event['id']);
}

// Sign-in opens at the session start, or for sessions requiring late sign-in,
// the lead interval before the end (never earlier than the start).
function virtualSignInThreshold(array $window): int
{
    $startTs = strtotime(date('Y-m-d') . ' ' . $window['start_time']);

    if (isset($window['require_late_signin']) && !$window['require_late_signin']) {
        return $startTs;
    }

    $endTs = strtotime(date('Y-m-d') . ' ' . $window['end_time']);

    return max($endTs - (\VetBoard\SigninRules::lead($window, 'virtual') * 60), $startTs);
}

// Sign-in opens at the session start, or for sessions requiring late sign-in,
// the lead interval before the end (never earlier than the start).
function onsiteSignInThreshold(array $window): int
{
    $startTs = strtotime(date('Y-m-d') . ' ' . $window['start_time']);

    if (isset($window['require_late_signin']) && !$window['require_late_signin']) {
        return $startTs;
    }

    $endTs = strtotime(date('Y-m-d') . ' ' . $window['end_time']);

    return max($endTs - (\VetBoard\SigninRules::lead($window, 'onsite') * 60), $startTs);
}

function meetingProviderLabel(?string $key): string
{
    return $key !== null ? (MEETING_PROVIDERS[$key] ?? 'the meeting') : 'the meeting';
}

$sessionLabels = array_column($eventModel->getSessions((int) $event['id']), 'label', 'id');
$sessionInfo   = detectSession($event, $eventModel);
$eventMode     = $event['event_mode'];
$isPureOnsite  = $eventMode === 'onsite';
$isHybrid      = $eventMode === 'hybrid';
$error         = $_SESSION['attend_error'] ?? null;
unset($_SESSION['attend_error']);

$virtualSignInOpen    = true;
$virtualSignInOpensAt = null;
if ($sessionInfo['window'] !== null && !$isPureOnsite) {
    $thresholdTs          = virtualSignInThreshold($sessionInfo['window']);
    $virtualSignInOpen    = time() >= $thresholdTs;
    $virtualSignInOpensAt = date('g:i A', $thresholdTs);
}

$onsiteSignInOpen    = true;
$onsiteSignInOpensAt = null;
if ($sessionInfo['window'] !== null) {
    $onsiteThresholdTs   = onsiteSignInThreshold($sessionInfo['window']);
    $onsiteSignInOpen    = time() >= $onsiteThresholdTs;
    $onsiteSignInOpensAt = date('g:i A', $onsiteThresholdTs);
}

$existingRegNo   = strtoupper(trim($_GET['reg_no'] ?? ''));
$isRegisteredView = false;
if ($existingRegNo !== '' && Participant::isValidRegNo($existingRegNo)) {
    $existingReg = (new Registration())->find((int) $event['id'], $existingRegNo);
    $isRegisteredView = (bool) $existingReg;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    if (($_POST['action'] ?? '') === 'confirm_day') {
        $back              = '/events/attend.php?token=' . urlencode($token);
        if (isset($_GET['confirm'])) {
            $back .= '&confirm=1';
        }
        $regNo             = strtoupper(trim($_POST['reg_no'] ?? ''));
        $registrationModel = new Registration();

        $fail = static function (string $message) use ($back): void {
            $_SESSION['attend_error'] = $message;
            header('Location: ' . $back);
            exit;
        };

        if (!$registrationModel->dayConfirmationEnabled((int) $event['id'])) {
            $fail('Daily confirmation is not enabled for this event.');
        }

        if ($regNo === '' || !Participant::isValidRegNo($regNo)) {
            $fail('Please enter a valid registration number.');
        }

        $registration = $registrationModel->find((int) $event['id'], $regNo);

        if (!$registration) {
            $fail('You are not registered for this event yet. Register first.');
        }

        $registrationId = (int) $registration['id'];

        if ($registrationModel->isDayConfirmed($registrationId, $today)) {
            $_SESSION['attend_day_confirmed'] = true;
            header('Location: ' . $back);
            exit;
        }

        $deviceId = Auth::deviceId();

        if ($registrationModel->deviceConfirmedOther($today, $deviceId, $registrationId)) {
            $fail('This device was already used for another registration number today.');
        }

        $method = $isHybrid ? ($_POST['day_method'] ?? '') : ($isPureOnsite ? 'onsite' : 'virtual');

        if (!in_array($method, ['onsite', 'virtual'], true)) {
            $fail('Choose on-site or virtual.');
        }

        $forwarded = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')[0]);
        $ip        = filter_var($forwarded, FILTER_VALIDATE_IP) ?: ($_SERVER['REMOTE_ADDR'] ?? null);
        $meta      = ['device_id' => $deviceId, 'ip' => $ip !== null ? substr((string) $ip, 0, 45) : null];

        if ($method === 'onsite') {
            $lat = $_POST['geo_lat'] ?? '';
            $lng = $_POST['geo_lng'] ?? '';

            if ($lat === '' || $lng === '') {
                $fail('Allow location to confirm you are on-site.');
            }

            $geo = $eventModel->isWithinGeofence($event, (float) $lat, (float) $lng);

            if (!$geo['within']) {
                $fail('You appear to be ' . (int) $geo['distance_m'] . 'm from the venue. Presence can only be confirmed at the venue.');
            }

            $meta['geo_verified'] = true;
            $meta['distance_m']   = (int) $geo['distance_m'];
        }

        try {
            $registrationModel->confirmDay($registrationId, $today, 'self', $meta);
            $_SESSION['attend_day_confirmed'] = true;
        } catch (\Throwable $e) {
            error_log('Day confirmation error: ' . $e->getMessage());
            $_SESSION['attend_error'] = 'Failed to confirm presence. Please try again.';
        }

        header('Location: ' . $back);
        exit;
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
            $_SESSION['attend_error'] = 'Choose on-site or virtual.';
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
        $_SESSION['attend_error'] = 'You are not registered for this event yet. Register first.';
        header('Location: /events/register.php?token=' . urlencode($token));
        exit;
    }

    $sessionId = $sessionInfo['session_id'];
    if ($sessionId === null) {
        $_SESSION['attend_error'] = 'Sign-in is only open during a session.';
        header('Location: /events/attend.php?token=' . urlencode($token));
        exit;
    }

    if ($registrationModel->hasSignedSession((int) $registration['id'], $sessionId)) {
        $_SESSION['attend_error'] = 'You have already signed for the ' . ($sessionLabels[$sessionId] ?? $sessionId) . ' session today.';
        header('Location: /events/attend.php?token=' . urlencode($token));
        exit;
    }

    $deviceId = Auth::deviceId();

    if ($registrationModel->deviceSignedDifferentReg($sessionId, $deviceId, (int) $registration['id'])) {
        $_SESSION['attend_error'] = 'This device already signed in someone else for this session. One person per device.';
        header('Location: /events/attend.php?token=' . urlencode($token));
        exit;
    }

    try {

        if ($isOnsiteSubmission) {

            // On-site: geofence and timing checks only.
            if ($sessionInfo['window'] !== null) {
                $onsiteThresholdTs = onsiteSignInThreshold($sessionInfo['window']);

                if (time() < $onsiteThresholdTs) {
                    $_SESSION['attend_error'] = 'On-site attendance for the ' . ($sessionLabels[$sessionId] ?? $sessionId) .
                        ' session opens at ' . date('g:i A', $onsiteThresholdTs) . '. Please return then.';
                    header('Location: /events/attend.php?token=' . urlencode($token));
                    exit;
                }
            }

            $lat = $_POST['geo_lat'] ?? '';
            $lng = $_POST['geo_lng'] ?? '';

            if ($lat === '' || $lng === '') {
                $_SESSION['attend_error'] = 'Location access is required to sign attendance on-site.';
                header('Location: /events/attend.php?token=' . urlencode($token));
                exit;
            }

            $submittedLat = (float) $lat;
            $submittedLng = (float) $lng;

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
                    $flagReason   = 'Too far from venue to qualify for points (' . $excessM . 'm beyond radius) — not eligible for review';
                } else {
                    $flagReason = 'Outside geofence radius (' . $distanceM . 'm from venue)';
                }
            }

            $registrationModel->recordAttendance([
                'registration_id' => (int) $registration['id'],
                'session_id'      => $sessionId,
                'method'          => 'onsite',
                'geo_verified'    => $geoVerified,
                'submitted_lat'   => $submittedLat,
                'submitted_lng'   => $submittedLng,
                'distance_m'      => $distanceM,
                'flagged'         => $flagged,
                'flag_reason'     => $flagReason,
                'points_denied'   => $pointsDenied,
                'device_id'       => $deviceId,
            ]);

            $_SESSION['attend_success']       = true;
            $_SESSION['attend_session']       = $sessionId;
            $_SESSION['attend_flagged']       = $flagged;
            $_SESSION['attend_points_denied'] = $pointsDenied;
            $_SESSION['attend_method']        = 'onsite';
            header('Location: /events/attend.php?token=' . urlencode($token) . '&done=1');
            exit;

        } else {

            // Virtual: timing check, plus the quiz where configured.
            if ($sessionInfo['window'] !== null) {
                $thresholdTs = virtualSignInThreshold($sessionInfo['window']);

                if (time() < $thresholdTs) {
                    $_SESSION['attend_error'] = 'Virtual attendance for the ' . ($sessionLabels[$sessionId] ?? $sessionId) .
                        ' session opens at ' . date('g:i A', $thresholdTs) . '. Please stay connected and return then.';
                    header('Location: /events/attend.php?token=' . urlencode($token));
                    exit;
                }
            }

            $registrationModel->recordAttendance([
                'registration_id' => (int) $registration['id'],
                'session_id'      => $sessionId,
                'method'          => 'virtual',
                'geo_verified'    => null,
                'submitted_lat'   => null,
                'submitted_lng'   => null,
                'distance_m'      => null,
                'flagged'         => false,
                'flag_reason'     => null,
                'points_denied'   => false,
                'device_id'       => $deviceId,
            ]);

            $pointsStatus = $registrationModel->getPointsStatus((int) $registration['id'], $sessionId);
            $quizPending  = ($pointsStatus === 'pending_quiz');

            if ($quizPending) {
                $_SESSION['quiz_context'] = [
                    'token'           => $token,
                    'session'         => (string) $sessionId,
                    'registration_id' => (int) $registration['id'],
                    'event_id'        => (int) $event['id'],
                ];
            }

            $_SESSION['attend_success']          = true;
            $_SESSION['attend_session']          = $sessionId;
            $_SESSION['attend_flagged']          = false;
            $_SESSION['attend_points_denied']    = false;
            $_SESSION['attend_method']           = 'virtual';
            $_SESSION['attend_meeting_link']     = $event['meeting_link']     ?? null;
            $_SESSION['attend_meeting_provider'] = $event['meeting_provider'] ?? null;
            $_SESSION['attend_quiz_pending']     = $quizPending;
            header('Location: /events/attend.php?token=' . urlencode($token) . '&done=1');
            exit;
        }

    } catch (\Throwable $e) {
        error_log('Attendance error: ' . $e->getMessage());
        $_SESSION['attend_error'] = 'Failed to record attendance. Please try again.';
        header('Location: /events/attend.php?token=' . urlencode($token));
        exit;
    }
}

$attendSuccess         = $_SESSION['attend_success']          ?? false;
$attendSession         = $_SESSION['attend_session']          ?? '';
$attendFlagged         = $_SESSION['attend_flagged']          ?? false;
$attendPointsDenied    = $_SESSION['attend_points_denied']    ?? false;
$attendMethod          = $_SESSION['attend_method']           ?? '';
$attendMeetingLink     = $_SESSION['attend_meeting_link']     ?? null;
$attendMeetingProvider = $_SESSION['attend_meeting_provider'] ?? null;
$attendQuizPending     = $_SESSION['attend_quiz_pending']     ?? false;
$dayConfirmed         = $_SESSION['attend_day_confirmed'] ?? false;
unset($_SESSION['attend_day_confirmed']);
$dayEnabled           = (new Registration())->dayConfirmationEnabled((int) $event['id']);
$confirmView          = isset($_GET['confirm']) && $dayEnabled;
unset(
    $_SESSION['attend_success'], $_SESSION['attend_session'], $_SESSION['attend_flagged'],
    $_SESSION['attend_points_denied'], $_SESSION['attend_method'], $_SESSION['attend_meeting_link'],
    $_SESSION['attend_meeting_provider'], $_SESSION['attend_quiz_pending']
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance - <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/style.css') ?>">
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
.meeting-cta { display: flex; flex-direction: column; gap: .6rem; margin-top: 1.25rem; }
.meeting-cta .btn { width: 100%; }
.timing-notice {
    font-size: .82rem; color: var(--kvb-green-dark); background: var(--kvb-green-light);
    border-radius: 8px; padding: .6rem .75rem; margin-bottom: 1rem;
}
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
            <?php if ($attendPointsDenied): ?>
                <div class="outcome-ico warn"><?= ui_icon('alert', 34) ?></div>
                <h2 style="color:var(--kvb-red-dark);margin:.5rem 0;">Attendance Recorded</h2>
                <p style="font-weight:600;color:var(--kvb-red-dark);">Points are not awarded — offsite.</p>
                <p style="font-size:.82rem;color:var(--text-muted);margin-top:.5rem;">Your check-in was too far from the venue to qualify for CPD points.</p>

            <?php else: ?>
                <div class="outcome-ico ok"><?= ui_icon('check', 34) ?></div>
                <h2 style="color:var(--kvb-green-dark);margin:.5rem 0;">Attendance Recorded</h2>
                <p>Your <?= htmlspecialchars($sessionLabels[$attendSession] ?? $attendSession, ENT_QUOTES, 'UTF-8') ?> session attendance has been saved.</p>

                <?php if ($attendFlagged): ?>
                    <p style="font-size:.82rem;color:var(--kvb-red-dark);margin-top:.75rem;">
                        Your location was outside the expected event radius. This record has been flagged for admin review before points are released.
                    </p>
                <?php endif; ?>

                <?php if ($attendMethod === 'virtual'): ?>
                    <div class="meeting-cta">
                        <?php if (!empty($attendMeetingLink)): ?>
                            <a href="<?= htmlspecialchars($attendMeetingLink, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
                                Join <?= htmlspecialchars(meetingProviderLabel($attendMeetingProvider), ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php else: ?>
                            <p style="font-size:.82rem;color:var(--kvb-red-dark);">No meeting link yet. Contact the organizer.</p>
                        <?php endif; ?>

                        <?php if ($attendQuizPending): ?>
                            <a href="/events/quiz.php?token=<?= urlencode($token) ?>&session=<?= urlencode($attendSession) ?>" class="btn btn-ghost">
                                Take the Session Quiz to Confirm Your Points
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <script>
            localStorage.setItem(
                'vetboard_attend_' + <?= (int) $event['id'] ?> + '_' + <?= json_encode($today) ?> + '_' + <?= json_encode($attendSession) ?>,
                '1'
            );
        </script>
    <?php else: ?>

        <?php if (!$confirmView): ?>
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

        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if (!$confirmView): ?>
        <?php if (!$isRegisteredView && !$isPureOnsite): ?>
            <div class="card" style="padding:1rem 1.25rem;margin-bottom:1rem;">
                <p style="font-size:.85rem;margin-bottom:.5rem;">Already registered? Enter your registration number to find your meeting link.</p>
                <form method="GET" action="/events/attend.php" style="display:flex;gap:.5rem;">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="text" name="reg_no" placeholder="e.g. 1234 or VTA1234" required style="flex:1;">
                    <button type="submit" class="btn btn-secondary">Find Link</button>
                </form>
                <?php if ($existingRegNo !== '' && !$isRegisteredView): ?>
                    <p style="font-size:.8rem;color:var(--kvb-red-dark);margin-top:.5rem;">Number not found. Check it or register first.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($isRegisteredView && !$isPureOnsite && !empty($event['meeting_link'])): ?>
            <div class="card" style="text-align:center;padding:1.25rem;margin-bottom:1rem;">
                <p style="margin-bottom:.6rem;font-size:.88rem;">You're registered for this event.</p>
                <a href="<?= htmlspecialchars($event['meeting_link'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-block">
                    Join <?= htmlspecialchars(meetingProviderLabel($event['meeting_provider'] ?? null), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
        <?php endif; ?>

        <?php if (!$isPureOnsite && $sessionInfo['window'] !== null && !$virtualSignInOpen): ?>
            <div class="timing-notice">
                Virtual attendance opens at <strong><?= htmlspecialchars($virtualSignInOpensAt, ENT_QUOTES, 'UTF-8') ?></strong> for this session &mdash; this confirms real participation rather than just a click at the start. <?= $isHybrid ? 'On-site check-in below is unaffected.' : 'Please stay connected and return at that time.' ?>
            </div>
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

                <button type="submit" class="btn btn-primary btn-block" id="submit-btn" style="margin-top:.5rem;"
                    <?= ($isPureOnsite || $isHybrid) ? 'disabled' : '' ?>
                    <?= (!$isPureOnsite && !$isHybrid && !$virtualSignInOpen) ? 'disabled' : '' ?>>
                    Earn Session Points
                </button>
            </form>
        </div>

        <?php endif; ?>

        <?php if ($confirmView): ?>
        <div class="card" style="margin-top:1rem;">
            <h2 style="font-size:1rem;font-weight:700;color:var(--kvb-green-dark);margin-bottom:.3rem;">Confirm Today&rsquo;s Presence</h2>
            <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:.75rem;">
                Records that you are here on <?= htmlspecialchars(date('d M Y'), ENT_QUOTES, 'UTF-8') ?>.
            </p>

            <?php if ($dayConfirmed): ?>
                <div class="alert alert-success">Your presence for today has been confirmed.</div>
            <?php endif; ?>

            <div id="day-geo-status" class="status-box pending" style="display:none;"></div>

            <form method="POST" action="/events/attend.php?token=<?= urlencode($token) ?><?= $confirmView ? '&amp;confirm=1' : '' ?>" id="day-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="confirm_day">
                <input type="hidden" name="geo_lat" id="day_geo_lat">
                <input type="hidden" name="geo_lng" id="day_geo_lng">

                <?php if ($isHybrid): ?>
                    <div class="method-choice">
                        <label><input type="radio" name="day_method" value="onsite"> On-site</label>
                        <label><input type="radio" name="day_method" value="virtual"> Virtual</label>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="day_reg_no">Registration Number <span class="required">*</span></label>
                    <input type="text" id="day_reg_no" name="reg_no" required placeholder="e.g. 1234 or VTA1234">
                </div>

                <button type="submit" class="btn btn-secondary btn-block" id="day-btn" style="margin-top:.5rem;" disabled>
                    Confirm Presence
                </button>
            </form>
        </div>
        <script>
        (function () {
            const form   = document.getElementById('day-form');
            const btn    = document.getElementById('day-btn');
            const status = document.getElementById('day-geo-status');
            const lat    = document.getElementById('day_geo_lat');
            const lng    = document.getElementById('day_geo_lng');
            const mode   = <?= json_encode($eventMode) ?>;
            let located  = false;

            function method() {
                if (mode !== 'hybrid') { return mode; }
                const checked = form.querySelector('input[name="day_method"]:checked');
                return checked ? checked.value : null;
            }

            function denied() {
                located = false;
                status.style.display = '';
                status.textContent = 'Allow location to confirm you are on-site, then reload.';
                status.className = 'status-box denied';
                btn.disabled = true;
            }

            function locate() {
                status.style.display = '';
                status.textContent = 'Checking location access…';
                status.className = 'status-box pending';

                if (!navigator.geolocation) { denied(); return; }

                navigator.geolocation.getCurrentPosition(function (position) {
                    lat.value = position.coords.latitude;
                    lng.value = position.coords.longitude;
                    located = true;
                    status.textContent = 'Location confirmed.';
                    status.className = 'status-box granted';
                    btn.disabled = false;
                }, denied, { enableHighAccuracy: true, timeout: 10000 });
            }

            function refresh() {
                const m = method();

                if (m === 'onsite') {
                    if (located) { btn.disabled = false; } else { btn.disabled = true; locate(); }
                    return;
                }

                status.style.display = 'none';
                btn.disabled = m === null;
            }

            form.querySelectorAll('input[name="day_method"]').forEach(function (radio) {
                radio.addEventListener('change', function () {
                    form.querySelectorAll('.method-choice label').forEach(function (l) {
                        l.classList.toggle('selected', l.querySelector('input').checked);
                    });
                    refresh();
                });
            });

            refresh();
        })();
        </script>
        <?php endif; ?>

    <?php endif; ?>
</div>
</div>

<?php if (!$attendSuccess && !$confirmView): ?>
<script>
const geoStatus  = document.getElementById('geo-status');
const submitBtn  = document.getElementById('submit-btn');
const latInput   = document.getElementById('geo_lat');
const lngInput   = document.getElementById('geo_lng');
const isHybrid   = <?= $isHybrid ? 'true' : 'false' ?>;
const isPureOnsite = <?= $isPureOnsite ? 'true' : 'false' ?>;
const virtualSignInOpen = <?= $virtualSignInOpen ? 'true' : 'false' ?>;
const onsiteSignInOpen = <?= $onsiteSignInOpen ? 'true' : 'false' ?>;
const onsiteSignInOpensAt = <?= $onsiteSignInOpensAt ? json_encode($onsiteSignInOpensAt) : 'null' ?>;

const deviceKey = <?= $sessionInfo['session_id'] ? json_encode('vetboard_attend_' . (int) $event['id'] . '_' . $today . '_' . $sessionInfo['session_id']) : 'null' ?>;
const deviceBlocked = deviceKey !== null && localStorage.getItem(deviceKey) === '1';

if (deviceBlocked) {
    document.getElementById('reg_no').disabled = true;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Already Signed on This Device';
    geoStatus.style.display = 'none';
    const methodChoice = document.getElementById('method-choice');
    if (methodChoice) methodChoice.style.display = 'none';
}

function onGeoSuccess(position) {
    latInput.value = position.coords.latitude;
    lngInput.value = position.coords.longitude;

    if (deviceBlocked) { return; }

    if (!onsiteSignInOpen) {
        geoStatus.textContent = onsiteSignInOpensAt
            ? 'Location confirmed. Sign-in opens at ' + onsiteSignInOpensAt + '.'
            : 'Location confirmed. Sign-in is not yet open for this session.';
        geoStatus.className = 'status-box pending';
        submitBtn.disabled = true;
        return;
    }

    geoStatus.textContent = 'Location confirmed.';
    geoStatus.className = 'status-box granted';
    submitBtn.disabled = false;
}

function onGeoError() {
    if (deviceBlocked) { return; }
    geoStatus.textContent = 'Turn on location in your browser settings, then reload this page.';
    geoStatus.className = 'status-box denied';
    submitBtn.disabled = true;
}

function requestLocation() {
    if (deviceBlocked) { return; }
    if (!navigator.geolocation) { onGeoError(); return; }
    navigator.geolocation.getCurrentPosition(onGeoSuccess, onGeoError, { enableHighAccuracy: true, timeout: 10000 });
}

if (isPureOnsite && !deviceBlocked) {
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

if (!isPureOnsite && !isHybrid) {
    geoStatus.style.display = 'none';
}

if (isHybrid && !deviceBlocked) {
    const radios = document.querySelectorAll('input[name="attendance_method"]');
    radios.forEach(radio => {
        radio.addEventListener('change', () => {
            document.querySelectorAll('.method-choice label').forEach(l => l.classList.remove('selected'));
            radio.closest('label').classList.add('selected');

            if (radio.value === 'virtual') {
                geoStatus.style.display = 'none';
                submitBtn.disabled = !virtualSignInOpen;
            } else {
                geoStatus.style.display = '';
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
