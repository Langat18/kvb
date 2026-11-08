(function () {
    'use strict';

    function buildSessionRowHTML(sid, label, startTime, endTime, points, sessionDate, requireLateSignin) {
        var lateChecked = requireLateSignin === false ? '' : 'checked';
        var n = 'sessions[' + sid + ']';
        return '' +
            '<div class="session-row ef-session" data-sid="' + sid + '">' +
            '<div class="ef-session-head">' +
            '<span class="ef-session-no" aria-hidden="true"></span>' +
            '<input type="text" class="session-label-input" name="' + n + '[label]" value="' + (label || '') + '" placeholder="Session name, e.g. Morning" aria-label="Session name" required>' +
            '<button type="button" class="ef-link-btn danger remove-session-btn">Remove session</button>' +
            '</div>' +
            '<div class="ef-session-body">' +
            '<div class="ef-grid4">' +
            '<div class="form-group"><label>Starts</label><input type="time" name="' + n + '[start_time]" value="' + (startTime || '') + '" required></div>' +
            '<div class="form-group"><label>Ends</label><input type="time" name="' + n + '[end_time]" value="' + (endTime || '') + '" required></div>' +
            '<div class="form-group"><label>Points</label><input type="number" min="0" step="0.1" class="session-points-input" name="' + n + '[points]" value="' + (points != null ? points : 0) + '"></div>' +
            '<div class="form-group"><label>Day <small>optional</small></label><input type="date" name="' + n + '[session_date]" value="' + (sessionDate || '') + '"></div>' +
            '</div>' +
            '<details class="ef-adv"><summary>Sign-in window</summary>' +
            '<div class="ef-grid3">' +
            '<div class="form-group"><label>Opens before end (min)</label><input type="number" min="1" max="180" name="' + n + '[signin_lead_minutes]" placeholder="Default"></div>' +
            '<div class="form-group"><label>Closes after end (min)</label><input type="number" min="0" max="180" name="' + n + '[signin_grace_minutes]" placeholder="Default"></div>' +
            '<div class="form-group ef-check-cell"><label class="ef-check"><input type="checkbox" id="late_signin_' + sid + '" name="' + n + '[require_late_signin]" value="1" ' + lateChecked + '><span>Require late sign-in</span></label></div>' +
            '</div>' +
            '<small class="field-hint">Leave blank to use the defaults from Settings.</small>' +
            '</details>' +
            buildPresentationsHTML(sid) +
            '</div>' +
            '</div>';
    }

    function buildQuizSessionHTML(sid, label) {
        return '' +
            '<div class="quiz-session" data-swid="' + sid + '">' +
            '<input type="hidden" class="quiz-expected-count" name="quiz[' + sid + '][expected_count]" value="0">' +
            '<div class="quiz-session-header"><strong class="quiz-session-label">' + (label || '') + '</strong><span class="quiz-rule" aria-live="polite"></span></div>' +
            '<div class="quiz-questions-list"></div>' +
            '<button type="button" class="btn btn-ghost btn-sm add-question-btn">Add question</button>' +
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
            var pts = Math.round((parseFloat(i.value) || 0) * 10);
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
            submit.disabled = total > 80;
        }
        var span = document.getElementById('points-total');
        if (span) {
            span.textContent = 'Highest daily total: ' + (total / 10) + ' / 8';
            span.style.color = total > 80 ? 'var(--kvb-red-dark)' : '';
        }
        var bar = document.getElementById('points-bar');
        if (bar) {
            bar.style.width = Math.min(100, total / 80 * 100) + '%';
            bar.className = total > 80 ? 'over' : '';
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
            '<div class="pr-f pr-presenter"><label>Presenter</label><input type="text" name="' + base + '[presenter]" maxlength="150"></div>' +
            '<div class="pr-f pr-reg"><label>Reg no. <small>optional</small></label><input type="text" name="' + base + '[reg_no]" maxlength="10"></div>' +
            '<div class="pr-f pr-topic"><label>Topic</label><input type="text" name="' + base + '[topic]" maxlength="255"></div>' +
            '<div class="pr-f pr-room"><label>Room <small>optional</small></label><input type="text" name="' + base + '[room]" maxlength="60"></div>' +
            '<div class="pr-f pr-start"><label>Starts</label><input type="time" name="' + base + '[start_time]"></div>' +
            '<div class="pr-f pr-end"><label>Ends</label><input type="time" name="' + base + '[end_time]"></div>' +
            '<button type="button" class="ef-link-btn danger pr-remove remove-presentation-btn">Remove</button>' +
            '</div>';
    }

    function buildPresentationsHTML(sid) {
        return '' +
            '<div class="presentations" data-sid="' + sid + '">' +
            '<div class="presentations-title">Presenters <small>optional</small></div>' +
            '<input type="hidden" name="presentations[' + sid + '][_marker]" value="1">' +
            '<div class="presentation-list"></div>' +
            '<button type="button" class="btn btn-ghost btn-sm add-presentation-btn">Add presenter</button>' +
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

                var roomInput = rows[j].querySelector('[name$="[room]"]');
                var regInput = rows[j].querySelector('[name$="[reg_no]"]');
                var room = (roomInput ? roomInput.value : '').trim().replace(/\s+/g, ' ').toLowerCase();
                var reg = (regInput ? regInput.value : '').trim().toUpperCase();
                var who = reg !== '' ? 'r:' + reg : 'n:' + f[0].value.trim().replace(/\s+/g, ' ').toLowerCase();

                for (var k = 0; k < taken.length; k++) {
                    if (start >= taken[k].end || taken[k].start >= end) { continue; }

                    if (taken[k].room === room) {
                        return flagField(f[2], 'Overlaps another presentation in the same room. Use a different room to run them at the same time.');
                    }

                    if (taken[k].who === who) {
                        return flagField(f[2], 'The same presenter cannot be in two rooms at the same time.');
                    }
                }

                taken.push({ start: start, end: end, room: room, who: who });
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
