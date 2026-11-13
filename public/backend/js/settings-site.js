"use strict";

/**
 * Site Settings — progressive enhancement only.
 *
 * Every section still posts its own form to its own URL; nothing below is
 * required for a setting to save. What it adds is what the page could not
 * answer before: which settings exist (search), which ones you have touched
 * (dirty tracking and the save bar), and what a switch is about to cost you
 * (the confirmation dialog).
 */

/* ── Secret fields ─────────────────────────────────────────────────────────
 * Secrets start empty; the eye button only reveals what is being typed now,
 * never a stored value — none is ever sent to the browser.
 */
(function () {
    document.addEventListener("click", function (event) {
        const button = event.target.closest("[data-settings-secret-reveal]");

        if (!button) {
            return;
        }

        const field = button.closest(".settings-site-secret-field");
        const input = field ? field.querySelector(".settings-site-secret-field__input") : null;
        const icon = button.querySelector("i");

        if (!input) {
            return;
        }

        const revealed = input.getAttribute("type") === "text";

        input.setAttribute("type", revealed ? "password" : "text");

        if (icon) {
            icon.className = revealed ? "fa-solid fa-eye" : "fa-solid fa-eye-slash";
        }
    });
})();

/* ── Independent scroll containers ─────────────────────────────────────────
 * The rail and the body scroll on their own. Below 992px the page scrolls
 * normally again, so a wheel that has run one of them to its end hands the
 * rest of the gesture back to the window instead of dead-ending.
 */
(function () {
    const scrollAreaSelector = ".ss-body, .ss-nav";

    function canScroll(element) {
        return element.scrollHeight > element.clientHeight + 1;
    }

    function isAtTop(element) {
        return element.scrollTop <= 0;
    }

    function isAtBottom(element) {
        return Math.ceil(element.scrollTop + element.clientHeight) >= element.scrollHeight;
    }

    /**
     * Whether this element scrolls its own content, rather than merely being
     * taller than its box. Overflowing is not the same as scrolling: almost
     * every wrapper on the page overflows something.
     */
    function scrollsItsOwnContent(element) {
        const overflowY = window.getComputedStyle(element).overflowY;

        return (overflowY === "auto" || overflowY === "scroll") && canScroll(element);
    }

    /**
     * Something between the pointer and the scroll area that can still take
     * the gesture — a textarea holding more text than it shows, say.
     *
     * Without this the walk went straight past it to `.ss-body`, and if the
     * body happened to be at its end the wheel was cancelled and handed to
     * the window: the KYC consent box had 161px left to scroll and moved for
     * nobody, because the section it sits in is short enough to reach the
     * bottom of the body on the way down to it.
     */
    function innerScrollerHasRoom(target, scrollArea, deltaY) {
        let node = target instanceof Element ? target : null;

        while (node && node !== scrollArea) {
            if (scrollsItsOwnContent(node) && (deltaY < 0 ? !isAtTop(node) : !isAtBottom(node))) {
                return true;
            }

            node = node.parentElement;
        }

        return false;
    }

    document.addEventListener("wheel", function (event) {
        const scrollArea = event.target.closest(scrollAreaSelector);

        if (!scrollArea || !canScroll(scrollArea) || event.deltaY === 0) {
            return;
        }

        /* The innermost thing that can scroll gets the gesture, which is what
           the browser would do on its own if we never cancelled anything. */
        if (innerScrollerHasRoom(event.target, scrollArea, event.deltaY)) {
            return;
        }

        const shouldScrollPage =
            (event.deltaY < 0 && isAtTop(scrollArea)) ||
            (event.deltaY > 0 && isAtBottom(scrollArea));

        if (!shouldScrollPage) {
            return;
        }

        event.preventDefault();
        window.scrollBy({
            top: event.deltaY,
            left: 0,
            behavior: "auto",
        });
    }, { passive: false });
})();

/* ── Storage link repair ───────────────────────────────────────────────── */
(function () {
    document.querySelectorAll("[data-storage-link-form]").forEach(function (form) {
        form.addEventListener("submit", function () {
            const button = form.querySelector("[data-storage-link-button]");
            const label = form.querySelector("[data-storage-link-button-label]");
            const loadingLabel = button ? button.dataset.loadingLabel : "";

            form.classList.add("is-processing");

            if (button) {
                button.disabled = true;
                button.classList.add("disabled");
                button.setAttribute("aria-busy", "true");
            }

            if (label && loadingLabel) {
                label.textContent = loadingLabel;
            }
        });
    });
})();

