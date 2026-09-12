<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Department;
use VetBoard\Event;
use VetBoard\EventModeRepository;

Auth::startSession();
Auth::requireLogin();

$breadcrumbs = [['label' => 'Dashboard', 'url' => '/index.php'], ['label' => 'Events', 'url' => '/events/index.php'], ['label' => 'Edit Event', 'url' => null]];

$user = Auth::user();

if (empty($_GET['token'])) {
    header('Location: /events/index.php');
    exit;
}

$eventModel = new Event();
$event      = $eventModel->findByViewToken(trim($_GET['token']));

if (!$event) {
    $_SESSION['error'] = 'Event not found.';
    header('Location: /events/index.php');
    exit;
}

$canEdit = Auth::can('manage_events') || (int) $user['id'] === (int) ($event['created_by'] ?? 0);

if (!$canEdit) {
    http_response_code(403);
    die('Access denied.');
}

$eventModeRepo    = new EventModeRepository();
$departmentModel  = new Department();
$eventModes       = $eventModeRepo->list(true);
$departments      = $departmentModel->list(true);

$error    = $_SESSION['error']     ?? null;
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['error'], $_SESSION['form_data']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $data = [
        'title'             => trim($_POST['title']             ?? ''),
        'event_code'        => trim($_POST['event_code']        ?? ''),
        'event_mode_id'     => $_POST['event_mode_id']  !== '' ? (int) $_POST['event_mode_id'] : null,
        'venue'             => trim($_POST['venue']              ?? ''),
        'department_id'     => $_POST['department_id'] !== '' ? (int) $_POST['department_id'] : null,
        'coordinator'       => trim($_POST['coordinator']        ?? ''),
        'program_assistant' => trim($_POST['program_assistant'] ?? ''),
        'start_date'        => $_POST['start_date']              ?? '',
        'end_date'          => $_POST['end_date']                ?? '',
        'geo_lat'           => $_POST['geo_lat']    !== '' ? (float) $_POST['geo_lat']    : null,
        'geo_lng'           => $_POST['geo_lng']    !== '' ? (float) $_POST['geo_lng']    : null,
        'geo_radius_m'      => $_POST['geo_radius_m'] !== '' ? (int) $_POST['geo_radius_m'] : null,
        'points_morning'     => $_POST['points_morning']     !== '' ? (int) $_POST['points_morning']     : null,
        'points_mid_morning' => $_POST['points_mid_morning'] !== '' ? (int) $_POST['points_mid_morning'] : null,
        'points_afternoon'   => $_POST['points_afternoon']   !== '' ? (int) $_POST['points_afternoon']   : null,
    ];

    try {
        $eventModel->update((int) $event['id'], $data);
        $_SESSION['success'] = 'Event updated successfully.';
        header('Location: /events/view.php?token=' . urlencode($event['view_token']));
        exit;

    } catch (\InvalidArgumentException $e) {
        $_SESSION['error']     = $e->getMessage();
        $_SESSION['form_data'] = $data;
        header('Location: /events/edit.php?token=' . urlencode($event['view_token']));
        exit;

    } catch (\Throwable $e) {
        error_log('Event update error: ' . $e->getMessage());
        $_SESSION['error']     = 'A system error occurred. Please try again.';
        $_SESSION['form_data'] = $data;
        header('Location: /events/edit.php?token=' . urlencode($event['view_token']));
        exit;
    }
}

