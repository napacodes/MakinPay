"use strict";

/*
 * Control Panel — the Launchpad layout.
 *
 * Every module is rendered once, as a card in its section. Everything
 * else on the page — search results, the pinned dock, recents — is built
 * from those cards, so a label, an icon or a link can only ever be wrong
 * in one place.
 *
 * Four things worth knowing before changing anything here:
 *
 * 1. This page does NOT bind "/" or Ctrl/Cmd+K. admin-palette.js owns
 *    both globally, and that palette already searches admin pages. The
 *    console field searches this page's modules only; the header button
 *    and the rail tip open the palette for everything else.
 *
 * 2. The open section and the A–Z view are in the URL (?section=<code>,
 *    ?view=az), so either can be linked and bookmarked. The server picks
 *    them on first paint; this script only keeps the URL in step.
 *
 * 3. Pins and recents live in localStorage, per browser. Pins reuse the
 *    key the old layout kept stars under, so an operator's stars carry
 *    over as pins. A stored key whose module this role cannot see is
 *    kept but not shown — dropping it would lose it for good the day the
 *    permission comes back.
 *
 * 4. The rail's counts are rewritten while a search runs and restored
 *    from data-total afterwards — never parsed back out of the text, so a
 *    locale that formats numbers differently cannot corrupt them.
 */
