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

$pdo = VetBoard\Database::getInstance()->getPdo();

$sessionWindows = $eventModel->getSessions((int) $event['id']);

$datesStmt = $pdo->prepare(
    'SELECT DISTINCT a.attendance_date
     FROM attendance a
     JOIN registrations r ON r.id = a.registration_id
     WHERE r.event_id = :event_id
     ORDER BY a.attendance_date'
);
$datesStmt->execute([':event_id' => $event['id']]);
$availableDates = array_column($datesStmt->fetchAll(), 'attendance_date');

$defaultDate  = !empty($availableDates) ? end($availableDates) : date('Y-m-d');
$selectedDate = $_GET['date'] ?? $defaultDate;
if (!empty($availableDates) && !in_array($selectedDate, $availableDates, true)) {
    $selectedDate = $defaultDate;
}

$sessionWindows = array_values(array_filter(
    $sessionWindows,
    fn($sw) => empty($sw['session_date']) || $sw['session_date'] === $selectedDate
));

$caseSql = [];
foreach ($sessionWindows as $sw) {
    $col = 's' . (int) $sw['id'];
    $sid = (int) $sw['id'];
    $caseSql[] = "COALESCE(SUM(CASE WHEN a.session_id = {$sid} THEN pl.points END), 0) AS \"{$col}\"";
}
$caseSqlStr = implode(",\n    ", $caseSql);

$sql = "
    SELECT
        p.reg_no,
        p.name,
        {$caseSqlStr},
        COALESCE(SUM(pl.points), 0) AS total
    FROM registrations r
    JOIN participants p ON p.reg_no = r.reg_no
    LEFT JOIN attendance a ON a.registration_id = r.id AND a.attendance_date = :date
    LEFT JOIN points_ledger pl ON pl.attendance_id = a.id
    WHERE r.event_id = :event_id
    GROUP BY p.reg_no, p.name
    ORDER BY p.name
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':date' => $selectedDate, ':event_id' => $event['id']]);
$rows = $stmt->fetchAll();

$format = $_GET['format'] ?? 'print';

$startDate = date('d M Y', strtotime($event['start_date']));
$endDate   = date('d M Y', strtotime($event['end_date']));

if ($format === 'word') {
    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $phpWord->setDefaultFontName('Arial');
    $phpWord->setDefaultFontSize(9);

    $section = $phpWord->addSection(['orientation' => 'landscape']);
    $section->addText('Points by Session — ' . $event['title'], ['bold' => true, 'size' => 14]);
    $section->addText($startDate . ' to ' . $endDate . ' | Date: ' . $selectedDate);
    $section->addTextBreak(1);

    $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80]);

    $table->addRow();
    $table->addCell(1800)->addText('Reg No', ['bold' => true]);
    $table->addCell(3000)->addText('Name', ['bold' => true]);
    foreach ($sessionWindows as $sw) {
        $table->addCell(1600)->addText($sw['label'], ['bold' => true]);
    }
    $table->addCell(1200)->addText('Total', ['bold' => true]);

    foreach ($rows as $row) {
        $table->addRow();
        $table->addCell(1800)->addText($row['reg_no']);
        $table->addCell(3000)->addText($row['name']);
        foreach ($sessionWindows as $sw) {
            $table->addCell(1600)->addText((string) $row['s' . (int) $sw['id']]);
        }
        $table->addCell(1200)->addText((string) $row['total']);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment;filename="points_by_session_' . $selectedDate . '.docx"');
    header('Cache-Control: max-age=0');

    $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save('php://output');
    exit;
}

if ($format === 'excel') {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Points by Session');

    $headers = ['Reg No', 'Name'];
    foreach ($sessionWindows as $sw) {
        $headers[] = $sw['label'];
    }
    $headers[] = 'Total';

    $col = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($col . '1', $h);
        $col++;
    }
    $lastCol = $col;

    $sheet->getStyle('A1:' . chr(ord($lastCol) - 1) . '1')->getFont()->setBold(true);

    $row = 2;
    foreach ($rows as $data) {
        $col = 'A';
        $sheet->setCellValue($col . $row, $data['reg_no']);
        $col++;
        $sheet->setCellValue($col . $row, $data['name']);
        $col++;
        foreach ($sessionWindows as $sw) {
            $sheet->setCellValue($col . $row, (int) $data['s' . (int) $sw['id']]);
            $col++;
        }
        $sheet->setCellValue($col . $row, (int) $data['total']);
        $row++;
    }

    $col = 'A';
    while ($col !== $lastCol) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
        $col++;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="points_by_session_' . $selectedDate . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Points by Session — <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
        th, td { border: 1px solid #ccc; padding: .5rem .75rem; text-align: left; }
        th { background: #f2f2f2; }
        .total-col { font-weight: 600; }
    </style>
</head>
<body style="padding:2rem;">

    <h2>Points by Session</h2>
    <p><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?> — <?= $startDate ?> to <?= $endDate ?></p>

    <form method="GET" style="margin:1rem 0;display:flex;gap:.5rem;align-items:center;">
        <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token'], ENT_QUOTES, 'UTF-8') ?>">
        <label for="date">Date:</label>
        <select name="date" id="date" onchange="this.form.submit()">
            <?php foreach ($availableDates as $d): ?>
                <option value="<?= $d ?>" <?= $d === $selectedDate ? 'selected' : '' ?>>
                    <?= date('d M Y', strtotime($d)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <a href="?token=<?= urlencode($_GET['token']) ?>&date=<?= urlencode($selectedDate) ?>&format=excel" class="btn btn-secondary">Download Excel</a>
        <a href="?token=<?= urlencode($_GET['token']) ?>&date=<?= urlencode($selectedDate) ?>&format=word" class="btn btn-secondary">Download Word</a>
    </form>

    <table>
        <thead>
            <tr>
                <th>Reg No</th>
                <th>Name</th>
                <?php foreach ($sessionWindows as $sw): ?>
                    <th><?= htmlspecialchars($sw['label'], ENT_QUOTES, 'UTF-8') ?></th>
                <?php endforeach; ?>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="<?= count($sessionWindows) + 3 ?>">No registrations found.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['reg_no'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <?php foreach ($sessionWindows as $sw): ?>
                            <td><?= (int) $row['s' . (int) $sw['id']] ?></td>
                        <?php endforeach; ?>
                        <td class="total-col"><?= (int) $row['total'] ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

</body>
</html>