$v = empty($formData) ? $event : array_merge($event, $formData);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Event — Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: 680px; margin: 0 auto; }
.container h1 { font-size: 1.3rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: 1rem; }
</style>
</head>
<body>
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="container">
    <h1>Edit Event</h1>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="/events/edit.php?token=<?= urlencode($event['view_token']) ?>" id="event-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-grid">
                <div class="form-group full">
                    <label for="title">Event Title <span class="required">*</span></label>
                    <input type="text" id="title" name="title" required
                           value="<?= htmlspecialchars($v['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label for="event_code">Event Code</label>
                    <input type="text" id="event_code" name="event_code"
                           value="<?= htmlspecialchars($v['event_code'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label for="event_mode_id">Mode <span class="required">*</span></label>
                    <select id="event_mode_id" name="event_mode_id" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($eventModes as $mode): ?>
                            <option value="<?= (int) $mode['id'] ?>"
                                    data-key="<?= htmlspecialchars($mode['item_key'], ENT_QUOTES, 'UTF-8') ?>"
                                    <?= (int) ($v['event_mode_id'] ?? 0) === (int) $mode['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($mode['label'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="start_date">Start Date <span class="required">*</span></label>
                    <input type="date" id="start_date" name="start_date" required
                           value="<?= htmlspecialchars($v['start_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label for="end_date">End Date <span class="required">*</span></label>
                    <input type="date" id="end_date" name="end_date" required
                           value="<?= htmlspecialchars($v['end_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label for="venue">Venue</label>
                    <input type="text" id="venue" name="venue"
                           value="<?= htmlspecialchars($v['venue'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label for="coordinator">Coordinator</label>
                    <input type="text" id="coordinator" name="coordinator"
                           value="<?= htmlspecialchars($v['coordinator'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label for="program_assistant">Program Assistant</label>
                    <input type="text" id="program_assistant" name="program_assistant"
                           value="<?= htmlspecialchars($v['program_assistant'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label for="department_id">Department <span class="required">*</span></label>
                    <select id="department_id" name="department_id" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= (int) $dept['id'] ?>" <?= (int) ($v['department_id'] ?? 0) === (int) $dept['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-grid geo-fields" id="geo-fields">
                <div class="form-group full">
                    <label>Location <span class="required">*</span> <span style="font-weight:normal;color:var(--text-muted);">(required for on-site/hybrid events)</span></label>
                </div>

                <div class="geo-map" id="geo-map"></div>

                <div class="form-group full">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="detectLocation()">&#128205; Use My Location</button>
                </div>

                <div class="form-group">
                    <label for="geo_lat">Latitude</label>
                    <input type="number" step="any" id="geo_lat" name="geo_lat"
                           value="<?= htmlspecialchars((string) ($v['geo_lat'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="geo_lng">Longitude</label>
                    <input type="number" step="any" id="geo_lng" name="geo_lng"
                           value="<?= htmlspecialchars((string) ($v['geo_lng'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="geo_radius_m">Radius (meters)</label>
                    <input type="number" id="geo_radius_m" name="geo_radius_m"
                           value="<?= htmlspecialchars((string) ($v['geo_radius_m'] ?? DEFAULT_GEOFENCE_RADIUS_M), ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <div class="form-grid" style="margin-top:1rem;">
                <div class="form-group full">
                    <label>Points per Session <span style="font-weight:normal;color:var(--text-muted);">(total across all sessions cannot exceed 8)</span></label>
                </div>
                <div class="form-group">
                    <label for="points_morning">Morning</label>
                    <input type="number" min="0" id="points_morning" name="points_morning"
                           value="<?= htmlspecialchars((string) ($v['points_morning'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="points_mid_morning">Mid-Morning</label>
                    <input type="number" min="0" id="points_mid_morning" name="points_mid_morning"
                           value="<?= htmlspecialchars((string) ($v['points_mid_morning'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="points_afternoon">Afternoon</label>
                    <input type="number" min="0" id="points_afternoon" name="points_afternoon"
                           value="<?= htmlspecialchars((string) ($v['points_afternoon'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group full">
                    <span id="points-total" style="font-size:.85rem;color:var(--text-muted);">Total: 0 / 8</span>
                </div>
            </div>

            <div style="margin-top:1.5rem;display:flex;gap:.6rem;">
                <button type="submit" class="btn btn-primary" id="submit-btn">Save Changes</button>
                <a href="/events/view.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="/assets/js/event-geo-form.js"></script>
</body>
</html>
