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
