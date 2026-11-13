"use strict";

/**
 * Admin → Security → IP deny list: the address panel.
 *
 * The panel is server-rendered — every state it shows (held back, refusing,
 * lapsed, permanent) is a branch in _drawer.blade.php, and duplicating those
 * branches in JavaScript is how the two drift apart. So this file does three
 * things and nothing else: fetch that HTML, show it, and put focus back where
 * it came from on close.
 *
 * The row's link is a real <a href> to the same route, so the panel has a
 * URL, opens in a new tab, and still works with scripting off. Only the
 * plain left click is intercepted.
 */
(function () {
    var drawer = document.querySelector('[data-ipa-drawer]');
    var backdrop = document.querySelector('[data-ipa-backdrop]');

    if (!drawer || !backdrop) {
        return;
    }

    var body = drawer.querySelector('[data-ipa-drawer-body]');
    var opener = null;
    var request = 0;

    function message(text) {
        body.innerHTML = '';

        var note = document.createElement('p');
        note.className = 'ipa-drawer__loading';
        note.textContent = text;
        body.appendChild(note);
    }

    function open(ip, trigger) {
        opener = trigger || null;

        drawer.classList.add('is-open');
        backdrop.classList.add('is-open');
        document.addEventListener('keydown', onKey);

        message(drawer.getAttribute('data-ipa-loading') || '…');

        /*
         * Every open gets a ticket. A slow first request must not paint
         * itself over the panel a later click already filled — the operator
         * would be reading one address under another one's heading.
         */
        request += 1;

        var ticket = request;
        var url = drawer.getAttribute('data-ipa-url');

        if (!url) {
            message(drawer.getAttribute('data-ipa-failed') || '');
            return;
        }

        fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'ip=' + encodeURIComponent(ip), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.text();
            })
            .then(function (html) {
                if (ticket !== request) {
                    return;
                }

                body.innerHTML = html;

                var close = body.querySelector('[data-ipa-close]');

                if (close) {
                    close.focus();
                }
            })
            .catch(function () {
                if (ticket === request) {
                    message(drawer.getAttribute('data-ipa-failed') || '');
                }
            });
    }

    function close() {
        drawer.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        document.removeEventListener('keydown', onKey);

        if (opener && typeof opener.focus === 'function') {
            opener.focus();
        }

        opener = null;
    }

    function onKey(event) {
        if (event.key === 'Escape') {
            close();
        }
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest ? event.target.closest('[data-ipa-open]') : null;

        if (trigger) {
            /* Let a middle-click or a modified click open the real URL. */
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
                return;
            }

            event.preventDefault();
            open(trigger.getAttribute('data-ipa-open'), trigger);

            return;
        }

        if (event.target.closest && event.target.closest('[data-ipa-close]')) {
            close();

            return;
        }

        if (event.target === backdrop) {
            close();
        }
    });
})();
