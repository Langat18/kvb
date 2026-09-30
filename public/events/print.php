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

$startDate     = date('d M Y', strtotime($event['start_date']));
$endDate       = date('d M Y', strtotime($event['end_date']));

$providerName   = $event['provider_name'] ?: '-';
$providerAcc    = $event['provider_accreditation_no'] ?: '-';
$supervisorLine = $event['coordinator'] ?: '-';
$modeLine       = $event['event_mode_label'] ?? $event['event_mode'] ?? '-';
if (($_GET['format'] ?? '') === 'excel') {
    $cadreLabels = (new \VetBoard\CadreRepository())->labelMap();

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

    $headers = ['#', 'Reg No', 'Full Name', 'Email', 'Phone', 'Cadre', 'Session', 'Method', 'Location', 'Points'];
    foreach ($headers as $idx => $h) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1) . '5';
        $sheet->setCellValue($cell, $h);
        $sheet->getStyle($cell)->getFont()->setBold(true);
        $sheet->getStyle($cell)->getFill()->setFillType('solid')->getStartColor()->setRGB('D9D9D9');
    }

    $rowNum = 6;
    foreach ($rows as $i => $r) {
        $cadreKey = $r['cadre'] ?? '';

        if ($r['method'] === 'onsite') {
            $location = $r['flagged'] ? 'Flagged (' . (int) $r['distance_m'] . 'm)' : 'Verified';
        } else {
            $location = '-';
        }

        $sheet->setCellValue('A' . $rowNum, $i + 1);
        $sheet->getCell('B' . $rowNum)->setValueExplicit((string) $r['reg_no'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('C' . $rowNum, \VetBoard\Security::excelSafe($r['name']));
        $sheet->setCellValue('D' . $rowNum, \VetBoard\Security::excelSafe($r['email']));
        $sheet->getCell('E' . $rowNum)->setValueExplicit(\VetBoard\Security::excelSafe($r['phone'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('F' . $rowNum, $cadreLabels[$cadreKey] ?? $cadreKey);
        $sheet->setCellValue('G' . $rowNum, \VetBoard\Security::excelSafe($r['session_label'] ?? 'Unknown'));
        $sheet->setCellValue('H' . $rowNum, ucfirst((string) $r['method']));
        $sheet->setCellValue('I' . $rowNum, $location);
        $sheet->setCellValue('J' . $rowNum, (int) $r['points_awarded']);
        $rowNum++;
    }

    foreach (['A' => 5, 'B' => 12, 'C' => 28, 'D' => 28, 'E' => 15, 'F' => 20, 'G' => 18, 'H' => 10, 'I' => 16, 'J' => 8] as $col => $w) {
        $sheet->getColumnDimension($col)->setWidth($w);
    }

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
@media print { .day-selector, .no-print { display: none; } body { padding: .5cm 1cm; } }
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
            <th>#</th><th>Reg No</th><th>Full Name</th>
            <th>Email</th><th>Phone</th><th>Session</th><th>Method</th><th>Location</th><th>Points</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="9" style="text-align:center;padding:.6rem;color:#888;">No attendance records for this date.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $i => $r): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($r['reg_no'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($r['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($r['session_label'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= ucfirst($r['method']) ?></td>
                <td>
                    <?php if ($r['method'] === 'onsite'): ?>
                        <?php if ($r['flagged']): ?>
                            <span class="check-flag">&#9888; <?= (int) $r['distance_m'] ?>m</span>
                        <?php else: ?>
                            <span class="check-yes">&#10003;</span>
                        <?php endif; ?>
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td style="text-align:center;font-weight:bold;color:#0f5132;"><?= (int) $r['points_awarded'] ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<div class="footer">Kenya Veterinary Board &mdash; Printed by <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?> on <?= date('d M Y') ?></div>

</body>
</html>
