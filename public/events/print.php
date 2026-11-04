<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;
use VetBoard\Registration;

Auth::startSession();
Auth::requirePermission('print_attendance_register');

$user = Auth::user();

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

$registrationModel = new Registration();
$dates = $registrationModel->attendanceDatesForEvent((int) $event['id']);

$selectedDate = $_GET['date'] ?? $dates[0] ?? date('Y-m-d');

$rows = $registrationModel->attendanceRowsForDate((int) $event['id'], $selectedDate);
$register = \VetBoard\DailyRegister::build($rows, $eventModel->getSessions((int) $event['id'], false), $selectedDate);

$startDate     = date('d M Y', strtotime($event['start_date']));
$endDate       = date('d M Y', strtotime($event['end_date']));

$providerName   = $event['provider_name'] ?: '-';
$providerAcc    = $event['provider_accreditation_no'] ?: '-';
$supervisorLine = $event['coordinator'] ?: '-';
$modeLine       = $event['event_mode_label'] ?? $event['event_mode'] ?? '-';

$cadreLabels = (new \VetBoard\CadreRepository())->labelMap();
$eventReport = new \VetBoard\EventReport();
$programme   = $eventReport->programme((int) $event['id']);
$cadreStats  = $eventReport->cadreByDay((int) $event['id'], $cadreLabels, $event['start_date'], $event['end_date']);

