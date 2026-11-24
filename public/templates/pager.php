<?php
/**
 * Shared page links. Expects $pager (VetBoard\Paginator) and optionally $pagerNoun (for example "events").
 * Draws nothing when the whole list fits on one small page.
 */
if (!isset($pager) || !$pager->needed()) {
    return;
}
$pagerNoun   = $pagerNoun ?? 'records';
$pagerAnchor = $pagerAnchor ?? '';
$esc = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<nav class="pagination" aria-label="Pages of <?= $esc($pagerNoun) ?>">
    <span class="pager-count">Showing <?= number_format($pager->from) ?>&ndash;<?= number_format($pager->to) ?> of <?= number_format($pager->total) ?> <?= $esc($pagerNoun) ?></span>
    <div class="pagination-links">
        <?php if ($pager->page > 1): ?>
            <a href="<?= $esc($pager->url($pager->page - 1) . $pagerAnchor) ?>" rel="prev">Previous</a>
        <?php else: ?>
            <span class="disabled">Previous</span>
        <?php endif; ?>
        <?php foreach ($pager->window() as $p): ?>
            <?php if ($p === null): ?>
                <span class="gap" aria-hidden="true">&hellip;</span>
            <?php elseif ($p === $pager->page): ?>
                <span class="current" aria-current="page"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= $esc($pager->url($p) . $pagerAnchor) ?>" aria-label="Page <?= $p ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($pager->page < $pager->pages): ?>
            <a href="<?= $esc($pager->url($pager->page + 1) . $pagerAnchor) ?>" rel="next">Next</a>
        <?php else: ?>
            <span class="disabled">Next</span>
        <?php endif; ?>
    </div>
    <form method="get" action="<?= $esc($pagerAnchor) ?>" class="pager-size">
        <?php foreach ($pager->carried() as $k => $v): ?>
            <input type="hidden" name="<?= $esc($k) ?>" value="<?= $esc($v) ?>">
        <?php endforeach; ?>
        <label>Rows
            <select name="<?= $esc($pager->sizeKey()) ?>" onchange="this.form.submit()">
                <?php foreach (\VetBoard\Paginator::SIZES as $n): ?>
                    <option value="<?= $n ?>"<?= $n === $pager->perPage ? ' selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <noscript><button type="submit" class="btn btn-sm">Go</button></noscript>
    </form>
</nav>
