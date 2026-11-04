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
    http_response_code(400);
    die('Invalid link.');
}

$participantModel = new Participant();
$participant = $participantModel->findByRegNo($regNo);

if (!$participant) {
    http_response_code(404);
    die('Participant not found.');
}

$cadreLabels = (new CadreRepository())->labelMap();
$summary     = $participantModel->pointsSummary($regNo);
$total       = $participantModel->totalPoints($regNo);
$format      = $_GET['format'] ?? 'print';

if ($format === 'excel') {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->mergeCells('A1:D1');
    $sheet->setCellValue('A1', 'Kenya Veterinary Board - CPD Points Statement');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

    $sheet->mergeCells('A2:D2');
    $sheet->setCellValue('A2', $participant['name'] . ' (' . $participant['reg_no'] . ')');
    $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');

    $headers = ['Event', 'Date', 'Reason', 'Points'];
    foreach ($headers as $col => $h) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . '4';
        $sheet->setCellValue($cell, $h);
        $sheet->getStyle($cell)->getFont()->setBold(true);
        $sheet->getStyle($cell)->getFill()->setFillType('solid')->getStartColor()->setRGB('D9D9D9');
    }

    foreach ($summary as $i => $s) {
        $row = $i + 5;
        $sheet->setCellValue('A' . $row, $s['title']);
        $sheet->setCellValue('B' . $row, date('d M Y', strtotime($s['start_date'])));
        $sheet->setCellValue('C' . $row, $s['reason'] ?? '');
        $sheet->setCellValue('D' . $row, (float) $s['points']);
    }

    $totalRow = count($summary) + 6;
    $sheet->setCellValue('C' . $totalRow, 'Total Points');
    $sheet->getStyle('C' . $totalRow)->getFont()->setBold(true);
    $sheet->setCellValue('D' . $totalRow, $total);
    $sheet->getStyle('D' . $totalRow)->getFont()->setBold(true);

    foreach (['A' => 32, 'B' => 16, 'C' => 30, 'D' => 12] as $col => $w) {
        $sheet->getColumnDimension($col)->setWidth($w);
    }

    $filename = 'Points_Statement_' . preg_replace('/[^a-zA-Z0-9]/', '_', $participant['name']) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Points Statement - <?= htmlspecialchars($participant['name'], ENT_QUOTES, 'UTF-8') ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', Arial, sans-serif; font-size: 10pt; color: #0f172a; background: #fff; padding: 1cm 1.5cm; }
.doc-header { display: flex; align-items: center; gap: 1rem; border-bottom: 2px solid #1a7b32; padding-bottom: .6rem; margin-bottom: .75rem; }
.doc-header img { height: 55px; }
.doc-header-text h1 { font-size: 11.5pt; color: #0f5132; }
.doc-header-text h2 { font-size: 9.5pt; font-weight: normal; color: #333; }
.meta-table { width: 100%; border-collapse: collapse; margin-bottom: .75rem; font-size: 8.5pt; table-layout: fixed; }
.meta-table th { width: 18%; text-align: left; padding: .18rem .35rem; color: #555; white-space: nowrap; font-weight: 600; }
.meta-table td { padding: .18rem .35rem; width: 32%; }
.stmt-table { width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-bottom: 1rem; }
.stmt-table th { background: #0f5132; color: #fff; padding: .32rem .5rem; text-align: left; font-size: 8pt; }
.stmt-table td { border: 1px solid #ccc; padding: .32rem .5rem; vertical-align: middle; }
.stmt-table tr:nth-child(even) td { background: #f5f9f6; }
.stmt-table tfoot td { font-weight: 700; border-top: 2px solid #0f5132; }
.total-row-label { text-align: right; }
.no-print { margin-bottom: 1rem; display: flex; gap: .5rem; }
@media print {
    .no-print { display: none; }
    body { padding: .5cm 1cm; }
}
</style>
</head>
<body>

<div class="no-print">
    <a href="?reg_no=<?= urlencode($regNo) ?>&format=excel" style="padding:.4rem .9rem;background:#1a7b32;color:#fff;text-decoration:none;border-radius:6px;font-size:.85rem;">
        Download Excel
    </a>
    <button onclick="window.print()" style="padding:.4rem .9rem;background:#0f5132;color:#fff;border:none;border-radius:6px;font-size:.85rem;cursor:pointer;">
        Print
    </button>
</div>

<div class="doc-header">
    <img src="/assets/img/kvb_logo.png" alt="KVB Logo">
    <div class="doc-header-text">
        <h1>Kenya Veterinary Board</h1>
        <h2>CPD Points Statement</h2>
    </div>
</div>

<table class="meta-table">
    <tr>
        <th>Name</th><td><?= htmlspecialchars($participant['name'], ENT_QUOTES, 'UTF-8') ?></td>
        <th>Reg No</th><td><?= htmlspecialchars($participant['reg_no'], ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
    <tr>
        <th>Cadre</th><td><?= htmlspecialchars($cadreLabels[$participant['cadre']] ?? $participant['cadre'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
        <th>Statement Date</th><td><?= date('d M Y') ?></td>
    </tr>
</table>

<table class="stmt-table">
    <thead>
        <tr>
            <th>Event</th>
            <th>Date</th>
            <th>Reason</th>
            <th style="text-align:right;">Points</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($summary)): ?>
            <tr><td colspan="4" style="text-align:center;padding:.75rem;color:#888;font-style:italic;">No points earned yet.</td></tr>
        <?php else: ?>
            <?php foreach ($summary as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= date('d M Y', strtotime($s['start_date'])) ?></td>
                <td><?= htmlspecialchars($s['reason'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td style="text-align:right;"><?= \VetBoard\Points::format($s['points']) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="3" class="total-row-label">Total Points</td>
            <td style="text-align:right;"><?= \VetBoard\Points::format($total) ?></td>
        </tr>
    </tfoot>
</table>

<div style="font-size:7.5pt;color:#777;text-align:center;margin-top:1rem;border-top:1px solid #ccc;padding-top:.5rem;">
    Kenya Veterinary Board &mdash; Printed by <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?> on <?= date('d M Y') ?>
</div>

</body>
</html>