/* ── The page: search, dirty tracking, save bar, shortcuts ─────────────── */
(function () {
    const page = document.querySelector(".ss-page");

    if (!page) {
        return;
    }

    const body = document.getElementById("ss-body");
    const nav = document.getElementById("settings-site-tablist");
    const savebar = document.getElementById("ss-savebar");
    const saveState = document.querySelector("[data-ss-save-state]");
    const searchInput = document.getElementById("ss-search-input");
    const results = document.getElementById("ss-results");
    const searchClear = document.querySelector("[data-ss-search-clear]");

    const forms = Array.from(page.querySelectorAll(".ss-form"));
    const rows = Array.from(page.querySelectorAll(".ss-row"));
    const initial = new WeakMap();
    const savedLabel = saveState ? saveState.textContent.trim() : "";
    const copy = savebar ? savebar.dataset : {};

    function controlsOf(form) {
        return Array.from(form.elements).filter(function (element) {
            return element.name && element.name !== "_token" && element.name !== "_method";
        });
    }

    function readValue(control) {
        if (control.type === "checkbox" || control.type === "radio") {
            return control.checked ? "1" : "0";
        }

        if (control.type === "file") {
            return control.files && control.files.length ? "file:" + control.files[0].name : "";
        }

        return control.value;
    }

    forms.forEach(function (form) {
        controlsOf(form).forEach(function (control) {
            initial.set(control, readValue(control));
        });
    });

    function phrase(name, count, fallback) {
        const template = copy[name] || fallback;

        return template.replace(":count", String(count));
    }

    /* ── Dirty state ──────────────────────────────────────────────────── */

    /**
     * Which rows differ from what the server rendered, and which controls the
     * browser already knows are invalid.
     *
     * Recomputed from the values rather than incremented on each keystroke: a
     * value typed back to what it was is not a change, and a counter would
     * never learn that. Counted in rows, not controls, because a row like
     * "Screen Lock" owns both a toggle and a number and is one decision.
     */
    function scan() {
        const dirtyRows = new Set();
        const perSection = new Map();
        const invalid = [];

        forms.forEach(function (form) {
            const section = form.dataset.ssSection;

            controlsOf(form).forEach(function (control) {
                const row = control.closest(".ss-row");

                if (initial.has(control) && readValue(control) !== initial.get(control) && row) {
                    dirtyRows.add(row);
                }

                if (!control.checkValidity()) {
                    invalid.push({ section: section, control: control });
                }
            });
        });

        rows.forEach(function (row) {
            const isDirty = dirtyRows.has(row);

            row.classList.toggle("is-dirty", isDirty);

            const defaultLine = row.querySelector("[data-ss-default-line]");

            if (defaultLine && row.dataset.ssDefault !== undefined) {
                const control = row.querySelector("input[name], select[name], textarea[name]:not([type=hidden])");
                const now = control ? readValue(control) : null;

                defaultLine.hidden = now === null || now === row.dataset.ssDefault;
            }

            if (isDirty) {
                const key = row.dataset.ssSection;

                perSection.set(key, (perSection.get(key) || 0) + 1);
            }
        });

        return { dirty: perSection, invalid: invalid };
    }

    function sectionLabel(sectionKey) {
        const item = nav ? nav.querySelector('[data-ss-section="' + sectionKey + '"] .ss-nav__title') : null;

        return item ? item.textContent.trim() : sectionKey;
    }

    function activeSectionKey() {
        const pane = body ? body.querySelector(".tab-pane.active") : null;

        return pane ? pane.id.replace("v-pills-", "") : null;
    }

    function render(state) {
        const total = Array.from(state.dirty.values()).reduce(function (sum, n) {
            return sum + n;
        }, 0);

        if (nav) {
            nav.querySelectorAll("[data-ss-section]").forEach(function (item) {
                const flag = item.querySelector("[data-ss-nav-flag]");

                if (flag) {
                    flag.classList.toggle("is-dirty", state.dirty.has(item.dataset.ssSection));
                }
            });
        }

        if (saveState) {
            saveState.classList.remove("ph-state--success", "ph-state--warning", "ph-state--danger");

            if (state.invalid.length) {
                saveState.classList.add("ph-state--danger");
                saveState.textContent = state.invalid.length === 1
                    ? phrase("ssAttentionOne", 1, ":count field needs attention")
                    : phrase("ssAttentionMany", state.invalid.length, ":count fields need attention");
            } else if (total) {
                saveState.classList.add("ph-state--warning");
                saveState.textContent = total === 1
                    ? phrase("ssUnsavedOne", 1, ":count unsaved change")
                    : phrase("ssUnsavedMany", total, ":count unsaved changes");
            } else {
                saveState.classList.add("ph-state--success");
                saveState.textContent = savedLabel;
            }
        }

        if (!savebar) {
            return;
        }

        if (!total && !state.invalid.length) {
            savebar.hidden = true;

            return;
        }

        const title = savebar.querySelector("[data-ss-savebar-title]");
        const detail = savebar.querySelector("[data-ss-savebar-detail]");
        const saveButton = savebar.querySelector("[data-ss-save]");
        const current = activeSectionKey();
        const blocking = state.invalid.filter(function (entry) {
            return entry.section === current;
        });

        savebar.hidden = false;
        savebar.classList.toggle("is-blocked", blocking.length > 0);

        if (title) {
            title.textContent = blocking.length
                ? (blocking.length === 1
                    ? phrase("ssBlockedOne", 1, ":count field needs attention before saving")
                    : phrase("ssBlockedMany", blocking.length, ":count fields need attention before saving"))
                : (total === 1
                    ? phrase("ssUnsavedOne", 1, ":count unsaved change")
                    : phrase("ssUnsavedMany", total, ":count unsaved changes"));
        }

        if (detail) {
            const parts = [];

            state.dirty.forEach(function (count, key) {
                parts.push(count + " " + (copy.ssIn || "in") + " " + sectionLabel(key));
            });

            const elsewhere = Array.from(state.dirty.keys()).some(function (key) {
                return key !== current;
            });

            detail.textContent = parts.join(" · ")
                + " — "
                + (elsewhere
                    ? (copy.ssOthers || "Save applies to the section you are viewing.")
                    : (copy.ssNothing || "Nothing is applied until you save."));
        }

        if (saveButton) {
            saveButton.disabled = blocking.length > 0;
        }
    }

    let frame = null;

    function refresh() {
        if (frame) {
            return;
        }

        frame = window.requestAnimationFrame(function () {
            frame = null;
            render(scan());
        });
    }

    page.addEventListener("input", refresh);
    page.addEventListener("change", refresh);

    /* ── Rows: reset to default, colour presets ───────────────────────── */

    page.addEventListener("click", function (event) {
        const reset = event.target.closest("[data-ss-reset]");

        if (reset) {
            const row = reset.closest(".ss-row");
            const value = row ? row.dataset.ssDefault : undefined;
            const control = row
                ? row.querySelector('input[type="checkbox"][name], select[name], textarea[name], input[type="text"][name], input[type="number"][name]')
                : null;

            if (control && value !== undefined) {
                if (control.type === "checkbox") {
                    control.checked = value === "1";
                } else {
                    control.value = value;
                }

                const picker = row.querySelector("[data-settings-color-target]");

                if (picker && /^#[0-9A-Fa-f]{6}$/.test(value)) {
                    picker.value = value;
                }

                refresh();
            }

            return;
        }

        const preset = event.target.closest("[data-ss-color-preset]");

        if (!preset) {
            return;
        }

        const hex = preset.dataset.ssColorPreset;
        const key = preset.dataset.ssColorFor;
        const text = document.querySelector('[data-settings-color-input="' + key + '"]');
        const picker = document.querySelector('[data-settings-color-target="' + key + '"]');

        if (text) {
            text.value = hex;
        }

        if (picker) {
            picker.value = hex;
        }

        syncColorPresets(key, hex);
        refresh();
    });

    /**
     * Ring whichever preset the value now matches, and no other.
     *
     * Only the click used to set this, so typing a hex or dragging the native
     * picker left the ring where it was — a dot claiming to be the current
     * colour while the field beside it said something else.
     */
    function syncColorPresets(key, value) {
        const wanted = String(value || "").trim().toUpperCase();

        document.querySelectorAll('[data-ss-color-for="' + key + '"]').forEach(function (dot) {
            dot.classList.toggle("is-selected", String(dot.dataset.ssColorPreset).toUpperCase() === wanted);
        });
    }

    function syncEveryColorPreset() {
        document.querySelectorAll("[data-settings-color-input]").forEach(function (text) {
            syncColorPresets(text.dataset.settingsColorInput, text.value);
        });
    }

    /* The value can also change by typing, by the native picker, and by
       Discard putting every form back to what the server sent. */
    page.addEventListener("input", function (event) {
        const text = event.target.closest("[data-settings-color-input]");

        if (text) {
            syncColorPresets(text.dataset.settingsColorInput, text.value);

            return;
        }

        const picker = event.target.closest("[data-settings-color-target]");

        if (picker) {
            syncColorPresets(picker.dataset.settingsColorTarget, picker.value);
        }
    });

    page.addEventListener("reset", function () {
        window.requestAnimationFrame(syncEveryColorPreset);
    });

    /* ── Save bar ─────────────────────────────────────────────────────── */

    function activeForm() {
        const pane = body ? body.querySelector(".tab-pane.active") : null;

        return pane ? pane.querySelector(".ss-form") : null;
    }

    function save() {
        const form = activeForm();

        if (!form || !form.reportValidity()) {
            return;
        }

        form.requestSubmit();
    }

    if (savebar) {
        const discard = savebar.querySelector("[data-ss-discard]");
        const saveButton = savebar.querySelector("[data-ss-save]");
        const shortcut = savebar.querySelector("[data-ss-save-shortcut]");

        if (shortcut && /Mac|iPhone|iPad/.test(window.navigator.platform || "")) {
            shortcut.textContent = "⌘ S";
        }

        if (discard) {
            discard.addEventListener("click", function () {
                /* reset() restores each control's markup value, which is
                   exactly what the server rendered — the same baseline the
                   dirty scan compares against. */
                forms.forEach(function (form) {
                    form.reset();
                });

                page.querySelectorAll("[data-settings-color-input]").forEach(function (text) {
                    const picker = document.querySelector('[data-settings-color-target="' + text.dataset.settingsColorInput + '"]');

                    if (picker && /^#[0-9A-Fa-f]{6}$/.test(text.value)) {
                        picker.value = text.value;
                    }
                });

                refresh();
            });
        }

        if (saveButton) {
            saveButton.addEventListener("click", save);
        }
    }

    /* ── Scrolling to a group or a row ────────────────────────────────── */

    function scrollTo(element) {
        if (!element || !body) {
            return;
        }

        const pane = element.closest(".tab-pane");
        const head = pane ? pane.querySelector(".ss-section-head") : null;
        const offset = (head ? head.offsetHeight : 0) + 16;
        const top = body.scrollTop
            + element.getBoundingClientRect().top
            - body.getBoundingClientRect().top
            - offset;

        body.scrollTo({ top: Math.max(0, top), behavior: "smooth" });
    }

    page.addEventListener("click", function (event) {
        const jump = event.target.closest("[data-ss-jump]");

        if (jump) {
            scrollTo(document.getElementById(jump.dataset.ssJump));
        }
    });

    /* A dialogue sending the reader to the field it is about. It may be open
       over a different section entirely — the Upload Limits chip sits in the
       page header — so the section is named alongside the field. */
    document.addEventListener("click", function (event) {
        const goTo = event.target.closest("[data-ss-goto-field]");

        if (!goTo) {
            return;
        }

        const entry = {
            key: goTo.dataset.ssGotoField,
            sectionKey: goTo.dataset.ssGotoSection,
        };

        /*
         * These buttons live in a dialogue and dismiss it on the way out, and
         * a dialogue hands focus back to whatever opened it — here the chip up
         * in the page header. Jumping first meant landing on the right row
         * with the caret two hundred pixels above it, in a button, so the
         * first thing typed went nowhere. So: wait for the dialogue to finish
         * closing, then jump.
         */
        const dialog = goTo.closest(".modal");

        if (!dialog) {
            openField(entry);

            return;
        }

        let jumped = false;

        function jump() {
            if (jumped) {
                return;
            }

            jumped = true;

            openField(entry, function (control) {
                if (!control) {
                    return;
                }

                /*
                 * CoreUI hands focus back to whatever opened the dialogue,
                 * and it does so a beat after `hidden` — measured at 160ms,
                 * which is the backdrop's own fade. Rather than guess that
                 * number, take the focus back the one time it moves away.
                 */
                function reclaim(event) {
                    stop();

                    if (control.isConnected && event.target !== control) {
                        control.focus({ preventScroll: true });
                    }
                }

                function stop() {
                    document.removeEventListener("focusin", reclaim, true);
                }

                document.addEventListener("focusin", reclaim, true);

                /* Only the dialogue's own restore is worth taking back. A
                   click the reader makes a second later is theirs. */
                window.setTimeout(stop, 400);
            });
        }

        dialog.addEventListener("hidden.coreui.modal", jump, { once: true });
        dialog.addEventListener("hidden.bs.modal", jump, { once: true });

        /* If neither event arrives the jump still has to happen. */
        window.setTimeout(jump, 600);
    });

    /* ── The head, once it is holding the top ─────────────────────────
     *
     * Cards slide underneath it, and both are white, so the row that has
     * just emerged from under the head reads as part of the head — a
     * field's validation warning was taken for a header caption. The 1px
     * ring is 5% black, too faint to separate a moving surface from a
     * fixed one, so the head takes a short shadow while it is holding.
     *
     * The head is the first thing in the pane and pins at top: 0, so it is
     * holding exactly when the body has been scrolled at all. An
     * IntersectionObserver was tried first and cannot tell: the head sits
     * on the scrollport's top edge at rest too, so it reads as clipped
     * from the very first frame.
     */
    let headIsStuck = null;
    let headIsRaised = false;

    /*
     * Two states, not one.
     *
     * `is-stuck` is the shadow and the plate behind, and it belongs to the
     * first pixel of scroll — the head is holding the top from then on.
     *
     * `is-raised` drops the head's top padding so the tile lifts to the line
     * the rail's search box sits on. Nothing is hidden and nothing shrinks —
     * the head simply gives up the 14px of air above its first row.
     *
     * The gap between the two thresholds has to be wider than those 14px,
     * and that is not a matter of taste. The head is in the flow, so the
     * change shortens the content, and the browser's scroll anchoring moves
     * `scrollTop` by the same amount to hold the content still — which is
     * the behaviour we want, but it means the position lands somewhere new
     * the instant the class changes. Thresholds closer together than the
     * height given up send the two states chasing each other: raise, the
     * position drops below the lower threshold, lower it again, the position
     * climbs past the upper one, and round it goes on any small scroll.
     *
     * 48 and 16 leave 32 between them, comfortably clear of 14.
     */
    const RAISE_AT = 48;
    const LOWER_AT = 16;

    function syncStuckHead() {
        if (body === null) {
            return;
        }

        const scrolled = body.scrollTop;
        const stuck = scrolled > 0;

        /* A section with barely anything to scroll never raises: the head
           would lift and settle again within one flick of the wheel. */
        const worthRaising = body.scrollHeight - body.clientHeight > 160;

        let raised = headIsRaised;

        if (!worthRaising || scrolled < LOWER_AT) {
            raised = false;
        } else if (scrolled > RAISE_AT) {
            raised = true;
        }

        if (stuck === headIsStuck && raised === headIsRaised) {
            return;
        }

        headIsStuck = stuck;
        headIsRaised = raised;

        page.querySelectorAll(".ss-section-head").forEach(function (head) {
            head.classList.toggle("is-stuck", stuck);
            head.classList.toggle("is-raised", raised);
        });
    }

    if (body) {
        body.addEventListener("scroll", syncStuckHead, { passive: true });
        syncStuckHead();
    }

    /* ── Search ───────────────────────────────────────────────────────── */

    let index = [];

    try {
        const raw = document.getElementById("ss-search-index");

        index = raw ? JSON.parse(raw.textContent) : [];
    } catch (error) {
        index = [];
    }

    function escapeHtml(value) {
        const node = document.createElement("span");

        node.textContent = value;

        return node.innerHTML;
    }

    function matches(query) {
        const needle = query.toLowerCase();

        return index.filter(function (entry) {
            return (entry.label + " " + entry.key + " " + entry.hint + " " + entry.section + " " + entry.group)
                .toLowerCase()
                .indexOf(needle) !== -1;
        }).slice(0, 10);
    }

    /**
     * The control a reader sent to a field should be typing into.
     *
     * Scoped to the control column on purpose: a selector list returns
     * whatever comes first in the document, and a row's left column opens
     * with a Reset button that is hidden until the value differs from its
     * default. Unscoped, this picked that button, and focusing a hidden
     * element does nothing — silently, which is how it survived a review.
     */
    function controlOf(row) {
        return row.querySelector(
            ".ss-row__control input:not([type=hidden]):not([disabled]),"
            + " .ss-row__control select:not([disabled]),"
            + " .ss-row__control textarea:not([disabled])"
        );
    }

    /**
     * @param {{key: string, sectionKey: string}} entry
     * @param {function(Element|null): void} [then] Run once the row is framed.
     */
    function openField(entry, then) {
        const tab = nav ? nav.querySelector('[data-ss-section="' + entry.sectionKey + '"]') : null;

        if (tab && !tab.classList.contains("active")) {
            tab.click();
        }

        /*
         * The rail is the map, so it has to show where the jump landed. A
         * search result can move you thirteen sections down the list while
         * the rail stays at the top, and the row you asked for then appears
         * under a list that still points somewhere else.
         *
         * `nearest` scrolls only when it has to, so clicking a rail item —
         * which was visible by definition — moves nothing.
         */
        if (tab) {
            tab.scrollIntoView({ block: "nearest", inline: "nearest" });
        }

        window.requestAnimationFrame(function () {
            const row = document.getElementById("field-" + entry.key);

            scrollTo(row);

            if (!row) {
                if (then) {
                    then(null);
                }

                return;
            }

            row.classList.add("is-found");
            window.setTimeout(function () {
                row.classList.remove("is-found");
            }, 1800);

            /* Sent to a field, so put the caret in it. `preventScroll`
               because scrollTo() above has already framed the row. */
            const control = controlOf(row);

            if (control) {
                control.focus({ preventScroll: true });
            }

            if (then) {
                then(control);
            }
        });
    }

    function closeResults() {
        if (results) {
            results.hidden = true;
            results.innerHTML = "";
        }

        if (nav) {
            nav.hidden = false;
        }

        if (searchClear) {
            searchClear.hidden = true;
        }
    }

    function renderResults(query) {
        if (!results) {
            return;
        }

        if (query === "") {
            closeResults();

            return;
        }

        const found = matches(query);

        results.hidden = false;

        if (nav) {
            nav.hidden = true;
        }

        if (searchClear) {
            searchClear.hidden = false;
        }

        if (!found.length) {
            results.innerHTML = '<p class="ss-results__empty">'
                + escapeHtml(results.dataset.ssEmpty || "No setting matches")
                + " “" + escapeHtml(query) + "”.</p>";

            return;
        }

        results.innerHTML = '<span class="ss-results__caption">'
            + found.length + " " + escapeHtml(results.dataset.ssCaption || "matching settings")
            + "</span>"
            + found.map(function (entry, position) {
                return '<button type="button" class="ss-results__item" data-ss-result="' + position + '">'
                    + '<span class="ss-results__text">'
                    + '<span class="ss-results__title">' + escapeHtml(entry.label) + "</span>"
                    + '<span class="ss-results__sub">' + escapeHtml(entry.section)
                    + (entry.group ? " · " + escapeHtml(entry.group) : "")
                    + "</span></span></button>";
            }).join("");

        results.querySelectorAll("[data-ss-result]").forEach(function (button) {
            button.addEventListener("click", function () {
                const entry = found[Number(button.dataset.ssResult)];

                if (searchInput) {
                    searchInput.value = "";
                }

                closeResults();
                openField(entry);
            });
        });
    }

    if (searchInput) {
        searchInput.addEventListener("input", function () {
            renderResults(searchInput.value.trim());
        });

        searchInput.addEventListener("keydown", function (event) {
            if (event.key !== "Escape") {
                return;
            }

            searchInput.value = "";
            closeResults();
        });
    }

    if (searchClear) {
        searchClear.addEventListener("click", function () {
            if (searchInput) {
                searchInput.value = "";
                searchInput.focus();
            }

            closeResults();
        });
    }

    /* ── Keyboard ─────────────────────────────────────────────────────── */

    document.addEventListener("keydown", function (event) {
        const typing = event.target instanceof Element
            && event.target.matches('input, textarea, select, [contenteditable="true"]');

        if (event.key === "/" && !typing && searchInput) {
            event.preventDefault();
            searchInput.focus();

            return;
        }

        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "s") {
            event.preventDefault();
            save();
        }
    });

    /* Switching section re-renders the bar: what blocks Save is the section
       being looked at, not whichever one happened to be invalid first. */
    if (nav) {
        nav.addEventListener("click", function (event) {
            if (!event.target.closest("[data-ss-section]")) {
                return;
            }

            /*
             * Every pane shares one scroller, so without this the new section
             * opens at whatever depth the last one was left at — 900px down a
             * section you have never seen, with a sticky head above it giving
             * no hint that there is anything above.
             *
             * Set before the tab changes, so search can scroll to a field
             * afterwards without fighting this.
             */
            if (body) {
                body.scrollTop = 0;
            }

            window.setTimeout(refresh, 0);
        });
    }

    refresh();
})();

