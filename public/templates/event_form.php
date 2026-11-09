<?php
/**
 * Shared event form (create + edit).
 *
 * Expects: $formAction, $submitLabel, $cancelUrl (string|null), $eventModes, $v (array),
 *          $providerNameDisplay (string), $sessionsForDisplay (array), $presentationsBySession (array),
 *          $quizBySession (array), $sessionHelpers (bool, optional)
 */

declare(strict_types=1);

use VetBoard\Auth;
use VetBoard\QuizAttempt;

if (!function_exists('ef_h')) {
    function ef_h($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ef_truthy')) {
    function ef_truthy($value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1';
    }
}

$sessionsForDisplay     = $sessionsForDisplay ?? [];
$presentationsBySession = $presentationsBySession ?? [];
$quizBySession          = $quizBySession ?? [];
$providerNameDisplay    = $providerNameDisplay ?? '';
$cancelUrl              = $cancelUrl ?? null;
$passPct                = (int) QuizAttempt::PASS_THRESHOLD;
$maxAttempts            = (int) QuizAttempt::MAX_ATTEMPTS;
?>
<form method="POST" action="<?= ef_h($formAction) ?>" id="event-form" class="ef">
    <input type="hidden" name="csrf_token" value="<?= ef_h(Auth::csrfToken()) ?>">
    <input type="hidden" name="provider_id" id="provider_id" value="<?= ef_h($v['provider_id'] ?? '') ?>">

    <section class="ef-card" aria-labelledby="ef-h-details">
        <header class="ef-head"><h2 id="ef-h-details">Event details</h2></header>
        <div class="form-grid">
            <div class="form-group full">
                <label for="title">Event title <span class="required">*</span></label>
                <input type="text" id="title" name="title" required value="<?= ef_h($v['title'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="event_code">Activity registration no.</label>
                <input type="text" id="event_code" name="event_code" placeholder="e.g. REG/CPD-A/0956/2026" value="<?= ef_h($v['event_code'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="event_mode_id">Mode <span class="required">*</span></label>
                <select id="event_mode_id" name="event_mode_id" required>
                    <option value="">Select mode</option>
                    <?php foreach ($eventModes as $mode): ?>
                        <option value="<?= (int) $mode['id'] ?>"
                                data-key="<?= ef_h($mode['item_key']) ?>"
                                <?= (int) ($v['event_mode_id'] ?? 0) === (int) $mode['id'] ? 'selected' : '' ?>>
                            <?= ef_h($mode['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="field-hint" id="mode-hint"></small>
            </div>

            <div class="form-group">
                <label for="start_date">Start date <span class="required">*</span></label>
                <input type="date" id="start_date" name="start_date" required value="<?= ef_h($v['start_date'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="end_date">End date <span class="required">*</span></label>
                <input type="date" id="end_date" name="end_date" required value="<?= ef_h($v['end_date'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="venue">Venue name</label>
                <input type="text" id="venue" name="venue" value="<?= ef_h($v['venue'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="coordinator">Supervisor</label>
                <input type="text" id="coordinator" name="coordinator" value="<?= ef_h($v['coordinator'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="provider_accreditation_no">Provider accreditation no.</label>
                <input type="text" id="provider_accreditation_no" name="provider_accreditation_no"
                       placeholder="e.g. ACC/CPD-P/001/2013" value="<?= ef_h($v['provider_accreditation_no'] ?? '') ?>">
                <small id="provider-status" class="field-hint"></small>
            </div>

            <div class="form-group">
                <label for="provider_name_display">Provider name</label>
                <input type="text" id="provider_name_display" readonly value="<?= ef_h($providerNameDisplay) ?>" placeholder="Fills in automatically">
            </div>

            <div class="form-group full">
                <input type="hidden" name="day_confirmation" value="0">
                <label class="ef-check">
                    <input type="checkbox" name="day_confirmation" value="1" <?= ef_truthy($v['day_confirmation'] ?? false) ? 'checked' : '' ?>>
                    <span><strong>Daily confirmation</strong><small>Attendees confirm presence on each event day, separate from session sign-in and points.</small></span>
                </label>
            </div>
        </div>
    </section>

    <section class="ef-card" id="meeting-fields" aria-labelledby="ef-h-meeting">
        <header class="ef-head"><h2 id="ef-h-meeting">Online meeting</h2><p>Shown to attendees who join online.</p></header>
        <div class="form-grid">
            <div class="form-group">
                <label for="meeting_provider">Platform</label>
                <select id="meeting_provider" name="meeting_provider">
                    <?php foreach (MEETING_PROVIDERS as $key => $label): ?>
                        <option value="<?= ef_h($key) ?>" <?= ($v['meeting_provider'] ?? '') === $key ? 'selected' : '' ?>><?= ef_h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="meeting_link">Meeting link</label>
                <input type="url" id="meeting_link" name="meeting_link" placeholder="https://meet.google.com/xxx-xxxx-xxx" value="<?= ef_h($v['meeting_link'] ?? '') ?>">
            </div>
        </div>
    </section>

    <section class="ef-card geo-fields" id="geo-fields" aria-labelledby="ef-h-geo">
        <header class="ef-head">
            <h2 id="ef-h-geo">Venue location <span class="required">*</span></h2>
            <p>Place the pin on the building. Attendees must be inside the circle to sign in.</p>
        </header>

        <div class="geo-tools">
            <div class="geo-search">
                <input type="search" id="geo-search" placeholder="Search a place or address" aria-label="Search for a place" autocomplete="off">
                <button type="button" class="btn btn-ghost btn-sm" id="geo-search-btn">Search</button>
                <button type="button" class="btn btn-ghost btn-sm" id="geo-locate-btn"><?= ui_icon('pin', 16) ?> Use my location</button>
            </div>
            <ul class="geo-results" id="geo-results" role="listbox" aria-label="Search results" hidden></ul>
        </div>

        <div class="geo-map" id="geo-map" aria-label="Map for placing the venue pin"></div>
        <p class="geo-status" id="geo-status" role="status" aria-live="polite">Search for the venue, or click the map to place the pin. Switch to Satellite to see the building.</p>

        <div class="ef-grid3">
            <div class="form-group">
                <label for="geo_radius_m">Radius (metres)</label>
                <input type="number" id="geo_radius_m" name="geo_radius_m" min="20" max="5000" value="<?= ef_h($v['geo_radius_m'] ?? DEFAULT_GEOFENCE_RADIUS_M) ?>">
                <div class="geo-presets" role="group" aria-label="Radius presets">
                    <?php foreach ([50, 100, 200, 500] as $r): ?><button type="button" class="geo-chip" data-radius="<?= $r ?>"><?= $r ?> m</button><?php endforeach; ?>
                </div>
                <small class="field-hint">100 to 200 m suits most venues. Phone GPS can be 20 to 50 m off, more indoors.</small>
            </div>
            <div class="form-group">
                <label for="geo_lat">Latitude</label>
                <input type="number" step="any" id="geo_lat" name="geo_lat" value="<?= ef_h($v['geo_lat'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="geo_lng">Longitude</label>
                <input type="number" step="any" id="geo_lng" name="geo_lng" value="<?= ef_h($v['geo_lng'] ?? '') ?>">
            </div>
        </div>
    </section>

    <section class="ef-card" id="sessions-section" aria-labelledby="ef-h-sessions">
        <header class="ef-head">
            <h2 id="ef-h-sessions">Sessions</h2>
            <p>Each session has its own sign-in and points. Add presenters under the session they speak in.</p>
        </header>

        <div id="sessions-list">
            <?php foreach ($sessionsForDisplay as $sw):
                $sid = ef_h($sw['id']);
                $presRows = array_values(array_filter((array) ($presentationsBySession[$sw['id']] ?? []), 'is_array'));
            ?>
            <div class="session-row ef-session" data-sid="<?= $sid ?>">
                <div class="ef-session-head">
                    <span class="ef-session-no" aria-hidden="true"></span>
                    <input type="text" class="session-label-input" name="sessions[<?= $sid ?>][label]" value="<?= ef_h($sw['label']) ?>" placeholder="Session name, e.g. Morning" aria-label="Session name" required>
                    <button type="button" class="ef-link-btn danger remove-session-btn">Remove session</button>
                </div>
                <div class="ef-session-body">
                    <div class="ef-grid4">
                        <div class="form-group"><label>Starts</label><input type="time" name="sessions[<?= $sid ?>][start_time]" value="<?= ef_h($sw['start_time']) ?>" required></div>
                        <div class="form-group"><label>Ends</label><input type="time" name="sessions[<?= $sid ?>][end_time]" value="<?= ef_h($sw['end_time']) ?>" required></div>
                        <div class="form-group"><label>Points</label><input type="number" min="0" step="0.1" class="session-points-input" name="sessions[<?= $sid ?>][points]" value="<?= ef_h($sw['points']) ?>"></div>
                        <div class="form-group"><label>Day <small>optional</small></label><input type="date" name="sessions[<?= $sid ?>][session_date]" value="<?= ef_h($sw['session_date'] ?? '') ?>"></div>
                    </div>

                    <details class="ef-adv">
                        <summary>Sign-in window</summary>
                        <div class="ef-grid3">
                            <div class="form-group"><label>Opens before end (min)</label><input type="number" min="1" max="180" name="sessions[<?= $sid ?>][signin_lead_minutes]" value="<?= ef_h($sw['signin_lead_minutes'] ?? '') ?>" placeholder="Default"></div>
                            <div class="form-group"><label>Closes after end (min)</label><input type="number" min="0" max="180" name="sessions[<?= $sid ?>][signin_grace_minutes]" value="<?= ef_h($sw['signin_grace_minutes'] ?? '') ?>" placeholder="Default"></div>
                            <div class="form-group ef-check-cell">
                                <label class="ef-check"><input type="checkbox" id="late_signin_<?= $sid ?>" name="sessions[<?= $sid ?>][require_late_signin]" value="1" <?= (($sw['require_late_signin'] ?? true) ? 'checked' : '') ?>><span>Require late sign-in</span></label>
                            </div>
                        </div>
                        <small class="field-hint">Leave blank to use the defaults from Settings.</small>
                    </details>

                    <div class="presentations" data-sid="<?= $sid ?>">
                        <div class="presentations-title">Presenters <small>optional</small></div>
                        <input type="hidden" name="presentations[<?= $sid ?>][_marker]" value="1">
                        <div class="presentation-list">
                            <?php foreach ($presRows as $pi => $p): $pb = 'presentations[' . $sid . '][' . $pi . ']'; ?>
                            <div class="presentation-row" data-pidx="<?= (int) $pi ?>">
                                <div class="pr-f pr-presenter"><label>Presenter</label><input type="text" name="<?= $pb ?>[presenter]" value="<?= ef_h($p['presenter'] ?? '') ?>" maxlength="150"></div>
                                <div class="pr-f pr-reg"><label>Reg no. <small>optional</small></label><input type="text" name="<?= $pb ?>[reg_no]" value="<?= ef_h($p['reg_no'] ?? '') ?>" maxlength="10"></div>
                                <div class="pr-f pr-topic"><label>Topic</label><input type="text" name="<?= $pb ?>[topic]" value="<?= ef_h($p['topic'] ?? '') ?>" maxlength="255"></div>
                                <div class="pr-f pr-room"><label>Room <small>optional</small></label><input type="text" name="<?= $pb ?>[room]" value="<?= ef_h($p['room'] ?? '') ?>" maxlength="60"></div>
                                <div class="pr-f pr-start"><label>Starts</label><input type="time" name="<?= $pb ?>[start_time]" value="<?= ef_h(substr((string) ($p['start_time'] ?? ''), 0, 5)) ?>"></div>
                                <div class="pr-f pr-end"><label>Ends</label><input type="time" name="<?= $pb ?>[end_time]" value="<?= ef_h(substr((string) ($p['end_time'] ?? ''), 0, 5)) ?>"></div>
                                <button type="button" class="ef-link-btn danger pr-remove remove-presentation-btn">Remove</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm add-presentation-btn">Add presenter</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="ef-session-foot">
            <button type="button" class="btn btn-ghost btn-sm" id="add-session-btn">Add session</button>
            <div class="ef-meter-wrap">
                <span id="points-total">Highest daily total: 0 / 8</span>
                <div class="ef-meter" aria-hidden="true"><span id="points-bar"></span></div>
            </div>
        </div>
    </section>

    <section class="ef-card quiz-panel" id="quiz-panel" data-pass="<?= $passPct ?>" data-attempts="<?= $maxAttempts ?>" aria-labelledby="ef-h-quiz">
        <header class="ef-head">
            <h2 id="ef-h-quiz">Session quiz</h2>
            <p>Confirms online attendees were present. Questions are optional for each session.</p>
        </header>
        <ul class="ef-rules">
            <li>Attendees need <strong><?= $passPct ?>%</strong> to earn the session points, with <strong><?= $maxAttempts ?> attempts</strong>.</li>
            <li>Questions and answers are shuffled for every attempt.</li>
            <li>A session with no questions awards its points on sign-in.</li>
        </ul>

        <div id="quiz-sessions-list">
            <?php foreach ($sessionsForDisplay as $sw):
                $existingQuestions = $quizBySession[$sw['id']] ?? [];
                $sw_id = ef_h($sw['id']);
            ?>
            <div class="quiz-session" data-swid="<?= $sw_id ?>">
                <input type="hidden" class="quiz-expected-count" name="quiz[<?= $sw_id ?>][expected_count]" value="<?= count($existingQuestions) ?>">
                <div class="quiz-session-header">
                    <strong class="quiz-session-label"><?= ef_h($sw['label']) ?></strong>
                    <span class="quiz-rule" aria-live="polite"></span>
                    <?php if (!empty($existingQuestions)): ?>
                        <label class="quiz-clear-label"><input type="checkbox" name="quiz[<?= $sw_id ?>][clear]" value="1"> Clear all questions</label>
                    <?php endif; ?>
                </div>
                <div class="quiz-questions-list">
                    <?php foreach ($existingQuestions as $qIdx => $q): ?>
                    <div class="quiz-question-block" data-qidx="<?= (int) $qIdx ?>">
                        <div class="qb-top">
                            <span class="qb-num" aria-hidden="true"></span>
                            <input type="text" class="qb-text" name="quiz[<?= $sw_id ?>][questions][<?= (int) $qIdx ?>][text]" value="<?= ef_h($q['question_text']) ?>" placeholder="Write the question" aria-label="Question">
                            <button type="button" class="ef-link-btn danger remove-question-btn">Remove</button>
                        </div>
                        <p class="qb-hint">Tick the one correct answer.</p>
                        <div class="quiz-options" data-swid="<?= $sw_id ?>" data-qidx="<?= (int) $qIdx ?>">
                            <?php foreach ($q['options'] as $oIdx => $opt): ?>
                            <div class="quiz-option-row">
                                <input type="radio" name="quiz[<?= $sw_id ?>][questions][<?= (int) $qIdx ?>][correct]" value="<?= (int) $oIdx ?>" <?= ef_truthy($opt['is_correct']) ? 'checked' : '' ?> aria-label="Correct answer">
                                <input type="text" name="quiz[<?= $sw_id ?>][questions][<?= (int) $qIdx ?>][options][<?= (int) $oIdx ?>]" value="<?= ef_h($opt['option_text']) ?>" placeholder="Answer option" aria-label="Answer option">
                                <button type="button" class="qb-x remove-option-btn" aria-label="Remove option"></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="quiz-question-actions"><button type="button" class="btn btn-ghost btn-sm add-option-btn">Add option</button></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-ghost btn-sm add-question-btn">Add question</button>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary" id="submit-btn"><?= ef_h($submitLabel) ?></button>
        <?php if ($cancelUrl): ?><a href="<?= ef_h($cancelUrl) ?>" class="btn btn-ghost">Cancel</a><?php endif; ?>
    </div>
</form>
