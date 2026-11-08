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
        body { padding: 0; background: #f4f6f8; }
        .ps { max-width: 1180px; margin: 0 auto; padding: clamp(1rem, 3vw, 2rem); }
        .ps-back { display: inline-flex; align-items: center; gap: .35rem; font-size: .82rem; font-weight: 600; color: var(--text-muted); text-decoration: none; margin-bottom: .75rem; }
        .ps-back:hover { color: var(--kvb-green-dark); }
        .ps h1 { font-size: clamp(1.3rem, 1rem + 1.4vw, 1.75rem); line-height: 1.25; margin: 0 0 .25rem; }
        .ps-sub { color: var(--text-muted); font-size: .88rem; margin: 0 0 1.25rem; }
        .ps-bar { display: flex; flex-wrap: wrap; gap: .6rem; align-items: center; margin: 0 0 1rem; }
        .ps-bar .ps-date { display: flex; align-items: center; gap: .5rem; flex: 1 1 220px; max-width: 320px; }
        .ps-bar label { margin: 0; white-space: nowrap; }
        .ps-bar .ps-dl { display: flex; gap: .5rem; margin-left: auto; }
        .ps-card { background: #fff; border: 1px solid var(--line); border-radius: 16px; box-shadow: var(--shadow-sm); overflow: hidden; }
        .ps-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .ps table { border-collapse: collapse; width: 100%; font-size: .85rem; }
        .ps th { position: sticky; top: 0; background: var(--surface-2); text-align: left; padding: .7rem .85rem; font-size: .72rem; letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted); border-bottom: 1px solid var(--line); white-space: nowrap; }
        .ps td { padding: .65rem .85rem; border-bottom: 1px solid #f0f2f5; vertical-align: top; }
        .ps tbody tr:hover td { background: #f7faf8; }
        .ps tbody tr:last-child td { border-bottom: 0; }
        .ps-empty { padding: 2rem; text-align: center; color: var(--text-muted); }
        .ps-foot { margin: .85rem .25rem 0; font-size: .82rem; color: var(--text-muted); }
        @media (max-width: 640px) { .ps-bar .ps-dl { margin-left: 0; width: 100%; } .ps-bar .ps-dl .btn { flex: 1; } .ps-bar .ps-date { max-width: none; width: 100%; } }
    </style>
</head>
<body>
<main class="ps">
    <a class="ps-back" href="/events/view.php?token=<?= urlencode((string) $_GET['token']) ?>">Back to event</a>
    <h1>Points by Session</h1>
    <p class="ps-sub"><?= $h($event['title']) ?> &middot; <?= $startDate ?> to <?= $endDate ?></p>

    <form method="GET" class="ps-bar">
        <input type="hidden" name="token" value="<?= $h($_GET['token']) ?>">
        <div class="ps-date">
            <label for="date">Date</label>
            <select name="date" id="date" onchange="this.form.submit()">
                <?php foreach ($availableDates as $d): ?>
                    <option value="<?= $h($d) ?>" <?= $d === $selectedDate ? 'selected' : '' ?>>
                        <?= date('d M Y', strtotime($d)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ps-dl">
            <a href="?token=<?= urlencode($_GET['token']) ?>&date=<?= urlencode($selectedDate) ?>&format=excel" class="btn btn-ghost">Excel</a>
            <a href="?token=<?= urlencode($_GET['token']) ?>&date=<?= urlencode($selectedDate) ?>&format=word" class="btn btn-ghost">Word</a>
        </div>
    </form>

    <div class="ps-card"><div class="ps-scroll">
    <table>
        <thead>
            <tr>
                <?php foreach ($columns as $c): ?><th><?= $h($c['label']) ?></th><?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="<?= count($columns) ?>" class="ps-empty">No attendance for this date.</td></tr>
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
    </div></div>

    <?php if ($rows): ?>
        <p class="ps-foot"><?= count($rows) ?> sign-ins by <?= $people ?> people &middot; <?= \VetBoard\Points::format($totalPoints) ?> points awarded</p>
    <?php endif; ?>
</main>
</body>
</html>