/* ── Confirming a switch that can lock you out ─────────────────────────────
 * Maintenance Mode, Force HTTPS and Development Mode all arrive here. The
 * switch is flipped back off the moment it is touched and only goes on again
 * once the dialog is confirmed, so a stray click never leaves it enabled.
 *
 * Confirming does not save; it hands the change to the save bar like any
 * other edit.
 */
(function () {
    const modalElement = document.querySelector("[data-settings-enable-warning-modal]");
    const modalTitle = modalElement ? modalElement.querySelector("[data-settings-warning-title]") : null;
    const modalMessage = modalElement ? modalElement.querySelector("[data-settings-warning-message]") : null;
    const modalSecret = modalElement ? modalElement.querySelector("[data-settings-warning-secret]") : null;
    const recoverySection = modalElement ? modalElement.querySelector("[data-settings-warning-recovery]") : null;
    const recoveryUrl = modalElement ? modalElement.querySelector("[data-settings-warning-recovery-url]") : null;
    const ackWrap = modalElement ? modalElement.querySelector("[data-settings-warning-ack-wrap]") : null;
    const ackInput = modalElement ? modalElement.querySelector("[data-settings-warning-ack]") : null;
    const ackLabel = modalElement ? modalElement.querySelector("[data-settings-warning-ack-label]") : null;
    const modalConfirmButton = modalElement ? modalElement.querySelector("[data-settings-enable-warning-confirm]") : null;
    const modalConfirmLabel = modalElement ? modalElement.querySelector("[data-settings-enable-warning-confirm-label]") : null;
    const copyRecoveryButton = modalElement ? modalElement.querySelector("[data-settings-copy-recovery-url]") : null;
    const copyRecoveryLabel = modalElement ? modalElement.querySelector("[data-settings-copy-recovery-label]") : null;
    const defaultCopyLabel = copyRecoveryLabel ? copyRecoveryLabel.textContent : "";
    let pendingSwitch = null;

    /*
     * Switches whose confirmation has just been accepted.
     *
     * Turning one back on has to tell the save bar, and the save bar counts
     * values rather than clicks, so the only honest signal is a change event.
     * That event also reaches this switch's own listener, which would read it
     * as a fresh attempt and reopen the dialog — the switch then never came
     * on at all. One entry here says "this one is already answered".
     */
    const answered = new WeakSet();

    const secretInput = document.querySelector('input[name="secret_key"]');
    const secretForm = secretInput ? secretInput.closest("form") : null;
    const secretConfig = modalElement ? modalElement.dataset : {};
    const secretPattern = new RegExp(secretConfig.settingsSecretPattern || "^[A-Za-z0-9._~-]+$");
    const secretMin = parseInt(secretConfig.settingsSecretMin || "4", 10);
    const secretMax = parseInt(secretConfig.settingsSecretMax || "64", 10);
    let secretWarning = null;

    function getWarningModal() {
        if (!modalElement || !window.coreui || !window.coreui.Modal) {
            return null;
        }

        return window.coreui.Modal.getOrCreateInstance(modalElement);
    }

    function currentSecretKey() {
        const secretValue = secretInput ? secretInput.value.trim() : "";

        return secretValue || (modalSecret ? modalSecret.textContent.trim() : "");
    }

    function secretWarningNode() {
        if (secretWarning || !secretInput) {
            return secretWarning;
        }

        const anchor = secretInput.closest(".settings-site-copy-field") || secretInput;

        secretWarning = document.createElement("div");
        secretWarning.className = "invalid-feedback d-block settings-site-secret-warning";
        secretWarning.setAttribute("role", "alert");
        secretWarning.hidden = true;
        anchor.parentNode.insertBefore(secretWarning, anchor.nextSibling);

        return secretWarning;
    }

    function secretProblem() {
        if (!secretInput) {
            return "";
        }

        const value = secretInput.value;

        if (value.trim() === "") {
            return secretConfig.settingsSecretRequired || "Set a Secret Key before enabling Maintenance Mode.";
        }

        const rejected = [];

        value.split("").forEach(function (character) {
            if (secretPattern.test(character)) {
                return;
            }

            const label = character.trim() === "" ? "space" : character;

            if (rejected.indexOf(label) === -1) {
                rejected.push(label);
            }
        });

        if (rejected.length) {
            return (secretConfig.settingsSecretInvalid || "These characters cannot be used: :chars")
                .replace(":chars", rejected.join("   "));
        }

        if (value.length < secretMin || value.length > secretMax) {
            return (secretConfig.settingsSecretLength || "The Secret Key must be between :min and :max characters.")
                .replace(":min", secretMin)
                .replace(":max", secretMax);
        }

        return "";
    }

    function renderSecretProblem() {
        const problem = secretProblem();
        const node = secretWarningNode();

        if (!secretInput || !node) {
            return problem;
        }

        secretInput.classList.toggle("is-invalid", problem !== "");
        node.textContent = problem;
        node.hidden = problem === "";

        return problem;
    }

    function revealSecretProblem() {
        renderSecretProblem();

        if (secretInput) {
            secretInput.focus({ preventScroll: true });
            secretInput.scrollIntoView({ block: "center", behavior: "smooth" });
        }
    }

    function currentRecoveryUrl() {
        const baseUrl = recoveryUrl ? recoveryUrl.dataset.settingsWarningBaseUrl.replace(/\/$/, "") : "";

        return baseUrl + "/" + encodeURIComponent(currentSecretKey());
    }

    function writeClipboard(value) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(value);
        }

        const textarea = document.createElement("textarea");
        textarea.value = value;
        textarea.setAttribute("readonly", "");
        textarea.style.position = "fixed";
        textarea.style.opacity = "0";
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand("copy");
        textarea.remove();

        return Promise.resolve();
    }

    function resetCopyLabel() {
        if (copyRecoveryLabel) {
            copyRecoveryLabel.textContent = defaultCopyLabel;
        }
    }

    function syncConfirmButton() {
        if (!modalConfirmButton) {
            return;
        }

        const needsAck = ackWrap ? !ackWrap.hidden : false;

        modalConfirmButton.disabled = needsAck && ackInput ? !ackInput.checked : false;
    }

    function fillWarningModal(switchInput) {
        const wantsRecovery = switchInput.dataset.settingsEnableWarningRecovery === "1";
        const ackText = switchInput.dataset.settingsEnableWarningAck || "";

        if (modalTitle) {
            modalTitle.textContent = switchInput.dataset.settingsEnableWarningTitle || modalTitle.textContent;
        }

        if (modalMessage) {
            modalMessage.textContent = switchInput.dataset.settingsEnableWarning || modalMessage.textContent;
        }

        if (modalConfirmLabel) {
            modalConfirmLabel.textContent = switchInput.dataset.settingsEnableWarningConfirm || modalConfirmLabel.textContent;
        }

        if (recoverySection) {
            recoverySection.hidden = !wantsRecovery;
        }

        if (modalSecret) {
            modalSecret.textContent = currentSecretKey();
        }

        if (ackWrap) {
            ackWrap.hidden = ackText === "";
        }

        if (ackLabel && ackText !== "") {
            ackLabel.textContent = ackText;
        }

        if (ackInput) {
            ackInput.checked = false;
        }

        syncConfirmButton();
        resetCopyLabel();
    }

    document.querySelectorAll("[data-settings-enable-warning]").forEach(function (switchInput) {
        switchInput.addEventListener("change", function () {
            if (!switchInput.checked) {
                return;
            }

            if (answered.has(switchInput)) {
                answered.delete(switchInput);

                return;
            }

            /*
             * Only the switch that offers a recovery URL depends on the secret
             * key. A key that cannot survive a URL makes that link unusable,
             * and the site can only be taken down once — refuse to even offer
             * the confirmation until the key is fixed.
             */
            if (switchInput.dataset.settingsEnableWarningRecovery === "1" && renderSecretProblem() !== "") {
                switchInput.checked = false;
                revealSecretProblem();

                return;
            }

            switchInput.checked = false;
            pendingSwitch = switchInput;
            fillWarningModal(switchInput);

            const warningModal = getWarningModal();

            if (warningModal) {
                warningModal.show();

                return;
            }

            answered.add(pendingSwitch);
            pendingSwitch.checked = true;
            pendingSwitch.dispatchEvent(new Event("change", { bubbles: true }));
            pendingSwitch = null;
        });
    });

    if (ackInput) {
        ackInput.addEventListener("change", syncConfirmButton);
    }

    if (modalConfirmButton) {
        modalConfirmButton.addEventListener("click", function () {
            const warningModal = getWarningModal();

            if (pendingSwitch) {
                const confirmed = pendingSwitch;

                pendingSwitch = null;
                answered.add(confirmed);
                confirmed.checked = true;
                confirmed.focus({ preventScroll: true });
                /* The save bar counts values, not clicks — tell it this moved. */
                confirmed.dispatchEvent(new Event("change", { bubbles: true }));
            }

            if (warningModal) {
                warningModal.hide();
            }
        });
    }

    if (copyRecoveryButton) {
        copyRecoveryButton.addEventListener("click", function () {
            const recoveryUrlValue = currentRecoveryUrl();

            if (!currentSecretKey()) {
                return;
            }

            writeClipboard(recoveryUrlValue).then(function () {
                if (copyRecoveryLabel) {
                    copyRecoveryLabel.textContent = copyRecoveryButton.dataset.settingsCopySuccess || defaultCopyLabel;
                }
            });
        });
    }

    if (modalElement) {
        modalElement.addEventListener("hidden.coreui.modal", function () {
            pendingSwitch = null;
            resetCopyLabel();
        });
    }

    if (secretInput) {
        secretInput.addEventListener("input", renderSecretProblem);
        secretInput.addEventListener("blur", renderSecretProblem);
    }

    if (secretForm) {
        secretForm.addEventListener("submit", function (event) {
            if (renderSecretProblem() === "") {
                return;
            }

            event.preventDefault();
            revealSecretProblem();
        });
    }
})();

