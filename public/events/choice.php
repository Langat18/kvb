<?php
declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
use VetBoard\Auth;
use VetBoard\Event;
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
die('Event not found or link is invalid.');
}
$startDate = date('d M Y', strtotime($event['start_date']));
$endDate   = date('d M Y', strtotime($event['end_date']));
$isPureOnsite = $event['event_mode'] === 'onsite';
$today             = date('Y-m-d');
$dayConfirmEnabled = $today >= $event['start_date'] && $today <= $event['end_date']
    && (new Registration())->dayConfirmationEnabled((int) $event['id']);
$dayNumber         = (new DateTimeImmutable($event['start_date']))->diff(new DateTimeImmutable($today))->days + 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?> - Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.public-shell { text-align: center; }
.choice-buttons { display: flex; flex-direction: column; gap: 1rem; }
.choice-btn {
    display: block; padding: 1.1rem 1.5rem;
    border-radius: 14px; font-size: 1rem; font-weight: 600;
    text-decoration: none; transition: var(--transition);
}
.choice-btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-sm); }
.choice-btn-register { background: var(--kvb-green-dark); color: #fff; }
.choice-btn-attend   { background: var(--kvb-gold); color: var(--kvb-black); }
.choice-btn-confirm  { background: #fff; color: var(--kvb-green-dark); border: 2px solid var(--kvb-green-dark); }
.choice-btn small { display: block; font-weight: 400; font-size: .78rem; margin-top: .25rem; opacity: .85; }
.virtual-notice {
    font-size: .8rem; color: var(--kvb-green-dark); background: var(--kvb-green-light);
    border-radius: 10px; padding: .75rem 1rem; margin-bottom: 1.5rem; text-align: left;
}
</style>
</head>
<body>
<div class="public-page">
<div class="public-shell">
<div class="card">
<h1 style="font-size:1.15rem;font-weight:700;color:var(--kvb-green-dark);margin-bottom:.3rem;">
<?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?>
</h1>
<p style="font-size:.85rem;color:var(--text-muted);margin-bottom:1rem;">
<?= htmlspecialchars($event['venue'] ?? '', ENT_QUOTES, 'UTF-8') ?>
&bull; <?= $startDate ?> &ndash; <?= $endDate ?>
</p>

<?php if (!$isPureOnsite): ?>
<div class="virtual-notice">
    Sign in <?= $eventModel->signInWhenText((int) $event['id']) ?> to earn points.
</div>
<?php endif; ?>

<div class="choice-buttons">
<a href="/events/register.php?token=<?= urlencode($token) ?>" class="choice-btn choice-btn-register">
                Register
<small>First time? Fill in your details to join this event.</small>
</a>
<a href="/events/attend.php?token=<?= urlencode($token) ?>" class="choice-btn choice-btn-attend">
                Earn Session Points
<small>Registered? Earn Session Points.</small>
</a>
<?php if ($dayConfirmEnabled): ?>
<a href="/events/attend.php?token=<?= urlencode($token) ?>&amp;confirm=1" class="choice-btn choice-btn-confirm">
                Day <?= (int) $dayNumber ?> Confirmation
<small>Registered? Confirm you are here today.</small>
</a>
<?php endif; ?>
</div>
<p style="margin-top:1.5rem;font-size:.78rem;">
<a href="/points/mylookup.php" style="color:var(--text-muted);">Check my CPD points</a>
</p>
</div>
</div>
</div>
</body>
</html>
