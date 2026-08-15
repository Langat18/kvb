<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Participant;

Auth::startSession();

$error = null;
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
<title>Check My Points — Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
.lookup-wrap { max-width: 480px; width: 100%; }
.lookup-header { text-align: center; margin-bottom: 1.5rem; }
.lookup-header h1 { font-size: 1.1rem; font-weight: 700; color: var(--kvb-green-dark); margin: 0; }
.lookup-header p { font-size: .85rem; color: var(--text-muted); margin: .3rem 0 0; }
.total-badge { text-align: center; padding: 1.25rem; margin-bottom: 1rem; }
.total-badge .num { font-size: 2.2rem; font-weight: 700; color: var(--kvb-green-dark); }
.total-badge .label { font-size: .8rem; color: var(--text-muted); }
.list-table { width: 100%; border-collapse: collapse; }
.list-table th { text-align: left; padding: .5rem; font-size: .75rem; color: var(--text-muted); border-bottom: 1px solid #eee; }
.list-table td { padding: .5rem; font-size: .82rem; border-bottom: 1px solid #f2f2f2; }
.points-value { font-weight: 700; color: var(--kvb-green-dark); }
.empty-state { color: var(--text-muted); font-size: .85rem; padding: 1rem 0; text-align: center; }
</style>
</head>
<body>
<div class="lookup-wrap">
    <div class="lookup-header">
        <h1>Check My Points</h1>
        <p>Kenya Veterinary Board — CPD Points Lookup</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($result): ?>
        <div class="card">
            <p style="text-align:center;font-weight:600;margin-bottom:.5rem;">
                <?= htmlspecialchars($result['participant']['name'], ENT_QUOTES, 'UTF-8') ?>
                (<?= htmlspecialchars($result['participant']['reg_no'], ENT_QUOTES, 'UTF-8') ?>)
            </p>
        </div>
        <div class="card total-badge">
            <div class="num"><?= $result['total'] ?></div>
            <div class="label">Total Points</div>
        </div>
        <div class="card" style="padding:0;">
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
        <p style="text-align:center;margin-top:1rem;">
            <a href="/points/mylookup.php" style="color:var(--kvb-green-dark);font-size:.85rem;">Check another record</a>
        </p>
    <?php else: ?>
        <div class="card">
            <form method="POST" action="/points/mylookup.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-group" style="margin-bottom:1rem;">
                    <label for="reg_no">Registration Number</label>
                    <input type="text" id="reg_no" name="reg_no" required placeholder="e.g. 1234 or VTA1234">
                </div>

                <div class="form-group" style="margin-bottom:1.25rem;">
                    <label for="id_number">ID Number</label>
                    <input type="text" id="id_number" name="id_number" required>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;">Check My Points</button>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
