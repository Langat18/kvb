<?php
/**
 * Shared navbar + breadcrumb partial.
 * Expects $user (from Auth::user()) and optional $breadcrumbs array of ['label' => ..., 'url' => ... or null for current page].
 */
$roleLabels = ['admin' => 'Admin', 'staff' => 'Staff', 'ceo' => 'CEO'];
?>
<div class="navbar">
    <div class="navbar-brand">
        <img src="/assets/img/kvb_logo.png" alt="KVB">
    </div>
    <div class="navbar-user">
        <span><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="role-badge"><?= htmlspecialchars($roleLabels[$user['role']] ?? $user['role'], ENT_QUOTES, 'UTF-8') ?></span>
        <a href="/account/change_password.php">Change Password</a>
        <a href="/logout.php">Log Out</a>
    </div>
</div>

<?php if (!empty($breadcrumbs)): ?>
<div class="page-body-outer">
<div class="breadcrumb">
    <?php foreach ($breadcrumbs as $i => $crumb): ?>
        <?php if ($i > 0): ?> &rsaquo; <?php endif; ?>
        <?php if (!empty($crumb['url'])): ?>
            <a href="<?= htmlspecialchars($crumb['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($crumb['label'], ENT_QUOTES, 'UTF-8') ?></a>
        <?php else: ?>
            <?= htmlspecialchars($crumb['label'], ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>
