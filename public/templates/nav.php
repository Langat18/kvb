<?php
?>
<div class="navbar">
    <div class="navbar-brand">
        <img src="/assets/img/kvb_logo.png" alt="KVB">
    </div>
    <?php if (!empty($useAppSidebar)): ?>
    <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle menu" aria-expanded="false" aria-controls="appSidebar">
        <span></span><span></span><span></span>
    </button>
    <?php endif; ?>
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

<?php if (!empty($useAppSidebar)): ?>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<aside class="app-sidebar" id="appSidebar">
    <div class="app-sidebar-brand"><img src="/assets/img/kvb_logo.png" alt="KVB"></div>

    <div class="app-sidebar-body">
        <?php if (isset($sidebarFlaggedPct)): ?>
            <div class="ring-card">
                <div class="ring <?= $sidebarFlaggedPct > 20 ? 'ring-warn' : '' ?>" style="--pct: <?= (int) $sidebarFlaggedPct ?>;">
                    <span><?= (int) $sidebarFlaggedPct ?>%</span>
                </div>
                <div>
                    <div class="ring-title">Flagged Rate</div>
                    <div class="ring-sub">of attendance records</div>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($sidebarActionGroups)): ?>
        <details class="app-sidebar-actions" open>
            <summary>Quick Actions <span class="chev">&rsaquo;</span></summary>
            <?php foreach ($sidebarActionGroups as $groupLabel => $groupActions): ?>
                <?php if (empty($groupActions)) { continue; } ?>
                <details class="app-sidebar-group">
                    <summary><?= htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') ?> <span class="chev">&rsaquo;</span></summary>
                    <?php foreach ($groupActions as $a): ?>
                        <a href="<?= htmlspecialchars($a['url'], ENT_QUOTES, 'UTF-8') ?>" class="app-action-row">
                            <span class="badge"><?= $a['icon'] ?></span>
                            <span>
                                <span class="title"><?= htmlspecialchars($a['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="desc"><?= htmlspecialchars($a['desc'], ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </details>
            <?php endforeach; ?>
        </details>
        <?php endif; ?>
    </div>

    <div class="app-sidebar-profile-wrap">
        <details>
            <summary>
                <span class="avatar"><?php
                    $__n = preg_replace('/^(Mr|Mrs|Ms|Miss|Dr|Prof|Eng|Rev)\.?\s+/i', '', trim($user['name']));
                    $__p = array_filter(explode(' ', $__n));
                    echo htmlspecialchars(implode('', array_map(fn($x) => strtoupper($x[0]), array_slice($__p, 0, 2))) ?: '?', ENT_QUOTES, 'UTF-8');
                ?></span>
                <span>
                    <span class="name"><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="role"><?= htmlspecialchars($user['role_label'] ?: $user['role'], ENT_QUOTES, 'UTF-8') ?></span>
                </span>
                <span class="chev">&#9650;</span>
            </summary>
            <div class="app-sidebar-profile-menu">
                <a href="/account/change_password.php">Change Password</a>
                <a href="/logout.php">Log Out</a>
            </div>
        </details>
    </div>
</aside>
<script>
(function () {
    var toggle   = document.getElementById('sidebarToggle');
    var sidebar  = document.getElementById('appSidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    if (!toggle || !sidebar) return;

    function closeSidebar() {
        sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('open');
        document.body.classList.remove('sidebar-open');
        toggle.setAttribute('aria-expanded', 'false');
    }

    function openSidebar() {
        sidebar.classList.add('open');
        if (backdrop) backdrop.classList.add('open');
        document.body.classList.add('sidebar-open');
        toggle.setAttribute('aria-expanded', 'true');
    }

    toggle.addEventListener('click', function () {
        sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
    });

    if (backdrop) backdrop.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSidebar();
    });
})();
</script>
<?php endif; ?>

<?php if (!empty($breadcrumbs)): ?>
<div class="<?= !empty($useAppSidebar) ? 'sidebar-breadcrumb' : 'page-body-outer' ?>">
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
</div>
<?php endif; ?>
