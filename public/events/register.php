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
$regNo   = $_SESSION['reg_success_regno'] ?? null;
unset($_SESSION['reg_error'], $_SESSION['reg_success'], $_SESSION['reg_success_regno']);

$justRegistered = isset($_GET['done']);
$showMeetingLink = ($event['event_mode'] !== 'onsite') && !empty($event['meeting_link']);

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

    if (!Participant::isValidNewRegNo($regNo)) {
        $_SESSION['reg_error'] = 'Check the registration number. Use 4 or 5 digits, or VTA, VTB or VTC followed by 5 digits.';
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
            $_SESSION['reg_error'] = 'Allow location to register for this on-site event, then try again.';
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
            $_SESSION['reg_error'] = 'You are already registered. Use Earn Session Points instead.';
            header('Location: /events/register.php?token=' . urlencode($token));
            exit;
        }

        $registrationModel->create((int) $event['id'], $regNo, $signature);
        $registrationModel->pmsObserve((int) $event['id'], $regNo, $name, $email, $phone);

        // Registration confirms interest, not presence 
        // It persists on reload (queried fresh from $event each
        // time), not a one-time session flash.
        $_SESSION['reg_success']       = 'Registration successful.';
        $_SESSION['reg_success_regno'] = $regNo;
        header('Location: /events/register.php?token=' . urlencode($token) . '&done=1');
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
<title>Register - <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/style.css') ?>">
<style>
.public-shell { max-width: 540px; }
#signature-pad {
    border: 1px solid #d8dde3; border-radius: 10px;
    width: 100%; height: 140px; touch-action: none; background: #fff; display: block;
}
#submit-btn:disabled { opacity: .5; cursor: not-allowed; }
</style>
</head>
<body>
<div class="public-page">
<div class="public-shell">
    <div class="public-header">
        <h1><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($event['venue'] ?? '', ENT_QUOTES, 'UTF-8') ?> &bull; <?= $startDate ?> &ndash; <?= $endDate ?></p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($justRegistered): ?>
        <div class="card" style="text-align:center;padding:1.5rem;margin-bottom:1rem;">
            <?php if ($showMeetingLink): ?>
                <p style="margin-bottom:.75rem;">You're registered. Save this link &mdash; you'll need it to join the meeting, and can return to this page any time to find it again.</p>
                <a href="<?= htmlspecialchars($event['meeting_link'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-block">
                    Join <?= htmlspecialchars(MEETING_PROVIDERS[$event['meeting_provider']] ?? 'the meeting', ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php elseif ($event['event_mode'] !== 'onsite'): ?>
                <p style="color:var(--kvb-red-dark);font-size:.85rem;">No meeting link yet. Contact the organizer.</p>
            <?php endif; ?>
            <p style="margin-top:1rem;font-size:.85rem;">
                <a href="/events/attend.php?token=<?= urlencode($token) ?><?= $regNo ? '&amp;reg_no=' . urlencode($regNo) : '' ?>" style="color:var(--kvb-green-dark);">Earn Session Points</a>
                (opens <?= $eventModel->signInWhenText((int) $event['id']) ?>)
            </p>
        </div>
    <?php endif; ?>

    <div class="card">
        <p class="notice-box">
            Enter your details exactly as they appear in the CPD directory.
            <?php if ($event['event_mode'] === 'hybrid'): ?>
                <br>You choose on-site or virtual for each session when you sign in.
            <?php endif; ?>
        </p>

        <?php if ($event['event_mode'] !== 'onsite'): ?>
        <p class="notice-box" style="background:var(--kvb-green-light);color:var(--kvb-green-dark);">
            Points are earned by signing in <?= $eventModel->signInWhenText((int) $event['id']) ?>.
        </p>
        <?php endif; ?>

        <?php if ($isOnsite): ?>
            <div id="geo-status" class="status-box pending">Checking location access&hellip;</div>
        <?php endif; ?>

        <form method="POST" action="/events/register.php?token=<?= urlencode($token) ?>" id="reg-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="geo_lat" id="geo_lat">
            <input type="hidden" name="geo_lng" id="geo_lng">

            <div class="form-grid">
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

            <div class="form-group" id="dob-group" hidden>
                    <?php $idMode = (new \VetBoard\PmsAutofill())->idMode(); ?>
                    <?php if ($idMode !== null): ?>
                    <label for="dob"><?= $idMode === 'last' ? 'Last 4 characters of your ID number' : 'First 4 characters of your ID number' ?></label>
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <?php if ($idMode === 'last'): ?><span aria-hidden="true" style="letter-spacing:.15em;color:var(--text-muted);">&bull;&bull;&bull;&bull;</span><?php endif; ?>
                        <input type="text" id="dob" inputmode="numeric" maxlength="4" autocomplete="off" style="width:6rem;letter-spacing:.2em;">
                        <?php if ($idMode === 'first'): ?><span aria-hidden="true" style="letter-spacing:.15em;color:var(--text-muted);">&bull;&bull;&bull;&bull;</span><?php endif; ?>
                    </div>
                    <small style="color:var(--text-muted);">Optional. Used once to fill your details from the KVB register; never stored. You can always fill the form in yourself.</small>
                    <?php else: ?>
                    <label for="dob">Date of Birth</label>
                    <input type="date" id="dob" autocomplete="bday">
                    <small style="color:var(--text-muted);">Optional. Used once to fill your details from the KVB register; never stored.</small>
                    <?php endif; ?>
                    <button type="button" class="btn btn-ghost" id="dob-fetch" style="margin-top:.5rem;">Fill my details</button>
                </div>
    
            <div class="form-group">
                <label for="name">Full Name <span class="required">*</span></label>
                <input type="text" id="name" name="name" required>
            </div>

            <div class="form-grid">
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

            <button type="submit" class="btn btn-primary btn-block" id="submit-btn" <?= $isOnsite ? 'disabled' : '' ?>>
                Register
            </button>
        </form>

        <p style="text-align:center;margin-top:1rem;font-size:.82rem;color:var(--text-muted);">
            Already registered?
            <a href="/events/attend.php?token=<?= urlencode($token) ?>" style="color:var(--kvb-green-dark);">Earn Session Points</a>
        </p>
    </div>
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

let lookupFilled = false;

function lockFields(data) {
    lookupFilled = true;
    document.getElementById('name').value  = data.name  ?? '';
    document.getElementById('email').value = data.email ?? '';
    document.getElementById('phone').value = data.phone ?? '';
    ['name', 'email', 'phone'].forEach(id => {
        document.getElementById(id).setAttribute('readonly', true);
    });
    lookupStatus.textContent = 'Please enter your ID number to confirm.';
    lookupStatus.style.color = 'var(--kvb-green-dark)';
}

function unlockFields() {
    ['name', 'email', 'phone'].forEach(id => {
        document.getElementById(id).removeAttribute('readonly');
    });
    if (lookupFilled) {
        ['name', 'email', 'phone'].forEach(id => { document.getElementById(id).value = ''; });
        lookupFilled = false;
    }
    lookupStatus.textContent = '';
    hideDob();
}

const dobGroup  = document.getElementById('dob-group');
    const dobInput  = document.getElementById('dob');
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;

    function showDob() { dobGroup.hidden = false; }
    function hideDob() { dobGroup.hidden = true; dobInput.value = ''; }

    const dobMessages = {
        mismatch: 'We could not match those details. Fill in the form yourself.',
        invalid: 'Please check the number and the four characters, or fill in the form yourself.',
        not_found: 'Number not found on the KVB register. Fill in the form yourself.',
        limited: 'Too many attempts. Please fill in the form yourself.',
    };

    document.getElementById('dob-fetch').addEventListener('click', () => {
        const idMode = <?= json_encode($idMode ?? null) ?>;
        if (!dobInput.value || (idMode && dobInput.value.replace(/[^A-Za-z0-9]/g, '').length !== 4)) { return; }

        lookupStatus.textContent = 'Checking the KVB register\u2026';
        lookupStatus.style.color = 'var(--text-muted)';

        fetch('/events/pms_lookup.php', {
            method: 'POST',
            body: new URLSearchParams(idMode ? { csrf_token: csrfToken, reg_no: regNoEl.value.trim(), id_part: dobInput.value } : { csrf_token: csrfToken, reg_no: regNoEl.value.trim(), dob: dobInput.value }),
        })
            .then(r => r.json())
            .then(d => {
                if (d.status === 'ok') {
                    document.getElementById('name').value  = d.data.name;
                    document.getElementById('email').value = d.data.email;
                    document.getElementById('phone').value = d.data.phone;
                    lookupFilled = true;
                    lookupStatus.textContent = 'Details filled from the KVB register. Check them, then enter your ID number.';
                    lookupStatus.style.color = 'var(--kvb-green-dark)';
                    hideDob();
                    return;
                }
                lookupStatus.textContent = dobMessages[d.status] || 'The KVB register is unavailable right now. Fill in the form yourself.';
                lookupStatus.style.color = 'var(--kvb-red-dark)';
            })
            .catch(() => {
                lookupStatus.textContent = 'The KVB register is unavailable right now. Fill in the form yourself.';
                lookupStatus.style.color = 'var(--kvb-red-dark)';
            });
    });

    
regNoEl.addEventListener('input', () => {
    clearTimeout(lookupTimer);
    unlockFields();
    lookupTimer = setTimeout(() => {
        const regNo = regNoEl.value.trim();
        if (regNo.length < 4) return;
        fetch('/events/lookup.php?reg_no=' + encodeURIComponent(regNo))
            .then(r => r.json())
            .then(d => { if (d.found) { lockFields(d); } else if (d.pms) { showDob(); } })
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
    geoStatus.className = 'status-box granted';
    submitBtn.disabled = false;
}

function onGeoError() {
    geoStatus.textContent = 'Turn on location in your browser settings, then reload this page.';
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
