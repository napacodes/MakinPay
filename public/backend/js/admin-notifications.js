"use strict";

/**
 * The header notification panel.
 *
 * Everything is delegated from the document, because the panel replaces
 * itself wholesale whenever the poller sees the feed change - a handler
 * bound to a button would be thrown away with the markup that carried it.
 * The three routes it calls travel on the panel as data attributes for the
 * same reason.
 *
 * Nothing is fetched to filter. Every row the panel can show is already in
 * the markup, and each states whether it is unread and whether it needs
 * action, so the tabs are a matter of hiding rows.
 */
(function () {
    var container = document.getElementById('append-new-admin-notification');

    if (!container) {
        return;
    }

    var live = document.querySelector('[data-dk-notify-live]');
    var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

    /* A mark-all happened while the panel was open; the server is repainted from once it closes. */
    var dirty = false;

    function panel() {
        return container.querySelector('[data-notif-panel]');
    }

    /* ── Tabs ───────────────────────────────────────────────────────────── */

    function applyFilter(mode) {
        var current = panel();

        if (!current) {
            return;
        }

        Array.prototype.forEach.call(current.querySelectorAll('[data-notif-filter]'), function (button) {
            var on = button.getAttribute('data-notif-filter') === mode;

            button.classList.toggle('is-active', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        });

        var shown = 0;

        Array.prototype.forEach.call(current.querySelectorAll('.notification-dropdown-item--pro'), function (row) {
            var keep = mode === 'all'
                || (mode === 'unread' && row.getAttribute('data-notif-unread') === '1')
                || (mode === 'action' && row.getAttribute('data-notif-action') === '1');

            row.hidden = !keep;

            if (keep) {
                shown += 1;
            }
        });

        var empty = current.querySelector('[data-notif-none]');

        if (empty) {
            empty.hidden = shown > 0;
        }
    }

    /* ── Read state ─────────────────────────────────────────────────────── */

    function announce() {
        if (!live) {
            return;
        }

        var text = container.querySelector('[data-notif-announce]');
        var next = text ? text.textContent.trim() : '';

        if (next !== live.textContent) {
            live.textContent = next;
        }
    }

    /*
     * Settle the panel as all-read without refetching it: a repaint would
     * replace the open menu with a closed one under the operator's pointer.
     * The server is asked to repaint once the panel has closed, so the list
     * and the bell agree with it before it is opened again.
     */
    function settleAllRead(current) {
        Array.prototype.forEach.call(current.querySelectorAll('.notification-dropdown-item--pro'), function (row) {
            row.classList.remove('is-unread');
            row.setAttribute('data-notif-unread', '0');
        });

        var trigger = container.querySelector('.admin-notification-trigger');

        if (trigger) {
            trigger.classList.remove('has-unread');

            var counter = trigger.querySelector('.admin-notification-counter');

            if (counter) {
                counter.remove();
            }

            var text = trigger.querySelector('[data-notif-announce]');

            if (text) {
                text.textContent = current.getAttribute('data-notif-zero-label') || '';
            }

            trigger.setAttribute('aria-label', current.getAttribute('data-notif-zero-label') || '');
        }

        Array.prototype.forEach.call(current.querySelectorAll('[data-notif-unread-count], [data-notif-mark-all]'), function (node) {
            node.remove();
        });

        /*
         * Back to All. The panel opens on Unread, and a mark-all has just
         * emptied that tab by definition — staying on it would answer the
         * button with "Nothing here under this filter" and hide every row the
         * operator was reading a moment ago.
         */
        applyFilter('all');
        announce();
        dirty = true;
    }

    function markAllRead(button) {
        var current = panel();
        var url = current ? current.getAttribute('data-notif-mark-all-url') : null;

        if (!current || !url) {
            return;
        }

        button.disabled = true;

        window.fetch(url, { headers: headers, credentials: 'same-origin' }).then(function (response) {
            if (!response.ok) {
                throw new Error(String(response.status));
            }

            settleAllRead(current);
        }).catch(function () {
            button.disabled = false;
        });
    }

    /*
     * A row that goes somewhere is marked read on the way out; keepalive
     * lets the request finish after the page it was sent from has gone. A
     * row with nowhere to go is marked read where it stands.
     */
    function markRead(row, event) {
        var current = panel();
        var template = current ? current.getAttribute('data-notif-read-url') : null;
        var id = row.getAttribute('data-id');

        if (!template || !id) {
            return;
        }

        var href = row.getAttribute('href') || '#';
        var navigates = href !== '#' && href !== '';

        if (!navigates) {
            event.preventDefault();
        }

        try {
            window.fetch(template.replace(':id', encodeURIComponent(id)), {
                headers: headers,
                credentials: 'same-origin',
                keepalive: true
            }).then(function () {
                if (navigates) {
                    return;
                }

                row.classList.remove('is-unread');
                row.setAttribute('data-notif-unread', '0');
                dirty = true;
            }).catch(function () {
                /* The poller will say what the server thinks on its next pass. */
            });
        } catch (error) {
            /* Nothing further to try. */
        }
    }

    /* ── Events ─────────────────────────────────────────────────────────── */

    document.addEventListener('click', function (event) {
        var filter = event.target.closest('[data-notif-filter]');

        if (filter && container.contains(filter)) {
            event.preventDefault();
            applyFilter(filter.getAttribute('data-notif-filter') || 'all');

            return;
        }

        var markAll = event.target.closest('[data-notif-mark-all]');

        if (markAll && container.contains(markAll)) {
            event.preventDefault();
            markAllRead(markAll);

            return;
        }

        var row = event.target.closest('.read-notification');

        if (row && container.contains(row)) {
            markRead(row, event);
        }
    });

    /* Space and Enter already activate a tab; arrow keys move between them. */
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') {
            return;
        }

        var tab = event.target.closest('[data-notif-filter]');

        if (!tab || !container.contains(tab)) {
            return;
        }

        var tabs = Array.prototype.slice.call(tab.parentElement.querySelectorAll('[data-notif-filter]'));
        var step = event.key === 'ArrowRight' ? 1 : -1;

        event.preventDefault();
        tabs[(tabs.indexOf(tab) + step + tabs.length) % tabs.length].focus();
    });

    /* Once the panel has closed, let the poller repaint it from the server. */
    document.addEventListener('hidden.coreui.dropdown', function (event) {
        if (!dirty || !container.contains(event.target)) {
            return;
        }

        dirty = false;

        if (window.DigiKashNotificationFeed && typeof window.DigiKashNotificationFeed.sync === 'function') {
            window.DigiKashNotificationFeed.sync({ force: true });
        }
    });

    /* The poller replaces the container's contents; the count is read out when it does. */
    if (typeof window.MutationObserver === 'function') {
        new window.MutationObserver(announce).observe(container, { childList: true });
    }
})();
