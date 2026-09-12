<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\CadreRepository;
use VetBoard\Participant;

Auth::startSession();
Auth::requireLogin();

$user = Auth::user();

$regNo = strtoupper(trim($_GET['reg_no'] ?? ''));
if (empty($regNo)) {
    header('Location: /points/leaderboard.php');
    exit;
}

$participantModel = new Participant();
$participant = $participantModel->findByRegNo($regNo);

if (!$participant) {
    $_SESSION['error'] = 'Participant not found.';
    header('Location: /points/leaderboard.php');
    exit;
}

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/index.php'],
    ['label' => 'Points Leaderboard', 'url' => '/points/leaderboard.php'],
    ['label' => $participant['name'], 'url' => null],
];

$cadreLabels = (new CadreRepository())->labelMap();
$summary     = $participantModel->pointsSummary($regNo);
$total       = $participantModel->totalPoints($regNo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($participant['name'], ENT_QUOTES, 'UTF-8') ?> — Points — Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 0; }
.container { max-width: 800px; margin: 0 auto; padding: 1.25rem 2.5rem 2.5rem; }
.profile-card { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; }
.profile-card h1 { font-size: 1.3rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: .2rem; }
.profile-card p { font-size: .85rem; color: var(--text-muted); }
.total-badge { text-align: center; padding: 1rem 1.5rem; }
.total-badge .num { font-size: 2rem; font-weight: 700; color: var(--kvb-green-dark); }
.total-badge .label { font-size: .78rem; color: var(--text-muted); }
.list-table { width: 100%; border-collapse: collapse; }
.list-table th { text-align: left; padding: .65rem .6rem; font-size: .78rem; color: var(--text-muted); border-bottom: 1px solid #eee; background: #faf9f7; }
.list-table td { padding: .65rem .6rem; font-size: .87rem; border-bottom: 1px solid #f2f2f2; }
.points-value { font-weight: 700; color: var(--kvb-green-dark); }
.empty-state { color: var(--text-muted); font-size: .85rem; padding: 1.5rem 0; text-align: center; }
</style>
</head>
<body>
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="container">
    <div class="profile-card">
        <div>
            <h1><?= htmlspecialchars($participant['name'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p><?= htmlspecialchars($participant['reg_no'], ENT_QUOTES, 'UTF-8') ?> &bull; <?= htmlspecialchars($cadreLabels[$participant['cadre']] ?? $participant['cadre'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="card total-badge">
            <div class="num"><?= $total ?></div>
            <div class="label">Total Points</div>
        </div>
    </div>

    <div class="card" style="padding:0;">
        <?php if (empty($summary)): ?>
            <p class="empty-state">No points earned yet.</p>
        <?php else: ?>
        <table class="list-table">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Date</th>
                    <th>Reason</th>
                    <th>Points</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summary as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= date('d M Y', strtotime($s['start_date'])) ?></td>
                    <td><?= htmlspecialchars($s['reason'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="points-value"><?= (int) $s['points'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
