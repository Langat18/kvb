<?php
?>
<div class="navbar">
    <div class="navbar-brand">
        <img src="/assets/img/kvb_logo.png" alt="KVB">
    </div>
    <button type="button" class="navbar-toggle" id="navbarToggle" aria-label="Toggle menu" aria-expanded="false" aria-controls="navbarUser">
        <span></span><span></span><span></span>
    </button>
    <div class="navbar-user" id="navbarUser">
        <span><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="role-badge"><?= htmlspecialchars($user['role_label'] ?: $user['role'], ENT_QUOTES, 'UTF-8') ?></span>
        <a href="/account/change_password.php">Change Password</a>
        <a href="/logout.php">Log Out</a>
    </div>
</div>

<script>
(function () {
    var toggle = document.getElementById('navbarToggle');
    var menu   = document.getElementById('navbarUser');
    if (!toggle || !menu) return;

    toggle.addEventListener('click', function () {
        var isOpen = menu.classList.toggle('open');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', function (e) {
        if (!menu.classList.contains('open')) return;
        if (menu.contains(e.target) || toggle.contains(e.target)) return;
        menu.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
    });
})();
</script>

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
