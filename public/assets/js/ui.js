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

/* Row action menus: a fixed-position list so table scrolling never clips it. */
(function () {
    var open = null;
    function close(focusBtn) {
        if (!open) { return; }
        open.btn.setAttribute('aria-expanded', 'false');
        open.list.hidden = true;
        if (open.home && open.list.parentNode === document.body) { open.home.appendChild(open.list); }
        if (focusBtn) { open.btn.focus(); }
        open = null;
    }
    function show(btn, byKey) {
        var list = btn._list || btn.nextElementSibling;
        btn._list = list;
        close(false);
        var home = list.parentNode;
        document.body.appendChild(list);
        list.hidden = false;
        list.style.visibility = 'hidden';
        var r = btn.getBoundingClientRect(), w = list.offsetWidth, h = list.offsetHeight;
        var left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8));
        var top = r.bottom + 4;
        if (top + h > window.innerHeight - 8 && r.top - h - 4 > 8) { top = r.top - h - 4; }
        list.style.left = left + 'px';
        list.style.top = top + 'px';
        list.style.visibility = '';
        btn.setAttribute('aria-expanded', 'true');
        open = { btn: btn, list: list, home: home };
        if (byKey) { var first = list.querySelector('a'); if (first) { first.focus(); } }
    }
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-menu]') : null;
        if (btn) {
            e.preventDefault();
            if (open && open.btn === btn) { close(false); } else { show(btn, e.detail === 0); }
            return;
        }
        if (open && !e.target.closest('.act-list')) { close(false); }
    });
    document.addEventListener('keydown', function (e) {
        if (!open) { return; }
        if (e.key === 'Escape') { close(true); return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var items = Array.prototype.slice.call(open.list.querySelectorAll('a'));
            var i = items.indexOf(document.activeElement);
            i = e.key === 'ArrowDown' ? (i + 1) % items.length : (i <= 0 ? items.length - 1 : i - 1);
            items[i].focus();
        }
    });
    window.addEventListener('resize', function () { close(false); });
    document.addEventListener('scroll', function () { close(false); }, true);
})();