/* ── Image fields ──────────────────────────────────────────────────────────
 * Three states, one secondary button.
 *
 *   stored  — what the server rendered.
 *   staged  — a file is picked and will be uploaded on save.
 *   reset   — the packaged default will be restored on save.
 *
 * The button reads "Reset" only from `stored`; from the other two it reads
 * "Undo", because from there the useful move is always to come back. A picker
 * with no way out is how someone ends up saving the wrong logo.
 */
(function () {
    const widgets = Array.from(document.querySelectorAll("[data-ss-image]"));

    if (!widgets.length) {
        return;
    }

    function humanSize(bytes) {
        if (!bytes) {
            return "";
        }

        return bytes >= 1048576
            ? Math.round((bytes / 1048576) * 10) / 10 + " MB"
            : Math.max(1, Math.round(bytes / 1024)) + " KB";
    }

    function facts(file) {
        const parts = [];
        const extension = file.name.indexOf(".") > -1 ? file.name.split(".").pop() : "";

        if (extension) {
            parts.push(extension.toUpperCase());
        }

        const size = humanSize(file.size);

        if (size) {
            parts.push(size);
        }

        return parts.join(" · ");
    }

    widgets.forEach(function (widget) {
        const data = widget.dataset;
        const input = widget.querySelector("[data-ss-image-input]");
        const flag = widget.querySelector("[data-ss-image-flag]");
        const secondary = widget.querySelector("[data-ss-image-secondary]");
        const secondaryLabel = widget.querySelector("[data-ss-image-secondary-label]");
        const thumb = widget.querySelector("[data-ss-image-thumb]");
        const title = widget.querySelector("[data-ss-image-title]");
        const detail = widget.querySelector("[data-ss-image-detail]");
        const pending = widget.querySelector("[data-ss-image-pending]");

        if (!input || !flag || !secondary) {
            return;
        }

        let state = "stored";
        let objectUrl = null;

        function show(source, name, description, note) {
            thumb.style.backgroundImage = "url('" + String(source).replace(/(['"\\])/g, "\\$1") + "')";
            title.textContent = name;
            title.title = name;
            detail.textContent = description;

            pending.textContent = note || "";
            pending.hidden = !note;
        }

        function release() {
            if (objectUrl) {
                window.URL.revokeObjectURL(objectUrl);
                objectUrl = null;
            }
        }

        /** Tell the page a control changed; the save bar recounts from values. */
        function announce(element) {
            element.dispatchEvent(new Event("input", { bubbles: true }));
        }

        function render() {
            if (state === "staged") {
                secondaryLabel.textContent = data.ssImageLabelUndo;
                secondary.hidden = false;

                return;
            }

            if (state === "reset") {
                show(
                    data.ssImageDefaultSource,
                    data.ssImageDefaultName,
                    data.ssImageDefaultFacts,
                    data.ssImageNoteReset
                );
                secondaryLabel.textContent = data.ssImageLabelUndo;
                secondary.hidden = false;

                return;
            }

            show(data.ssImageSource, data.ssImageName, data.ssImageFacts, "");
            secondaryLabel.textContent = data.ssImageLabelReset;
            secondary.hidden = data.ssImageRestorable !== "1";
        }

        function toStored() {
            release();
            input.value = "";
            flag.value = "0";
            state = "stored";
            render();
            announce(flag);
        }

        input.addEventListener("change", function () {
            release();

            if (!input.files || !input.files.length) {
                state = "stored";
                flag.value = "0";
                render();

                return;
            }

            const file = input.files[0];

            objectUrl = window.URL.createObjectURL(file);
            flag.value = "0";
            state = "staged";
            show(objectUrl, file.name, facts(file), data.ssImageNoteReplace);
            render();
        });

        secondary.addEventListener("click", function () {
            if (state === "stored") {
                state = "reset";
                flag.value = "1";
                release();
                input.value = "";
                render();
                announce(flag);

                return;
            }

            toStored();
        });

        /*
         * Discard calls form.reset() on every form, which puts the file input
         * and the hidden flag back to their markup values but knows nothing
         * about the thumbnail. The event bubbles, so catch it and follow.
         */
        const form = widget.closest("form");

        if (form) {
            form.addEventListener("reset", function () {
                window.requestAnimationFrame(function () {
                    release();
                    flag.value = "0";
                    state = "stored";
                    render();
                });
            });
        }

        render();
    });
})();
