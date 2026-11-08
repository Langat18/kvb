<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\DailyRegister;
use VetBoard\Event;
use VetBoard\Registration;
use VetBoard\Security;

Auth::startSession();
Auth::requireLogin();

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

$eventId      = (int) $event['id'];
$registration = new Registration();
$pdo          = VetBoard\Database::getInstance()->getPdo();

$datesStmt = $pdo->prepare(
    'SELECT DISTINCT a.attendance_date
     FROM attendance a
     JOIN registrations r ON r.id = a.registration_id
     WHERE r.event_id = :event_id
     ORDER BY a.attendance_date'
);
$datesStmt->execute([':event_id' => $eventId]);
$availableDates = array_column($datesStmt->fetchAll(), 'attendance_date');

$defaultDate  = $availableDates !== [] ? end($availableDates) : date('Y-m-d');
$selectedDate = (string) ($_GET['date'] ?? $defaultDate);

if (
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)
    || ($availableDates !== [] && !in_array($selectedDate, $availableDates, true))
) {
    $selectedDate = $defaultDate;
}

$rows     = $registration->attendanceRowsForDate($eventId, $selectedDate);
$sessions = $eventModel->getSessions($eventId, false);
$order    = [];

foreach ($sessions as $i => $s) {
    $order[(int) $s['id']] = $i;
}

usort($rows, static fn(array $a, array $b): int =>
    strcasecmp((string) $a['name'], (string) $b['name'])
    ?: strcmp((string) $a['reg_no'], (string) $b['reg_no'])
    ?: (($order[(int) ($a['session_id'] ?? 0)] ?? PHP_INT_MAX) <=> ($order[(int) ($b['session_id'] ?? 0)] ?? PHP_INT_MAX)));

// Contact details follow the same permission as the attendance register.
$showContact = Auth::can('print_attendance_register');

$columns = [
    ['label' => '#', 'width' => 500, 'value' => static fn(array $r, int $i): int => $i + 1],
    ['label' => 'Reg No', 'width' => 1100, 'text' => true, 'value' => static fn(array $r, int $i): string => (string) $r['reg_no']],
    ['label' => 'Full Name', 'width' => 2400, 'value' => static fn(array $r, int $i): string => (string) $r['name']],
];

if ($showContact) {
    $columns[] = ['label' => 'Email', 'width' => 2600, 'value' => static fn(array $r, int $i): string => (string) ($r['email'] ?? '')];
    $columns[] = ['label' => 'Phone', 'width' => 1300, 'text' => true, 'value' => static fn(array $r, int $i): string => (string) ($r['phone'] ?? '')];
}

$columns[] = ['label' => 'Session', 'width' => 1200, 'value' => static fn(array $r, int $i): string => (string) ($r['session_label'] ?? 'Unknown')];
$columns[] = ['label' => 'Method', 'width' => 900, 'value' => static fn(array $r, int $i): string => ucfirst((string) $r['method'])];
$columns[] = ['label' => 'Location', 'width' => 1300, 'value' => static function (array $r, int $i): string {
    if ((string) $r['method'] !== 'onsite') {
        return '-';
    }

    return DailyRegister::truthy($r['flagged'] ?? false) ? 'Flagged (' . (int) $r['distance_m'] . 'm)' : 'Verified';
}];
$columns[] = ['label' => 'Status', 'width' => 1300, 'value' => static fn(array $r, int $i): string => DailyRegister::status($r)];
$columns[] = ['label' => 'Points', 'width' => 700, 'value' => static fn(array $r, int $i): float => (float) $r['points_awarded']];

$startDate = date('d M Y', strtotime($event['start_date']));
$endDate   = date('d M Y', strtotime($event['end_date']));
$format    = $_GET['format'] ?? 'print';

if ($format === 'word') {
    \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);

    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $phpWord->setDefaultFontName('Arial');
    $phpWord->setDefaultFontSize(9);

    $section = $phpWord->addSection(['orientation' => 'landscape']);
    $section->addText('Points by Session — ' . $event['title'], ['bold' => true, 'size' => 14]);
    $section->addText($startDate . ' to ' . $endDate . ' | Date: ' . $selectedDate);
    $section->addTextBreak(1);

    $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80]);

    $table->addRow();
    foreach ($columns as $c) {
        $table->addCell($c['width'])->addText($c['label'], ['bold' => true]);
    }

    foreach ($rows as $i => $r) {
        $table->addRow();

        foreach ($columns as $c) {
            $v = ($c['value'])($r, $i);
            $table->addCell($c['width'])->addText((string) $v);
        }
    }

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment;filename="points_by_session_' . $selectedDate . '.docx"');
    header('Cache-Control: max-age=0');

    \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
    exit;
}

if ($format === 'excel') {
    $coord       = static fn(int $n): string => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($n);
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Points by Session');

    foreach ($columns as $n => $c) {
        $sheet->setCellValue($coord($n + 1) . '1', $c['label']);
        $sheet->getColumnDimension($coord($n + 1))->setAutoSize(true);
    }

    $sheet->getStyle('A1:' . $coord(count($columns)) . '1')->getFont()->setBold(true);

    foreach ($rows as $i => $r) {
        foreach ($columns as $n => $c) {
            $addr = $coord($n + 1) . ($i + 2);
            $v    = ($c['value'])($r, $i);

            if (is_int($v) || is_float($v)) {
                $sheet->setCellValue($addr, $v);
            } elseif (!empty($c['text'])) {
                $sheet->getCell($addr)->setValueExplicit(Security::excelSafe($v), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue($addr, Security::excelSafe($v));
            }
        }
    }

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="points_by_session_' . $selectedDate . '.xlsx"');
    header('Cache-Control: max-age=0');

    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    exit;
}

$h           = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$people      = count(array_unique(array_column($rows, 'reg_no')));
$totalPoints = array_sum(array_map('floatval', array_column($rows, 'points_awarded')));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Points by Session — <?= $h($event['title']) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
        th, td { border: 1px solid #ccc; padding: .5rem .75rem; text-align: left; }
        th { background: #f2f2f2; }
        .summary { margin-top: .75rem; font-size: .85rem; color: #555; }
    @media screen and (max-width: 900px) {
    body { padding: 1rem !important; }
    table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; max-width: 100%; }
    form { flex-wrap: wrap; }
    img { max-width: 100%; height: auto; }
}
</style>
</head>
<body style="padding:2rem;">

    <h2>Points by Session</h2>
    <p><?= $h($event['title']) ?> — <?= $startDate ?> to <?= $endDate ?></p>

    <form method="GET" style="margin:1rem 0;display:flex;gap:.5rem;align-items:center;">
        <input type="hidden" name="token" value="<?= $h($_GET['token']) ?>">
        <label for="date">Date:</label>
        <select name="date" id="date" onchange="this.form.submit()">
            <?php foreach ($availableDates as $d): ?>
                <option value="<?= $h($d) ?>" <?= $d === $selectedDate ? 'selected' : '' ?>>
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
                <?php foreach ($columns as $c): ?><th><?= $h($c['label']) ?></th><?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="<?= count($columns) ?>">No attendance records for this date.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <?php foreach ($columns as $c): ?>
                            <td><?= $h((string) ($c['value'])($r, $i)) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($rows): ?>
        <p class="summary"><?= count($rows) ?> sign-in(s) by <?= $people ?> attendee(s) &middot; <?= \VetBoard\Points::format($totalPoints) ?> point(s) awarded</p>
    <?php endif; ?>

</body>
</html>
