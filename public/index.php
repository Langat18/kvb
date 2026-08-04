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
<title>Dashboard — Vet Board</title>
</head>
<body style="font-family:Arial,sans-serif;padding:2rem;">
    <h1>Welcome, <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p>Role: <?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8') ?></p>
    <p>Email: <?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></p>
</body>
</html>
