<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\CadreRepository;
use VetBoard\Participant;
use VetBoard\Security;

Auth::startSession();
Auth::requirePermission('view_leaderboard');

$user = Auth::user();

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/index.php'],
    ['label' => 'Points Leaderboard', 'url' => null],
];

$participantModel = new Participant();
$cadreLabels      = (new CadreRepository())->labelMap();
$search           = trim($_GET['search'] ?? '');
$leaderboard      = $participantModel->leaderboard($search, 100, 0);
$format           = $_GET['format'] ?? '';

if ($format === 'excel') {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->mergeCells('A1:E1');
    $sheet->setCellValue('A1', 'Kenya Veterinary Board - Points Leaderboard');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

    $headers = ['#', 'Reg No', 'Full Name', 'Cadre', 'Total Points'];
    foreach ($headers as $col => $h) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . '3';
        $sheet->setCellValue($cell, $h);
        $sheet->getStyle($cell)->getFont()->setBold(true);
        $sheet->getStyle($cell)->getFill()->setFillType('solid')->getStartColor()->setRGB('D9D9D9');
    }

    foreach ($leaderboard as $i => $p) {
        $row = $i + 4;
        $sheet->setCellValue('A' . $row, $i + 1);
        $sheet->setCellValue('B' . $row, $p['reg_no']);
        $sheet->setCellValue('C' . $row, Security::excelSafe($p['name']));
        $sheet->setCellValue('D' . $row, $cadreLabels[$p['cadre']] ?? $p['cadre']);
        $sheet->setCellValue('E' . $row, (int) $p['total_points']);
    }

    foreach (['A' => 5, 'B' => 14, 'C' => 28, 'D' => 26, 'E' => 14] as $col => $w) {
        $sheet->getColumnDimension($col)->setWidth($w);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Points_Leaderboard.xlsx"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leaderboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.filter-bar { display: flex; gap: .6rem; margin-bottom: 1.25rem; }
.filter-bar input { flex: 1; }
@media print {
    .navbar, .app-sidebar, .no-print { display: none !important; }
    .page-with-sidebar { margin-left: 0; padding: 1cm; max-width: none; }
}
</style>
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="app-page-hero">
        <div><h1>Points Leaderboard</h1></div>
        <div class="no-print" style="display:flex;gap:.5rem;">
            <a href="?search=<?= urlencode($search) ?>&format=excel" class="btn btn-secondary">Download Excel</a>
            <button type="button" class="btn btn-secondary" onclick="window.print()">Print / Save as PDF</button>
        </div>
    </div>

    <form method="GET" action="/points/leaderboard.php" class="filter-bar no-print">
        <input type="text" name="search" placeholder="Search by name or RegNo..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-secondary">Search</button>
    </form>

    <div class="app-card card-flush">
        <?php if (empty($leaderboard)): ?>
            <p class="empty-state">No participants found.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Reg No</th>
                    <th>Name</th>
                    <th>Cadre</th>
                    <th>Total Points</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leaderboard as $i => $p): ?>
                <tr onclick="window.location='/points/detail.php?reg_no=<?= urlencode($p['reg_no']) ?>'" style="cursor:pointer;">
                    <td class="rank"><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars($p['reg_no'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($cadreLabels[$p['cadre']] ?? $p['cadre'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="points-value"><?= (int) $p['total_points'] ?></td>
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
