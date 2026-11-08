<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\PmsDirectory;

Auth::startSession();
Auth::requirePermission('manage_events');

$user      = Auth::user();
$directory = new PmsDirectory();
$today     = date('Y-m-d');
$perPage   = 25;

$tabs = ['activities', 'links', 'providers'];
$tab  = in_array($_GET['tab'] ?? '', $tabs, true) ? (string) $_GET['tab'] : 'activities';

$back = static function (array $extra = []) use ($tab): string {
    $keep = array_filter(['tab' => $tab, 'scope' => $_GET['scope'] ?? '', 'q' => $_GET['q'] ?? '', 'type' => $_GET['type'] ?? '', 'linked' => $_GET['linked'] ?? '', 'page' => $_GET['page'] ?? ''], static fn($v) => $v !== '');

    return '/pms/index.php?' . http_build_query(array_merge($keep, $extra));
};

$flash = static function (string $kind, string $message): void {
    $_SESSION['pd_flash'] = [$kind, $message];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        die('Invalid security token. Please refresh and try again.');
    }

    $action     = (string) ($_POST['action'] ?? '');
    $activityId = (int) ($_POST['activity_id'] ?? 0);
    $eventId    = (int) ($_POST['event_id'] ?? 0);

    if ($action === 'sync_now') {
        $last = $directory->lastRun();

        if ($last && $last['status'] === 'running' && strtotime((string) $last['started_at']) > time() - 120) {
            $flash('err', 'A sync is already running. Try again in a minute.');
        } elseif ($last && strtotime((string) $last['started_at']) > time() - 60) {
            $flash('err', 'A sync ran less than a minute ago.');
        } else {
            $r = $directory->sync('manual');
            $flash($r['ok'] ? 'ok' : 'err', $r['ok']
                ? 'Synced ' . $r['activities_seen'] . ' activities and ' . $r['providers_seen'] . ' providers (' . $r['activities_added'] . ' new, ' . $r['activities_changed'] . ' changed, ' . $r['events_linked'] . ' events linked).'
                : 'The sync failed: ' . $r['message']);
        }
    } elseif ($action === 'create_event' && Auth::can('create_events')) {
        $a = $directory->findActivity($activityId);

        if (!$a) {
            $flash('err', 'That PMS activity no longer exists.');
        } else {
            $provider = $a['provider_name'] ? $directory->matchLocalProvider((string) $a['provider_name']) : null;

            $_SESSION['form_data'] = array_filter([
                'title'                     => $a['title'],
                'event_code'                => $a['activity_number'],
                'venue'                     => $a['venue'],
                'coordinator'               => $a['coordinator'],
                'start_date'                => $a['start_date'],
                'end_date'                  => $a['end_date'],
                'provider_id'               => $provider['id'] ?? null,
                'provider_accreditation_no' => $provider['accreditation_no'] ?? null,
            ], static fn($v) => $v !== null && $v !== '');

            header('Location: /events/create.php');
            exit;
        }
    } elseif ($action === 'link' && $activityId > 0 && $eventId > 0) {
        $directory->link($eventId, $activityId, isset($user['id']) ? (int) $user['id'] : null, 'manual');
        $flash('ok', 'Event linked to the PMS activity.');
    } elseif ($action === 'unlink' && $eventId > 0) {
        $directory->unlink($eventId);
        $flash('ok', 'Link removed.');
    } elseif ($action === 'add_provider' && Auth::can('manage_providers')) {
        $added = $directory->addProviderLocally(trim((string) ($_POST['accred_num'] ?? '')));
        $flash($added ? 'ok' : 'err', $added ? 'Provider added to your providers list.' : 'That provider could not be added. It may already exist or is not approved.');
    }

    header('Location: ' . $back());
    exit;
}

$filters = [
    'scope'  => in_array($_GET['scope'] ?? '', ['recent', 'upcoming', 'past', 'all', 'undated'], true) ? (string) $_GET['scope'] : 'recent',
    'q'      => trim((string) ($_GET['q'] ?? '')),
    'type'   => trim((string) ($_GET['type'] ?? '')),
    'linked' => in_array($_GET['linked'] ?? '', ['yes', 'no'], true) ? (string) $_GET['linked'] : '',
];

