<?php
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$hasRooms = false;

foreach ($programme as $s) {
    foreach ($s['items'] as $p) {
        if (($p['room'] ?? '') !== '') {
            $hasRooms = true;
            break 2;
        }
    }
}
?>
<div class="sec-title">Attendance by Cadre</div>
<table class="att-table">
    <thead>
        <tr>
            <th>Cadre</th>
            <?php foreach ($cadreStats['days'] as $d): ?>
                <th class="num"><?= date('D d M', strtotime($d)) ?></th>
            <?php endforeach; ?>
            <th class="num">Total</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($cadreStats['rows'] as $row): ?>
        <tr>
            <td><?= $h($row['label']) ?></td>
            <?php foreach ($row['days'] as $n): ?><td class="num"><?= $n ?></td><?php endforeach; ?>
            <td class="num"><?= $row['total'] ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td>Total</td>
            <?php foreach ($cadreStats['totals']['days'] as $n): ?><td class="num"><?= $n ?></td><?php endforeach; ?>
            <td class="num"><?= $cadreStats['totals']['total'] ?></td>
        </tr>
    </tbody>
</table>
<p class="note">
    Unique attendees per day; Total counts each person once.
    <?php if ($cadreStats['excluded'] > 0): ?>
        <?= (int) $cadreStats['excluded'] ?> record(s) pending review or denied are excluded.
    <?php endif; ?>
</p>

<?php if ($programme): ?>
<div class="sec-title">Programme</div>
<table class="att-table">
    <thead>
        <tr>
            <th>Day</th><th>Session</th><th>Time</th><th>Presenter</th><th>Topic</th>
            <?php if ($hasRooms): ?><th>Room</th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($programme as $s): $items = $s['items'] ?: [null]; ?>
            <?php foreach ($items as $i => $p): ?>
            <tr>
                <?php if ($i === 0): ?>
                    <td rowspan="<?= count($items) ?>"><?= $s['date'] ? date('d M Y', strtotime($s['date'])) : 'All days' ?></td>
                    <td rowspan="<?= count($items) ?>">
                        <strong><?= $h($s['label']) ?></strong><br>
                        <span class="muted"><?= $h($s['start']) ?> &ndash; <?= $h($s['end']) ?></span>
                    </td>
                <?php endif; ?>
                <td><?= $p ? $h($p['start']) . ' &ndash; ' . $h($p['end']) : '&mdash;' ?></td>
                <td><?= $p ? $h($p['presenter']) : '&mdash;' ?></td>
                <td><?= $p ? $h($p['topic']) : '&mdash;' ?></td>
                <?php if ($hasRooms): ?>
                    <td><?= $p && ($p['room'] ?? '') !== '' ? $h($p['room']) : '&mdash;' ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
