<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;
use VetBoard\EventModeRepository;
use VetBoard\EventQuiz;
use VetBoard\SessionWindowRepository;

Auth::startSession();
Auth::requirePermission('create_events');

$breadcrumbs = [['label' => 'Dashboard', 'url' => '/index.php'], ['label' => 'Events', 'url' => '/events/index.php'], ['label' => 'New Event', 'url' => null]];

$user = Auth::user();

$eventModeRepo    = new EventModeRepository();
$eventModes       = $eventModeRepo->list(true);
$sessionWindows   = (new SessionWindowRepository())->list(true);

$error            = $_SESSION['error']            ?? null;
$duplicateWarning = $_SESSION['duplicate_warning'] ?? null;
$formData         = $_SESSION['form_data']         ?? [];
unset($_SESSION['error'], $_SESSION['duplicate_warning'], $_SESSION['form_data']);

require __DIR__ . '/../templates/sidebar_data.php';

// Parses quiz[] POST into [session_window_id => ['clear' => bool, 'questions' => [...]]].
// Logs if the browser's pre-submit question count doesn't match what arrived server-side.
function parseQuizSubmission(array $post, int $eventId): array
{
    $result = [];

    foreach ($post as $swid => $swdata) {
        $swid = (int) $swid;

        $expectedCount = (int) ($swdata['expected_count'] ?? 0);
        $actualCount   = count($swdata['questions'] ?? []);

        if ($expectedCount !== $actualCount) {
            error_log("Quiz integrity mismatch — event {$eventId}, session_window {$swid}: expected {$expectedCount}, got {$actualCount}.");
        }

        if (!empty($swdata['clear'])) {
            $result[$swid] = ['clear' => true, 'questions' => []];
            continue;
        }

        $questions = [];

        foreach (($swdata['questions'] ?? []) as $qdata) {
            $text = trim($qdata['text'] ?? '');

            if ($text === '') {
                continue;
            }

            $options    = [];
            $correctIdx = (string) ($qdata['correct'] ?? '');

            foreach (($qdata['options'] ?? []) as $idx => $optText) {
                $optText = trim((string) $optText);

                if ($optText === '') {
                    continue;
                }

                $options[] = ['text' => $optText, 'correct' => ((string) $idx === $correctIdx)];
            }

            if (!empty($options)) {
                $questions[] = ['text' => $text, 'options' => $options];
            }
        }

        if (!empty($questions)) {
            $result[$swid] = ['clear' => false, 'questions' => $questions];
        }
    }

    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token. Please refresh and try again.');
    }

    $data = [
        'title'                     => $_POST['title']             ?? '',
        'event_code'                => $_POST['event_code']        ?? '',
        'event_mode_id'             => $_POST['event_mode_id']  !== '' ? (int) $_POST['event_mode_id'] : null,
        'venue'                     => $_POST['venue']              ?? '',
        'provider_id'               => $_POST['provider_id']    !== '' ? (int) $_POST['provider_id'] : null,
        'provider_accreditation_no' => $_POST['provider_accreditation_no'] ?? '',
        'coordinator'               => $_POST['coordinator']        ?? '',
        'start_date'                => $_POST['start_date']        ?? '',
        'end_date'                  => $_POST['end_date']           ?? '',
        'geo_lat'                   => $_POST['geo_lat']    !== '' ? (float) $_POST['geo_lat']    : null,
        'geo_lng'                   => $_POST['geo_lng']    !== '' ? (float) $_POST['geo_lng']    : null,
        'geo_radius_m'              => $_POST['geo_radius_m'] !== '' ? (int) $_POST['geo_radius_m'] : null,
        'meeting_provider'          => $_POST['meeting_provider'] ?? '',
        'meeting_link'              => $_POST['meeting_link']      ?? '',
        'session_points'            => $_POST['session_points'] ?? [],
        'created_by'                => $user['id'],
    ];

    $eventModel = new Event();

    if (empty($_POST['confirm_duplicate'])) {
        $similar = $eventModel->findRecentSimilarTitle($data['title']);

        if ($similar) {
            $_SESSION['duplicate_warning'] = $similar;
            $_SESSION['form_data']         = $data;
            header('Location: /events/create.php');
            exit;
        }
    }

    try {
        $eventId = $eventModel->create($data);
    } catch (\InvalidArgumentException $e) {
        $_SESSION['error']     = $e->getMessage();
        $_SESSION['form_data'] = $data;
        header('Location: /events/create.php');
        exit;
    } catch (\Throwable $e) {
        error_log('Event creation error: ' . $e->getMessage());
        $_SESSION['error']     = 'A system error occurred. Please try again.';
        $_SESSION['form_data'] = $data;
        header('Location: /events/create.php');
        exit;
    }

    $created = $eventModel->findById($eventId);

    try {
        // Quizzes only apply to virtual/hybrid — never save them for a pure onsite event.
        if (($created['event_mode'] ?? '') !== 'onsite') {
            $quizModel = new EventQuiz();

            foreach (parseQuizSubmission($_POST['quiz'] ?? [], $eventId) as $swid => $q) {
                $quizModel->saveQuestions($eventId, $swid, $q['questions']);
            }
        }

        $_SESSION['success'] = 'Event created successfully.';
        header('Location: /events/view.php?token=' . urlencode($created['view_token']));
        exit;

    } catch (\InvalidArgumentException $e) {
        $_SESSION['success'] = 'Event created, but a quiz could not be saved (' . $e->getMessage() . '). You can fix it below.';
        header('Location: /events/edit.php?token=' . urlencode($created['view_token']));
        exit;

    } catch (\Throwable $e) {
        error_log('Quiz save error: ' . $e->getMessage());
        $_SESSION['success'] = 'Event created, but the quiz could not be saved due to a system error. You can add it below.';
        header('Location: /events/edit.php?token=' . urlencode($created['view_token']));
        exit;
    }
}

