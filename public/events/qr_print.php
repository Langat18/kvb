<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;

Auth::startSession();
Auth::requireLogin();

if (empty($_GET['token'])) {
    http_response_code(400);
    die('Invalid event link.');
}

$eventModel = new Event();
$event      = $eventModel->findByViewToken(trim($_GET['token']));

if (!$event) {
    http_response_code(404);
    die('Event not found.');
}

$appUrl    = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');
$choiceUrl = $appUrl . '/events/choice.php?token=' . urlencode($event['token']);
$startDate = date('d M Y', strtotime($event['start_date']));
$endDate   = date('d M Y', strtotime($event['end_date']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>QR Code — <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Poppins', Arial, sans-serif;
    background: #fff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    padding: 2cm;
    text-align: center;
}
img.logo { height: 70px; margin-bottom: 1rem; }
h1 { font-size: 14pt; color: #0f5132; margin-bottom: .25rem; }
h2 { font-size: 11pt; font-weight: normal; color: #333; margin-bottom: .5rem; }
.meta { font-size: 9pt; color: #555; margin-bottom: 1.5rem; }
#qrcode { display: inline-block; margin: 1rem 0; }
#qrcode img, #qrcode canvas { width: 300px !important; height: 300px !important; }
.url { font-size: 7.5pt; color: #888; word-break: break-all; max-width: 400px; margin: .5rem auto 0; }
@media print { .url { display: none; } }
.instruction {
    margin-top: 1.5rem;
    font-size: 10pt;
    color: #444;
    border-top: 1px solid #ccc;
    padding-top: 1rem;
    max-width: 420px;
}
.footer { font-size: 7.5pt; color: #aaa; margin-top: 2rem; }
@media print { body { padding: 1cm; } }
</style>
</head>
<body>

<img src="/assets/img/kvb_logo.png" alt="KVB Logo" class="logo">
<h1><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></h1>
<?php if (!empty($event['event_code'])): ?>
<h2><?= htmlspecialchars($event['event_code'], ENT_QUOTES, 'UTF-8') ?></h2>
<?php endif; ?>
<div class="meta">
<?= htmlspecialchars($event['venue'] ?? '', ENT_QUOTES, 'UTF-8') ?> &bull;
<?= $startDate ?> &ndash; <?= $endDate ?>
</div>

<div id="qrcode"></div>

<div class="instruction">
    Scan the QR code above with your phone camera to register or sign your attendance.<br>
    <strong>Sign in for each session you attend.</strong>
</div>

<div class="footer">Kenya Veterinary Board &bull; Event Attendance System</div>

<script>
new QRCode(document.getElementById("qrcode"), {
    text: <?= json_encode($choiceUrl) ?>,
    width: 300,
    height: 300,
    colorDark: "#0f5132",
    colorLight: "#ffffff",
    correctLevel: QRCode.CorrectLevel.H
});
window.onload = function() {
    setTimeout(function() { window.print(); }, 800);
};
</script>
</body>
</html>
