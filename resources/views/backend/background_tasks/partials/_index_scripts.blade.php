<script>
"use strict";

/*
 * Background Tasks — shared page behaviour.
 *
 * Included by Commands, Run history and Failed jobs, so everything below
 * degrades on its own: a page without a Commands toolbar simply skips that
 * block. Nothing here writes a sentence — every string it can show is read
 * off a data attribute the blade rendered in the administrator's own locale.
 */
(function () {
    var toast     = document.getElementById('bt-toast');
    var toastText = toast ? toast.querySelector('[data-bt-toast-text]') : null;
    var toastTimer;

    function showToast(message) {
        if (!toast || !toastText) { return; }

        if (message) { toastText.textContent = message; }

        toast.hidden = false;

        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toast.hidden = true; }, 2200);
    }

    /* ---------------- Commands: search + status filter ---------------- */

    (function () {
        var page = document.querySelector('[data-bt-commands]');

        if (!page) { return; }

        var rows  = Array.prototype.slice.call(document.querySelectorAll('[data-bt-command]'));
        var empty = document.querySelector('[data-bt-empty]');
        var state = { filter: 'all', query: '' };

        function apply() {
            var shown = 0;

            rows.forEach(function (row) {
                var matchesFilter = state.filter === 'all' || row.dataset.status === state.filter;
                var matchesQuery  = state.query === '' || (row.dataset.search || '').indexOf(state.query) !== -1;
                var visible       = matchesFilter && matchesQuery;

                row.hidden = !visible;

                if (visible) { shown += 1; }
            });

            if (empty) { empty.hidden = shown > 0; }

            Array.prototype.forEach.call(page.querySelectorAll('[data-bt-filter]'), function (button) {
                var on = button.dataset.btFilter === state.filter;

                button.classList.toggle('is-active', on);
                button.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }

        page.addEventListener('click', function (event) {
            var button = event.target.closest('[data-bt-filter]');

            if (!button) { return; }

            state.filter = button.dataset.btFilter;
            apply();
        });

        page.addEventListener('input', function (event) {
            var search = event.target.closest('[data-bt-search]');

            if (!search) { return; }

            state.query = search.value.trim().toLowerCase();
            apply();
        });

        apply();
    }());

    /* ---------------- Copy ---------------- */

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-bt-copy]');

        if (!trigger) { return; }

        event.preventDefault();

        var text = trigger.dataset.btCopy;

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { showToast(); }, function () {});

            return;
        }

        /*
         * No clipboard API — an insecure origin, usually. Fall back, but only
         * claim the copy when execCommand actually reports one.
         */
        var field = document.createElement('textarea');

        field.value = text;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();

        try {
            if (document.execCommand('copy')) { showToast(); }
        } catch (error) { /* nothing to report */ }

        field.remove();
    });

    /* ---------------- Run modal ---------------- */

    (function () {
        var modal = document.getElementById('run-task-modal');

        if (!modal) { return; }

        var keyInput = document.getElementById('modal-task-key');
        var tile     = modal.querySelector('[data-bt-modal-tile]');
        var icon     = modal.querySelector('[data-bt-modal-icon]');
        var label    = modal.querySelector('[data-bt-modal-label]');
        var sig      = modal.querySelector('[data-bt-modal-sig]');
        var desc     = modal.querySelector('[data-bt-modal-desc]');
        var optsBox  = modal.querySelector('[data-bt-modal-options]');
        var preview  = modal.querySelector('[data-bt-modal-preview]');
        var picker   = modal.querySelector('[data-bt-modal-picker]');
        var select   = modal.querySelector('[data-bt-task-picker]');
        var submit   = modal.querySelector('[data-bt-modal-submit]');

        var signature = '';

        /*
         * The preview is assembled the way BackgroundTaskController@run
         * assembles the real invocation: a boolean contributes its flag only
         * while it is on, an integer always contributes its value. If the two
         * ever disagree, the line on screen is the one that is wrong.
         */
        function paintPreview() {
            var parts = [];

            Array.prototype.forEach.call(optsBox.querySelectorAll('[data-bt-opt]'), function (row) {
                var name = row.dataset.btOpt;

                if (row.dataset.btOptType === 'boolean') {
                    if (row.querySelector('input[type="checkbox"]').checked) {
                        parts.push('--' + name);
                    }

                    return;
                }

                var value = row.querySelector('input[type="number"]').value;

                if (value !== '') { parts.push('--' + name + '=' + value); }
            });

            preview.textContent = signature === ''
                ? ''
                : ('php artisan ' + signature + ' ' + parts.join(' ')).trim();
        }

        function buildOptions(options) {
            optsBox.innerHTML = '';
            optsBox.hidden = options.length === 0;

            options.forEach(function (option) {
                var row = document.createElement('div');

                row.className = 'bt-opt';
                row.dataset.btOpt = option.name;
                row.dataset.btOptType = option.type;

                var copy = document.createElement('span');

                copy.className = 'bt-opt__copy';
                copy.innerHTML = '<span class="bt-opt__label"></span><span class="bt-opt__help"></span>';
                copy.querySelector('.bt-opt__label').textContent = option.label;
                copy.querySelector('.bt-opt__help').textContent = option.help;
                row.appendChild(copy);

                if (option.type === 'boolean') {
                    /*
                     * A checkbox that is off posts nothing, and the controller
                     * then falls back to the option's default — which would
                     * turn a switch the administrator just switched off back
                     * on. The hidden 0 is what makes "off" mean off.
                     */
                    var hidden = document.createElement('input');

                    hidden.type = 'hidden';
                    hidden.name = option.name;
                    hidden.value = '0';
                    row.appendChild(hidden);

                    var toggle = document.createElement('label');

                    toggle.className = 'bt-switch';
                    toggle.innerHTML =
                        '<input class="bt-switch__input" type="checkbox" role="switch" value="1">' +
                        '<span class="bt-switch__track" aria-hidden="true"></span>';

                    var box = toggle.querySelector('input');

                    box.name = option.name;
                    box.checked = option.default === true;
                    box.setAttribute('aria-label', option.label);
                    box.addEventListener('change', paintPreview);

                    row.appendChild(toggle);
                    optsBox.appendChild(row);

                    return;
                }

                var group = document.createElement('span');

                group.className = 'bt-num';
                group.innerHTML = '<input class="bt-num__input" type="number"><span class="bt-num__range" dir="ltr"></span>';

                var number = group.querySelector('input');

                number.name = option.name;
                number.value = option.default;
                number.setAttribute('aria-label', option.label);

                if (option.min !== null) { number.min = option.min; }
                if (option.max !== null) { number.max = option.max; }

                group.querySelector('.bt-num__range').textContent =
                    option.min !== null && option.max !== null ? option.min + '–' + option.max : '';

                number.addEventListener('input', paintPreview);
                row.appendChild(group);
                optsBox.appendChild(row);
            });
        }

        /*
         * `source` is any element carrying the task's data attributes: a row
         * button on Commands, or an <option> in the picker. Passing null
         * dresses the dialog for "nothing chosen yet", which is how it opens
         * from the header, where there is no row to read.
         */
        function applyTask(source) {
            signature = source ? (source.dataset.taskSig || '') : '';

            keyInput.value = source ? (source.dataset.taskKey || '') : '';
            label.textContent = source
                ? (source.dataset.taskLabel || '')
                : (modal.dataset.btModalPlaceholder || '');
            sig.textContent = signature;
            desc.textContent = source ? (source.dataset.taskDesc || '') : '';
            icon.className = 'fa-solid ' + ((source && source.dataset.taskIcon) || 'fa-play');
            tile.style.setProperty('--bt-tile-bg', (source && source.dataset.taskTileBg) || '');
            tile.style.setProperty('--bt-tile-fg', (source && source.dataset.taskTileFg) || '');

            var options = [];

            if (source) {
                try {
                    options = JSON.parse(source.dataset.taskOptions || '[]');
                } catch (error) {
                    options = [];
                }
            }

            buildOptions(options);
            paintPreview();

            /* Submitting with no key posts a request the validator rejects. */
            if (submit) { submit.disabled = keyInput.value === ''; }
        }

        if (select) {
            select.addEventListener('change', function () {
                var chosen = select.options[select.selectedIndex];

                applyTask(chosen && chosen.value !== '' ? chosen : null);
            });
        }

        modal.addEventListener('show.coreui.modal', function (event) {
            var trigger = event.relatedTarget;
            var named   = trigger && trigger.dataset && trigger.dataset.taskKey;

            if (picker) { picker.hidden = !!named; }
            if (select) { select.value = ''; }

            applyTask(named ? trigger : null);
        });

        modal.addEventListener('hidden.coreui.modal', function () {
            keyInput.value = '';
            optsBox.innerHTML = '';
            optsBox.hidden = true;

            if (select) { select.value = ''; }
        });
    }());

    /* ---------------- Confirm dialog ---------------- */

    /*
     * Delete and Flush share one dialog. Each trigger carries its own copy and
     * the form it posts to, so the dialog states what is about to happen
     * rather than a sentence general enough to cover both.
     */
    (function () {
        var modal = document.getElementById('bt-confirm-modal');

        if (!modal) { return; }

        var form   = document.getElementById('bt-confirm-form');
        var title  = modal.querySelector('[data-bt-confirm-title]');
        var body   = modal.querySelector('[data-bt-confirm-body]');
        var method = modal.querySelector('[data-bt-confirm-method]');
        var cta    = modal.querySelector('[data-bt-confirm-cta]');

        modal.addEventListener('show.coreui.modal', function (event) {
            var trigger = event.relatedTarget;

            if (!trigger) { return; }

            form.setAttribute('action', trigger.dataset.action || '');
            title.textContent = trigger.dataset.title || '';
            body.textContent = trigger.dataset.body || '';
            cta.textContent = trigger.dataset.cta || '';

            /*
             * Flush posts; delete spoofs DELETE. Laravel ignores _method on a
             * POST route, so clearing the name rather than the value is what
             * keeps a flush from arriving as a DELETE.
             */
            var wantsDelete = (trigger.dataset.method || 'DELETE').toUpperCase() === 'DELETE';

            method.setAttribute('name', wantsDelete ? '_method' : '');
            method.value = 'DELETE';
        });
    }());

    /* ---------------- Run history: command select ---------------- */

    /*
     * Each option carries the URL it leads to, filters already merged in, so
     * choosing a command keeps the status and range that were set.
     */
    document.addEventListener('change', function (event) {
        var select = event.target.closest('[data-bt-history-task]');

        if (!select) { return; }

        window.location.href = select.value;
    });

    /*
     * Kept for any page still posting its filters as a form. The form, its
     * action and its field names are untouched — this only saves the click
     * that used to be needed to apply a selection.
     */
    document.addEventListener('change', function (event) {
        var control = event.target.closest('[data-bt-submit-on-change]');

        if (!control || !control.form) { return; }

        control.form.submit();
    });

    /*
     * Row expansion for Run history and Failed jobs: one row open per table, so
     * a long stack trace never pushes the row an operator is comparing it
     * against off the screen.
     */
    /* A row that behaves as a button has to answer the keyboard like one. */
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') { return; }

        var row = event.target.closest('[data-bt-expand][role="button"]');

        if (!row || event.target !== row) { return; }

        event.preventDefault();
        row.click();
    });

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-bt-expand]');

        if (!trigger) { return; }

        /* A row's Retry form and its Delete trigger are not the disclosure. */
        if (event.target.closest('a, form, [data-bt-delete-job]')) { return; }

        var row = trigger.classList.contains('bt-erow') ? trigger : trigger.closest('.bt-erow');

        if (!row) { return; }

        var open  = row.classList.contains('is-open');
        var scope = row.closest('.bt-grid') || document;

        Array.prototype.forEach.call(scope.querySelectorAll('.bt-erow.is-open'), function (other) {
            other.classList.remove('is-open');
            other.setAttribute('aria-expanded', 'false');
        });

        if (!open) {
            row.classList.add('is-open');
            row.setAttribute('aria-expanded', 'true');
        }
    });
}());
</script>
