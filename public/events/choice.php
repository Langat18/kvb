<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Event;

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?> — Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
.choice-wrap { max-width: 440px; width: 100%; text-align: center; }
.choice-wrap h1 { font-size: 1.15rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: .3rem; }
.choice-wrap p.sub { font-size: .85rem; color: var(--text-muted); margin-bottom: 2rem; }
.choice-buttons { display: flex; flex-direction: column; gap: 1rem; }
.choice-btn {
    display: block; padding: 1.1rem 1.5rem;
    border-radius: 14px; font-size: 1rem; font-weight: 600;
    text-decoration: none; transition: var(--transition);
}
.choice-btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-sm); }
.choice-btn-register { background: var(--kvb-green-dark); color: #fff; }
.choice-btn-attend   { background: var(--kvb-gold); color: var(--kvb-black); }
.choice-btn small { display: block; font-weight: 400; font-size: .78rem; margin-top: .25rem; opacity: .85; }
</style>
</head>
<body>
<div class="card choice-wrap">
    <h1><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="sub">
        <?= htmlspecialchars($event['venue'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        &bull; <?= $startDate ?> &ndash; <?= $endDate ?>
    </p>
    <div class="choice-buttons">
        <a href="/events/register.php?token=<?= urlencode($token) ?>" class="choice-btn choice-btn-register">
            Register
            <small>First time? Fill in your details to join this event.</small>
        </a>
        <a href="/events/attend.php?token=<?= urlencode($token) ?>" class="choice-btn choice-btn-attend">
            Sign Attendance
            <small>Already registered? Sign in for today's session.</small>
        </a>
    </div>
</div>
</body>
</html>