(function () {
    var root = document.querySelector('[data-control-panel]');

    if (!root) {
        return;
    }

    var PIN_KEY = 'dk.admin.controlPanel.stars';
    var RECENT_KEY = 'dk.admin.controlPanel.recents';
    var RECENT_MAX = 5;
    var RECENT_KEEP = 20;
    var DOCK_SIZE = parseInt(root.getAttribute('data-dock-size'), 10) || 8;

    var input = root.querySelector('[data-cp-search]');
    var clear = root.querySelector('[data-cp-clear]');
    var dock = root.querySelector('[data-cp-dock]');
    var dockEmpty = root.querySelector('[data-cp-dock-empty]');
    var recentList = root.querySelector('[data-cp-recent]');
    var recentEmpty = root.querySelector('[data-cp-recent-empty]');
    var railLabel = root.querySelector('[data-cp-rail-label]');
    var az = root.querySelector('[data-cp-az]');
    var results = root.querySelector('[data-cp-results]');
    var resultList = root.querySelector('[data-cp-results-list]');
    var resultTitle = root.querySelector('[data-cp-results-title]');
    var empty = root.querySelector('[data-cp-empty]');
    var live = document.getElementById('cp-live');

    var navs = toArray(root.querySelectorAll('[data-cp-section]'));
    var panels = toArray(root.querySelectorAll('[data-cp-panel]'));
    var segButtons = toArray(root.querySelectorAll('[data-cp-view]'));
    var cards = toArray(root.querySelectorAll('[data-cp-item]'));

    var cardByKey = {};

    cards.forEach(function (card) {
        cardByKey[card.getAttribute('data-key')] = card;
    });

    var view = root.getAttribute('data-view') === 'az' ? 'az' : 'sections';
    var section = root.getAttribute('data-section') || (navs[0] ? navs[0].getAttribute('data-cp-section') : '');
    var matches = [];
    var active = -1;

    function toArray(list) {
        return Array.prototype.slice.call(list);
    }

    /* ── Storage ───────────────────────────────────────────────────────
     *
     * Every read is guarded: a private window, cleared site data or a
     * hand-edited value must leave the page working, just without
     * anyone's pins.
     */
    function readList(key) {
        try {
            var parsed = JSON.parse(window.localStorage.getItem(key) || '[]');

            return Array.isArray(parsed) ? parsed.filter(function (entry) {
                return typeof entry === 'string';
            }) : [];
        } catch (error) {
            return [];
        }
    }

    function writeList(key, list) {
        try {
            window.localStorage.setItem(key, JSON.stringify(list));
        } catch (error) {
            /* Storage full or blocked — the page keeps working unsaved. */
        }
    }

    var pins = readList(PIN_KEY);
    var recents = readList(RECENT_KEY);

    function visible(list) {
        return list.filter(function (key) {
            return Object.prototype.hasOwnProperty.call(cardByKey, key);
        });
    }

    /** Fills :placeholders in one of the strings the view translated. */
    function phrase(template, values) {
        return Object.keys(values).reduce(function (text, name) {
            return text.split(':' + name).join(String(values[name]));
        }, template || '');
    }

    function announce(text) {
        if (live) {
            live.textContent = text;
        }
    }

    /* ── Building from a card ──────────────────────────────────────────
     *
     * The dock, recents and results all show a module the same way: its
     * app icon, its name, where it lives. Each is built from the card —
     * the icon is the card's own node, cloned.
     */
    function parseKeywords(value) {
        try {
            var parsed = JSON.parse(value || '[]');

            return Array.isArray(parsed) ? parsed.map(String) : [];
        } catch (error) {
            return [];
        }
    }

    function moduleOf(key) {
        var card = cardByKey[key];
        var link = card.querySelector('[data-cp-link]');

        return {
            key: key,
            href: link ? link.getAttribute('href') : '#',
            label: card.getAttribute('data-label') || '',
            desc: card.getAttribute('data-desc') || '',
            group: card.getAttribute('data-group') || '',
            section: card.getAttribute('data-section') || '',
            sectionLabel: card.getAttribute('data-section-label') || '',
            search: card.getAttribute('data-search') || '',
            keywords: parseKeywords(card.getAttribute('data-keywords'))
        };
    }

    function iconOf(key) {
        var icon = cardByKey[key].querySelector('.cp-ai');

        return icon ? icon.cloneNode(true) : document.createElement('span');
    }

    function element(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text !== undefined) {
            node.textContent = text;
        }

        return node;
    }

    function launchLink(module, className) {
        var link = element('a', className);

        link.href = module.href;
        link.setAttribute('data-cp-link', '');
        link.setAttribute('data-key', module.key);

        return link;
    }

    /* ── Pins ──────────────────────────────────────────────────────── */

    function renderDock() {
        var shown = visible(pins).slice(0, DOCK_SIZE);

        dock.textContent = '';

        shown.forEach(function (key) {
            var module = moduleOf(key);
            var link = launchLink(module, 'cp-dock__item');

            link.title = module.label;
            link.appendChild(iconOf(key));
            link.appendChild(element('span', 'cp-dock__label', module.label));
            dock.appendChild(link);
        });

        dockEmpty.hidden = shown.length > 0;
    }

    function syncPinButtons() {
        cards.forEach(function (card) {
            var button = card.querySelector('[data-cp-pin]');

            if (!button) {
                return;
            }

            var pinned = pins.indexOf(card.getAttribute('data-key')) !== -1;

            button.setAttribute('aria-pressed', pinned ? 'true' : 'false');
            button.setAttribute('aria-label', button.getAttribute(pinned ? 'data-label-unpin' : 'data-label-pin'));
        });
    }

    function togglePin(key, button) {
        var label = cardByKey[key].getAttribute('data-label') || '';
        var at = pins.indexOf(key);

        if (at !== -1) {
            pins.splice(at, 1);
            announce(phrase(root.getAttribute('data-unpinned-one'), { module: label }));
        } else if (visible(pins).length >= DOCK_SIZE) {
            button.classList.remove('is-refused');
            void button.offsetWidth;
            button.classList.add('is-refused');
            announce(root.getAttribute('data-dock-full') || '');

            return;
        } else {
            pins.push(key);
            announce(phrase(root.getAttribute('data-pinned-one'), { module: label }));
        }

        writeList(PIN_KEY, pins);
        syncPinButtons();
        renderDock();
    }

    /* ── Recents ───────────────────────────────────────────────────── */

    function renderRecents() {
        var shown = visible(recents).slice(0, RECENT_MAX);

        recentList.textContent = '';

        shown.forEach(function (key) {
            var module = moduleOf(key);
            var link = launchLink(module, 'cp-recent__item');

            link.appendChild(iconOf(key));
            link.appendChild(element('span', 'cp-recent__name', module.label));
            link.appendChild(element('span', 'cp-recent__where', module.sectionLabel));
            recentList.appendChild(link);
        });

        recentEmpty.hidden = shown.length > 0;
    }

    function remember(key) {
        if (!key || !Object.prototype.hasOwnProperty.call(cardByKey, key)) {
            return;
        }

        recents = [key].concat(recents.filter(function (entry) {
            return entry !== key;
        })).slice(0, RECENT_KEEP);

        writeList(RECENT_KEY, recents);
    }

    /* ── Views ─────────────────────────────────────────────────────── */

    function keepUrl() {
        try {
            var url = new URL(window.location.href);

            url.searchParams.delete('section');
            url.searchParams.delete('view');

            if (view === 'az') {
                url.searchParams.set('view', 'az');
            } else if (section) {
                url.searchParams.set('section', section);
            }

            window.history.replaceState(window.history.state, '', url.toString());
        } catch (error) {
            /* An old browser without URL — the view still switches. */
        }
    }

    function paintView() {
        var browsing = input.value.trim() === '';

        panels.forEach(function (panel) {
            panel.hidden = !browsing || view !== 'sections' || panel.getAttribute('data-cp-panel') !== section;
        });

        az.hidden = !browsing || view !== 'az';

        navs.forEach(function (nav) {
            var current = browsing && view === 'sections' && nav.getAttribute('data-cp-section') === section;

            nav.setAttribute('aria-current', current ? 'true' : 'false');
        });

        segButtons.forEach(function (button) {
            button.setAttribute('aria-pressed', button.getAttribute('data-cp-view') === view ? 'true' : 'false');
        });
    }

    /*
     * Under 992px the rail is a row of tabs that scrolls sideways, and the
     * open one can sit past the edge. Scroll the row only — scrollIntoView
     * would also scroll the page to a rail that is still below the fold.
     * Measured from the rects, so it holds in RTL as well.
     */
    function revealCurrentTab() {
        var current = navs.filter(function (nav) {
            return nav.getAttribute('aria-current') === 'true';
        })[0];
        var row = current ? current.parentNode : null;

        if (!row || row.scrollWidth <= row.clientWidth) {
            return;
        }

        var offset = current.getBoundingClientRect().left - row.getBoundingClientRect().left;

        if (offset < 0 || offset + current.offsetWidth > row.clientWidth) {
            row.scrollLeft += offset - 12;
        }
    }

    function openSection(code) {
        view = 'sections';
        section = code;
        endSearch();
        keepUrl();
        revealCurrentTab();
    }

    function openView(next) {
        view = next === 'az' ? 'az' : 'sections';
        endSearch();
        keepUrl();
    }

    /* ── Search ────────────────────────────────────────────────────────
     *
     * Ranked, not merely filtered: a name that starts with the query
     * beats one that contains it, which beats a keyword, a group, the
     * description and — last — the section's name. Each result says which
     * of those it matched on, so a module that turns up for "refund"
     * explains itself.
     */
    function rank(module, needle) {
        var name = module.label.toLowerCase();
        var keyword;

        if (name.indexOf(needle) === 0) {
            return [0, root.getAttribute('data-why-name')];
        }

        if (name.indexOf(needle) !== -1) {
            return [1, root.getAttribute('data-why-name')];
        }

        keyword = module.keywords.filter(function (entry) {
            return entry.toLowerCase().indexOf(needle) !== -1;
        })[0];

        if (keyword) {
            return [2, phrase(root.getAttribute('data-why-keyword'), { keyword: keyword })];
        }

        if (module.group !== '' && module.group.toLowerCase().indexOf(needle) !== -1) {
            return [3, phrase(root.getAttribute('data-why-group'), { group: module.group })];
        }

        if (module.desc.toLowerCase().indexOf(needle) !== -1) {
            return [4, root.getAttribute('data-why-description')];
        }

        if (module.sectionLabel.toLowerCase().indexOf(needle) !== -1) {
            return [5, phrase(root.getAttribute('data-why-section'), { section: module.sectionLabel })];
        }

        /* The haystack also carries the config's English keywords, so a
           translated panel still answers to "audit" or "payments". */
        if (module.search.indexOf(needle) !== -1) {
            return [6, phrase(root.getAttribute('data-why-keyword'), { keyword: needle })];
        }

        return null;
    }

    function highlighted(label, needle) {
        var holder = element('span', 'cp-result__name');
        var at = label.toLowerCase().indexOf(needle);

        if (at === -1) {
            holder.textContent = label;

            return holder;
        }

        holder.appendChild(document.createTextNode(label.slice(0, at)));
        holder.appendChild(element('mark', '', label.slice(at, at + needle.length)));
        holder.appendChild(document.createTextNode(label.slice(at + needle.length)));

        return holder;
    }

    function resultRow(match, index, needle) {
        var module = match.module;
        var row = launchLink(module, 'cp-result');
        var text = element('span', 'cp-result__text');
        var enter = element('span', 'cp-result__enter');

        row.id = 'cp-result-' + index;
        row.setAttribute('role', 'option');
        row.setAttribute('aria-selected', 'false');

        text.appendChild(highlighted(module.label, needle));
        text.appendChild(element('span', 'cp-result__desc', module.desc));

        enter.setAttribute('aria-hidden', 'true');
        enter.appendChild(element('kbd', 'cp-kbd', '↵'));
        enter.appendChild(document.createTextNode(' ' + (root.getAttribute('data-open-label') || '')));

        row.appendChild(iconOf(module.key));
        row.appendChild(text);
        row.appendChild(element('span', 'cp-result__why', match.why));
        row.appendChild(element('span', 'cp-result__where', module.sectionLabel));
        row.appendChild(enter);

        row.addEventListener('mouseenter', function () {
            setActive(index, false);
        });

        return row;
    }

    function setActive(index, scroll) {
        var rows = toArray(resultList.children);

        active = rows.length === 0 ? -1 : Math.max(0, Math.min(index, rows.length - 1));

        rows.forEach(function (row, position) {
            var on = position === active;

            row.classList.toggle('is-active', on);
            row.setAttribute('aria-selected', on ? 'true' : 'false');
        });

        if (active === -1) {
            input.removeAttribute('aria-activedescendant');

            return;
        }

        input.setAttribute('aria-activedescendant', rows[active].id);

        if (scroll !== false && typeof rows[active].scrollIntoView === 'function') {
            rows[active].scrollIntoView({ block: 'nearest' });
        }
    }

    function paintCounts(hits) {
        navs.forEach(function (nav) {
            var count = nav.querySelector('[data-cp-count]');
            var code = nav.getAttribute('data-cp-section');
            var value = hits ? (hits[code] || 0) : parseInt(count.getAttribute('data-total'), 10) || 0;

            count.textContent = String(value);
            nav.classList.toggle('is-dim', Boolean(hits) && value === 0);
        });

        railLabel.textContent = railLabel.getAttribute(hits ? 'data-search' : 'data-browse');
    }

    function runSearch() {
        var query = input.value.trim();
        var needle = query.toLowerCase();
        var hits = {};

        clear.hidden = input.value === '';

        if (needle === '') {
            endSearch();

            return;
        }

        matches = cards.map(function (card) {
            var module = moduleOf(card.getAttribute('data-key'));
            var ranked = rank(module, needle);

            return ranked ? { module: module, score: ranked[0], why: ranked[1] } : null;
        }).filter(Boolean).sort(function (a, b) {
            return a.score - b.score || a.module.label.localeCompare(b.module.label);
        });

        matches.forEach(function (match) {
            hits[match.module.section] = (hits[match.module.section] || 0) + 1;
        });

        resultList.textContent = '';

        matches.forEach(function (match, index) {
            resultList.appendChild(resultRow(match, index, needle));
        });

        resultTitle.textContent = phrase(
            resultTitle.getAttribute(matches.length === 1 ? 'data-one' : 'data-many'),
            { count: matches.length, query: query }
        );

        empty.hidden = matches.length > 0;
        results.hidden = false;
        input.setAttribute('aria-expanded', matches.length > 0 ? 'true' : 'false');

        paintCounts(hits);
        paintView();
        setActive(0, false);

        if (live) {
            announce(phrase(live.getAttribute(matches.length === 1 ? 'data-one' : 'data-many'), { count: matches.length }));
        }
    }

    function endSearch() {
        matches = [];
        active = -1;
        resultList.textContent = '';
        results.hidden = true;
        empty.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');

        if (input.value !== '') {
            input.value = '';
        }

        clear.hidden = true;
        paintCounts(null);
        paintView();
    }

    /* ── Wiring ────────────────────────────────────────────────────── */

    input.addEventListener('input', runSearch);

    input.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' && matches.length > 0) {
            event.preventDefault();
            setActive(active + 1);
        } else if (event.key === 'ArrowUp' && matches.length > 0) {
            event.preventDefault();
            setActive(active - 1);
        } else if (event.key === 'Enter' && active !== -1 && matches[active]) {
            event.preventDefault();
            remember(matches[active].module.key);
            window.location.assign(matches[active].module.href);
        } else if (event.key === 'Escape' && input.value !== '') {
            event.preventDefault();
            endSearch();
            announce('');
        }
    });

    clear.addEventListener('click', function () {
        endSearch();
        announce('');
        input.focus();
    });

    navs.forEach(function (nav) {
        nav.addEventListener('click', function () {
            openSection(nav.getAttribute('data-cp-section'));
        });
    });

    segButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            openView(button.getAttribute('data-cp-view'));
        });
    });

    /* One listener for every launch link on the page — cards, the A–Z
       index, the dock, recents and results — and for every pin. */
    root.addEventListener('click', function (event) {
        var pin = event.target.closest('[data-cp-pin]');

        if (pin) {
            event.preventDefault();
            togglePin(pin.closest('[data-cp-item]').getAttribute('data-key'), pin);

            return;
        }

        var link = event.target.closest('[data-cp-link]');

        if (link) {
            var holder = link.closest('[data-cp-item]');

            remember(link.getAttribute('data-key') || (holder ? holder.getAttribute('data-key') : ''));
        }
    });

    /* Another tab pinned or opened something: follow it. */
    window.addEventListener('storage', function (event) {
        if (event.key === PIN_KEY) {
            pins = readList(PIN_KEY);
            syncPinButtons();
            renderDock();
        } else if (event.key === RECENT_KEY) {
            recents = readList(RECENT_KEY);
            renderRecents();
        }
    });

    syncPinButtons();
    renderDock();
    renderRecents();
    paintView();
    revealCurrentTab();
})();
