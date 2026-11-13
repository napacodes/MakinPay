"use strict";

/*
 * Mass payments, customer side.
 *
 * - New batch: switches between the CSV upload and the manual grid, adds and
 *   removes grid rows (never past the row cap), names the chosen file, labels
 *   each typed recipient (local number, international number, email, wallet
 *   ID), fills rows pasted from a spreadsheet, and shows a running total.
 * - Preview: filters the row table to the invalid or warned lines; the
 *   confirm button waits for the boxes the batch needs ticked.
 * - Progress: polls the batch status and redraws the bar, the counts and the
 *   "still queued" banner; reloads once the batch finishes or changes state.
 * - Every form that moves or discards money asks once and then locks its
 *   button, so a double click is one request.
 *
 * The running total is the one figure computed here, and it is advice: the
 * typed amounts added in whole minor units, before fees. Every figure that
 * moves money - fees, totals, the reservation - is the server's.
 */
(function ($) {
    if (typeof $ !== 'function') {
        return;
    }

    function lockOnSubmit($form) {
        $form.on('submit', function (event) {
            var message = $form.data('confirm-message');

            if (message && !window.confirm(String(message))) {
                event.preventDefault();

                return;
            }

            if ($form.data('mp-submitted')) {
                event.preventDefault();

                return;
            }

            $form.data('mp-submitted', true);

            var $button = $form.find('button[type="submit"]').first();
            var label = $form.data('processing-label');

            $button.prop('disabled', true).attr('aria-busy', 'true');

            if (label) {
                $button.text(String(label));
            }
        });
    }

    /* ---------- New batch ---------- */

    function initCreateForm($form) {
        var maxRows = parseInt($form.data('max-rows'), 10) || 0;
        var $body = $form.find('[data-mp-grid-body]');
        var $limit = $form.find('[data-mp-grid-limit]');
        var template = $form.find('[data-mp-grid-template]').get(0);
        var nextIndex = $body.find('[data-mp-grid-row]').length;

        function showPanel(source) {
            $form.find('[data-mp-panel]').each(function () {
                var $panel = $(this);

                $panel.prop('hidden', $panel.data('mp-panel') !== source);
            });
        }

        function refreshLimit() {
            var count = $body.find('[data-mp-grid-row]').length;
            var full = maxRows > 0 && count >= maxRows;

            $form.find('[data-mp-add-row]').prop('disabled', full);
            $limit.prop('hidden', !full).text(full ? String($form.data('max-rows-message')) : '');
        }

        $form.on('change', '[data-mp-source]', function () {
            showPanel(String($(this).val()));
        });

        $form.on('change', '[data-mp-file]', function () {
            var $name = $form.find('[data-mp-file-name]');
            var file = this.files && this.files[0];

            $name.text(file ? file.name : String($name.data('empty-label')));
        });

        $form.on('click', '[data-mp-add-row]', function () {
            if (!template || (maxRows > 0 && $body.find('[data-mp-grid-row]').length >= maxRows)) {
                return;
            }

            var markup = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));

            nextIndex += 1;
            $body.append(markup);
            $body.find('[data-mp-grid-row]').last().find('input').first().trigger('focus');
            refreshLimit();
        });

        $form.on('click', '[data-mp-remove-row]', function () {
            var $rows = $body.find('[data-mp-grid-row]');
            var $row = $(this).closest('[data-mp-grid-row]');

            if ($rows.length <= 1) {
                $row.find('input').val('');
            } else {
                $row.remove();
            }

            refreshLimit();
        });

        var $checked = $form.find('[data-mp-source]:checked');

        if ($checked.length) {
            showPanel(String($checked.val()));
        }

        /* --- What each typed recipient is, as the draft will read it --- */

        var homeDial = String($form.data('home-dial') || '');

        function kindOf(value) {
            var text = $.trim(value);
            var digits = text.replace(/\D+/g, '');

            if (text === '') {
                return null;
            }

            if (text.indexOf('@') !== -1) {
                return { label: $form.data('kind-email'), tone: 'plain' };
            }

            if (/^\+?[0-9\s().\-]{6,20}$/.test(text)) {
                if (text.charAt(0) === '+') {
                    return homeDial !== '' && digits.indexOf(homeDial) === 0
                        ? { label: $form.data('kind-local'), tone: 'local' }
                        : { label: $form.data('kind-international'), tone: 'intl' };
                }

                return homeDial !== ''
                    ? { label: $form.data('kind-local'), tone: 'local' }
                    : { label: $form.data('kind-needs-code'), tone: 'warn' };
            }

            if (/^[A-Za-z0-9][A-Za-z0-9\-_ ]{5,40}$/.test(text)) {
                return { label: $form.data('kind-wallet'), tone: 'plain' };
            }

            return null;
        }

        function refreshKind($input) {
            var $kind = $input.closest('td').find('[data-mp-kind]');
            var kind = kindOf(String($input.val() || ''));

            $kind.removeClass('mp-kind--local mp-kind--intl mp-kind--warn mp-kind--plain')
                .text(kind ? String(kind.label) : '');

            if (kind) {
                $kind.addClass('mp-kind--' + kind.tone);
            }
        }

        /* --- Running total of the typed amounts, display only --- */

        function decimalsOfWallet() {
            var decimals = parseInt($form.find('[data-mp-wallet] option:selected').data('decimals'), 10);

            return isNaN(decimals) ? 2 : decimals;
        }

        // Amounts are added as whole minor units (BigInt), never as floats, so
        // the figure is exact. Anything the server would refuse is left out.
        function toMinor(text, decimals) {
            var clean = String(text || '').replace(/\s+/g, '');
            var match = /^(\d+)(?:\.(\d+))?$/.exec(clean);

            if (!match || (match[2] && match[2].length > decimals)) {
                return null;
            }

            var fraction = (match[2] || '') + new Array(decimals + 1).join('0');

            return BigInt(match[1] + fraction.slice(0, decimals));
        }

        function formatMinor(minor, decimals) {
            var text = minor.toString();

            while (text.length <= decimals) {
                text = '0' + text;
            }

            var whole = decimals > 0 ? text.slice(0, -decimals) : text;
            var fraction = decimals > 0 ? text.slice(-decimals) : '';

            whole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

            return fraction === '' ? whole : whole + '.' + fraction;
        }

        function refreshTotal() {
            var $total = $form.find('[data-mp-grid-total]');

            if (!$total.length || typeof BigInt !== 'function') {
                return;
            }

            var decimals = decimalsOfWallet();
            var currency = String($form.find('[data-mp-wallet] option:selected').data('currency') || '');
            var sum = BigInt(0);
            var rows = 0;

            $body.find('[data-mp-grid-row]').each(function () {
                var minor = toMinor($(this).find('input[name$="[amount]"]').val(), decimals);

                if (minor !== null && $.trim(String($(this).find('input[name$="[recipient]"]').val() || '')) !== '') {
                    sum += minor;
                    rows += 1;
                }
            });

            $total.text(rows === 0 ? '' : String($form.data('total-template'))
                .replace(':rows', String(rows))
                .replace(':amount', (formatMinor(sum, decimals) + ' ' + currency).trim()));
        }

        /* --- Paste rows copied from a spreadsheet --- */

        function emptyRow() {
            var $empty = $body.find('[data-mp-grid-row]').filter(function () {
                return $(this).find('input').filter(function () {
                    return $.trim(String($(this).val() || '')) !== '';
                }).length === 0;
            }).first();

            if ($empty.length) {
                return $empty;
            }

            if (!template || (maxRows > 0 && $body.find('[data-mp-grid-row]').length >= maxRows)) {
                return null;
            }

            $body.append(template.innerHTML.replace(/__INDEX__/g, String(nextIndex)));
            nextIndex += 1;

            return $body.find('[data-mp-grid-row]').last();
        }

        function pasteRows(text) {
            var lines = String(text || '').split(/\r\n|\r|\n/);
            var added = 0;
            var full = false;

            lines.forEach(function (line) {
                if (full || $.trim(line) === '') {
                    return;
                }

                var separator = line.indexOf('\t') !== -1 ? '\t' : (line.indexOf(';') !== -1 ? ';' : ',');
                var cells = line.split(separator).map(function (cell) {
                    return $.trim(cell.replace(/^"|"$/g, ''));
                });

                if (added === 0 && /^(recipient|phone|email|wallet|wallet_id)$/i.test(cells[0] || '')) {
                    return;
                }

                var $row = emptyRow();

                if ($row === null) {
                    full = true;

                    return;
                }

                ['recipient', 'amount', 'note', 'reference'].forEach(function (field, index) {
                    $row.find('input[name$="[' + field + ']"]').val(cells[index] || '');
                });

                refreshKind($row.find('input[name$="[recipient]"]'));
                added += 1;
            });

            var $result = $form.find('[data-mp-paste-result]');

            $result.text(String($result.data(full ? 'full-template' : 'added-template')).replace(':count', String(added)));
            refreshLimit();
            refreshTotal();
        }

        $form.on('input', 'input[name$="[recipient]"]', function () {
            refreshKind($(this));
            refreshTotal();
        });

        $form.on('input', 'input[name$="[amount]"]', refreshTotal);
        $form.on('change', '[data-mp-wallet]', refreshTotal);
        $form.on('click', '[data-mp-add-row], [data-mp-remove-row]', function () {
            window.setTimeout(refreshTotal, 0);
        });

        $form.on('click', '[data-mp-paste-toggle]', function () {
            var $panel = $form.find('[data-mp-paste]');
            var open = $panel.prop('hidden');

            $panel.prop('hidden', !open);
            $(this).attr('aria-expanded', open ? 'true' : 'false');

            if (open) {
                $panel.find('[data-mp-paste-text]').trigger('focus');
            }
        });

        $form.on('click', '[data-mp-paste-apply]', function () {
            var $text = $form.find('[data-mp-paste-text]');

            pasteRows($text.val());
            $text.val('');
        });

        $body.find('input[name$="[recipient]"]').each(function () {
            refreshKind($(this));
        });

        refreshTotal();
        refreshLimit();
        lockOnSubmit($form);
    }

    /* ---------- Confirm: the required boxes before the button ---------- */

    function initConfirmGate($form) {
        var $button = $form.find('[data-mp-confirm]');
        var $required = $form.find('[data-mp-required]');

        if (!$required.length || $button.prop('disabled')) {
            return;
        }

        function refresh() {
            $button.prop('disabled', $required.filter(':not(:checked)').length > 0);
        }

        $required.on('change', refresh);
        refresh();
    }

    /* ---------- Preview filter ---------- */

    function initRowFilter($page) {
        $page.on('click', '[data-mp-filter]', function () {
            var filter = String($(this).data('mp-filter'));

            $page.find('[data-mp-filter]').each(function () {
                var active = String($(this).data('mp-filter')) === filter;

                $(this).toggleClass('is-active', active).attr('aria-pressed', active ? 'true' : 'false');
            });

            $page.find('[data-mp-rows] [data-mp-row]').each(function () {
                var $row = $(this);

                $row.prop('hidden', filter !== 'all' && String($row.data('mp-row')) !== filter);
            });
        });
    }

    /* ---------- Progress polling ---------- */

    function initPolling($progress) {
        var url = $progress.data('status-url');
        var interval = parseInt($progress.data('poll-interval'), 10) || 5000;
        var initialStatus = String($progress.data('initial-status') || '');
        var $page = $progress.closest('[data-mp-batch-page]');
        var timer = null;
        var failures = 0;

        if (!url) {
            return;
        }

        function draw(data) {
            var counts = data.counts || {};

            ['paid', 'failed', 'pending', 'skipped'].forEach(function (key) {
                $page.find('[data-mp-count="' + key + '"]').text(String(counts[key] || 0));
            });

            var payable = (counts.pending || 0) + (counts.paid || 0) + (counts.failed || 0) + (counts.reviewed || 0);
            var percent = function (part) {
                return payable === 0 ? 0 : Math.round((part * 10000) / payable) / 100;
            };

            $progress.find('[data-mp-seg="paid"]').css('inline-size', percent(counts.paid || 0) + '%');
            $progress.find('[data-mp-seg="failed"]').css('inline-size', percent((counts.failed || 0) + (counts.reviewed || 0)) + '%');
            $page.find('[data-mp-stale]').prop('hidden', !data.stale);
            $page.find('[data-mp-status-label]').text(String(data.status_label || ''));
        }

        function poll() {
            $.ajax({
                url: url,
                method: 'GET',
                dataType: 'json',
                cache: false,
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
            }).done(function (data) {
                failures = 0;
                draw(data);

                // Reload once the batch finishes or moves to another state, so
                // the page shows that state's own notices. A batch waiting for
                // an admin's approval stays put and keeps polling: reloading on
                // "anything but queued or processing" reloaded that page every
                // few seconds until somebody approved it.
                var paying = function (status) {
                    return status === 'queued' || status === 'processing';
                };
                var moved = paying(initialStatus) || initialStatus === ''
                    ? !paying(data.status)
                    : data.status !== initialStatus;

                if (data.terminal || moved) {
                    window.location.reload();

                    return;
                }

                schedule();
            }).fail(function () {
                failures += 1;

                if (failures < 5) {
                    schedule();
                }
            });
        }

        function schedule() {
            window.clearTimeout(timer);
            timer = window.setTimeout(poll, interval);
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                window.clearTimeout(timer);
                poll();
            }
        });

        schedule();
    }

    $(function () {
        $('[data-mp-create-form]').each(function () {
            initCreateForm($(this));
        });

        $('[data-mp-batch-page]').each(function () {
            initRowFilter($(this));
        });

        $('[data-mp-progress]').each(function () {
            initPolling($(this));
        });

        $('[data-mp-confirm-form]').each(function () {
            initConfirmGate($(this));
        });

        $('[data-mp-confirm-form], [data-mp-discard-form], [data-mp-stop-form], [data-mp-repeat-form], [data-mp-schedules-page] form').each(function () {
            lockOnSubmit($(this));
        });

        // "Pay this batch again": only the fields the chosen cadence needs.
        $('[data-mp-repeat-form]').each(function () {
            var $form = $(this);
            var $cadence = $form.find('[data-mp-repeat-cadence]');

            function showFor() {
                var cadence = String($cadence.val());

                $form.find('[data-mp-repeat-for]').each(function () {
                    var $field = $(this);
                    var shown = $field.data('mp-repeat-for') === cadence;

                    $field.toggle(shown);
                    $field.find('input, select').prop('disabled', !shown);
                });
            }

            $cadence.on('change', showFor);
            showFor();
        });
    });
})(window.jQuery);
