(function () {
    'use strict';

    function buildOptionRowHTML(swid, qidx, oidx, checked, text) {
        return '' +
            '<div class="quiz-option-row">' +
            '<input type="radio" name="quiz[' + swid + '][questions][' + qidx + '][correct]" value="' + oidx + '"' + (checked ? ' checked' : '') + ' aria-label="Correct answer">' +
            '<input type="text" name="quiz[' + swid + '][questions][' + qidx + '][options][' + oidx + ']" value="' + (text || '') + '" placeholder="Answer option" aria-label="Answer option">' +
            '<button type="button" class="qb-x remove-option-btn" aria-label="Remove option"></button>' +
            '</div>';
    }

    function buildQuestionBlockHTML(swid, qidx) {
        var optionsHtml = '';
        for (var o = 0; o < 4; o++) {
            optionsHtml += buildOptionRowHTML(swid, qidx, o, false, '');
        }

        return '' +
            '<div class="quiz-question-block" data-qidx="' + qidx + '">' +
            '<div class="qb-top">' +
            '<span class="qb-num" aria-hidden="true"></span>' +
            '<input type="text" class="qb-text" name="quiz[' + swid + '][questions][' + qidx + '][text]" value="" placeholder="Write the question" aria-label="Question">' +
            '<button type="button" class="ef-link-btn danger remove-question-btn">Remove</button>' +
            '</div>' +
            '<p class="qb-hint">Tick the one correct answer.</p>' +
            '<div class="quiz-options" data-swid="' + swid + '" data-qidx="' + qidx + '">' + optionsHtml + '</div>' +
            '<div class="quiz-question-actions"><button type="button" class="btn btn-ghost btn-sm add-option-btn">Add option</button></div>' +
            '</div>';
    }

    function nextOptionIndex(optWrap) {
        var max = -1;
        optWrap.querySelectorAll('input[type="radio"]').forEach(function (r) {
            var n = parseInt(r.value, 10);
            if (!isNaN(n) && n > max) { max = n; }
        });
        return max + 1;
    }

    /** Live rule line under each session: how many answers an attendee needs. */
    function updateRules() {
        var panel = document.getElementById('quiz-panel');
        var pass = panel ? parseFloat(panel.dataset.pass || '90') : 90;
        document.querySelectorAll('.quiz-session').forEach(function (session) {
            var n = 0;
            session.querySelectorAll('.qb-text').forEach(function (t) { if (t.value.trim() !== '') { n++; } });
            var rule = session.querySelector('.quiz-rule');
            if (!rule) { return; }
            if (n === 0) {
                rule.textContent = 'No questions: points are awarded on sign-in.';
            } else {
                var need = Math.ceil(n * pass / 100 - 1e-9);
                rule.textContent = n + (n === 1 ? ' question' : ' questions') + '. Attendees need ' + need + ' correct to pass.';
            }
        });
    }

    /**
     * The next safe index for a new question block in this session, derived
     * from the ACTUAL DOM state (max existing data-qidx + 1) rather than a
     * separately-tracked counter. A tracked counter can drift out of sync
     * with reality (e.g. after a question is removed, or across rapid
     * clicks), and when two blocks end up sharing an index, PHP's array
     * parsing silently keeps only the last one on submit — no error, no
     * warning, just quietly fewer questions than you typed. Deriving the
     * index from the DOM every time makes that entire failure mode
     * structurally impossible rather than just less likely.
     */
    function nextQuestionIndex(session) {
        var blocks = session.querySelectorAll('.quiz-question-block');
        var max = -1;

        blocks.forEach(function (b) {
            var idx = parseInt(b.dataset.qidx, 10);
            if (!isNaN(idx) && idx > max) {
                max = idx;
            }
        });

        return max + 1;
    }

    document.addEventListener('click', function (e) {
        var addQuestionBtn = e.target.closest('.add-question-btn');
        if (addQuestionBtn) {
            var session = addQuestionBtn.closest('.quiz-session');
            var swid    = session.dataset.swid;
            var qidx    = nextQuestionIndex(session);
            var list    = session.querySelector('.quiz-questions-list');

            var wrapper = document.createElement('div');
            wrapper.innerHTML = buildQuestionBlockHTML(swid, qidx);
            list.appendChild(wrapper.firstElementChild);
            updateRules();
            return;
        }

        var addOptionBtn = e.target.closest('.add-option-btn');
        if (addOptionBtn) {
            var block   = addOptionBtn.closest('.quiz-question-block');
            var optWrap = block.querySelector('.quiz-options');
            var oswid   = optWrap.dataset.swid;
            var oqidx   = optWrap.dataset.qidx;

            if (optWrap.querySelectorAll('.quiz-option-row').length >= 6) {
                return;
            }

            var owrapper = document.createElement('div');
            owrapper.innerHTML = buildOptionRowHTML(oswid, oqidx, nextOptionIndex(optWrap), false, '');
            optWrap.appendChild(owrapper.firstElementChild);
            return;
        }

        var removeOptionBtn = e.target.closest('.remove-option-btn');
        if (removeOptionBtn) {
            var row  = removeOptionBtn.closest('.quiz-option-row');
            var wrap = row.parentNode;
            if (wrap.querySelectorAll('.quiz-option-row').length <= 2) {
                return;
            }
            wrap.removeChild(row);
            return;
        }

        var removeQuestionBtn = e.target.closest('.remove-question-btn');
        if (removeQuestionBtn) {
            var qblock = removeQuestionBtn.closest('.quiz-question-block');
            qblock.parentNode.removeChild(qblock);
            updateRules();
        }
    });

    /**
     * Integrity check: right before the form serializes, stamp each
     * session's hidden "expected_count" input with how many question
     * blocks are actually in the DOM at that instant. The server compares
     * this to what it actually parsed out of $_POST and logs a mismatch
     * immediately — turning any future silent data-loss bug (index
     * collisions, browser quirks, PHP request-size limits) into a visible
     * log line the moment it happens, instead of something discovered
     * later as "why is only 1 question saved".
     */
    function flag(field, message) {
        field.setCustomValidity(message);
        field.reportValidity();
        return false;
    }

    /** Only checked when the quiz panel is visible (virtual / hybrid). */
    function quizValid() {
        var panel = document.getElementById('quiz-panel');
        if (!panel || panel.offsetParent === null) { return true; }

        var blocks = document.querySelectorAll('.quiz-question-block');
        for (var i = 0; i < blocks.length; i++) {
            var block = blocks[i];
            var text = block.querySelector('.qb-text');
            if (!text || text.value.trim() === '') { continue; }

            var rows = block.querySelectorAll('.quiz-option-row');
            var filled = 0, correctRow = null;
            rows.forEach(function (r) {
                var t = r.querySelector('input[type="text"]');
                var c = r.querySelector('input[type="radio"]');
                if (t.value.trim() !== '') { filled++; }
                if (c.checked) { correctRow = r; }
            });

            if (filled < 2) {
                return flag(rows[Math.min(1, rows.length - 1)].querySelector('input[type="text"]'), 'Add at least two answer options.');
            }
            if (!correctRow) {
                return flag(rows[0].querySelector('input[type="radio"]'), 'Tick the correct answer.');
            }
            if (correctRow.querySelector('input[type="text"]').value.trim() === '') {
                return flag(correctRow.querySelector('input[type="text"]'), 'The correct answer cannot be empty.');
            }
        }
        return true;
    }

    var eventForm = document.getElementById('event-form');
    if (eventForm) {
        eventForm.addEventListener('submit', function (e) {
            if (!quizValid()) { e.preventDefault(); return; }
            document.querySelectorAll('.quiz-session').forEach(function (session) {
                var counter = session.querySelector('.quiz-expected-count');
                if (counter) {
                    counter.value = session.querySelectorAll('.quiz-question-block').length;
                }
            });
        });
    }

    document.addEventListener('input', function (e) {
        if (e.target.setCustomValidity) { e.target.setCustomValidity(''); }
        if (e.target.classList && e.target.classList.contains('qb-text')) { updateRules(); }
    });
    document.addEventListener('change', function (e) {
        if (e.target.setCustomValidity) { e.target.setCustomValidity(''); }
    });

    updateRules();
    // Sessions are added after this script runs; keep the rule lines current.
    new MutationObserver(updateRules).observe(document.getElementById('quiz-sessions-list') || document.body, { childList: true });
})();
