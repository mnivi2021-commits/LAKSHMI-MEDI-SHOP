// Marketing CRM - small progressive enhancements (no inline scripts: CSP script-src 'self').
// Everything still works without JavaScript; these only add convenience.
(function () {
    'use strict';

    // Show / hide password fields.
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = btn.parentElement.querySelector('input');
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? 'Hide' : 'Show';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    // Age from a date of birth: <input type="date" data-age-target="id-of-output">
    document.querySelectorAll('[data-age-target]').forEach(function (input) {
        var out = document.getElementById(input.getAttribute('data-age-target'));
        if (!out) { return; }
        var show = function () {
            var d = input.value ? new Date(input.value + 'T00:00:00') : null;
            if (!d || isNaN(d.getTime())) { out.textContent = '\u2014'; return; }
            var t = new Date(), age = t.getFullYear() - d.getFullYear();
            if (t.getMonth() < d.getMonth() || (t.getMonth() === d.getMonth() && t.getDate() < d.getDate())) { age--; }
            out.textContent = age >= 0 ? age + ' years' : '\u2014';
        };
        input.addEventListener('input', show);
        input.addEventListener('change', show);
        show();
    });

    // Month start sheet: live "x% of" cells and column totals (paise kept in data-value)
    var paise = function (text) {
        var t = String(text || '').replace(/[,\s\u20b9]/g, '');
        if (!/^\d+(\.\d{1,2})?$/.test(t)) { return 0; }
        return Math.round(parseFloat(t) * 100);
    };
    var inr = function (p) { return '\u20b9' + Math.round(p / 100).toLocaleString('en-IN'); };
    var outs = document.querySelectorAll('output[data-pct-of]');
    var totals = function () {
        document.querySelectorAll('[data-total-of]').forEach(function (o) {
            var key = o.getAttribute('data-total-of'), sum = 0;
            document.querySelectorAll('[data-total~="' + key + '"]').forEach(function (el) {
                sum += el.tagName === 'INPUT' ? paise(el.value) : parseInt(el.getAttribute('data-value') || '0', 10);
            });
            o.textContent = inr(sum);
        });
    };
    outs.forEach(function (o) {
        var src = document.getElementById(o.getAttribute('data-pct-of'));
        if (!src) { return; }
        var pct = parseInt(o.getAttribute('data-pct'), 10) || 0;
        var calc = function () {
            var v = Math.round(paise(src.value) * pct / 100);
            o.setAttribute('data-value', String(v));
            o.textContent = v ? inr(v) : '\u2014';
            totals();
        };
        src.addEventListener('input', calc);
    });

    // Print buttons: <button data-print>
    document.querySelectorAll('[data-print]').forEach(function (b) {
        b.addEventListener('click', function () { window.print(); });
    });

    // Confirmation for destructive / important actions: <form data-confirm="...">
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        });
    });

    // Prevent double submission of forms.
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented || form.hasAttribute('data-quick-add')) { return; }
            var submit = form.querySelector('[type="submit"]');
            if (submit) {
                setTimeout(function () { submit.disabled = true; }, 0);
            }
        });
    });

    // Mobile sidebar toggle.
    var toggle = document.querySelector('[data-nav-toggle]');
    if (toggle) {
        toggle.addEventListener('click', function () {
            var open = document.body.classList.toggle('nav-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    // Close open row menus when clicking elsewhere.
    document.addEventListener('click', function (e) {
        document.querySelectorAll('details.row-menu[open]').forEach(function (d) {
            if (!d.contains(e.target)) { d.removeAttribute('open'); }
        });
    });

    // Permission matrix helpers.
    var matrix = document.querySelector('[data-matrix-table]');
    if (matrix) {
        var boxes = function (selector) {
            return Array.prototype.slice.call(matrix.querySelectorAll(selector)).filter(function (b) { return !b.disabled; });
        };
        document.querySelectorAll('[data-matrix]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var mode = btn.getAttribute('data-matrix');
                if (mode === 'view') {
                    boxes('input[data-action="view"]').forEach(function (b) { b.checked = true; });
                } else {
                    boxes('input[type="checkbox"]').forEach(function (b) { b.checked = false; });
                }
            });
        });
        matrix.querySelectorAll('[data-row-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row = Array.prototype.slice.call(btn.closest('tr').querySelectorAll('input[type="checkbox"]'))
                    .filter(function (b) { return !b.disabled; });
                var allOn = row.every(function (b) { return b.checked; });
                row.forEach(function (b) { b.checked = !allOn; });
            });
        });
    }

    // Progress meters: width from data attribute (inline style attributes are blocked by CSP).
    document.querySelectorAll('[data-width]').forEach(function (el) {
        el.style.width = Math.max(0, Math.min(100, parseFloat(el.getAttribute('data-width')) || 0)) + '%';
    });
})();
