<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;
use VetBoard\EventModeRepository;
use VetBoard\EventQuiz;

Auth::startSession();
Auth::requirePermission('view_events');

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

$eventModeRepo = new EventModeRepository();
$eventModes    = $eventModeRepo->list(true);
$quizModel     = new EventQuiz();

$error    = $_SESSION['error']     ?? null;
$success  = $_SESSION['success']   ?? null;
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['error'], $_SESSION['success'], $_SESSION['form_data']);

/**
 * Parses the quiz[] POST block into a save-ready structure keyed by session id:
 * [ session_id => ['clear' => bool, 'questions' => [...]] ]
 *
 * The id is a numeric session id, or a client placeholder (new_N) for a session
 * added in the same submission. Sessions with a blank panel are omitted so an
 * untouched quiz is never cleared. A mismatch between the submitted and received
 * question counts is logged.
 */
function parseQuizSubmission(array $post, int $eventId): array
{
    $result = [];

    foreach ($post as $swid => $swdata) {
        $swid = (string) $swid;

        $expectedCount = (int) ($swdata['expected_count'] ?? 0);
        $actualCount   = count($swdata['questions'] ?? []);

        if ($expectedCount !== $actualCount) {
            error_log(
                "Quiz integrity mismatch — event {$eventId}, session_window {$swid}: " .
                "browser reported {$expectedCount} question block(s) at submit time, " .
                "server received {$actualCount} distinct question(s) in \$_POST. " .
                "This usually means two question blocks shared an array index client-side."
            );
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

                $options[] = [
                    'text'    => $optText,
                    'correct' => ((string) $idx === $correctIdx),
                ];
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

/** Normalizes a PDO boolean value. */
function isTruthyDbBool($value): bool
{
    return $value === true || $value === 't' || $value === 1 || $value === '1';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $data = [
        'title'                     => trim($_POST['title']             ?? ''),
        'event_code'                => trim($_POST['event_code']        ?? ''),
        'event_mode_id'             => $_POST['event_mode_id']  !== '' ? (int) $_POST['event_mode_id'] : null,
        'venue'                     => trim($_POST['venue']              ?? ''),
        'provider_id'               => $_POST['provider_id']    !== '' ? (int) $_POST['provider_id'] : null,
        'provider_accreditation_no' => $_POST['provider_accreditation_no'] ?? '',
        'coordinator'               => trim($_POST['coordinator']        ?? ''),
        'start_date'                => $_POST['start_date']              ?? '',
        'end_date'                  => $_POST['end_date']                ?? '',
        'geo_lat'                   => $_POST['geo_lat']    !== '' ? (float) $_POST['geo_lat']    : null,
        'geo_lng'                   => $_POST['geo_lng']    !== '' ? (float) $_POST['geo_lng']    : null,
        'geo_radius_m'              => $_POST['geo_radius_m'] !== '' ? (int) $_POST['geo_radius_m'] : null,
        'meeting_provider'          => $_POST['meeting_provider'] ?? '',
        'meeting_link'              => $_POST['meeting_link']      ?? '',
        'sessions'                  => $_POST['sessions'] ?? [],
    ];

    try {
        $updateResult = $eventModel->update((int) $event['id'], $data);
        $sessionIdMap = $updateResult['session_id_map'];
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

    $updatedMode = $eventModeRepo->findById((int) $data['event_mode_id']);
    $isOnsiteNow = ($updatedMode['item_key'] ?? '') === 'onsite';

    try {
        if ($isOnsiteNow) {
            foreach ($eventModel->getSessions((int) $event['id']) as $sw) {
                $quizModel->saveQuestions((int) $event['id'], (int) $sw['id'], []);
            }
        } else {
            foreach (parseQuizSubmission($_POST['quiz'] ?? [], (int) $event['id']) as $swid => $q) {
                $realSwid = $sessionIdMap[$swid] ?? null;
                if ($realSwid === null) {
                    continue;
                }
                $quizModel->saveQuestions((int) $event['id'], $realSwid, $q['questions']);
            }
        }

        $_SESSION['success'] = 'Event updated successfully.';
        header('Location: /events/view.php?token=' . urlencode($event['view_token']));
        exit;

    } catch (\InvalidArgumentException $e) {
        $_SESSION['error'] = 'Event details were saved, but a quiz could not be saved (' . $e->getMessage() . '). Please fix it below and save again.';
        header('Location: /events/edit.php?token=' . urlencode($event['view_token']));
        exit;

    } catch (\Throwable $e) {
        error_log('Quiz save error: ' . $e->getMessage());
        $_SESSION['error'] = 'Event details were saved, but the quiz could not be saved due to a system error. Please try again below.';
        header('Location: /events/edit.php?token=' . urlencode($event['view_token']));
        exit;
    }
}

$v = empty($formData) ? $event : array_merge($event, $formData);

if (isset($formData['sessions'])) {
    $sessionsForDisplay = [];
    foreach ($formData['sessions'] as $clientId => $s) {
        $sessionsForDisplay[] = [
            'id'         => $clientId,
            'label'      => $s['label'] ?? '',
            'start_time' => $s['start_time'] ?? '',
            'end_time'   => $s['end_time'] ?? '',
            'points'     => (int) ($s['points'] ?? 0),
            'session_date' => $s['session_date'] ?? '',
            'require_late_signin' => !empty($s['require_late_signin']),
            'signin_lead_minutes'  => $s['signin_lead_minutes'] ?? '',
            'signin_grace_minutes' => $s['signin_grace_minutes'] ?? '',
        ];
    }
} else {
    $sessionsForDisplay = array_map(function ($row) {
        return [
            'id'         => $row['id'],
            'label'      => $row['label'],
            'start_time' => substr($row['start_time'], 0, 5),
            'end_time'   => substr($row['end_time'], 0, 5),
            'points'     => (int) $row['points'],
            'session_date' => $row['session_date'] ?? '',
            'require_late_signin' => array_key_exists('require_late_signin', $row) ? (bool) $row['require_late_signin'] : true,
            'signin_lead_minutes'  => $row['signin_lead_minutes'] ?? '',
            'signin_grace_minutes' => $row['signin_grace_minutes'] ?? '',
        ];
    }, $eventModel->getSessions((int) $event['id']));
}

$providerNameDisplay = empty($formData) ? ($event['provider_name'] ?? '') : '';

$quizBySession = [];
foreach ($sessionsForDisplay as $sw) {
    $quizBySession[$sw['id']] = is_numeric($sw['id'])
        ? $quizModel->getQuestions((int) $event['id'], (int) $sw['id'], true)
        : [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Event - Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: none; margin: 0; padding-inline: clamp(1rem, 4vw, 3rem); }
body .page-body-outer { max-width: none; padding-inline: clamp(1rem, 4vw, 3rem); }
.container h1 { font-size: 1.3rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: 1rem; }
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
<body>
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="container">
    <h1>Edit Event</h1>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="/events/edit.php?token=<?= urlencode($event['view_token']) ?>" id="event-form">
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
                    <input type="text" id="provider_name_display" readonly value="<?= htmlspecialchars($providerNameDisplay, ENT_QUOTES, 'UTF-8') ?>" placeholder="Fills in automatically">
                </div>
            </div>

            <div class="form-grid" id="meeting-fields">
                <div class="form-group full">
                    <label>Virtual Meeting</label>
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
                    <label>Location <span class="required">*</span></label>
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

            <div style="margin-top:1rem;">
                <div class="form-group full">
                    <label>Sessions</label>
                </div>
                <div id="sessions-list">
                    <?php foreach ($sessionsForDisplay as $sw): $sidAttr = htmlspecialchars((string) $sw['id'], ENT_QUOTES, 'UTF-8'); ?>
                        <div class="session-row" data-sid="<?= $sidAttr ?>" style="border:1.5px solid #d8dde3;border-radius:10px;padding:.85rem;margin-bottom:.75rem;">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Label</label>
                                    <input type="text" class="session-label-input" name="sessions[<?= $sidAttr ?>][label]" value="<?= htmlspecialchars($sw['label'], ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Start Time</label>
                                    <input type="time" name="sessions[<?= $sidAttr ?>][start_time]" value="<?= htmlspecialchars($sw['start_time'], ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>End Time</label>
                                    <input type="time" name="sessions[<?= $sidAttr ?>][end_time]" value="<?= htmlspecialchars($sw['end_time'], ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Points</label>
                                    <input type="number" min="0" class="session-points-input" name="sessions[<?= $sidAttr ?>][points]" value="<?= (int) $sw['points'] ?>">
                                </div>
                                <div class="form-group">
                                    <label>Date <small style="font-weight:normal;color:var(--text-muted);">(optional)</small></label>
                                    <input type="date" name="sessions[<?= $sidAttr ?>][session_date]" value="<?= htmlspecialchars((string) ($sw['session_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="form-group">
                                    <label>Sign-in opens (min before end)</label>
                                    <input type="number" min="1" max="180" name="sessions[<?= $sidAttr ?>][signin_lead_minutes]" value="<?= htmlspecialchars((string) ($sw['signin_lead_minutes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Default">
                                </div>
                                <div class="form-group">
                                    <label>Sign-in closes (min after end)</label>
                                    <input type="number" min="0" max="180" name="sessions[<?= $sidAttr ?>][signin_grace_minutes]" value="<?= htmlspecialchars((string) ($sw['signin_grace_minutes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Default">
                                </div>
                                <div class="form-group full" style="display:flex;align-items:center;gap:.5rem;margin-top:-.5rem;">
                                    <input type="checkbox" id="late_signin_<?= $sidAttr ?>" name="sessions[<?= $sidAttr ?>][require_late_signin]" value="1" <?= (($sw['require_late_signin'] ?? true) ? 'checked' : '') ?> style="width:auto;">
                                    <label for="late_signin_<?= $sidAttr ?>" style="margin:0;font-weight:normal;">Require late sign-in</label>
                                </div>
                            </div>
                            <button type="button" class="btn btn-ghost btn-sm remove-session-btn">Remove Session</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-ghost btn-sm" id="add-session-btn">+ Add Session</button>
                <div class="form-group full" style="margin-top:.5rem;">
                    <span id="points-total" style="font-size:.85rem;color:var(--text-muted);">Total: 0 / 8</span>
                </div>
            </div>

            <div class="quiz-panel" id="quiz-panel">
                <div class="form-group full">
                    <label>Session Quiz</label>
                </div>

                <div id="quiz-sessions-list">
                <?php foreach ($sessionsForDisplay as $sw):
                    $existingQuestions = $quizBySession[$sw['id']] ?? [];
                    $swAttr = htmlspecialchars((string) $sw['id'], ENT_QUOTES, 'UTF-8');
                ?>
                    <div class="quiz-session" data-swid="<?= $swAttr ?>">
                        <input type="hidden" class="quiz-expected-count" name="quiz[<?= $swAttr ?>][expected_count]" value="<?= count($existingQuestions) ?>">
                        <div class="quiz-session-header">
                            <strong class="quiz-session-label"><?= htmlspecialchars($sw['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php if (!empty($existingQuestions)): ?>
                                <label class="quiz-clear-label">
                                    <input type="checkbox" name="quiz[<?= $swAttr ?>][clear]" value="1">
                                    Remove all quiz questions for this session
                                </label>
                            <?php endif; ?>
                        </div>

                        <div class="quiz-questions-list">
                            <?php foreach ($existingQuestions as $qIdx => $q): ?>
                                <div class="quiz-question-block" data-qidx="<?= $qIdx ?>">
                                    <div class="form-group">
                                        <label>Question</label>
                                        <input type="text"
                                               name="quiz[<?= $swAttr ?>][questions][<?= $qIdx ?>][text]"
                                               value="<?= htmlspecialchars($q['question_text'], ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <p style="font-size:.75rem;color:var(--text-muted);margin:.3rem 0;">Click the circle beside the correct answer &mdash; exactly one is required.</p>
                                    <div class="quiz-options" data-swid="<?= $swAttr ?>" data-qidx="<?= $qIdx ?>">
                                        <?php foreach ($q['options'] as $oIdx => $opt): ?>
                                            <div class="quiz-option-row">
                                                <input type="radio"
                                                       name="quiz[<?= $swAttr ?>][questions][<?= $qIdx ?>][correct]"
                                                       value="<?= $oIdx ?>" required
                                                       <?= isTruthyDbBool($opt['is_correct']) ? 'checked' : '' ?>>
                                                <input type="text"
                                                       name="quiz[<?= $swAttr ?>][questions][<?= $qIdx ?>][options][<?= $oIdx ?>]"
                                                       value="<?= htmlspecialchars($opt['option_text'], ENT_QUOTES, 'UTF-8') ?>">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="quiz-question-actions">
                                        <button type="button" class="btn btn-ghost btn-sm add-option-btn">+ Add Option</button>
                                        <button type="button" class="btn btn-ghost btn-sm quiz-remove-question remove-question-btn">Remove Question</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="button" class="btn btn-ghost btn-sm add-question-btn">+ Add Question</button>
                    </div>
                <?php endforeach; ?>
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
<script src="/assets/js/event-geo-form.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/event-geo-form.js') ?>"></script>
<script src="/assets/js/session-builder.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/session-builder.js') ?>"></script>
<script src="/assets/js/quiz-builder.js"></script>
<script>
// Quiz and meeting fields apply to virtual and hybrid events only.
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