$page      = max(1, (int) ($_GET['page'] ?? 1));
$total     = $tab === 'activities' ? $directory->countActivities($filters, $today) : 0;
$pages     = max(1, (int) ceil($total / $perPage));
$page      = min($page, $pages);
$activities = $tab === 'activities' ? $directory->listActivities($filters, $today, $perPage, ($page - 1) * $perPage) : [];
$types      = $tab === 'activities' ? $directory->activityTypes() : [];
$unlinked   = $directory->unlinkedEvents();
$report     = $tab === 'providers' ? $directory->providerReport() : null;
$lastRun    = $directory->lastRun();
$lastOk     = $directory->lastSuccess();

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$ago = static function (?string $ts): string {
    if (!$ts) {
        return 'never';
    }

    $d = time() - strtotime($ts);

    if ($d < 90) {
        return 'just now';
    }

    return $d < 5400 ? round($d / 60) . ' min ago' : ($d < 172800 ? round($d / 3600) . ' h ago' : round($d / 86400) . ' days ago');
};
$range = static function (?string $s, ?string $e): string {
    if (!$s) {
        return 'No schedule';
    }

    $a = strtotime($s);
    $b = strtotime((string) $e);

    return (!$e || $s === $e) ? date('d M Y', $a) : (date('Y', $a) === date('Y', $b) ? date('d M', $a) . ' – ' . date('d M Y', $b) : date('d M Y', $a) . ' – ' . date('d M Y', $b));
};

$stale   = !$lastOk || strtotime((string) $lastOk['finished_at']) < time() - 36 * 3600;
$failed  = $lastRun && $lastRun['status'] === 'failed';
$flashed = $_SESSION['pd_flash'] ?? null;
unset($_SESSION['pd_flash']);
$csrf = Auth::csrfToken();

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/index.php'],
    ['label' => 'PMS Activities', 'url' => null],
];

require __DIR__ . '/../templates/sidebar_data.php';

