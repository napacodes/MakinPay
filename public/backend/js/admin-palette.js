"use strict";

/**
 * The admin command palette, and the header's keyboard layer.
 *
 * One panel under the search trigger that searches pages, users and
 * transactions through the global search endpoint. The markup is the shell
 * in _palette.blade.php; every row here is built with createElement and
 * textContent, so nothing the endpoint returns is ever parsed as markup.
 *
 * Vanilla on purpose: it runs on every admin page and must not wait on
 * jQuery or CoreUI to boot. The three shortcuts live here too - Ctrl/Cmd+K
 * and "/" open the palette, N opens the Create menu - because a shortcut
 * belongs beside the panel it opens.
 */
(function () {
    var root = document.querySelector('[data-dk-palette]');

    if (!root) {
        return;
    }

    var panel = root.querySelector('[data-dk-palette-panel]');
    var input = root.querySelector('[data-dk-palette-input]');
    var results = root.querySelector('[data-dk-palette-results]');
    var spinner = root.querySelector('[data-dk-palette-spinner]');
    var scopeName = root.querySelector('[data-dk-palette-scope-name]');
    var chips = Array.prototype.slice.call(root.querySelectorAll('[data-dk-palette-scope]'));
    var openers = Array.prototype.slice.call(document.querySelectorAll('[data-dk-palette-open]'));
    var trigger = document.getElementById('admin-header-search-trigger');
    var createWrap = document.querySelector('[data-dk-create]');

    if (!panel || !input || !results) {
        return;
    }

    var labels = root.dataset;
    var searchUrl = labels.searchUrl || '';
    var recentKey = labels.recentKey || 'dk.admin.palette.recent';

    var DEBOUNCE_MS = 250;
    var RECENT_MAX = 5;
    var LIMIT = 5;

    var state = {
        open: false,
        scope: chips.length ? chips[0].dataset.dkPaletteScope : 'all',
        query: '',
        items: [],
        selected: -1,
        request: null,
        timer: null,
        opener: null
    };

    /* ── Recent ─────────────────────────────────────────────────────────── */

    /*
     * The last few things opened from here, kept per admin in localStorage.
     * Only the fields a row needs are stored, and every field is checked on
     * the way back in: storage is user-writable, and a row is drawn from it.
     */
    function isItem(item) {
        return !!item
            && typeof item === 'object'
            && typeof item.id === 'string'
            && typeof item.title === 'string'
            && typeof item.url === 'string';
    }

    function readRecent() {
        try {
            var parsed = JSON.parse(window.localStorage.getItem(recentKey) || '[]');

            return Array.isArray(parsed) ? parsed.filter(isItem).slice(0, RECENT_MAX) : [];
        } catch (error) {
            return [];
        }
    }

    function remember(item) {
        var list = readRecent().filter(function (other) {
            return other.id !== item.id;
        });

        list.unshift({
            id: item.id,
            type: typeof item.type === 'string' ? item.type : '',
            title: item.title,
            sub: typeof item.sub === 'string' ? item.sub : '',
            url: item.url,
            icon: typeof item.icon === 'string' ? item.icon : null,
            initials: typeof item.initials === 'string' ? item.initials : null,
            badge: null,
            tone: 'neutral'
        });

        try {
            window.localStorage.setItem(recentKey, JSON.stringify(list.slice(0, RECENT_MAX)));
        } catch (error) {
            /* A private window forgets; nothing else to do. */
        }
    }

    /* ── Open / close ───────────────────────────────────────────────────── */

    function setExpanded(open) {
        openers.forEach(function (button) {
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    function isVisible(element) {
        return !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
    }

    function open(opener) {
        if (state.open) {
            input.focus();

            return;
        }

        state.open = true;
        state.opener = opener || trigger;
        panel.hidden = false;
        root.classList.add('is-open');
        setExpanded(true);
        input.value = state.query;
        fetchResults();
        input.focus();
        input.select();
    }

    function close(restoreFocus) {
        if (!state.open) {
            return;
        }

        state.open = false;
        panel.hidden = true;
        root.classList.remove('is-open');
        setExpanded(false);
        abortRequest();

        var opener = state.opener;

        /* Back to the control that opened it, if that control is still on screen. */
        if (restoreFocus && opener && typeof opener.focus === 'function' && isVisible(opener)) {
            opener.focus();
        }
    }

    function toggle(opener) {
        if (state.open) {
            close(true);
        } else {
            open(opener);
        }
    }

    /* ── Fetch ──────────────────────────────────────────────────────────── */

    function setLoading(loading) {
        if (spinner) {
            spinner.hidden = !loading;
        }

        panel.classList.toggle('is-loading', loading);
    }

    function abortRequest() {
        if (state.request && typeof state.request.abort === 'function') {
            state.request.abort();
        }

        state.request = null;
        setLoading(false);
    }

    /*
     * One request in flight at a time. A slower answer to an earlier keystroke
     * must never paint over a faster answer to the current one, so the
     * previous request is cancelled rather than raced.
     */
    function fetchResults() {
        abortRequest();

        var query = state.query.trim();
        var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
        var url = searchUrl
            + (searchUrl.indexOf('?') === -1 ? '?' : '&')
            + 'q=' + encodeURIComponent(query)
            + '&scope=' + encodeURIComponent(state.scope)
            + '&limit=' + LIMIT;

        state.request = controller;
        setLoading(true);

        window.fetch(url, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            if (!response.ok) {
                throw new Error(String(response.status));
            }

            return response.json();
        }).then(function (data) {
            if (state.request !== controller) {
                return;
            }

            render(Array.isArray(data.groups) ? data.groups : [], query);
        }).catch(function (error) {
            if ((error && error.name === 'AbortError') || state.request !== controller) {
                return;
            }

            renderUnavailable();
        }).then(function () {
            if (state.request === controller) {
                state.request = null;
                setLoading(false);
            }
        });
    }

    /* ── Render ─────────────────────────────────────────────────────────── */

    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (typeof text === 'string') {
            node.textContent = text;
        }

        return node;
    }

    function currentNoun() {
        var chip = chips.filter(function (candidate) {
            return candidate.dataset.dkPaletteScope === state.scope;
        })[0];

        return chip ? (chip.dataset.noun || chip.textContent.trim()) : '';
    }

    function tileFor(item) {
        var tile = el('span', 'dk-palette__tile');

        tile.setAttribute('aria-hidden', 'true');

        if (item.initials) {
            tile.classList.add('is-initials');
            tile.textContent = String(item.initials).slice(0, 2);
        } else if (item.icon) {
            var glyph = el('span', 'dk-palette__tile-icon');

            /* The icon is a URL our own endpoint produced; quotes cannot be part of one. */
            glyph.style.setProperty('--dk-icon', 'url("' + String(item.icon).replace(/["'\\]/g, '') + '")');
            tile.appendChild(glyph);
        }

        return tile;
    }

    function row(item, index) {
        var link = el('a', 'dk-palette__row');

        link.href = item.url;
        link.id = 'dk-palette-option-' + index;
        link.setAttribute('role', 'option');
        link.setAttribute('aria-selected', 'false');
        link.appendChild(tileFor(item));

        var text = el('span', 'dk-palette__text');

        text.appendChild(el('span', 'dk-palette__title', item.title));

        if (typeof item.sub === 'string' && item.sub !== '') {
            text.appendChild(el('span', 'dk-palette__sub', item.sub));
        }

        link.appendChild(text);

        var end = el('span', 'dk-palette__end');

        if (typeof item.badge === 'string' && item.badge !== '') {
            var badge = el('span', 'dk-palette__badge', item.badge);
            var tone = /^(warn|success|danger|brand|neutral)$/.test(String(item.tone)) ? item.tone : 'neutral';

            badge.classList.add('is-' + tone);
            end.appendChild(badge);
        }

        var enter = el('kbd', 'dk-palette__enter', '\u21B5');

        enter.setAttribute('dir', 'ltr');
        enter.setAttribute('aria-hidden', 'true');
        end.appendChild(enter);
        link.appendChild(end);

        link.addEventListener('mousemove', function () {
            if (state.selected !== index) {
                state.selected = index;
                syncSelection();
            }
        });

        link.addEventListener('click', function () {
            remember(item);
            close(false);
        });

        return link;
    }

    function render(groups, query) {
        results.textContent = '';
        state.items = [];

        var all = groups.slice();

        if (query === '') {
            var recent = readRecent();

            if (recent.length) {
                all.unshift({ key: 'recent', label: labels.labelRecent || '', count: recent.length, items: recent });
            }
        }

        if (!all.length) {
            renderEmpty(query);
            state.selected = -1;
            syncSelection();

            return;
        }

        all.forEach(function (group) {
            var items = Array.isArray(group.items) ? group.items.filter(isItem) : [];

            if (!items.length) {
                return;
            }

            var head = el('div', 'dk-palette__group');

            head.appendChild(el('span', 'dk-palette__group-label', String(group.label || '')));
            head.appendChild(el('span', 'dk-palette__group-count', String(items.length)));
            results.appendChild(head);

            items.forEach(function (item) {
                results.appendChild(row(item, state.items.length));
                state.items.push(item);
            });
        });

        state.selected = state.items.length ? 0 : -1;
        syncSelection();
    }

    function renderEmpty(query) {
        var box = el('div', 'dk-palette__empty');

        if (query === '') {
            box.appendChild(el('strong', null, labels.labelStart || ''));
        } else {
            var text = (labels.labelNoMatch || '')
                .split(':query').join(query)
                .split(':scope').join(currentNoun());

            box.appendChild(el('strong', null, text));
            box.appendChild(el('span', null, labels.labelNoMatchHint || ''));
        }

        results.appendChild(box);
    }

    function renderUnavailable() {
        results.textContent = '';
        state.items = [];
        state.selected = -1;

        var box = el('div', 'dk-palette__empty');

        box.appendChild(el('strong', null, labels.labelUnavailable || ''));
        results.appendChild(box);
        syncSelection();
    }

    function syncSelection() {
        var rows = results.querySelectorAll('.dk-palette__row');

        Array.prototype.forEach.call(rows, function (candidate, index) {
            var on = index === state.selected;

            candidate.classList.toggle('is-selected', on);
            candidate.setAttribute('aria-selected', on ? 'true' : 'false');
        });

        var current = state.selected >= 0 ? rows[state.selected] : null;

        if (current) {
            input.setAttribute('aria-activedescendant', current.id);

            if (typeof current.scrollIntoView === 'function') {
                current.scrollIntoView({ block: 'nearest' });
            }
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function move(step) {
        if (!state.items.length) {
            return;
        }

        state.selected = (state.selected + step + state.items.length) % state.items.length;
        syncSelection();
    }

    function openSelected() {
        var item = state.items[state.selected];

        if (!item) {
            return;
        }

        remember(item);
        close(false);
        window.location.href = item.url;
    }

    /* ── Scopes ─────────────────────────────────────────────────────────── */

    function setScope(key) {
        var known = chips.some(function (chip) {
            return chip.dataset.dkPaletteScope === key;
        });

        if (!known) {
            return;
        }

        state.scope = key;

        chips.forEach(function (chip) {
            var on = chip.dataset.dkPaletteScope === key;

            chip.classList.toggle('is-active', on);
            chip.setAttribute('aria-selected', on ? 'true' : 'false');
        });

        if (scopeName) {
            scopeName.textContent = currentNoun();
        }

        fetchResults();
    }

    function cycleScope(step) {
        if (chips.length < 2) {
            return;
        }

        var index = chips.map(function (chip) {
            return chip.dataset.dkPaletteScope;
        }).indexOf(state.scope);

        setScope(chips[(index + step + chips.length) % chips.length].dataset.dkPaletteScope);
    }

    chips.forEach(function (chip) {
        chip.addEventListener('click', function (event) {
            event.preventDefault();
            setScope(chip.dataset.dkPaletteScope);
            input.focus();
        });
    });

    /* ── Input ──────────────────────────────────────────────────────────── */

    input.addEventListener('input', function () {
        state.query = input.value;

        if (state.timer) {
            window.clearTimeout(state.timer);
        }

        state.timer = window.setTimeout(fetchResults, DEBOUNCE_MS);
    });

    input.addEventListener('keydown', function (event) {
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                move(1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                move(-1);
                break;
            case 'Enter':
                if (state.selected >= 0) {
                    event.preventDefault();
                    openSelected();
                }

                break;
            case 'Tab':
                event.preventDefault();
                cycleScope(event.shiftKey ? -1 : 1);
                break;
            case 'Escape':
                event.preventDefault();
                close(true);
                break;
            default:
                break;
        }
    });

    openers.forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            toggle(button);
        });
    });

    /* Outside the panel and its openers, a click closes it. */
    document.addEventListener('click', function (event) {
        if (!state.open || root.contains(event.target)) {
            return;
        }

        var onOpener = openers.some(function (button) {
            return button.contains(event.target);
        });

        if (!onOpener) {
            close(false);
        }
    });

    /* ── Shortcuts ──────────────────────────────────────────────────────── */

    function inField(target) {
        if (!target || typeof target.tagName !== 'string') {
            return false;
        }

        if (target.isContentEditable) {
            return true;
        }

        var tag = target.tagName;

        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
    }

    /*
     * The Create menu is CoreUI's dropdown; opening it from a key means asking
     * CoreUI, so its outside-click and Escape handling stay in charge.
     */
    function openCreate() {
        if (!createWrap || !window.coreui || !window.coreui.Dropdown) {
            return false;
        }

        var toggleButton = createWrap.querySelector('[data-coreui-toggle="dropdown"]');

        if (!toggleButton) {
            return false;
        }

        window.coreui.Dropdown.getOrCreateInstance(toggleButton).show();

        return true;
    }

    document.addEventListener('keydown', function (event) {
        if (event.defaultPrevented) {
            return;
        }

        if ((event.key === 'k' || event.key === 'K') && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            toggle(trigger);

            return;
        }

        if (event.key === 'Escape' && state.open) {
            close(true);

            return;
        }

        /* Single keys only count while nothing is being typed into. */
        if (event.ctrlKey || event.metaKey || event.altKey || state.open || inField(event.target)) {
            return;
        }

        if (event.key === '/') {
            event.preventDefault();
            open(trigger);

            return;
        }

        if ((event.key === 'n' || event.key === 'N') && openCreate()) {
            event.preventDefault();
        }
    });
})();
