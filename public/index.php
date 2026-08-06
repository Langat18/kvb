<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

use VetBoard\Auth;

Auth::startSession();
Auth::requireLogin();

$user = Auth::user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: 800px; margin: 0 auto; }
.container h1 { font-size: 1.3rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: .3rem; }
.container p.sub { font-size: .88rem; color: var(--text-muted); margin-bottom: 1.5rem; }
.info-row { display: flex; gap: 1.5rem; font-size: .88rem; margin-bottom: .5rem; }
.info-row span.label { color: var(--text-muted); width: 90px; }
</style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>Welcome, <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="sub">Kenya Veterinary Board — Staff Dashboard</p>
        <div class="info-row"><span class="label">Role</span> <?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="info-row"><span class="label">Email</span> <?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></div>
        <div style="margin-top:1.5rem;display:flex;gap:.75rem;">
            <a href="/events/create.php" class="btn btn-primary">+ New Event</a>
            <a href="/events/index.php" class="btn btn-secondary">All Events</a>
        </div>
    </div>
</div>
</body>
</html>
