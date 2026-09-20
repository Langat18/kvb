<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\CadreRepository;
use VetBoard\Participant;

Auth::startSession();
Auth::requirePermission('view_leaderboard');

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

require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($participant['name'], ENT_QUOTES, 'UTF-8') ?> - Points - Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.profile-card { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem; }
.profile-card h1 { font-size: 1.3rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: .2rem; }
.profile-card p { font-size: .85rem; color: var(--text-muted); }
.total-badge { text-align: center; padding: 1rem 1.5rem; }
.total-badge .num { font-size: 2rem; font-weight: 700; color: var(--kvb-green-dark); }
.total-badge .label { font-size: .78rem; color: var(--text-muted); }
</style>
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="profile-card">
        <div>
            <h1><?= htmlspecialchars($participant['name'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p><?= htmlspecialchars($participant['reg_no'], ENT_QUOTES, 'UTF-8') ?> &bull; <?= htmlspecialchars($cadreLabels[$participant['cadre']] ?? $participant['cadre'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div style="text-align:right;">
            <div class="app-card total-badge">
                <div class="num"><?= $total ?></div>
                <div class="label">Total Points</div>
            </div>
            <a href="/points/print.php?reg_no=<?= urlencode($participant['reg_no']) ?>" class="btn btn-ghost btn-sm" target="_blank" style="margin-top:.5rem;display:inline-block;">Print Statement</a>
        </div>
    </div>

    <div class="app-card card-flush">
        <?php if (empty($summary)): ?>
            <p class="empty-state">No points earned yet.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="data-table">
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
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
