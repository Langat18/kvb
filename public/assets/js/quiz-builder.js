(function () {
    'use strict';

    function buildOptionRowHTML(swid, qidx, oidx, checked, text) {
        return '' +
            '<div class="quiz-option-row">' +
            '<input type="radio" name="quiz[' + swid + '][questions][' + qidx + '][correct]" value="' + oidx + '" required' + (checked ? ' checked' : '') + '>' +
            '<input type="text" name="quiz[' + swid + '][questions][' + qidx + '][options][' + oidx + ']" value="' + (text || '') + '" placeholder="Option text">' +
            '</div>';
    }

    function buildQuestionBlockHTML(swid, qidx) {
        var optionsHtml = '';
        for (var o = 0; o < 4; o++) {
            optionsHtml += buildOptionRowHTML(swid, qidx, o, o === 0, '');
        }

        return '' +
            '<div class="quiz-question-block" data-qidx="' + qidx + '">' +
            '<div class="form-group">' +
            '<label>Question</label>' +
            '<input type="text" name="quiz[' + swid + '][questions][' + qidx + '][text]" value="">' +
            '</div>' +
            '<p style="font-size:.75rem;color:var(--text-muted);margin:.3rem 0;">Click the circle beside the correct answer &mdash; exactly one is required.</p>' +
            '<div class="quiz-options" data-swid="' + swid + '" data-qidx="' + qidx + '">' + optionsHtml + '</div>' +
            '<div class="quiz-question-actions">' +
            '<button type="button" class="btn btn-ghost btn-sm add-option-btn">+ Add Option</button>' +
            '<button type="button" class="btn btn-ghost btn-sm quiz-remove-question remove-question-btn">Remove Question</button>' +
            '</div>' +
            '</div>';
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
            return;
        }

        var addOptionBtn = e.target.closest('.add-option-btn');
        if (addOptionBtn) {
            var block   = addOptionBtn.closest('.quiz-question-block');
            var optWrap = block.querySelector('.quiz-options');
            var oswid   = optWrap.dataset.swid;
            var oqidx   = optWrap.dataset.qidx;
            var count   = optWrap.querySelectorAll('.quiz-option-row').length;

            if (count >= 6) {
                return;
            }

            var wrapper = document.createElement('div');
            wrapper.innerHTML = buildOptionRowHTML(oswid, oqidx, count, false, '');
            optWrap.appendChild(wrapper.firstElementChild);
            return;
        }

        var removeQuestionBtn = e.target.closest('.remove-question-btn');
        if (removeQuestionBtn) {
            var qblock = removeQuestionBtn.closest('.quiz-question-block');
            qblock.parentNode.removeChild(qblock);
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
    var eventForm = document.getElementById('event-form');
    if (eventForm) {
        eventForm.addEventListener('submit', function () {
            document.querySelectorAll('.quiz-session').forEach(function (session) {
                var counter = session.querySelector('.quiz-expected-count');
                if (counter) {
                    counter.value = session.querySelectorAll('.quiz-question-block').length;
                }
            });
        });
    }
})();
