"use strict";

/**
 * Admin shell — the navigation column's behaviour.
 *
 * Vanilla on purpose: this runs on every admin page and must not wait on
 * jQuery or CoreUI to boot.
 *
 * The collapsed state lives in two places. localStorage is the working copy;
 * the cookie exists so PHP can render the right width on the first paint
 * instead of the sidebar flashing open and snapping shut.
 */
(function () {
    var MOBILE = 991.98;

    var side = document.querySelector('[data-dk-side]');

    if (!side) {
        return;
    }

    var scrim = document.querySelector('[data-dk-side-scrim]');
    var storageKey = side.dataset.stateKey || 'dk.admin.sidebar';
    var cookieName = side.dataset.stateCookie || 'dk_admin_sidebar';
    var scrollKey = side.dataset.scrollKey || 'dk.admin.sidebar.scroll';

    var state = { collapsed: side.classList.contains('is-collapsed'), groups: {} };

    /* ── State ──────────────────────────────────────────────────────────── */

    function read() {
        try {
            var raw = window.localStorage.getItem(storageKey);

            if (!raw) {
                return;
            }

            var parsed = JSON.parse(raw);

            if (parsed && typeof parsed === 'object') {
                state.collapsed = !!parsed.collapsed;
                state.groups = parsed.groups && typeof parsed.groups === 'object' ? parsed.groups : {};
            }
        } catch (error) {
            /* A private window or blocked storage is not a reason to stop. */
        }
    }

    function write() {
        var payload = JSON.stringify(state);

        try {
            window.localStorage.setItem(storageKey, payload);
        } catch (error) {
            /* Ignore: the cookie below is what the server actually reads. */
        }

        try {
            document.cookie = cookieName + '=' + encodeURIComponent(payload) +
                ';path=/;max-age=31536000;samesite=lax' +
                (window.location.protocol === 'https:' ? ';secure' : '');
        } catch (error) {
            /* Nothing further to try. */
        }
    }

    /*
     * The same question the stylesheet asks, asked the same way.
     *
     * window.innerWidth is not the viewport once a page overflows sideways.
     * The transaction list is wider than a phone, and on that page innerWidth
     * measured 1412 while the viewport was 375 and
     * `@media (max-width: 991.98px)` — the media query the drawer rules live
     * in — still matched. So the header button took the desktop branch and
     * collapsed a column that was already translated off-screen: one tap,
     * nothing on screen, nothing logged. Measured in Chrome device emulation
     * at 375px against the rendered admin page.
     *
     * matchMedia reads the same viewport the cascade does, which is the only
     * way to keep the two from disagreeing.
     */
    var mobileQuery = typeof window.matchMedia === 'function'
        ? window.matchMedia('(max-width: ' + MOBILE + 'px)')
        : null;

    function isMobile() {
        return mobileQuery
            ? mobileQuery.matches
            : document.documentElement.clientWidth <= MOBILE;
    }

    /* ── Collapse ───────────────────────────────────────────────────────── */

    function applyCollapsed() {
        side.classList.toggle('is-collapsed', state.collapsed);

        var edge = side.querySelector('[data-dk-side-toggle]');

        if (!edge) {
            return;
        }

        edge.setAttribute('aria-expanded', state.collapsed ? 'false' : 'true');

        var label = state.collapsed
            ? edge.dataset.expandLabel || edge.getAttribute('title')
            : edge.dataset.collapseLabel || edge.getAttribute('title');

        if (label) {
            edge.setAttribute('aria-label', label);
            edge.setAttribute('title', label);
        }
    }

    /*
     * The label fade belongs to the expand, and to nothing else.
     *
     * On the 72px rail the labels are display:none, so widening the column
     * brings a hundred-odd of them back into flow in one frame and they need
     * to arrive gently. Every other moment in the column's life — a page load
     * above all — already has them on screen, and the stylesheet no longer
     * fades them unless this class says the rail is opening.
     *
     * The timer outlives both the fade (380ms) and the width transition
     * (420ms), and a second toggle inside that window replaces it rather than
     * letting the first one strip the class mid-animation.
     */
    var expandTimer = null;

    function flagExpanding() {
        side.classList.add('is-expanding');

        if (expandTimer !== null) {
            window.clearTimeout(expandTimer);
        }

        expandTimer = window.setTimeout(function () {
            expandTimer = null;
            side.classList.remove('is-expanding');
        }, 500);
    }

    function toggleCollapsed() {
        var expanding = state.collapsed;

        state.collapsed = !state.collapsed;
        applyCollapsed();

        if (expanding) {
            flagExpanding();
        }

        write();
    }

    /* ── Groups ─────────────────────────────────────────────────────────── */

    /*
     * Group keys are slugs the config produces — "user-accounts". Anything
     * else came from a hand-edited localStorage value, and interpolating it
     * into a selector is how a stray quote becomes a DOMException.
     */
    function isGroupKey(key) {
        return typeof key === 'string' && /^[a-z0-9-]+$/i.test(key);
    }

    function setGroup(key, open, persist) {
        if (!isGroupKey(key)) {
            return;
        }

        var wrap = side.querySelector('[data-dk-side-group-wrap="' + key + '"]');

        if (!wrap) {
            return;
        }

        wrap.classList.toggle('is-open', open);

        var head = wrap.querySelector('[data-dk-side-group]');

        if (head) {
            head.setAttribute('aria-expanded', open ? 'true' : 'false');
            head.classList.toggle('is-open', open);
        }

        /*
         * The children stay in the DOM through the collapse so it can
         * animate, so a shut group has to be hidden rather than merely
         * short. Without this a keyboard tabs through eight invisible links
         * on the way to the next section, and a screen reader reads them out.
         * The blade renders the same two attributes on the first paint; this
         * is what keeps them true afterwards.
         */
        var subs = wrap.querySelector('.dk-side__subs');

        if (subs) {
            if (open) {
                subs.removeAttribute('aria-hidden');
            } else {
                subs.setAttribute('aria-hidden', 'true');
            }

            Array.prototype.forEach.call(subs.querySelectorAll('a'), function (link) {
                if (open) {
                    link.removeAttribute('tabindex');
                } else {
                    link.setAttribute('tabindex', '-1');
                }
            });
        }

        state.groups[key] = open;

        if (persist !== false) {
            write();
        }
    }

    /*
     * One section at a time.
     *
     * Opening a group used to leave every other open group where it was, so
     * the column grew until the row being reached for had been pushed off the
     * bottom. Closing the others is what makes the click land somewhere
     * predictable - and it is done before the new group opens, so the two
     * heights change together in one frame rather than the list jumping down
     * and then back up.
     */
    function openOnly(key) {
        Object.keys(state.groups).forEach(function (other) {
            if (other !== key && state.groups[other]) {
                setGroup(other, false, false);
            }
        });

        setGroup(key, true, false);
        write();
    }

    side.addEventListener('click', function (event) {
        if (event.target.closest('[data-dk-side-toggle]')) {
            event.preventDefault();
            toggleCollapsed();

            return;
        }

        if (event.target.closest('[data-dk-side-close]')) {
            event.preventDefault();
            closeDrawer();

            return;
        }

        var head = event.target.closest('[data-dk-side-group]');

        if (!head) {
            return;
        }

        event.preventDefault();

        var key = head.dataset.dkSideGroup;
        var wrap = head.closest('[data-dk-side-group-wrap]');

        /*
         * Opening a group on the collapsed rail would have nowhere to draw
         * itself, so the rail expands first and the group opens into it.
         */
        if (state.collapsed && !isMobile()) {
            state.collapsed = false;
            applyCollapsed();
            flagExpanding();
            openOnly(key);

            return;
        }

        if (wrap && wrap.classList.contains('is-open')) {
            setGroup(key, false);

            return;
        }

        openOnly(key);
    });

    /* ── Mobile drawer ──────────────────────────────────────────────────── */

    var lastFocused = null;

    /*
     * What Tab can actually reach inside the drawer.
     *
     * A shut group keeps its children in the DOM so the collapse can
     * animate, and they still have a layout box — so offsetParent alone
     * counted seventy-odd links the browser will never focus. The trap wraps
     * on the first and last of this list, so including them sent Shift+Tab
     * to somewhere Tab could not return from.
     */
    function focusable() {
        return Array.prototype.filter.call(
            side.querySelectorAll('a[href], button:not([disabled])'),
            function (el) {
                return el.offsetParent !== null && el.getAttribute('tabindex') !== '-1';
            }
        );
    }

    function openDrawer() {
        lastFocused = document.activeElement;
        side.classList.add('is-open');
        document.body.style.overflow = 'hidden';

        if (scrim) {
            scrim.hidden = false;
        }

        var first = focusable()[0];

        if (first) {
            first.focus();
        }
    }

    function closeDrawer() {
        if (!side.classList.contains('is-open')) {
            return;
        }

        side.classList.remove('is-open');
        document.body.style.overflow = '';

        if (scrim) {
            scrim.hidden = true;
        }

        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    }

    if (scrim) {
        scrim.addEventListener('click', closeDrawer);
    }

    document.addEventListener('keydown', function (event) {
        if (!side.classList.contains('is-open')) {
            return;
        }

        if (event.key === 'Escape') {
            closeDrawer();

            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        /* Keep Tab inside the drawer while it covers the page. */
        var items = focusable();

        if (items.length === 0) {
            return;
        }

        var first = items[0];
        var last = items[items.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    /*
     * The header's own toggle, still under the pre-redesign button id, so the
     * current header keeps working until it is rewritten in its turn.
     */
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('#admin-header-sidebar-toggle, [data-dk-side-open]');

        if (!trigger) {
            return;
        }

        event.preventDefault();

        if (!isMobile()) {
            toggleCollapsed();
        } else if (side.classList.contains('is-open')) {
            closeDrawer();
        } else {
            openDrawer();
        }
    });

    /*
     * Ctrl/Cmd+K used to focus the search box from here. The box is a command
     * palette now and admin-palette.js binds the shortcut beside the panel it
     * opens, so the header's keyboard layer lives in one file.
     */

    /* Leaving mobile with the drawer open would strand the scroll lock. */
    window.addEventListener('resize', function () {
        if (!isMobile()) {
            closeDrawer();
        }
    });

    /* ── Boot ───────────────────────────────────────────────────────────── */

    /*
     * Reconcile the rendered state with the stored one, without animating it.
     *
     * A transition does not fire on the first style computation, so the page
     * paints at whatever width the cookie asked PHP for and nothing moves.
     * What does move is this: localStorage is the working copy and can
     * disagree with the cookie - a second tab collapsed the column, the
     * cookie was dropped, storage was cleared - and the class flip below
     * would then animate 192px of sidebar and eighteen groups on a page the
     * operator has only just opened.
     *
     * So the correction happens with motion off, and motion comes back on
     * the next frame, once the browser has committed the corrected state.
     */
    function withoutMotion(apply) {
        side.classList.add('no-motion');
        document.body.classList.add('no-motion');

        var restore = function () {
            side.classList.remove('no-motion');
            document.body.classList.remove('no-motion');
        };

        /*
         * try/finally, because the alternative fails silently and for good.
         *
         * The keys replayed below come out of localStorage, which anyone can
         * hand-edit; one containing a quote makes the group selector throw a
         * DOMException. Without this the class added above is never scheduled
         * for removal, and motion is dead for the life of the page — no
         * collapse, no sub-menus, nothing to see and nothing logged.
         *
         * The scheduling belongs inside the finally for the same reason: an
         * exception would otherwise carry straight past it.
         */
        try {
            apply();
        } finally {
            /* Read a layout property so the state above is committed first. */
            void side.offsetWidth;

            if (typeof window.requestAnimationFrame === 'function') {
                window.requestAnimationFrame(function () {
                    window.requestAnimationFrame(restore);
                });
            } else {
                restore();
            }
        }
    }

    read();

    /*
     * Which group is open is the server's answer, not this script's.
     *
     * The blade opens exactly one - the group holding the current page, or
     * failing that the one the operator left open - so replaying storage over
     * the top of it could only ever disagree with what has already been
     * painted, and disagreeing after paint is the jump. The rendered column is
     * read into state instead, so the cookie stays in step with the screen.
     */
    function adoptRenderedGroups() {
        state.groups = {};

        Array.prototype.forEach.call(side.querySelectorAll('[data-dk-side-group-wrap]'), function (wrap) {
            var key = wrap.dataset.dkSideGroupWrap;

            if (isGroupKey(key) && wrap.classList.contains('is-open')) {
                state.groups[key] = true;
            }
        });
    }

    /*
     * Remember where the column is scrolled to, for the next page to restore.
     *
     * The restoring is done by an inline script beside the navigation itself,
     * because it has to be true before the first frame and this file is
     * fetched too late to promise that. All that is left here is recording it.
     *
     * Session storage, so two tabs scrolled to different places stay that way,
     * and written on a frame rather than on every scroll event, which fires
     * far faster than there is any reason to write.
     */
    function rememberScrolling() {
        var nav = side.querySelector('.dk-side__nav');

        if (!nav) {
            return;
        }

        var queued = false;

        var save = function () {
            queued = false;

            try {
                window.sessionStorage.setItem(scrollKey, String(Math.round(nav.scrollTop)));
            } catch (error) {
                /* Private windows keep the column at the top. Nothing to do. */
            }
        };

        nav.addEventListener('scroll', function () {
            if (queued) {
                return;
            }

            queued = true;

            if (typeof window.requestAnimationFrame === 'function') {
                window.requestAnimationFrame(save);
            } else {
                save();
            }
        }, { passive: true });
    }

    withoutMotion(function () {
        applyCollapsed();
        adoptRenderedGroups();
    });

    rememberScrolling();
    write();
})();
