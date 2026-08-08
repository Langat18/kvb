<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;

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

$canEdit = $user['role'] === 'admin' || (int) $user['id'] === (int) ($event['created_by'] ?? 0);

if (!$canEdit) {
    http_response_code(403);
    die('Access denied.');
}

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
        'event_mode'        => $_POST['event_mode']              ?? '',
        'venue'             => trim($_POST['venue']              ?? ''),
        'department'        => trim($_POST['department']        ?? ''),
        'coordinator'       => trim($_POST['coordinator']        ?? ''),
        'program_assistant' => trim($_POST['program_assistant'] ?? ''),
        'start_date'        => $_POST['start_date']              ?? '',
        'end_date'          => $_POST['end_date']                ?? '',
        'geo_lat'           => $_POST['geo_lat']    !== '' ? (float) $_POST['geo_lat']    : null,
        'geo_lng'           => $_POST['geo_lng']    !== '' ? (float) $_POST['geo_lng']    : null,
        'geo_radius_m'      => $_POST['geo_radius_m'] !== '' ? (int) $_POST['geo_radius_m'] : null,
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
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: 680px; margin: 0 auto; }
.container h1 { font-size: 1.3rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: 1rem; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.form-group { margin-bottom: 1rem; }
.form-group.full { grid-column: 1 / -1; }
#geo-fields { display: none; }
#geo-fields.active { display: grid; }
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
                    <label for="event_mode">Mode <span class="required">*</span></label>
                    <select id="event_mode" name="event_mode" required>
                        <?php foreach (EVENT_MODES as $key => $label): ?>
                            <option value="<?= $key ?>" <?= ($v['event_mode'] ?? '') === $key ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
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
                    <label for="department">Department</label>
                    <input type="text" id="department" name="department"
                           value="<?= htmlspecialchars($v['department'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <div class="form-grid" id="geo-fields">
                <div class="form-group full">
                    <label>Location <span class="required">*</span> <span style="font-weight:normal;color:var(--text-muted);">(required for on-site/hybrid events)</span></label>
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

            <div style="margin-top:1.5rem;display:flex;gap:.6rem;">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="/events/view.php?token=<?= urlencode($event['view_token']) ?>" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
const modeSelect = document.getElementById('event_mode');
const geoFields  = document.getElementById('geo-fields');

function toggleGeoFields() {
    const mode = modeSelect.value;
    if (mode === 'onsite' || mode === 'hybrid') {
        geoFields.classList.add('active');
    } else {
        geoFields.classList.remove('active');
    }
}

modeSelect.addEventListener('change', toggleGeoFields);
window.addEventListener('pageshow', toggleGeoFields);
toggleGeoFields();
</script>
</body>
</html>
