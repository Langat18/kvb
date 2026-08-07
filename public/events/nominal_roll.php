<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;

Auth::startSession();
Auth::requireLogin();

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

$participants = $eventModel->getParticipants((int) $event['id']);
$startDate    = date('d M Y', strtotime($event['start_date']));
$endDate      = date('d M Y', strtotime($event['end_date']));
$format       = $_GET['format'] ?? 'print';

$pdo = VetBoard\Database::getInstance()->getPdo();
foreach ($participants as &$p) {
    $sigStmt = $pdo->prepare('SELECT signature_image FROM registrations WHERE id = :id');
    $sigStmt->execute([':id' => $p['registration_id']]);
    $p['signature'] = $sigStmt->fetchColumn();
}
unset($p);

if ($format === 'word') {
    $wordParticipants = $participants;
    foreach ($wordParticipants as &$p) {
        unset($p['signature'], $p['attendance']);
    }
    unset($p);

    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $phpWord->setDefaultFontName('Arial');
    $phpWord->setDefaultFontSize(9);

    $section = $phpWord->addSection([
        'orientation'  => 'landscape',
        'marginTop'    => 600,
        'marginBottom' => 600,
        'marginLeft'   => 720,
        'marginRight'  => 720,
    ]);

    $green = '0F5132';

    $section->addText(
        'Kenya Veterinary Board',
        ['bold' => true, 'size' => 13, 'color' => $green],
        ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
    );
    $section->addText(
        'Event Nominal Roll',
        ['size' => 10, 'color' => '333333'],
        ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
    );
    $section->addTextBreak(1);

    $metaBold  = ['size' => 9, 'bold' => true];
    $metaStyle = ['size' => 9];
    $metaTable = $section->addTable(['borderSize' => 0, 'cellMargin' => 80]);

    $metaRows = [
        ['Event', str_replace('&', 'and', $event['title']), 'Event Code', $event['event_code'] ?? '-'],
        ['Venue', $event['venue'] ?? '-', 'Dates', $startDate . ' - ' . $endDate],
    ];

    foreach ($metaRows as $row) {
        $metaTable->addRow();
        $metaTable->addCell(2000)->addText($row[0], $metaBold);
        $metaTable->addCell(4000)->addText($row[1], $metaStyle);
        $metaTable->addCell(2000)->addText($row[2], $metaBold);
        $metaTable->addCell(4000)->addText($row[3], $metaStyle);
    }

    $section->addTextBreak(1);

    $colWidths = [500, 1600, 2600, 2400, 2600, 1600, 1600, 3000];
    $table = $section->addTable([
        'borderSize'  => 6,
        'borderColor' => 'CCCCCC',
        'cellMargin'  => 60,
    ]);

    $hStyle = ['bold' => true, 'size' => 8];
    $hPara  = ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER];

    $table->addRow();
    foreach (['#', 'Reg No', 'Full Name', 'Cadre', 'Email', 'Phone', 'Sessions', 'Flagged'] as $idx => $h) {
        $table->addCell($colWidths[$idx])->addText($h, $hStyle, $hPara);
    }

    $cStyle  = ['size' => 8];
    $cCenter = ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER];

    foreach ($participants as $i => $p) {
        $sessionsSigned = count($p['attendance']);
        $flaggedRows    = array_filter($p['attendance'], fn($a) => $a['flagged']);
        $flaggedText    = '';
        foreach ($flaggedRows as $f) {
            $flaggedText .= SESSION_LABELS[$f['session']] . ' (' . $f['distance_m'] . 'm); ';
        }
        $flaggedText = str_replace('&', 'and', $flaggedText ?: 'None');

        $table->addRow();
        $table->addCell($colWidths[0])->addText((string) ($i + 1), $cStyle, $cCenter);
        $table->addCell($colWidths[1])->addText($p['reg_no'], $cStyle);
        $table->addCell($colWidths[2])->addText(str_replace('&', 'and', $p['name']), $cStyle);
        $table->addCell($colWidths[3])->addText(CADRE_LABELS[$p['cadre']] ?? $p['cadre'], $cStyle);
        $table->addCell($colWidths[4])->addText($p['email'], $cStyle);
        $table->addCell($colWidths[5])->addText($p['phone'] ?? '', $cStyle);
        $table->addCell($colWidths[6])->addText((string) $sessionsSigned, $cStyle, $cCenter);
        $table->addCell($colWidths[7])->addText($flaggedText, $cStyle);
    }

    $section->addTextBreak(1);
    $section->addText(
        'Kenya Veterinary Board - Upholding Veterinary Standards',
        ['size' => 7, 'color' => '777777'],
        ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
    );
    $section->addText(
        'Printed by ' . $user['name'] . ' on ' . date('d M Y'),
        ['size' => 7, 'color' => '777777'],
        ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
    );

    $tmpFile = tempnam(sys_get_temp_dir(), 'phpword_');
    $writer  = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save($tmpFile);
    $fileSize = filesize($tmpFile);
    while (ob_get_level()) { ob_end_clean(); }
    $filename = 'Nominal_Roll_' . preg_replace('/[^a-zA-Z0-9]/', '_', $event['title']) . '.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Content-Transfer-Encoding: binary');
    header('Content-Encoding: identity');
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}

