(function () {
    'use strict';

    var HINTS = {
        onsite: 'Attendees sign in at the venue. Their phone location must be inside the circle you set.',
        virtual: 'Attendees join online and sign in during the session. A quiz can confirm they attended.',
        hybrid: 'Attendees may join on site or online. The venue circle and the quiz both apply.'
    };

    document.addEventListener('DOMContentLoaded', function () {
        var mode = document.getElementById('event_mode_id');
        var hint = document.getElementById('mode-hint');
        var meeting = document.getElementById('meeting-fields');
        var quiz = document.getElementById('quiz-panel');
        if (!mode) { return; }

        function apply() {
            var opt = mode.options[mode.selectedIndex];
            var key = opt ? opt.getAttribute('data-key') : '';
            var online = key !== 'onsite';
            if (hint) { hint.textContent = HINTS[key] || ''; }
            // Before a mode is chosen, show only the basics.
            if (meeting) { meeting.style.display = (key && online) ? '' : 'none'; }
            if (quiz) { quiz.style.display = (key && online) ? '' : 'none'; }
        }

        mode.addEventListener('change', apply);
        window.addEventListener('pageshow', apply);
        apply();
    });
})();
