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
    die('Invalid link.');
}

$eventModel = new Event();
$event      = $eventModel->findByToken($token);

if (!$event) {
    http_response_code(404);
    die('Event not found.');
}

$error   = $_SESSION['reg_error']   ?? null;
$success = $_SESSION['reg_success'] ?? null;
unset($_SESSION['reg_error'], $_SESSION['reg_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $regNo    = strtoupper(trim($_POST['reg_no'] ?? ''));
    $idNumber = trim($_POST['id_number']         ?? '');
    $name     = trim($_POST['name']              ?? '');
    $email    = strtolower(trim($_POST['email']  ?? ''));
    $phone    = trim($_POST['phone']              ?? '');
    $signature = trim($_POST['signature']         ?? '');

    if (empty($regNo) || empty($idNumber) || empty($name) || empty($email) || empty($phone)) {
        $_SESSION['reg_error'] = 'All fields are required.';
        header('Location: /events/register.php?token=' . urlencode($token));
        exit;
    }

    if (!Participant::isValidRegNo($regNo)) {
        $_SESSION['reg_error'] = 'Invalid registration number format. Expected XXXX, VTAXXXX, VTBXXXX or VTCXXXX.';
        header('Location: /events/register.php?token=' . urlencode($token));
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['reg_error'] = 'Please enter a valid email address.';
        header('Location: /events/register.php?token=' . urlencode($token));
        exit;
    }

    if (empty($signature) || !str_starts_with($signature, 'data:image/')) {
        $_SESSION['reg_error'] = 'A signature is required to complete registration.';
        header('Location: /events/register.php?token=' . urlencode($token));
        exit;
    }

    if ($event['event_mode'] === 'onsite') {
        $geoLat = $_POST['geo_lat'] ?? '';
        $geoLng = $_POST['geo_lng'] ?? '';

        if ($geoLat === '' || $geoLng === '') {
            $_SESSION['reg_error'] = 'Location access is required to register for this on-site event. Please enable location and try again.';
            header('Location: /events/register.php?token=' . urlencode($token));
            exit;
        }
    }

    $participantModel = new Participant();

    try {
        $existing = $participantModel->findByRegNo($regNo);

        if (!$existing) {
            $participantModel->create([
                'reg_no'    => $regNo,
                'name'      => $name,
                'id_number' => $idNumber,
                'email'     => $email,
                'phone'     => $phone,
            ]);
        }

        $registrationModel = new Registration();

        if ($registrationModel->find((int) $event['id'], $regNo)) {
            $_SESSION['reg_error'] = 'You are already registered for this event. Please use Sign Attendance instead.';
            header('Location: /events/register.php?token=' . urlencode($token));
            exit;
        }

        $registrationModel->create((int) $event['id'], $regNo, $signature);
        $_SESSION['reg_success'] = 'Registration successful. You may now sign your attendance.';
        header('Location: /events/attend.php?token=' . urlencode($token));
        exit;

    } catch (\InvalidArgumentException $e) {
        $_SESSION['reg_error'] = $e->getMessage();
        header('Location: /events/register.php?token=' . urlencode($token));
        exit;

    } catch (\Throwable $e) {
        error_log('Registration error: ' . $e->getMessage());
        $_SESSION['reg_error'] = 'Registration failed. Please try again.';
        header('Location: /events/register.php?token=' . urlencode($token));
        exit;
    }
}

$startDate = date('d M Y', strtotime($event['start_date']));
$endDate   = date('d M Y', strtotime($event['end_date']));
$isOnsite  = $event['event_mode'] === 'onsite';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 1.5rem; }
.reg-wrap { max-width: 540px; margin: 1.5rem auto; }
.reg-header { text-align: center; margin-bottom: 1.5rem; }
.reg-header h1 { font-size: 1.1rem; font-weight: 700; color: var(--kvb-green-dark); margin: 0; }
.reg-header p { font-size: .85rem; color: var(--text-muted); margin: .3rem 0 0; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
@media (max-width: 520px) { .form-row { grid-template-columns: 1fr; } }
.form-group { margin-bottom: .85rem; }
.notice-box {
    font-size: .82rem; color: var(--text-muted); margin-bottom: 1rem;
    padding: .6rem .75rem; background: var(--kvb-green-light);
    border-left: 3px solid var(--kvb-green); border-radius: 6px;
}
#signature-pad {
    border: 1px solid #d8dde3; border-radius: 10px;
    width: 100%; height: 140px; touch-action: none; background: #fff; display: block;
}
#geo-status {
    font-size: .8rem; padding: .6rem .75rem; border-radius: 8px; margin-bottom: 1rem;
}
#geo-status.pending { background: #f0f0f0; color: var(--text-muted); }
#geo-status.granted { background: var(--kvb-green-light); color: var(--kvb-green-dark); }
#geo-status.denied  { background: var(--kvb-red-light); color: var(--kvb-red-dark); }
#submit-btn:disabled { opacity: .5; cursor: not-allowed; }
</style>
</head>
<body>
<div class="reg-wrap">
    <div class="reg-header">
        <h1><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($event['venue'] ?? '', ENT_QUOTES, 'UTF-8') ?> &bull; <?= $startDate ?> &ndash; <?= $endDate ?></p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card">
        <p class="notice-box">
            Please fill in your official details as they appear in official CPD directory and records.
            <?php if ($event['event_mode'] === 'hybrid'): ?>
                <br>This event supports both on-site and virtual attendance — you will choose how you are attending each session when you sign in.
            <?php endif; ?>
        </p>

        <?php if ($isOnsite): ?>
            <div id="geo-status" class="pending">Checking location access…</div>
        <?php endif; ?>

        <form method="POST" action="/events/register.php?token=<?= urlencode($token) ?>" id="reg-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="geo_lat" id="geo_lat">
            <input type="hidden" name="geo_lng" id="geo_lng">

            <div class="form-row">
                <div class="form-group">
                    <label for="reg_no">Registration Number <span class="required">*</span></label>
                    <input type="text" id="reg_no" name="reg_no" required placeholder="e.g. 1234 or VTA1234">
                    <small id="lookup-status" style="color:var(--text-muted);"></small>
                </div>
                <div class="form-group">
                    <label for="id_number">ID Number <span class="required">*</span></label>
                    <input type="text" id="id_number" name="id_number" required>
                </div>
            </div>

            <div class="form-group">
                <label for="name">Full Name <span class="required">*</span></label>
                <input type="text" id="name" name="name" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="email">Email Address <span class="required">*</span></label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="phone">Phone <span class="required">*</span></label>
                    <input type="tel" id="phone" name="phone" required>
                </div>
            </div>

            <div class="form-group">
                <label>Signature <span class="required">*</span></label>
                <canvas id="signature-pad"></canvas>
                <div style="display:flex;justify-content:flex-end;margin-top:.35rem;">
                    <button type="button" id="clear-sig" style="font-size:.78rem;background:none;border:none;color:var(--text-muted);cursor:pointer;padding:0;">Clear</button>
                </div>
                <input type="hidden" name="signature" id="signature-data">
            </div>

            <button type="submit" class="btn btn-primary" id="submit-btn" style="width:100%;" <?= $isOnsite ? 'disabled' : '' ?>>
                Register
            </button>
        </form>

        <p style="text-align:center;margin-top:1rem;font-size:.82rem;color:var(--text-muted);">
            Already registered?
            <a href="/events/attend.php?token=<?= urlencode($token) ?>" style="color:var(--kvb-green-dark);">Sign Attendance</a>
        </p>
    </div>
