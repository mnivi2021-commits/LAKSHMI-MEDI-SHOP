// Dashboard: filters, searchable lookups, quick-ADD drawer.
(function () {
    'use strict';

    var base = document.querySelector('meta[name="app-base"]').getAttribute('content');   // e.g. /marketing_crm/

    // ---------------------------------------------------------------------------
    // Filters: changing the financial year resets the month and reloads.
    // ---------------------------------------------------------------------------
    document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var form = sel.form;
            var month = form.querySelector('select[name="month"]');
            if (month) { month.value = ''; }
            form.submit();
        });
    });

    // ---------------------------------------------------------------------------
    // Searchable lookup: <div class="lookup" data-lookup="customers|products">
    // ---------------------------------------------------------------------------
    function initLookup(box) {
        var input = box.querySelector('.lookup-input');
        var hidden = box.querySelector('input[type="hidden"]');
        var list = box.querySelector('.lookup-list');
        var timer = null, items = [], active = -1, seq = 0;

        function close() { list.hidden = true; active = -1; }
        function choose(item) {
            hidden.value = item.id;
            input.value = item.label;
            close();
            box.dispatchEvent(new CustomEvent('lookup:chosen', { detail: item, bubbles: true }));
        }
        function render() {
            list.innerHTML = '';
            if (items.length === 0) {
                var li = document.createElement('li');
                li.className = 'lookup-empty';
                li.textContent = 'No matches';
                list.appendChild(li);
            }
            items.forEach(function (item, i) {
                var li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.className = i === active ? 'active' : '';
                var strong = document.createElement('span');
                strong.textContent = item.label;
                var meta = document.createElement('small');
                meta.textContent = item.meta || '';
                li.appendChild(strong);
                li.appendChild(meta);
                li.addEventListener('mousedown', function (e) { e.preventDefault(); choose(item); });
                list.appendChild(li);
            });
            list.hidden = false;
        }
        function search() {
            var mine = ++seq;
            fetch(base + 'dashboard/lookup/' + box.getAttribute('data-lookup') + '?q=' + encodeURIComponent(input.value.trim()), {
                headers: { 'Accept': 'application/json' }, credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (mine !== seq) { return; }          // a newer search is in flight
                items = (data && data.items) || [];
                active = -1;
                render();
            }).catch(close);
        }

        input.addEventListener('input', function () {
            hidden.value = '';                          // typed text is not a selection
            clearTimeout(timer);
            timer = setTimeout(search, 200);
        });
        input.addEventListener('focus', function () { if (!hidden.value) { search(); } });
        input.addEventListener('blur', function () {
            setTimeout(close, 120);
            if (!hidden.value) { input.value = ''; }
        });
        input.addEventListener('keydown', function (e) {
            if (list.hidden) { return; }
            if (e.key === 'ArrowDown') { active = Math.min(items.length - 1, active + 1); render(); e.preventDefault(); }
            else if (e.key === 'ArrowUp') { active = Math.max(0, active - 1); render(); e.preventDefault(); }
            else if (e.key === 'Enter' && active >= 0) { choose(items[active]); e.preventDefault(); }
            else if (e.key === 'Escape') { close(); }
        });
        box.resetLookup = function () { hidden.value = ''; input.value = ''; close(); };
    }
    document.querySelectorAll('.lookup').forEach(initLookup);

    // ---------------------------------------------------------------------------
    // Quick ADD drawer
    // ---------------------------------------------------------------------------
    var drawer = document.getElementById('quick-add');
    if (!drawer) { return; }
    var backdrop = document.querySelector('.drawer-backdrop');
    var opener = document.querySelector('[data-drawer-open="quick-add"]');
    var successBox = drawer.querySelector('[data-qa-success]');
    var savedSomething = false;

    function openDrawer() {
        drawer.hidden = false; backdrop.hidden = false;
        document.body.classList.add('drawer-open');
        var firstField = drawer.querySelector('.qa-form:not([hidden]) select, .qa-form:not([hidden]) input:not([type="hidden"])');
        if (firstField) { firstField.focus(); }
    }
    function closeDrawer() {
        drawer.hidden = true; backdrop.hidden = true;
        document.body.classList.remove('drawer-open');
        if (savedSomething) { window.location.reload(); return; }   // refresh figures with the new entries
        if (opener) { opener.focus(); }
    }
    if (opener) { opener.addEventListener('click', openDrawer); }
    document.querySelectorAll('[data-drawer-close]').forEach(function (el) { el.addEventListener('click', closeDrawer); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !drawer.hidden) { closeDrawer(); } });

    // Tabs
    drawer.querySelectorAll('[data-tab]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            drawer.querySelectorAll('[data-tab]').forEach(function (t) {
                var on = t === tab;
                t.classList.toggle('active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            drawer.querySelectorAll('.qa-form').forEach(function (f) {
                f.hidden = f.getAttribute('data-quick-add') !== tab.getAttribute('data-tab');
            });
            successBox.hidden = true;
        });
    });

    // Target: month list follows the chosen financial year.
    var fySelect = drawer.querySelector('[data-fy-select]');
    var monthSelect = drawer.querySelector('[data-month-select]');
    var monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function buildMonths() {
        if (!fySelect || !monthSelect) { return; }
        var start = fySelect.options[fySelect.selectedIndex].getAttribute('data-start').split('-');
        var y = parseInt(start[0], 10), m = parseInt(start[1], 10) - 1;
        var wanted = monthSelect.value || monthSelect.getAttribute('data-default');
        monthSelect.innerHTML = '';
        var all = document.createElement('option');
        all.value = 'all';
        all.textContent = 'All 12 months (yearly amount, split equally)';
        monthSelect.appendChild(all);
        for (var i = 0; i < 12; i++) {
            var mm = (m + i) % 12, yy = y + Math.floor((m + i) / 12);
            var opt = document.createElement('option');
            opt.value = yy + '-' + String(mm + 1).padStart(2, '0');
            opt.textContent = monthNames[mm] + ' ' + yy;
            monthSelect.appendChild(opt);
        }
        monthSelect.value = wanted;
        if (!monthSelect.value) { monthSelect.selectedIndex = 1; }
    }
    if (fySelect) { fySelect.addEventListener('change', function () { monthSelect.value = ''; buildMonths(); }); buildMonths(); }

    // Sale: pre-fill GST % from the chosen product.
    drawer.addEventListener('lookup:chosen', function (e) {
        var form = e.target.closest('form');
        var gst = form && form.querySelector('[data-gst-field]');
        if (gst && e.detail.gst !== undefined && gst.value === '') { gst.value = e.detail.gst; }
    });

    // Submit any quick-add form as JSON.
    drawer.querySelectorAll('.qa-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = form.querySelector('[type="submit"]');
            form.querySelectorAll('[data-error-for]').forEach(function (el) { el.textContent = ''; });
            form.querySelectorAll('.has-error').forEach(function (el) { el.classList.remove('has-error'); });
            var general = form.querySelector('.qa-general-error');
            if (general) { general.remove(); }
            successBox.hidden = true;
            btn.disabled = true;

            fetch(form.getAttribute('action'), {
                method: 'POST',
                body: new FormData(form),
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            }).then(function (r) {
                return r.json().catch(function () { return { success: false, message: 'Your session may have expired. Please reload the page.' }; });
            }).then(function (data) {
                if (data.success) {
                    savedSomething = true;
                    successBox.textContent = data.message + ' The dashboard refreshes when you close this panel.';
                    successBox.hidden = false;
                    // Keep dates / mode / employee for fast repeated entry; clear the rest.
                    form.querySelectorAll('input[type="text"]').forEach(function (i) { if (!i.classList.contains('lookup-input')) { i.value = ''; } });
                    form.querySelectorAll('.lookup').forEach(function (l) { if (l.resetLookup) { l.resetLookup(); } });
                    drawer.querySelector('.drawer-body').scrollTop = 0;
                    return;
                }
                var errors = data.errors || {};
                var shown = false;
                Object.keys(errors).forEach(function (field) {
                    var slot = form.querySelector('[data-error-for="' + field + '"]');
                    if (slot) {
                        slot.textContent = errors[field];
                        slot.closest('.field').classList.add('has-error');
                        shown = true;
                    }
                });
                var msg = data.message || errors._ || (!shown ? 'Could not save. Please check the form.' : '');
                if (msg) {
                    var div = document.createElement('div');
                    div.className = 'alert alert-error qa-general-error';
                    div.textContent = msg;
                    form.insertBefore(div, form.firstChild.nextSibling);
                }
            }).catch(function () {
                var div = document.createElement('div');
                div.className = 'alert alert-error qa-general-error';
                div.textContent = 'Network error. Nothing was saved; please try again.';
                form.insertBefore(div, form.firstChild);
            }).finally(function () { btn.disabled = false; });
        });
    });
})();
