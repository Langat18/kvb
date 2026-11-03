(function () {
    'use strict';

    function buildSessionRowHTML(sid, label, startTime, endTime, points, sessionDate, requireLateSignin) {
        var lateChecked = requireLateSignin === false ? '' : 'checked';
        return '' +
            '<div class="session-row" data-sid="' + sid + '" style="border:1.5px solid #d8dde3;border-radius:10px;padding:.85rem;margin-bottom:.75rem;">' +
            '<div class="form-grid">' +
            '<div class="form-group">' +
            '<label>Label</label>' +
            '<input type="text" class="session-label-input" name="sessions[' + sid + '][label]" value="' + (label || '') + '" required>' +
            '</div>' +
            '<div class="form-group">' +
            '<label>Start Time</label>' +
            '<input type="time" name="sessions[' + sid + '][start_time]" value="' + (startTime || '') + '" required>' +
            '</div>' +
            '<div class="form-group">' +
            '<label>End Time</label>' +
            '<input type="time" name="sessions[' + sid + '][end_time]" value="' + (endTime || '') + '" required>' +
            '</div>' +
            '<div class="form-group">' +
            '<label>Points</label>' +
            '<input type="number" min="0" class="session-points-input" name="sessions[' + sid + '][points]" value="' + (points != null ? points : 0) + '">' +
            '</div>' +
            '<div class="form-group">' +
            '<label>Date <small style="font-weight:normal;color:var(--text-muted);">(optional)</small></label>' +
            '<input type="date" name="sessions[' + sid + '][session_date]" value="' + (sessionDate || '') + '">' +
            '</div>' +
            '<div class="form-group">' +
            '<label>Sign-in opens (min before end)</label>' +
            '<input type="number" min="1" max="180" name="sessions[' + sid + '][signin_lead_minutes]" placeholder="Default">' +
            '</div>' +
            '<div class="form-group">' +
            '<label>Sign-in closes (min after end)</label>' +
            '<input type="number" min="0" max="180" name="sessions[' + sid + '][signin_grace_minutes]" placeholder="Default">' +
            '</div>' +
            '<div class="form-group full" style="display:flex;align-items:center;gap:.5rem;margin-top:-.5rem;">' +
            '<input type="checkbox" id="late_signin_' + sid + '" name="sessions[' + sid + '][require_late_signin]" value="1" ' + lateChecked + ' style="width:auto;">' +
            '<label for="late_signin_' + sid + '" style="margin:0;font-weight:normal;">Require late sign-in</label>' +
            '</div>' +
            '</div>' +
            buildPresentationsHTML(sid) +
            '<button type="button" class="btn btn-ghost btn-sm remove-session-btn">Remove Session</button>' +
            '</div>';
    }

    function buildQuizSessionHTML(sid, label) {
        return '' +
            '<div class="quiz-session" data-swid="' + sid + '">' +
            '<input type="hidden" class="quiz-expected-count" name="quiz[' + sid + '][expected_count]" value="0">' +
            '<div class="quiz-session-header"><strong class="quiz-session-label">' + (label || '') + '</strong></div>' +
            '<div class="quiz-questions-list"></div>' +
            '<button type="button" class="btn btn-ghost btn-sm add-question-btn">+ Add Question</button>' +
            '</div>';
    }

    function nextNewSessionId() {
        var rows = document.querySelectorAll('.session-row[data-sid^="new_"]');
        var max = 0;
        rows.forEach(function (r) {
            var n = parseInt(r.dataset.sid.replace('new_', ''), 10);
            if (!isNaN(n) && n > max) max = n;
        });
        return 'new_' + (max + 1);
    }

    function updatePointsTotal() {
        var inputs = document.querySelectorAll('.session-points-input');
        var recurring = 0, byDate = {}, total = 0;
        inputs.forEach(function (i) {
            var pts = parseInt(i.value, 10) || 0;
            var row = i.closest('.session-row');
            var dEl = row ? row.querySelector('input[type="date"]') : null;
            var d = dEl ? dEl.value : '';
            if (d) { byDate[d] = (byDate[d] || 0) + pts; } else { recurring += pts; }
        });
        var maxDay = 0;
        Object.keys(byDate).forEach(function (k) { if (byDate[k] > maxDay) maxDay = byDate[k]; });
        total = recurring + maxDay;
        var submit = document.getElementById('submit-btn');
        if (submit) {
            submit.disabled = total > 8;
        }
        var span = document.getElementById('points-total');
        if (span) {
            span.textContent = 'Highest daily total: ' + total + ' / 8';
            span.style.color = total > 8 ? 'var(--kvb-red-dark)' : 'var(--text-muted)';
        }
    }

    function todayLocal() {
        var d = new Date();
        var m = d.getMonth() + 1;
        var day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (day < 10 ? '0' + day : day);
    }

    function defaultSessionDate() {
        var start = document.getElementById('start_date');
        var end = document.getElementById('end_date');
        var today = todayLocal();

        if (!start || !end || !start.value || !end.value) {
            return '';
        }

        return (today >= start.value && today <= end.value) ? today : '';
    }

    function addNewSession() {
        addSession(nextNewSessionId(), '', '', '', 0, defaultSessionDate());

        var rows = document.querySelectorAll('#sessions-list .session-row');
        var last = rows.length ? rows[rows.length - 1] : null;
        var dateEl = last ? last.querySelector('input[type="date"]') : null;

        if (dateEl) {
            dateEl.setAttribute('data-auto', '1');
        }
    }

    function addSession(sid, label, startTime, endTime, points, sessionDate, requireLateSignin) {
        var list = document.getElementById('sessions-list');
        if (!list) return;

        var wrapper = document.createElement('div');
        wrapper.innerHTML = buildSessionRowHTML(sid, label, startTime, endTime, points, sessionDate, requireLateSignin);
        list.appendChild(wrapper.firstElementChild);

        var quizList = document.getElementById('quiz-sessions-list');
        if (quizList) {
            var qWrapper = document.createElement('div');
            qWrapper.innerHTML = buildQuizSessionHTML(sid, label);
            quizList.appendChild(qWrapper.firstElementChild);
        }

        updatePointsTotal();
    }

    document.addEventListener('click', function (e) {
        var addBtn = e.target.closest('#add-session-btn');
        if (addBtn) {
            addNewSession();
            return;
        }

        var removeBtn = e.target.closest('.remove-session-btn');
        if (removeBtn) {
            var row = removeBtn.closest('.session-row');
            var sid = row.dataset.sid;
            row.parentNode.removeChild(row);

            var quizSession = document.querySelector('.quiz-session[data-swid="' + sid + '"]');
            if (quizSession) {
                quizSession.parentNode.removeChild(quizSession);
            }

            updatePointsTotal();
        }
    });

    document.addEventListener('input', function (e) {
        if (e.target.type === 'date' && e.target.hasAttribute('data-auto')) {
            e.target.removeAttribute('data-auto');
        }

        if (e.target.classList.contains('session-points-input') || e.target.type === 'date') {
            updatePointsTotal();
        }

        if (e.target.classList.contains('session-label-input')) {
            var row = e.target.closest('.session-row');
            var sid = row.dataset.sid;
            var quizSession = document.querySelector('.quiz-session[data-swid="' + sid + '"]');
            if (quizSession) {
                var labelEl = quizSession.querySelector('.quiz-session-label');
                if (labelEl) labelEl.textContent = e.target.value;
            }
        }
    });

    updatePointsTotal();
    document.addEventListener('change', function (e) {
        if (e.target.id !== 'start_date' && e.target.id !== 'end_date') {
            return;
        }

        var d = defaultSessionDate();

        document.querySelectorAll('.session-row input[type="date"][data-auto]').forEach(function (el) {
            el.value = d;
        });

        updatePointsTotal();
    });

    function buildPresentationRowHTML(sid, pidx) {
        var base = 'presentations[' + sid + '][' + pidx + ']';
        return '' +
            '<div class="presentation-row" data-pidx="' + pidx + '">' +
            '<input type="text" name="' + base + '[presenter]" placeholder="Presenter" maxlength="150" aria-label="Presenter">' +
            '<input type="text" name="' + base + '[reg_no]" placeholder="Reg No (optional)" maxlength="10" aria-label="Presenter registration number">' +
            '<input type="text" name="' + base + '[topic]" placeholder="Topic" maxlength="255" aria-label="Topic">' +
            '<input type="time" name="' + base + '[start_time]" aria-label="Start time">' +
            '<input type="time" name="' + base + '[end_time]" aria-label="End time">' +
            '<button type="button" class="btn btn-ghost btn-sm remove-presentation-btn">Remove</button>' +
            '</div>';
    }

    function buildPresentationsHTML(sid) {
        return '' +
            '<div class="presentations" data-sid="' + sid + '">' +
            '<div class="presentations-title">Presentations</div>' +
            '<input type="hidden" name="presentations[' + sid + '][_marker]" value="1">' +
            '<div class="presentation-list"></div>' +
            '<button type="button" class="btn btn-ghost btn-sm add-presentation-btn">+ Add Presentation</button>' +
            '</div>';
    }

    function flagField(field, message) {
        field.setCustomValidity(message);
        field.reportValidity();
        return false;
    }

    function presentationsValid(form) {
        var sessions = form.querySelectorAll('.session-row');

        for (var i = 0; i < sessions.length; i++) {
            var session = sessions[i];
            var sStart = session.querySelector('input[type="time"][name$="[start_time]"]');
            var sEnd = session.querySelector('input[type="time"][name$="[end_time]"]');
            var rows = session.querySelectorAll('.presentation-row');
            var taken = [];

            for (var j = 0; j < rows.length; j++) {
                var f = [
                    rows[j].querySelector('[name$="[presenter]"]'),
                    rows[j].querySelector('[name$="[topic]"]'),
                    rows[j].querySelector('[name$="[start_time]"]'),
                    rows[j].querySelector('[name$="[end_time]"]')
                ];
                var empty = f.filter(function (x) { return x.value.trim() === ''; });

                if (empty.length === 4) { continue; }
                if (empty.length > 0) { return flagField(empty[0], 'Complete this field or remove the row.'); }

                var start = f[2].value;
                var end = f[3].value;

                if (end <= start) { return flagField(f[3], 'End must be after start.'); }

                if (sStart && sEnd && sStart.value && sEnd.value && (start < sStart.value || end > sEnd.value)) {
                    return flagField(f[2], 'Must fall within the session time (' + sStart.value + ' to ' + sEnd.value + ').');
                }

                for (var k = 0; k < taken.length; k++) {
                    if (start < taken[k].end && taken[k].start < end) {
                        return flagField(f[2], 'Overlaps another presentation in this session.');
                    }
                }

                taken.push({ start: start, end: end });
            }
        }

        return true;
    }

    document.addEventListener('click', function (e) {
        var add = e.target.closest('.add-presentation-btn');

        if (add) {
            var block = add.closest('.presentations');
            var list = block.querySelector('.presentation-list');
            var max = -1;

            list.querySelectorAll('.presentation-row').forEach(function (r) {
                var n = parseInt(r.dataset.pidx, 10);
                if (!isNaN(n) && n > max) { max = n; }
            });

            var wrapper = document.createElement('div');
            wrapper.innerHTML = buildPresentationRowHTML(block.dataset.sid, max + 1);
            list.appendChild(wrapper.firstElementChild);
            return;
        }

        var remove = e.target.closest('.remove-presentation-btn');

        if (remove) {
            var row = remove.closest('.presentation-row');
            row.parentNode.removeChild(row);
        }
    });

    document.addEventListener('input', function (e) {
        if (e.target.setCustomValidity) { e.target.setCustomValidity(''); }
    });

    var eventForm = document.getElementById('event-form');

    if (eventForm) {
        eventForm.addEventListener('submit', function (e) {
            if (!presentationsValid(eventForm)) { e.preventDefault(); }
        });
    }
    var initialList = document.getElementById('sessions-list');
    if (initialList && initialList.children.length === 0) {
        addNewSession();
    }
})();