if (($_GET['format'] ?? '') === 'excel') {

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Attendance');

    $sheet->mergeCells('A1:J1');
    $sheet->setCellValue('A1', \VetBoard\Security::excelSafe($event['title'] . ' - Attendance Register'));
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

    $sheet->mergeCells('A2:J2');
    $sheet->setCellValue('A2', \VetBoard\Security::excelSafe(($event['venue'] ?? '') . ' | ' . $startDate . ' - ' . $endDate . ' | ' . date('l, d F Y', strtotime($selectedDate))));
    $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');

    $sheet->mergeCells('A3:J3');
    $sheet->setCellValue('A3', \VetBoard\Security::excelSafe('Provider: ' . $providerName . '   |   Accreditation No.: ' . $providerAcc . '   |   Supervisor: ' . $supervisorLine . '   |   Mode: ' . $modeLine));
    $sheet->getStyle('A3')->getAlignment()->setHorizontal('center');
    $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9);

    $columns = $register['sessions'];
    $headers = array_merge(
        ['#', 'Reg No', 'Full Name', 'Email', 'Phone', 'Cadre'],
        array_column($columns, 'label'),
        ['Method', 'Points']
    );
    $coord = static fn(int $n): string => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($n);

    foreach ($headers as $idx => $h) {
        $cell = $coord($idx + 1) . '5';
        $sheet->setCellValue($cell, \VetBoard\Security::excelSafe((string) $h));
        $sheet->getStyle($cell)->getFont()->setBold(true);
        $sheet->getStyle($cell)->getFill()->setFillType('solid')->getStartColor()->setRGB('D9D9D9');
    }

    $rowNum = 6;
    foreach ($register['people'] as $i => $p) {
        $sheet->setCellValue('A' . $rowNum, $i + 1);
        $sheet->getCell('B' . $rowNum)->setValueExplicit((string) $p['reg_no'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('C' . $rowNum, \VetBoard\Security::excelSafe($p['name']));
        $sheet->setCellValue('D' . $rowNum, \VetBoard\Security::excelSafe($p['email']));
        $sheet->getCell('E' . $rowNum)->setValueExplicit(\VetBoard\Security::excelSafe($p['phone']), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('F' . $rowNum, $cadreLabels[$p['cadre']] ?? $p['cadre']);

        foreach ($columns as $k => $col) {
            $sheet->setCellValue($coord(7 + $k) . $rowNum, \VetBoard\DailyRegister::cellValue($p['cells'][$col['id']] ?? null));
        }

        $sheet->setCellValue($coord(7 + count($columns)) . $rowNum, $p['method']);
        $sheet->setCellValue($coord(8 + count($columns)) . $rowNum, $p['total']);
        $rowNum++;
    }

    $widths = array_merge([5, 12, 28, 28, 15, 20], array_fill(0, count($columns), 12), [14, 10]);
    foreach ($widths as $k => $w) {
        $sheet->getColumnDimension($coord($k + 1))->setWidth($w);
    }

    \VetBoard\EventReport::appendSheets($spreadsheet, $programme, $cadreStats);
    $spreadsheet->setActiveSheetIndex(0);

    while (ob_get_level()) { ob_end_clean(); }
    $filename = 'Attendance_Register_' . preg_replace('/[^a-zA-Z0-9]/', '_', $event['title']) . '_' . preg_replace('/[^0-9-]/', '', $selectedDate) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Attendance Register — <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></title>
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
.att-table { width: 100%; border-collapse: collapse; font-size: 8pt; margin-bottom: 1rem; }
.att-table th { background: #0f5132; color: #fff; padding: .28rem .35rem; text-align: left; font-size: 7.5pt; white-space: nowrap; }
.att-table td { border: 1px solid #ccc; padding: .28rem .35rem; vertical-align: middle; }
.att-table tr:nth-child(even) td { background: #f5f9f6; }
.check-yes { color: #0f5132; font-weight: bold; }
.check-flag { color: #b91c1c; font-weight: bold; }
.day-selector { margin-bottom: .75rem; }
.day-selector a { margin-right: .35rem; padding: .18rem .45rem; border: 1px solid #ccc; text-decoration: none; color: #333; border-radius: 3px; font-size: 8pt; }
.day-selector a.active { background: #0f5132; color: #fff; border-color: #0f5132; }
.footer { font-size: 7pt; color: #888; text-align: center; margin-top: 1rem; border-top: 1px solid #ccc; padding-top: .4rem; }
.sec-title { font-size: 9.5pt; font-weight: bold; color: #0f5132; margin: .75rem 0 .35rem; }
.att-table .num { text-align: center; }
.total-row td { font-weight: bold; background: #e8f1ec !important; }
.note { font-size: 7.5pt; color: #666; margin: -.5rem 0 .75rem; }
.muted { color: #666; font-size: 7.5pt; }
.mark-none { color: #999; }
tr { break-inside: avoid; }
.report-toggle { margin: .75rem 0; }
.report-toggle > summary { cursor: pointer; font-size: 9.5pt; font-weight: bold; color: #0f5132; padding: .35rem .6rem; border: 1px solid #ccc; border-radius: 4px; background: #f5f9f6; }
.report-toggle[open] > summary { margin-bottom: .5rem; }
@media print { .day-selector, .no-print { display: none; } .report-toggle:not([open]) { display: none; } body { padding: .5cm 1cm; } }
</style>
</head>
<body>

<div class="no-print" style="margin-bottom:1rem;display:flex;gap:.5rem;">
    <a href="?token=<?= urlencode($event['view_token']) ?>&date=<?= urlencode($selectedDate) ?>&format=excel" style="padding:.4rem .9rem;background:#1a7b32;color:#fff;text-decoration:none;border-radius:6px;font-size:.85rem;">Download Excel</a>
    <button onclick="window.print()" style="padding:.4rem .9rem;background:#0f5132;color:#fff;border:none;border-radius:6px;font-size:.85rem;cursor:pointer;">Print</button>
</div>

<div class="doc-header">
    <img src="/assets/img/kvb_logo.png" alt="KVB Logo">
    <div class="doc-header-text">
        <h1>Kenya Veterinary Board</h1>
        <h2>Event Attendance Register</h2>
    </div>
</div>

<table class="meta-table">
    <tr>
        <th>Event</th><td><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></td>
        <th>Event Code</th><td><?= htmlspecialchars($event['event_code'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
    <tr>
        <th>Venue</th><td><?= htmlspecialchars($event['venue'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
        <th>Dates</th><td><?= $startDate ?> &ndash; <?= $endDate ?></td>
    </tr>
    <tr>
        <th>Provider</th><td><?= htmlspecialchars($providerName, ENT_QUOTES, 'UTF-8') ?></td>
        <th>Accreditation No.</th><td><?= htmlspecialchars($providerAcc, ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
    <tr>
        <th>Supervisor</th><td><?= htmlspecialchars($supervisorLine, ENT_QUOTES, 'UTF-8') ?></td>
        <th>Mode</th><td><?= htmlspecialchars($modeLine, ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
</table>

<details class="report-toggle">
    <summary>Report</summary>
    <?php require __DIR__ . '/../templates/event_report.php'; ?>
</details>

<?php if (!empty($dates)): ?>
<div class="day-selector no-print">
    <?php foreach ($dates as $d): ?>
        <a href="?token=<?= urlencode($event['view_token']) ?>&date=<?= $d ?>" class="<?= $d === $selectedDate ? 'active' : '' ?>">
            <?= date('d M', strtotime($d)) ?>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div style="font-size:9.5pt;font-weight:bold;color:#0f5132;margin:.5rem 0;">
    Attendance &mdash; <?= date('l, d F Y', strtotime($selectedDate)) ?>
</div>

<table class="att-table">
    <thead>
        <tr>
            <th>#</th><th>Reg No</th><th>Full Name</th><th>Email</th><th>Phone</th>
            <?php foreach ($register['sessions'] as $col): ?>
                <th class="num"><?= htmlspecialchars($col['label'], ENT_QUOTES, 'UTF-8') ?></th>
            <?php endforeach; ?>
            <th>Method</th><th class="num">Points</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($register['people'])): ?>
            <tr><td colspan="<?= 7 + count($register['sessions']) ?>" style="text-align:center;padding:.6rem;color:#888;">No attendance records for this date.</td></tr>
        <?php else: ?>
            <?php foreach ($register['people'] as $i => $p): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($p['reg_no'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($p['email'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($p['phone'], ENT_QUOTES, 'UTF-8') ?></td>
                <?php foreach ($register['sessions'] as $col): $cell = $p['cells'][$col['id']] ?? null; ?>
                    <td class="num <?= \VetBoard\DailyRegister::cellClass($cell) ?>"><?= htmlspecialchars(\VetBoard\DailyRegister::cellText($cell), ENT_QUOTES, 'UTF-8') ?></td>
                <?php endforeach; ?>
                <td><?= htmlspecialchars($p['method'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="num" style="font-weight:bold;color:#0f5132;"><?= \VetBoard\Points::format($p['total']) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
<p class="note"><?= count($register['people']) ?> attendee(s) signed in on this day.</p>

<div class="footer">Kenya Veterinary Board &mdash; Printed by <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?> on <?= date('d M Y') ?></div>

</body>
</html>
