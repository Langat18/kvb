<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;
use VetBoard\Registration;
use VetBoard\SessionWindowRepository;

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
$sessionLabels = (new SessionWindowRepository())->labelMap();
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

<div class="no-print" style="margin-bottom:1rem;">
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
                <td><?= htmlspecialchars($sessionLabels[$r['session']] ?? $r['session'], ENT_QUOTES, 'UTF-8') ?></td>
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
