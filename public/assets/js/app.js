// Marketing CRM - small progressive enhancements (no inline scripts: CSP script-src 'self').
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

    // Prevent double submission of forms.
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            var submit = form.querySelector('[type="submit"]');
            if (submit) {
                setTimeout(function () { submit.disabled = true; }, 0);
            }
        });
    });
})();
