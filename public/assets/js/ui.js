/* Shared UI helpers: scroll wrappers and phone-friendly table cards. No page logic. */
(function () {
    'use strict';
    function ready(fn) { if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }

    ready(function () {
        document.querySelectorAll('table').forEach(function (t) {
            if (t.closest('.table-scroll, .card-flush, .no-wrap')) { return; }
            var w = document.createElement('div');
            w.className = 'table-scroll';
            t.parentNode.insertBefore(w, t);
            w.appendChild(t);
        });

        document.querySelectorAll('table.data-table').forEach(function (t) {
            if (t.classList.contains('no-cards')) { return; }
            var heads = Array.prototype.map.call(t.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
            if (!heads.length || (heads.length > 5 && !t.classList.contains('roster'))) { return; }
            t.querySelectorAll('tbody tr').forEach(function (tr) {
                Array.prototype.forEach.call(tr.children, function (td, i) {
                    if (td.hasAttribute('colspan')) { return; }
                    td.setAttribute('data-label', heads[i] || '');
                });
            });
            t.classList.add('rt-cards');
        });
    });
})();
