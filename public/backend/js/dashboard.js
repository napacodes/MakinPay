"use strict";

/*
 * Admin dashboard home.
 *
 * Five behaviours, each degrading on its own:
 *  - the range control swaps the money panel for a server-rendered one;
 *  - the custom range modal writes the same start_date/end_date the URL has
 *    always accepted;
 *  - queue sort and the transaction tabs reorder what is already on the page;
 *  - the queues, the health rows and the risk count poll once a minute;
 *  - the SLA modal saves through the settings layer.
 *
 * Nothing here invents a number. Every figure this file puts on the page came
 * back from the server, and the one arithmetic it does — the number of days
 * between two dates in the range modal — is a label on the operator's own
 * input, not a measurement.
 */
(function ($) {
    $(function () {
        const root = document.querySelector('[data-dk-dashboard]');

        if (!root) {
            return;
        }

        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const urls = {
            live: root.dataset.liveUrl,
            range: root.dataset.rangeUrl,
            sla: root.dataset.slaUrl,
        };

        const pollSeconds = Math.max(15, parseInt(root.dataset.pollSeconds || '60', 10));

        /* Written by the blade, so a toast is in the same language as the page. */
        const strings = {
            rangeError: root.dataset.msgRangeError || '',
            saved: root.dataset.msgSaved || '',
        };

        function notify(status, title, text) {
            if (typeof Notify === 'function') {
                new Notify({ status: status, title: title, text: text, autotimeout: 4000 });
                return;
            }

            if (status === 'error' && window.console) {
                console.error(title, text);
            }
        }

        /*
         * One tab stop for a segmented group, arrow keys inside it. Four
         * presets should not cost four tabs to walk past.
         */
        function makeRovingGroup(group, selector) {
            const buttons = Array.from(group.querySelectorAll(selector));

            buttons.forEach(function (button, index) {
                button.tabIndex = button.classList.contains('is-active') ? 0 : -1;

                button.addEventListener('keydown', function (event) {
                    if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') {
                        return;
                    }

                    event.preventDefault();

                    const step = event.key === 'ArrowRight' ? 1 : -1;
                    const next = buttons[(index + step + buttons.length) % buttons.length];

                    buttons.forEach(function (other) { other.tabIndex = -1; });
                    next.tabIndex = 0;
                    next.focus();
                });
            });

            return buttons;
        }

        function markActive(buttons, active) {
            buttons.forEach(function (button) {
                const on = button === active;

                button.classList.toggle('is-active', on);
                button.setAttribute('aria-pressed', on ? 'true' : 'false');
                button.tabIndex = on ? 0 : -1;
            });
        }

        /* ---------------- date range ---------------- */


        /*
         * The panel is fetched already rendered and swapped in whole.
         *
         * The alternative — pulling JSON and rebuilding the bars, the KPI
         * cells and the fee line in JavaScript — means a second copy of the
         * blade's layout that has to be kept in step with it by hand. This way
         * the server stays the only thing that knows how the panel looks.
         */
        function applyRange(params) {
            // Re-queried, never cached: the panel is replaced on every change.
            const panel = root.querySelector('[data-dk-money]');

            if (!panel) {
                return;
            }

            const veil = panel.querySelector('[data-dk-veil]');
            const url = new URL(urls.range, window.location.origin);

            Object.keys(params).forEach(function (key) {
                if (params[key] !== '' && params[key] !== null && params[key] !== undefined) {
                    url.searchParams.set(key, params[key]);
                }
            });

            if (veil) {
                veil.hidden = false;
            }

            /*
             * Deliberately without X-Requested-With. The dashboard route has
             * carried an ajax contract since long before this panel — a
             * request that looks like XHR and names start_date or end_date is
             * answered with the legacy chart JSON, and one that names none of
             * the keys that branch understands is answered with a 400. This
             * asks for the page the way the address bar would, and lifts the
             * panel out of it.
             */
            fetch(url.toString(), { credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }

                    return response.text();
                })
                .then(function (html) {
                    const parsed = new DOMParser().parseFromString(html, 'text/html');
                    const fresh = parsed.querySelector('[data-dk-money]');

                    if (!fresh) {
                        throw new Error('The range response carried no money panel.');
                    }

                    panel.replaceWith(fresh);
                    window.history.replaceState({}, '', url.toString());
                    bindMoneyPanel();
                })
                .catch(function (error) {
                    if (veil) {
                        veil.hidden = true;
                    }

                    notify('error', strings.rangeError, error.message);
                });
        }

        function bindMoneyPanel() {
            const panel = root.querySelector('[data-dk-money]');

            if (!panel) {
                return;
            }

            const group = panel.querySelector('[data-dk-range]');

            if (!group) {
                return;
            }

            const buttons = makeRovingGroup(group, '.dk-seg__btn');

            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    const key = button.dataset.rangeKey;

                    if (key === 'custom') {
                        openRangeModal();
                        return;
                    }

                    markActive(buttons, button);
                    applyRange({ range: key, start_date: '', end_date: '' });
                });
            });
        }

        bindMoneyPanel();

        /* ---------------- custom range modal ---------------- */

        const rangeModal = root.querySelector('[data-dk-range-modal]');
        let rangeOpener = null;

        function modalInputs() {
            return {
                start: rangeModal.querySelector('[data-dk-range-input="start"]'),
                end: rangeModal.querySelector('[data-dk-range-input="end"]'),
            };
        }

        function dayCount(start, end) {
            return Math.round((end - start) / 86400000) + 1;
        }

        /*
         * A date input wants a local calendar day. toISOString() converts to
         * UTC first, so east of Greenwich "1 January" comes back as the 31st
         * of December — which is what "This year" was producing in Dhaka.
         */
        function localDate(date) {
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');

            return date.getFullYear() + '-' + month + '-' + day;
        }

        /*
         * Says what is wrong before Apply is pressed, and refuses to enable it
         * until nothing is. Reported in the footer rather than as an alert:
         * the operator is looking at the two fields they just typed into.
         */
        function refreshRangeSummary() {
            const fields = modalInputs();
            const note = rangeModal.querySelector('[data-dk-range-summary]');
            const apply = rangeModal.querySelector('[data-dk-range-apply]');
            const start = new Date(fields.start.value);
            const end = new Date(fields.end.value);
            const bad = !fields.start.value || !fields.end.value || isNaN(start) || isNaN(end);
            const reversed = !bad && end < start;

            let message;

            if (bad) {
                message = 'Pick both dates';
            } else if (reversed) {
                message = 'End must be after start';
            } else {
                const days = dayCount(start, end);

                message = days + (days === 1 ? ' day selected' : ' days selected');
            }

            if (note) {
                note.textContent = message;
                note.classList.toggle('is-invalid', bad || reversed);
            }

            if (apply) {
                apply.disabled = bad || reversed;
            }

            return !(bad || reversed);
        }

        function openRangeModal(trigger) {
            if (!rangeModal) {
                return;
            }

            rangeOpener = trigger || document.activeElement;
            rangeModal.hidden = false;
            refreshRangeSummary();
            modalInputs().start.focus();
        }

        function closeRangeModal() {
            if (!rangeModal || rangeModal.hidden) {
                return;
            }

            rangeModal.hidden = true;

            if (rangeOpener && typeof rangeOpener.focus === 'function') {
                rangeOpener.focus();
            }
        }

        if (rangeModal) {
            rangeModal.querySelectorAll('[data-dk-range-dismiss]').forEach(function (control) {
                control.addEventListener('click', closeRangeModal);
            });

            rangeModal.querySelectorAll('[data-dk-range-input]').forEach(function (input) {
                input.addEventListener('change', refreshRangeSummary);
                input.addEventListener('input', refreshRangeSummary);
            });

            rangeModal.querySelectorAll('[data-dk-range-preset]').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    const days = parseInt(chip.dataset.dkRangePreset, 10);
                    const fields = modalInputs();
                    const end = new Date();
                    const start = days === 0
                        ? new Date(end.getFullYear(), 0, 1)
                        : new Date(end.getTime() - (days - 1) * 86400000);

                    fields.start.value = localDate(start);
                    fields.end.value = localDate(end);
                    refreshRangeSummary();
                });
            });

            rangeModal.querySelector('[data-dk-range-apply]')?.addEventListener('click', function () {
                if (!refreshRangeSummary()) {
                    return;
                }

                const fields = modalInputs();

                closeRangeModal();
                applyRange({ range: 'custom', start_date: fields.start.value, end_date: fields.end.value });
            });
        }

        /* ---------------- queue sort ---------------- */

        const sortGroup = root.querySelector('[data-dk-queue-sort]');
        const queueRows = root.querySelector('[data-dk-queue-rows]');

        if (sortGroup && queueRows) {
            const buttons = makeRovingGroup(sortGroup, '.dk-seg__btn');
            const urgency = { over: 0, near: 1, in: 2, none: 3 };

            function sortQueues(mode) {
                const rows = Array.from(queueRows.children);

                rows.sort(function (a, b) {
                    const aEmpty = a.dataset.state === 'none' ? 1 : 0;
                    const bEmpty = b.dataset.state === 'none' ? 1 : 0;

                    // An empty queue has no urgency to rank, whatever the mode.
                    if (aEmpty !== bEmpty) {
                        return aEmpty - bEmpty;
                    }

                    if (mode === 'count') {
                        return Number(b.dataset.count) - Number(a.dataset.count);
                    }

                    if (mode === 'value') {
                        return Number(b.dataset.value) - Number(a.dataset.value);
                    }

                    const rank = (urgency[a.dataset.state] ?? 3) - (urgency[b.dataset.state] ?? 3);

                    return rank !== 0 ? rank : Number(b.dataset.ratio) - Number(a.dataset.ratio);
                });

                rows.forEach(function (row) { queueRows.appendChild(row); });
            }

            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    markActive(buttons, button);
                    sortQueues(button.dataset.sort);
                });
            });
        }

        /* ---------------- queue collapsibles ---------------- */

        /*
         * The queues that are clear, and the list of response-time limits.
         * Both arrive shut, both are pure client state, and both remember what
         * the operator last chose — a panel that reopens the way it was left
         * is one fewer click on every visit to this page.
         *
         * Every label these write is read off a data attribute the blade set
         * in the administrator's own locale, so nothing here says a word.
         */
        function remember(key, open) {
            try {
                window.localStorage.setItem(key, open ? '1' : '0');
            } catch (error) {
                /* Private window, blocked site data: the toggle still works. */
            }
        }

        function remembered(key) {
            try {
                return window.localStorage.getItem(key) === '1';
            } catch (error) {
                return false;
            }
        }

        function bindCollapsible(storageKey, panelSelector, labelSelector) {
            const panel = root.querySelector(panelSelector);

            if (!panel) {
                return;
            }

            /*
             * Both of the footer's controls open the same list, so the
             * triggers are found by what they point at rather than by a class
             * of their own — and they stay in step because one function sets
             * the state on all of them.
             */
            const triggers = Array.from(
                root.querySelectorAll('[aria-controls="' + panel.id + '"]')
            );

            if (triggers.length === 0) {
                return;
            }

            function apply(open) {
                panel.hidden = !open;

                triggers.forEach(function (trigger) {
                    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');

                    const label = trigger.querySelector(labelSelector);

                    if (label) {
                        label.textContent = open
                            ? (trigger.dataset.labelHide || label.textContent)
                            : (trigger.dataset.labelShow || label.textContent);
                    }
                });
            }

            triggers.forEach(function (trigger) {
                trigger.addEventListener('click', function () {
                    const open = panel.hidden;

                    apply(open);
                    remember(storageKey, open);
                });
            });

            if (remembered(storageKey)) {
                apply(true);
            }
        }

        bindCollapsible('dk.queues.emptyOpen', '#dkQuietQueues', '[data-dk-queue-quiet-label]');
        bindCollapsible('dk.queues.limitsOpen', '#dkQueueLimits', '[data-dk-queue-limits-label]');

        /* ---------------- transaction tabs ---------------- */

        const tabGroup = root.querySelector('[data-dk-trx-tabs]');
        const trxRows = root.querySelector('[data-dk-trx-rows]');

        if (tabGroup && trxRows) {
            const buttons = makeRovingGroup(tabGroup, '.dk-seg__btn');

            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    const tab = button.dataset.tab;

                    markActive(buttons, button);

                    /*
                     * Query by attribute rather than walking children: the
                     * rows are the shared transactions table now, so they sit
                     * inside a `tbody` and again inside the mobile list. Both
                     * carry `data-status`, so both filter, and neither has to
                     * be a direct child of anything.
                     */
                    trxRows.querySelectorAll('[data-status]').forEach(function (row) {
                        row.hidden = tab !== 'all' && row.dataset.status !== tab;
                    });
                });
            });
        }

        /* ---------------- live poll ---------------- */

        let refreshedAt = Date.parse(root.querySelector('[data-dk-refreshed]')?.dataset.refreshedAt || '') || Date.now();

        /*
         * Both age lines carry their own wording as data attributes, written
         * by the blade in the administrator's locale. This function only fills
         * in the elapsed figure.
         */
        function paintAge(node, seconds, freshBelow) {
            if (!node) {
                return;
            }

            if (seconds < freshBelow) {
                node.textContent = node.dataset.just || '';
                return;
            }

            const elapsed = seconds < 60 ? seconds + 's' : Math.round(seconds / 60) + 'm';

            node.textContent = (node.dataset.ago || '').replace(':elapsed', elapsed);
        }

        function paintAges() {
            const seconds = Math.max(0, Math.round((Date.now() - refreshedAt) / 1000));

            paintAge(root.querySelector('[data-dk-refreshed]'), seconds, 45);
            paintAge(root.querySelector('[data-dk-health-age]'), seconds, 5);
        }

        function paintQueues(payload) {
            (payload.queues || []).forEach(function (queue) {
                const row = root.querySelector('[data-queue="' + queue.key + '"]');

                if (!row) {
                    return;
                }

                const state = queue.count === 0 ? 'none' : queue.state;

                row.dataset.count = queue.count;
                row.dataset.state = state;
                row.className = row.className.replace(/dk-queue--\w+/, 'dk-queue--' + state);

                const count = row.querySelector('.dk-queue__count');

                if (count) {
                    count.textContent = new Intl.NumberFormat().format(queue.count);
                }

                const bar = row.querySelector('.dk-queue__bar');

                if (bar) {
                    bar.style.width = (queue.count === 0 ? 0 : queue.sla_percent) + '%';
                }
            });

            const summary = root.querySelector('[data-dk-queue-summary]');

            /*
             * innerHTML, because the line carries the emphasis the design puts
             * on the two figures an operator scans for. The markup is built by
             * the controller from figures it formatted itself, with every
             * interpolated value escaped there — the browser is assembling
             * nothing, only placing what came back.
             */
            if (summary && payload.queue_summary_html) {
                summary.innerHTML = payload.queue_summary_html;
            }

            const breach = root.querySelector('[data-dk-queue-breach]');

            if (breach) {
                breach.hidden = !payload.breaches;
                const value = breach.querySelector('[data-dk-queue-breach-count]');

                if (value) {
                    value.textContent = payload.breaches;
                }
            }
        }

        function paintHealth(payload) {
            (payload.health || []).forEach(function (row) {
                const node = root.querySelector('[data-health="' + row.key + '"]');

                if (!node) {
                    return;
                }

                node.className = node.className.replace(/dk-health__row--\w+/, 'dk-health__row--' + row.state);
                node.title = row.name + ' · ' + row.note + ' · ' + row.value;

                const note = node.querySelector('.dk-health__note');
                const value = node.querySelector('.dk-health__value');

                if (note) {
                    note.textContent = row.note;
                }

                if (value) {
                    value.textContent = row.value.length > 18 ? row.value.slice(0, 17) + '…' : row.value;
                }
            });

            const summary = payload.health_summary;

            if (!summary) {
                return;
            }

            const line = root.querySelector('[data-dk-health-summary]');
            const pill = root.querySelector('[data-dk-health-pill]');

            if (line && summary.text) {
                line.textContent = summary.text;
            }

            if (pill && summary.pill) {
                pill.classList.toggle('dk-status-pill--warn', summary.attention > 0);
                pill.textContent = summary.pill;
            }
        }

        function poll() {
            if (!urls.live) {
                return Promise.resolve();
            }

            return fetch(urls.live, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }

                    return response.json();
                })
                .then(function (payload) {
                    paintQueues(payload);
                    paintHealth(payload);

                    const count = root.querySelector('[data-dk-risk-count]');

                    if (count && payload.risk_count !== null && payload.risk_count !== undefined) {
                        count.textContent = payload.risk_count;
                    }

                    refreshedAt = Date.parse(payload.refreshed_at) || Date.now();
                    paintAges();
                })
                .catch(function () {
                    /*
                     * A failed poll is not worth a toast every minute. The
                     * page keeps the figures it has and the age line keeps
                     * counting, which is what tells the operator they are
                     * looking at something stale.
                     */
                });
        }

        window.setInterval(poll, pollSeconds * 1000);
        window.setInterval(paintAges, 15000);
        paintAges();

        root.querySelector('[data-dk-refresh]')?.addEventListener('click', function (event) {
            const button = event.currentTarget;

            button.disabled = true;
            poll().finally(function () { button.disabled = false; });
        });

        /* ---------------- SLA modal ---------------- */

        const slaModal = root.querySelector('[data-dk-sla-modal]');
        let slaOpener = null;

        function closeSlaModal() {
            if (!slaModal || slaModal.hidden) {
                return;
            }

            slaModal.hidden = true;

            if (slaOpener && typeof slaOpener.focus === 'function') {
                slaOpener.focus();
            }
        }

        if (slaModal) {
            root.querySelector('[data-dk-sla-open]')?.addEventListener('click', function (event) {
                slaOpener = event.currentTarget;
                slaModal.hidden = false;
                slaModal.querySelector('input')?.focus();
            });

            slaModal.querySelectorAll('[data-dk-sla-dismiss]').forEach(function (control) {
                control.addEventListener('click', closeSlaModal);
            });

            /*
             * A row states its own default in a data attribute rather than in
             * the chip beside it. Reading it back out of that chip meant
             * parsing rendered text — a number plus a unit, in whatever locale
             * the page is in — and the reset button is not the place to find
             * out that parseInt disagrees with a translation.
             */
            function markTuned(row) {
                const input = row.querySelector('[data-dk-sla-input]');

                if (input) {
                    row.classList.toggle('is-tuned', input.value !== row.dataset.default);
                }
            }

            slaModal.querySelectorAll('[data-dk-sla-row]').forEach(function (row) {
                row.querySelector('[data-dk-sla-input]')?.addEventListener('input', function () {
                    markTuned(row);
                });
            });

            slaModal.querySelector('[data-dk-sla-reset]')?.addEventListener('click', function () {
                slaModal.querySelectorAll('[data-dk-sla-row]').forEach(function (row) {
                    const input = row.querySelector('[data-dk-sla-input]');

                    if (input && row.dataset.default) {
                        input.value = row.dataset.default;
                    }

                    markTuned(row);
                });
            });

            slaModal.querySelector('[data-dk-sla-form]')?.addEventListener('submit', function (event) {
                event.preventDefault();

                const form = event.currentTarget;
                const save = form.querySelector('[data-dk-sla-save]');
                const error = form.querySelector('[data-dk-sla-error]');

                if (error) {
                    error.hidden = true;
                }

                if (save) {
                    save.disabled = true;
                }

                fetch(urls.sla, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                    body: new FormData(form),
                })
                    .then(function (response) {
                        return response.json().then(function (payload) {
                            if (!response.ok) {
                                throw new Error(payload.message || 'HTTP ' + response.status);
                            }

                            return payload;
                        });
                    })
                    .then(function (payload) {
                        closeSlaModal();
                        notify('success', strings.saved, payload.message);

                        /*
                         * The targets colour every queue row and the footer
                         * line under them. Rather than repaint each of those
                         * from here, the page is asked for again with the
                         * numbers it now has.
                         */
                        window.location.reload();
                    })
                    .catch(function (failure) {
                        if (error) {
                            error.textContent = failure.message;
                            error.hidden = false;
                        }
                    })
                    .finally(function () {
                        if (save) {
                            save.disabled = false;
                        }
                    });
            });
        }

        /* Escape closes whichever dialog is open. */
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            closeRangeModal();
            closeSlaModal();
        });
    });
})(jQuery);