$tabUrl = static fn(string $t): string => '/pms/index.php?tab=' . $t;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PMS Activities</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.pd { --ink:#1e293b; --muted:#64748b; --line:#e2e8f0; --soft:#f8fafc; --ok:#15803d; --ok-bg:#ecfdf3; --warn:#a16207; --warn-bg:#fffbeb; --warn-line:#fde68a; --bad:#b91c1c; --bad-bg:#fef2f2; --brand:var(--kvb-green-dark,#14532d); color:var(--ink); font-size:.9rem; line-height:1.5; }
.pd *, .pd *::before, .pd *::after { box-sizing:border-box; }
.pd-head { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; }
.pd-head h1 { margin:0; font-size:1.5rem; font-weight:700; letter-spacing:-.01em; }
.pd-lead { margin:.25rem 0 0; color:#334155; font-size:.875rem; max-width:64ch; }
.pd-card { background:#fff; border:1px solid var(--line); border-radius:12px; box-shadow:0 1px 2px rgba(15,23,42,.04); margin-bottom:1rem; overflow:hidden; }
.pd-card-h { display:flex; justify-content:space-between; align-items:center; gap:.75rem; flex-wrap:wrap; padding:.8rem 1.1rem; border-bottom:1px solid var(--line); background:var(--soft); }
.pd-card-h h2 { margin:0; font-size:.78rem; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
.pd-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.45rem .85rem; border-radius:8px; border:1px solid var(--line); background:#fff; color:var(--ink); font:inherit; font-size:.82rem; font-weight:600; text-decoration:none; cursor:pointer; }
.pd-btn:hover { background:var(--soft); border-color:#cbd5e1; }
.pd-btn.primary { background:var(--brand); border-color:var(--brand); color:#fff; }
.pd-btn.primary:hover { filter:brightness(1.1); }
.pd-btn.small { padding:.3rem .65rem; font-size:.78rem; }
.pd-btn.link { border:0; background:none; color:var(--muted); padding:.2rem .4rem; font-weight:500; text-decoration:underline; }
.pd-flash { padding:.7rem 1rem; border-radius:10px; border:1px solid; margin-bottom:1rem; font-size:.875rem; }
.pd-flash.ok { background:var(--ok-bg); border-color:#bbf7d0; color:#14532d; }
.pd-flash.err { background:var(--bad-bg); border-color:#fecaca; color:#7f1d1d; }
.pd-strip { display:flex; justify-content:space-between; align-items:center; gap:.75rem; flex-wrap:wrap; padding:.7rem 1rem; border-radius:12px; border:1px solid var(--line); background:#fff; margin-bottom:1rem; font-size:.85rem; }
.pd-strip.warn { background:var(--warn-bg); border-color:var(--warn-line); }
.pd-strip .m { color:var(--muted); }
.pd-tabs { display:flex; gap:.25rem; border-bottom:1px solid #cbd5e1; margin-bottom:1rem; flex-wrap:wrap; }
.pd-tab { padding:.55rem .95rem; border-radius:8px 8px 0 0; text-decoration:none; color:#334155; font-weight:600; font-size:.85rem; border:1px solid transparent; border-bottom:0; }
.pd-tab.on { background:#fff; border-color:var(--line); color:var(--brand); }
.pd-count { display:inline-block; min-width:1.4rem; padding:0 .4rem; margin-left:.3rem; border-radius:999px; background:var(--warn-bg); border:1px solid var(--warn-line); color:var(--warn); font-size:.72rem; text-align:center; }
.pd-filters { display:flex; gap:.5rem; flex-wrap:wrap; padding:.8rem 1.1rem; border-bottom:1px solid var(--line); }
.pd-filters input, .pd-filters select { padding:.42rem .65rem; border:1px solid var(--line); border-radius:8px; font:inherit; font-size:.85rem; background:#fff; color:var(--ink); }
.pd-filters input[type=search] { flex:1; min-width:200px; }
.pd-month { padding:.5rem 1.1rem; background:var(--soft); border-bottom:1px solid var(--line); font-size:.72rem; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:var(--muted); }
.pd-act { display:grid; grid-template-columns:140px 1fr auto; gap:1rem; padding:.9rem 1.1rem; border-bottom:1px solid #f1f5f9; align-items:start; }
.pd-act:last-child { border-bottom:0; }
.pd-date { font-weight:600; font-size:.82rem; font-variant-numeric:tabular-nums; }
.pd-date small { display:block; color:var(--muted); font-weight:400; }
.pd-title { font-weight:600; }
.pd-meta { color:var(--muted); font-size:.8rem; margin-top:.15rem; }
.pd-mono { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; }
.pd-badge { display:inline-block; padding:.08rem .5rem; border-radius:999px; font-size:.72rem; font-weight:600; border:1px solid var(--line); background:var(--soft); color:#475569; white-space:nowrap; }
.pd-badge.ok { background:var(--ok-bg); border-color:#bbf7d0; color:var(--ok); }
.pd-badge.warn { background:var(--warn-bg); border-color:var(--warn-line); color:var(--warn); }
.pd-act details { margin-top:.35rem; font-size:.8rem; }
.pd-act summary { cursor:pointer; color:var(--muted); }
.pd-act ul { margin:.3rem 0 0; padding-left:1.1rem; color:#334155; }
.pd-side { text-align:right; display:flex; flex-direction:column; gap:.35rem; align-items:flex-end; }
.pd-empty { padding:2rem 1.1rem; text-align:center; color:var(--muted); }
.pd-table { width:100%; border-collapse:separate; border-spacing:0; font-size:.85rem; }
.pd-table th { text-align:left; padding:.55rem 1rem; font-size:.7rem; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); background:var(--soft); border-bottom:1px solid var(--line); }
.pd-table td { padding:.55rem 1rem; border-bottom:1px solid #f1f5f9; vertical-align:top; }
.pd-pager { display:flex; justify-content:space-between; align-items:center; padding:.7rem 1.1rem; border-top:1px solid var(--line); color:var(--muted); font-size:.8rem; }
.pd-sub { padding:.9rem 1.1rem 0; font-size:.85rem; color:#334155; }
.pd form:not(.pd-filters) { display:inline; margin:0; }
@media (max-width:720px) { .pd-act { grid-template-columns:1fr; gap:.4rem; } .pd-side { align-items:flex-start; text-align:left; } .pd-head h1 { font-size:1.25rem; } }
</style>
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
<div class="pd">

    <div class="pd-head">
        <div>
            <h1>PMS Activities</h1>
            <p class="pd-lead">CPD activities and providers approved by the Kenya Veterinary Board, kept in step with PMS. Use them to plan the calendar and to create events with the right activity number.</p>
        </div>
        <form method="post" action="/pms/index.php">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="sync_now">
            <button type="submit" class="pd-btn primary">Sync now</button>
        </form>
    </div>

    <?php if ($flashed): ?>
        <div class="pd-flash <?= $h($flashed[0]) ?>" role="status"><?= $h($flashed[1]) ?></div>
    <?php endif; ?>

    <div class="pd-strip <?= ($failed || $stale) ? 'warn' : '' ?>">
        <?php if (!$lastOk): ?>
            <span><strong>Not synced yet.</strong> Press “Sync now” to load the PMS list.</span>
        <?php else: ?>
            <span><strong>Synced <?= $h($ago((string) $lastOk['finished_at'])) ?></strong>
                <span class="m">&middot; <?= (int) $lastOk['activities_seen'] ?> activities &middot; <?= (int) $lastOk['providers_seen'] ?> providers &middot; refreshes automatically every night</span></span>
        <?php endif; ?>
        <?php if ($failed): ?>
            <span><strong>The last sync failed</strong> <span class="m">(<?= $h($ago((string) $lastRun['started_at'])) ?>): <?= $h($lastRun['message'] ?? '') ?></span></span>
        <?php elseif ($lastOk && $stale): ?>
            <span><strong>The list is more than a day and a half old.</strong> <span class="m">Check the nightly sync.</span></span>
        <?php endif; ?>
    </div>

    <nav class="pd-tabs" aria-label="PMS sections">
        <a class="pd-tab <?= $tab === 'activities' ? 'on' : '' ?>" href="<?= $h($tabUrl('activities')) ?>">Activities</a>
        <a class="pd-tab <?= $tab === 'links' ? 'on' : '' ?>" href="<?= $h($tabUrl('links')) ?>">Event links<?= $unlinked ? '<span class="pd-count">' . count($unlinked) . '</span>' : '' ?></a>
        <a class="pd-tab <?= $tab === 'providers' ? 'on' : '' ?>" href="<?= $h($tabUrl('providers')) ?>">Providers</a>
    </nav>

<?php if ($tab === 'activities'): ?>
    <section class="pd-card">
        <form method="get" action="/pms/index.php" class="pd-filters">
            <input type="hidden" name="tab" value="activities">
            <input type="search" name="q" value="<?= $h($filters['q']) ?>" placeholder="Search title, number or provider" aria-label="Search activities">
            <select name="scope" aria-label="When">
                <?php foreach (['recent' => 'Upcoming and last 30 days', 'upcoming' => 'Upcoming only', 'past' => 'Past', 'all' => 'All dates', 'undated' => 'No schedule'] as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $filters['scope'] === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <select name="type" aria-label="Type">
                <option value="">Any type</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= $h($t) ?>" <?= $filters['type'] === $t ? 'selected' : '' ?>><?= $h($t) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="linked" aria-label="Linked">
                <option value="">Linked or not</option>
                <option value="yes" <?= $filters['linked'] === 'yes' ? 'selected' : '' ?>>Has an event</option>
                <option value="no" <?= $filters['linked'] === 'no' ? 'selected' : '' ?>>No event yet</option>
            </select>
            <button class="pd-btn" type="submit">Filter</button>
        </form>

        <?php if (!$activities): ?>
            <div class="pd-empty"><?= $lastOk ? 'No activities match these filters.' : 'Nothing to show until the first sync.' ?></div>
        <?php else: ?>
            <?php $month = null; foreach ($activities as $a): ?>
                <?php $m = $a['start_date'] ? date('F Y', strtotime($a['start_date'])) : 'No schedule'; if ($m !== $month): $month = $m; ?>
                    <div class="pd-month"><?= $h($month) ?></div>
                <?php endif; ?>
                <article class="pd-act">
                    <div class="pd-date"><?= $h($range($a['start_date'], $a['end_date'])) ?>
                        <?php if (count($a['schedules']) > 1): ?><small><?= count($a['schedules']) ?> sessions</small><?php endif; ?></div>
                    <div>
                        <div class="pd-title"><?= $h($a['title']) ?></div>
                        <div class="pd-meta">
                            <?php if ($a['activity_number']): ?><span class="pd-mono"><?= $h($a['activity_number']) ?></span><?php else: ?><span class="pd-badge warn">No activity number</span><?php endif; ?>
                            <?php if ((int) $a['number_shared'] > 0): ?><span class="pd-badge warn" title="Another PMS activity uses the same number">Number shared</span><?php endif; ?>
                            <?php if ($a['activity_type']): ?><span class="pd-badge"><?= $h($a['activity_type']) ?></span><?php endif; ?>
                            <?php if ($a['provider_name']): ?> &middot; <?= $h($a['provider_name']) ?><?php endif; ?>
                            <?php if ($a['venue']): ?> &middot; <?= $h($a['venue']) ?><?php endif; ?>
                            <?php if ($a['coordinator']): ?> &middot; Coordinator: <?= $h($a['coordinator']) ?><?php endif; ?>
                        </div>
                        <?php if (count($a['schedules']) > 1): ?>
                            <details><summary>Show all <?= count($a['schedules']) ?> sessions</summary>
                                <ul><?php foreach ($a['schedules'] as $s): ?><li><?= $h($range($s['start_date'], $s['end_date'])) ?><?= $s['venue'] !== '' ? ' &middot; ' . $h($s['venue']) : '' ?></li><?php endforeach; ?></ul>
                            </details>
                        <?php endif; ?>
                    </div>
                    <div class="pd-side">
                        <?php foreach ($a['linked_events'] as $ev): ?>
                            <span><a class="pd-badge ok" href="/events/view.php?token=<?= $h(urlencode((string) $ev['token'])) ?>" title="<?= $h($ev['title']) ?>">Event created</a></span>
                        <?php endforeach; ?>
                        <?php if (!$a['linked_events'] && Auth::can('create_events')): ?>
                            <form method="post" action="/pms/index.php">
                                <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                                <input type="hidden" name="action" value="create_event">
                                <input type="hidden" name="activity_id" value="<?= (int) $a['id'] ?>">
                                <button type="submit" class="pd-btn small">Create event</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            <div class="pd-pager">
                <span><?= number_format($total) ?> activit<?= $total === 1 ? 'y' : 'ies' ?> &middot; page <?= $page ?> of <?= $pages ?></span>
                <span>
                    <?php if ($page > 1): ?><a class="pd-btn small" href="<?= $h($back(['page' => $page - 1])) ?>">&larr; Previous</a><?php endif; ?>
                    <?php if ($page < $pages): ?><a class="pd-btn small" href="<?= $h($back(['page' => $page + 1])) ?>">Next &rarr;</a><?php endif; ?>
                </span>
            </div>
        <?php endif; ?>
    </section>

<?php elseif ($tab === 'links'): ?>
    <section class="pd-card">
        <div class="pd-card-h"><h2>Events without a PMS activity</h2><span class="pd-badge"><?= count($unlinked) ?> from the last 120 days and upcoming</span></div>
        <p class="pd-sub">An event is linked automatically when its code matches one PMS activity number and the dates overlap. The ones below need a look.</p>
        <?php if (!$unlinked): ?>
            <div class="pd-empty">Every recent event is linked to a PMS activity.</div>
        <?php else: ?>
            <table class="pd-table">
                <thead><tr><th>Event</th><th>Code</th><th>Dates</th><th>What to do</th></tr></thead>
                <tbody>
                <?php foreach ($unlinked as $ev): ?>
                    <tr>
                        <td><a href="/events/view.php?token=<?= $h(urlencode((string) $ev['view_token'])) ?>"><?= $h($ev['title']) ?></a></td>
                        <td class="pd-mono"><?= $h($ev['event_code'] ?: '—') ?></td>
                        <td><?= $h($range($ev['start_date'], $ev['end_date'])) ?></td>
                        <td>
                            <?php if ($ev['reason'] === 'no_code'): ?>
                                <span class="pd-badge warn">No activity number</span> Add the PMS activity number on the event.
                            <?php elseif ($ev['reason'] === 'unknown_code'): ?>
                                <span class="pd-badge warn">Unknown number</span> PMS has no activity numbered <span class="pd-mono"><?= $h($ev['event_code']) ?></span>. Check for a typo or the wrong year.
                            <?php else: ?>
                                <?php if ($ev['reason'] === 'ambiguous'): ?>
                                    <span class="pd-badge warn">Shared number</span> More than one PMS activity has this number. Choose the right one:
                                <?php else: ?>
                                    <span class="pd-badge warn">Dates differ</span> The number exists in PMS, but not on these dates:
                                <?php endif; ?>
                                <?php foreach ($ev['candidates'] as $c): ?>
                                    <div style="margin-top:.3rem;"><?= $h($c['title']) ?> <span class="pd-meta">(<?= $h($range($c['start'], $c['end'])) ?>)</span>
                                        <form method="post" action="/pms/index.php?tab=links">
                                            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                                            <input type="hidden" name="action" value="link">
                                            <input type="hidden" name="event_id" value="<?= (int) $ev['id'] ?>">
                                            <input type="hidden" name="activity_id" value="<?= (int) $c['id'] ?>">
                                            <button class="pd-btn small" type="submit">Link to this</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

<?php else: ?>
    <?php $c = $report['counts']; ?>
    <section class="pd-card">
        <div class="pd-card-h"><h2>Providers</h2>
            <span class="pd-badge"><?= (int) $c['pms'] ?> in PMS &middot; <?= (int) $c['local'] ?> in your list &middot; <?= (int) $c['matched'] ?> matched</span></div>

        <p class="pd-sub"><strong>In PMS but not in your providers (<?= count($report['pms_only']) ?>)</strong>. Only approved providers can be added.</p>
        <?php if (!$report['pms_only']): ?>
            <div class="pd-empty">Every PMS provider is in your list.</div>
        <?php else: ?>
            <table class="pd-table">
                <thead><tr><th>Accreditation no.</th><th>Name</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach (array_slice($report['pms_only'], 0, 100) as $p): ?>
                    <tr>
                        <td class="pd-mono"><?= $h($p['accred_num']) ?></td>
                        <td><?= $h($p['name']) ?></td>
                        <td><span class="pd-badge <?= $p['status'] === 'approved' ? 'ok' : 'warn' ?>"><?= $h(ucfirst((string) $p['status'])) ?></span></td>
                        <td style="text-align:right;">
                            <?php if ($p['status'] === 'approved' && Auth::can('manage_providers')): ?>
                                <form method="post" action="/pms/index.php?tab=providers">
                                    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                                    <input type="hidden" name="action" value="add_provider">
                                    <input type="hidden" name="accred_num" value="<?= $h($p['accred_num']) ?>">
                                    <button class="pd-btn small" type="submit">Add</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p class="pd-sub"><strong>Same number, different name (<?= count($report['name_differs']) ?>)</strong>. Nothing is changed automatically.</p>
        <?php if (!$report['name_differs']): ?>
            <div class="pd-empty">All matched providers have the same name.</div>
        <?php else: ?>
            <table class="pd-table">
                <thead><tr><th>Accreditation no.</th><th>PMS name</th><th>Your name</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($report['name_differs'], 0, 100) as $p): ?>
                    <tr><td class="pd-mono"><?= $h($p['accred_num']) ?></td><td><?= $h($p['pms_name']) ?></td><td><?= $h($p['local_name']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p class="pd-sub"><strong>In your list but not in PMS (<?= count($report['local_only']) ?>)</strong>. Worth checking with KVB if you still use them.</p>
        <?php if ($report['local_only']): ?>
            <table class="pd-table">
                <thead><tr><th>Accreditation no.</th><th>Name</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($report['local_only'], 0, 100) as $p): ?>
                    <tr><td class="pd-mono"><?= $h($p['accreditation_no']) ?></td><td><?= $h($p['name']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
<?php endif; ?>

</div>
</div>
</body>
</html>