if ($format === 'excel') {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->mergeCells('A1:H1');
    $sheet->setCellValue('A1', $event['title'] . ' — Nominal Roll');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

    $sheet->mergeCells('A2:H2');
    $sheet->setCellValue('A2', ($event['venue'] ?? '') . ' | ' . $startDate . ' - ' . $endDate);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');

    $headers = ['#', 'Reg No', 'Full Name', 'Cadre', 'Email', 'Phone', 'Sessions Signed', 'Flagged'];
    foreach ($headers as $col => $h) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . '3';
        $sheet->setCellValue($cell, $h);
        $sheet->getStyle($cell)->getFont()->setBold(true);
        $sheet->getStyle($cell)->getFill()->setFillType('solid')->getStartColor()->setRGB('D9D9D9');
    }

    foreach ($participants as $i => $p) {
        $row            = $i + 4;
        $sessionsSigned = count($p['attendance']);
        $flaggedRows    = array_filter($p['attendance'], fn($a) => $a['flagged']);
        $flaggedText    = '';
        foreach ($flaggedRows as $f) {
            $flaggedText .= SESSION_LABELS[$f['session']] . ' (' . $f['distance_m'] . 'm from venue); ';
        }

        $sheet->setCellValue('A' . $row, $i + 1);
        $sheet->setCellValue('B' . $row, $p['reg_no']);
        $sheet->setCellValue('C' . $row, $p['name']);
        $sheet->setCellValue('D' . $row, CADRE_LABELS[$p['cadre']] ?? $p['cadre']);
        $sheet->setCellValue('E' . $row, $p['email']);
        $sheet->getCell('F' . $row)->setValueExplicit($p['phone'] ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('G' . $row, $sessionsSigned);
        $sheet->setCellValue('H' . $row, $flaggedText ?: 'None');
    }

    foreach (['A' => 5, 'B' => 12, 'C' => 26, 'D' => 24, 'E' => 26, 'F' => 15, 'G' => 14, 'H' => 40] as $col => $w) {
        $sheet->getColumnDimension($col)->setWidth($w);
    }

    $filename = 'Nominal_Roll_' . preg_replace('/[^a-zA-Z0-9]/', '_', $event['title']) . '.xlsx';
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
<title>Nominal Roll — <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></title>
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
.nom-table { width: 100%; border-collapse: collapse; font-size: 8pt; margin-bottom: 1rem; }
.nom-table th { background: #0f5132; color: #fff; padding: .28rem .35rem; text-align: left; font-size: 7.5pt; white-space: nowrap; }
.nom-table td { border: 1px solid #ccc; padding: .28rem .35rem; vertical-align: middle; }
.nom-table tr:nth-child(even) td { background: #f5f9f6; }
.sig-img { max-height: 32px; max-width: 90px; }
.flag-badge { color: #b91c1c; font-weight: 600; font-size: 7.5pt; }
.ok-badge { color: #0f5132; font-weight: 600; font-size: 7.5pt; }
.no-print { margin-bottom: 1rem; display: flex; gap: .5rem; }
@media print {
    .no-print { display: none; }
    body { padding: .5cm 1cm; }
}
</style>
</head>
<body>

<div class="no-print">
    <a href="?token=<?= urlencode($_GET['token']) ?>&format=excel" style="padding:.4rem .9rem;background:#1a7b32;color:#fff;text-decoration:none;border-radius:6px;font-size:.85rem;">
        Download Excel
    </a>
    <a href="?token=<?= urlencode($_GET['token']) ?>&format=word" style="padding:.4rem .9rem;background:#1a3a6a;color:#fff;text-decoration:none;border-radius:6px;font-size:.85rem;">
        Download Word
    </a>
    <button onclick="window.print()" style="padding:.4rem .9rem;background:#0f5132;color:#fff;border:none;border-radius:6px;font-size:.85rem;cursor:pointer;">
        Print
    </button>
</div>

<div class="doc-header">
    <img src="/assets/img/kvb_logo.png" alt="KVB Logo">
    <div class="doc-header-text">
        <h1>Kenya Veterinary Board</h1>
        <h2>Event Nominal Roll</h2>
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
</table>

<table class="nom-table">
    <thead>
        <tr>
            <th>#</th>
            <th>Reg No</th>
            <th>Full Name</th>
            <th>Cadre</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Sessions Signed</th>
            <th>Flagged</th>
            <th>Signature</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($participants)): ?>
            <tr><td colspan="9" style="text-align:center;padding:.75rem;color:#888;font-style:italic;">No participants registered yet.</td></tr>
        <?php else: ?>
            <?php foreach ($participants as $i => $p):
                $sessionsSigned = count($p['attendance']);
            ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($p['reg_no'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(CADRE_LABELS[$p['cadre']] ?? $p['cadre'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($p['email'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($p['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td style="text-align:center;"><?= $sessionsSigned ?></td>
                <td>
                    <?php if (empty($p['attendance'])): ?>
                        &mdash;
                    <?php else: ?>
                        <?php foreach ($p['attendance'] as $a): ?>
                            <?php if ($a['flagged']): ?>
                                <div class="flag-badge">&#9888; <?= htmlspecialchars(SESSION_LABELS[$a['session']], ENT_QUOTES, 'UTF-8') ?>: <?= (int) $a['distance_m'] ?>m from venue</div>
                            <?php else: ?>
                                <div class="ok-badge">&#10003; <?= htmlspecialchars(SESSION_LABELS[$a['session']], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($p['signature'])): ?>
                        <img src="<?= htmlspecialchars($p['signature'], ENT_QUOTES, 'UTF-8') ?>" class="sig-img" alt="Sig">
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<div style="font-size:7.5pt;color:#777;text-align:center;margin-top:1rem;border-top:1px solid #ccc;padding-top:.5rem;">
    Kenya Veterinary Board &mdash; Printed by <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?> on <?= date('d M Y') ?>
</div>

</body>
</html>
