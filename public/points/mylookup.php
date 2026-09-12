<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Participant;

Auth::startSession();

$error  = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $regNo    = strtoupper(trim($_POST['reg_no']    ?? ''));
    $idNumber = trim($_POST['id_number'] ?? '');

    if (empty($regNo) || empty($idNumber)) {
        $error = 'Please enter both your Registration Number and ID Number.';
    } else {
        $participantModel = new Participant();
        $participant = $participantModel->findByRegNoAndIdNumber($regNo, $idNumber);

        if (!$participant) {
            $error = 'No matching record found. Please check your details and try again.';
        } else {
            $result = [
                'participant' => $participant,
                'summary'     => $participantModel->pointsSummary($regNo),
                'total'       => $participantModel->totalPoints($regNo),
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Check My Points</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.list-table { width: 100%; border-collapse: collapse; }
.list-table th { text-align: left; padding: .5rem; font-size: .75rem; color: var(--text-muted); border-bottom: 1px solid #eee; }
.list-table td { padding: .5rem; font-size: .82rem; border-bottom: 1px solid #f2f2f2; }
</style>
</head>
<body>
<div class="public-page">
<div class="public-shell">
    <div class="public-header">
        <h1>Check My Points</h1>
        <p>Kenya Veterinary Board &mdash; CPD Points Lookup</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($result): ?>
        <div class="card" style="text-align:center;margin-bottom:var(--space-4);">
            <p style="font-weight:600;">
                <?= htmlspecialchars($result['participant']['name'], ENT_QUOTES, 'UTF-8') ?>
                (<?= htmlspecialchars($result['participant']['reg_no'], ENT_QUOTES, 'UTF-8') ?>)
            </p>
        </div>
        <div class="card stat-card" style="margin-bottom:var(--space-4);">
            <div class="num"><?= $result['total'] ?></div>
            <div class="label">Total Points</div>
        </div>
        <div class="card card-flush">
            <?php if (empty($result['summary'])): ?>
                <p class="empty-state">No points earned yet.</p>
            <?php else: ?>
            <table class="list-table">
                <thead>
                    <tr><th>Event</th><th>Date</th><th>Points</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($result['summary'] as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= date('d M Y', strtotime($s['start_date'])) ?></td>
                        <td class="points-value"><?= (int) $s['points'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <p style="text-align:center;margin-top:var(--space-4);">
            <a href="/points/mylookup.php" style="color:var(--kvb-green-dark);font-size:var(--fs-base);">Check another record</a>
        </p>
    <?php else: ?>
        <div class="card">
            <form method="POST" action="/points/mylookup.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-group">
                    <label for="reg_no">Registration Number</label>
                    <input type="text" id="reg_no" name="reg_no" required placeholder="e.g. 1234 or VTA1234">
                </div>

                <div class="form-group">
                    <label for="id_number">ID Number</label>
                    <input type="text" id="id_number" name="id_number" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Check My Points</button>
            </form>
        </div>
    <?php endif; ?>
</div>
</div>
</body>
</html>