</div>

<script>
const canvas   = document.getElementById('signature-pad');
const ctx      = canvas.getContext('2d');
let drawing    = false;
let hasSig     = false;

function resizeCanvas() {
    const w = canvas.offsetWidth;
    canvas.width  = w;
    canvas.height = 140;
    ctx.strokeStyle = '#1a1a1a';
    ctx.lineWidth   = 1.8;
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';
}
resizeCanvas();
window.addEventListener('resize', resizeCanvas);

function getPos(e) {
    const r = canvas.getBoundingClientRect();
    const src = e.touches ? e.touches[0] : e;
    return { x: src.clientX - r.left, y: src.clientY - r.top };
}

canvas.addEventListener('mousedown',  e => { drawing = true; const p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); });
canvas.addEventListener('mousemove',  e => { if (!drawing) return; const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); hasSig = true; });
canvas.addEventListener('mouseup',    () => { drawing = false; });
canvas.addEventListener('mouseleave', () => { drawing = false; });
canvas.addEventListener('touchstart', e => { e.preventDefault(); drawing = true; const p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }, { passive: false });
canvas.addEventListener('touchmove',  e => { e.preventDefault(); if (!drawing) return; const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); hasSig = true; }, { passive: false });
canvas.addEventListener('touchend',   () => { drawing = false; });

const regNoEl      = document.getElementById('reg_no');
const lookupStatus = document.getElementById('lookup-status');
let lookupTimer;

function lockFields(data) {
    document.getElementById('name').value  = data.name  ?? '';
    document.getElementById('email').value = data.email ?? '';
    document.getElementById('phone').value = data.phone ?? '';
    ['name', 'email', 'phone'].forEach(id => {
        document.getElementById(id).setAttribute('readonly', true);
    });
    lookupStatus.textContent = 'Returning participant — details locked. Please enter your ID number to confirm.';
    lookupStatus.style.color = 'var(--kvb-green-dark)';
}

function unlockFields() {
    ['name', 'email', 'phone'].forEach(id => {
        document.getElementById(id).removeAttribute('readonly');
    });
    lookupStatus.textContent = '';
}

regNoEl.addEventListener('input', () => {
    clearTimeout(lookupTimer);
    unlockFields();
    lookupTimer = setTimeout(() => {
        const regNo = regNoEl.value.trim();
        if (regNo.length < 4) return;
        fetch('/events/lookup.php?reg_no=' + encodeURIComponent(regNo))
            .then(r => r.json())
            .then(d => { if (d.found) lockFields(d); })
            .catch(() => {});
    }, 500);
});

document.getElementById('clear-sig').addEventListener('click', () => {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    hasSig = false;
    document.getElementById('signature-data').value = '';
});

document.getElementById('reg-form').addEventListener('submit', function(e) {
    if (!hasSig) {
        e.preventDefault();
        alert('Please sign before submitting.');
        return;
    }
    document.getElementById('signature-data').value = canvas.toDataURL('image/png');
});

<?php if ($isOnsite): ?>
const geoStatus  = document.getElementById('geo-status');
const submitBtn  = document.getElementById('submit-btn');
const latInput   = document.getElementById('geo_lat');
const lngInput   = document.getElementById('geo_lng');

function onGeoSuccess(position) {
    latInput.value = position.coords.latitude;
    lngInput.value = position.coords.longitude;
    geoStatus.textContent = 'Location confirmed.';
    geoStatus.className = 'granted';
    submitBtn.disabled = false;
}

function onGeoError() {
    geoStatus.textContent = 'Location access is required to register for this on-site event. Please enable location in your browser settings and reload this page.';
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
<?php endif; ?>
</script>
</body>
</html>