$v = $formData;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Event - Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<style>
.quiz-panel { margin-top: 1.5rem; }
.quiz-panel > .form-group.full > label { font-weight: 600; }
.quiz-session {
    border: 1.5px solid #d8dde3; border-radius: 12px; padding: 1rem;
    margin-bottom: 1rem; background: #fafbfc;
}
.quiz-session-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: .75rem; }
.quiz-session-header strong { font-size: .92rem; }
.quiz-clear-label { font-size: .78rem; color: var(--text-muted); display: flex; align-items: center; gap: .35rem; cursor: pointer; }
.quiz-question-block {
    border: 1px dashed #c8ced6; border-radius: 10px; padding: .85rem;
    margin-bottom: .75rem; background: #fff;
}
.quiz-question-block .form-group { margin-bottom: .5rem; }
.quiz-option-row { display: flex; align-items: center; gap: .5rem; margin-bottom: .4rem; }
.quiz-option-row input[type="radio"] { accent-color: var(--kvb-green-dark); width: 16px; height: 16px; flex-shrink: 0; }
.quiz-option-row input[type="text"] { flex: 1; }
.quiz-question-actions { display: flex; gap: .5rem; margin-top: .5rem; }
.quiz-remove-question { color: var(--kvb-red-dark); }
</style>
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="app-page-hero"><div><h1>New Event</h1></div></div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($duplicateWarning): ?>
        <div class="alert alert-warning">
            An event titled "<?= htmlspecialchars($duplicateWarning['title'], ENT_QUOTES, 'UTF-8') ?>"
            (ID <?= (int) $duplicateWarning['id'] ?>, code <?= htmlspecialchars($duplicateWarning['event_code'], ENT_QUOTES, 'UTF-8') ?>,
            <?= htmlspecialchars($duplicateWarning['start_date'], ENT_QUOTES, 'UTF-8') ?> &ndash; <?= htmlspecialchars($duplicateWarning['end_date'], ENT_QUOTES, 'UTF-8') ?>)
            was created recently. Did you mean to
            <a href="/events/edit.php?token=" onclick="return false;">edit that one</a> instead?
            <label style="display:block;margin-top:.5rem;font-weight:normal;">
                <input type="checkbox" name="confirm_duplicate" value="1"> No, create this as a separate event anyway
            </label>
        </div>
    <?php endif; ?>

    <div class="app-card">
        <form method="POST" action="/events/create.php" id="event-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="provider_id" id="provider_id" value="<?= htmlspecialchars((string) ($v['provider_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-grid">
                <div class="form-group full">
                    <label for="title">Event Title <span class="required">*</span></label>
                    <input type="text" id="title" name="title" required
                           value="<?= htmlspecialchars($v['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label for="event_code">Registration No. of Activity</label>
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
                    <label for="coordinator">Supervisor</label>
                    <input type="text" id="coordinator" name="coordinator"
                           value="<?= htmlspecialchars($v['coordinator'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label for="provider_accreditation_no">Provider Accreditation No.</label>
                    <input type="text" id="provider_accreditation_no" name="provider_accreditation_no"
                           placeholder="e.g. ACC/CPD-P/001/2013"
                           value="<?= htmlspecialchars($v['provider_accreditation_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <small id="provider-status" style="color:var(--text-muted);"></small>
                </div>

                <div class="form-group">
                    <label for="provider_name_display">Provider Name</label>
                    <input type="text" id="provider_name_display" readonly placeholder="Fills in automatically">
                </div>
            </div>

            <div class="form-grid" id="meeting-fields">
                <div class="form-group full">
                    <label>Virtual Meeting <span style="font-weight:normal;color:var(--text-muted);">(required for virtual/hybrid)</span></label>
                </div>
                <div class="form-group">
                    <label for="meeting_provider">Provider</label>
                    <select id="meeting_provider" name="meeting_provider">
                        <?php foreach (MEETING_PROVIDERS as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= ($v['meeting_provider'] ?? '') === $key ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="meeting_link">Meeting Link</label>
                    <input type="url" id="meeting_link" name="meeting_link" placeholder="https://meet.google.com/xxx-xxxx-xxx"
                           value="<?= htmlspecialchars($v['meeting_link'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
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
                           value="<?= htmlspecialchars((string) ($v['geo_radius_m'] ?? DEFAULT_GEOFENCE_RADIUS_M), ENT_QUOTES, 'UTF-8')?>">
                </div>
            </div>

            <div class="form-grid" style="margin-top:1rem;">
                <div class="form-group full">
                    <label>Points per Session <span style="font-weight:normal;color:var(--text-muted);"></span></label>
                </div>
                <?php foreach ($sessionWindows as $sw): ?>
                    <div class="form-group">
                        <label for="session_points_<?= (int) $sw['id'] ?>"><?= htmlspecialchars($sw['label'], ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="number" min="0" class="session-points-input"
                               id="session_points_<?= (int) $sw['id'] ?>"
                               name="session_points[<?= (int) $sw['id'] ?>]"
                               value="<?= htmlspecialchars((string) ($v['session_points'][$sw['id']] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                <?php endforeach; ?>
                <div class="form-group full">
                    <span id="points-total" style="font-size:.85rem;color:var(--text-muted);">Total: 0 / 8</span>
                </div>
            </div>

            <div class="quiz-panel" id="quiz-panel">
                <div class="form-group full">
                    <label>Session Quiz <span style="font-weight:normal;color:var(--text-muted);"></span></label>
                </div>

                <?php foreach ($sessionWindows as $sw): ?>
                    <div class="quiz-session" data-swid="<?= (int) $sw['id'] ?>">
                        <input type="hidden" class="quiz-expected-count" name="quiz[<?= (int) $sw['id'] ?>][expected_count]" value="0">
                        <div class="quiz-session-header">
                            <strong><?= htmlspecialchars($sw['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="quiz-questions-list"></div>
                        <button type="button" class="btn btn-ghost btn-sm add-question-btn">+ Add Question</button>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:1.5rem;">
                <button type="submit" class="btn btn-primary" id="submit-btn">Create Event</button>
            </div>
        </form>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="/assets/js/event-geo-form.js"></script>
<script src="/assets/js/quiz-builder.js"></script>
<script>
// Quiz + meeting fields only apply to virtual/hybrid — hide both for onsite.
(function () {
    var modeSelect    = document.getElementById('event_mode_id');
    var quizPanel     = document.getElementById('quiz-panel');
    var meetingFields = document.getElementById('meeting-fields');
    if (!modeSelect) return;

    function toggle() {
        var selected = modeSelect.options[modeSelect.selectedIndex];
        var key = selected ? selected.getAttribute('data-key') : '';
        var hide = (key === 'onsite') ? 'none' : '';
        if (quizPanel)     quizPanel.style.display = hide;
        if (meetingFields) meetingFields.style.display = hide;
    }

    modeSelect.addEventListener('change', toggle);
    toggle();
})();
</script>
</body>
</html>
