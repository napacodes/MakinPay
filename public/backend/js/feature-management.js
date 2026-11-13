"use strict";

/*
 * Feature Management — the catalog screen and the Manage screen.
 *
 * The catalog has four behaviours, each degrading on its own: the category
 * rail, the state filter, the search box, and the confirmation every toggle
 * goes through before it posts. The Manage screen is one form behind panel
 * tabs.
 *
 * Nothing here writes a sentence. Every string either screen can show is read
 * off a data attribute the blade wrote in the administrator's own locale —
 * this file used to assemble them ("Disable " + label + "?"), which put an
 * English dialog on top of an Arabic page.
 */
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        const page = document.querySelector('[data-feature-catalog]');

        if (page) {
            initCatalog(page);
            initConfirm(page);
        }

        const addons = document.querySelector('[data-addon-catalog]');

        if (addons) {
            initAddonCatalog(addons);
        }

        const hint = document.querySelector('[data-addon-hint]');

        if (hint) {
            initFirstRunHint(hint);
        }

        initAddonDrawers();
        initAddonInstaller();
        initAddonOperation();

        const manage = document.querySelector('[data-feature-manage]');

        if (manage) {
            initManage(manage);
        }
    });

    function initCatalog(page) {
        const cards = Array.from(page.querySelectorAll('[data-feature-card]'));
        const empty = page.querySelector('[data-feature-empty]');
        const grid = page.querySelector('[data-feature-grid]');

        if (cards.length === 0) {
            return;
        }

        const state = {
            category: page.dataset.activeCategory || '',
            filter: 'all',
            query: '',
        };

        function matchesFilter(card) {
            if (state.filter === 'all') {
                return true;
            }

            if (state.filter === 'core') {
                return card.dataset.featureCore === '1';
            }

            return card.dataset.featureState === state.filter;
        }

        function clearSearch() {
            page.querySelectorAll('[data-feature-search-input]').forEach(function (input) {
                input.value = '';
            });
        }

        function apply() {
            const inCategory = cards.filter(function (card) {
                return card.dataset.featureCategory === state.category;
            });

            let shown = 0;

            cards.forEach(function (card) {
                const visible = card.dataset.featureCategory === state.category
                    && matchesFilter(card)
                    && (state.query === '' || (card.dataset.featureSearch || '').indexOf(state.query) !== -1);

                card.hidden = !visible;

                if (visible) {
                    shown += 1;
                }
            });

            if (empty) {
                empty.hidden = shown > 0;
            }

            if (grid) {
                grid.hidden = shown === 0;
            }

            /*
             * Counted off the cards of the open category rather than rendered
             * once on the server: the counts say what each filter would return
             * right now, and a toggle moves a feature between Enabled and
             * Disabled without a reload.
             */
            page.querySelectorAll('[data-feature-filter-count]').forEach(function (node) {
                const key = node.dataset.featureFilterCount;

                node.textContent = inCategory.filter(function (card) {
                    if (key === 'all') {
                        return true;
                    }

                    if (key === 'core') {
                        return card.dataset.featureCore === '1';
                    }

                    return card.dataset.featureState === key;
                }).length;
            });

            page.querySelectorAll('[data-feature-filter]').forEach(function (button) {
                const on = button.dataset.featureFilter === state.filter;

                button.classList.toggle('is-active', on);
                button.setAttribute('aria-pressed', on ? 'true' : 'false');
            });

            page.querySelectorAll('[data-feature-category]').forEach(function (button) {
                const on = button.dataset.featureCategory === state.category;

                button.classList.toggle('is-active', on);
                button.setAttribute('aria-selected', on ? 'true' : 'false');
            });

            page.querySelectorAll('[data-feature-category-head]').forEach(function (head) {
                head.hidden = head.dataset.featureCategoryHead !== state.category;
            });
        }

        page.addEventListener('click', function (event) {
            const category = event.target.closest('[data-feature-category]');

            if (category) {
                state.category = category.dataset.featureCategory;
                /* A filter belongs to the category it was chosen in. */
                state.filter = 'all';
                state.query = '';
                clearSearch();
                apply();

                return;
            }

            const filter = event.target.closest('[data-feature-filter]');

            if (filter) {
                state.filter = filter.dataset.featureFilter;
                apply();

                return;
            }

            if (event.target.closest('[data-feature-clear]')) {
                state.filter = 'all';
                state.query = '';
                clearSearch();
                apply();
            }
        });

        page.addEventListener('input', function (event) {
            const search = event.target.closest('[data-feature-search-input]');

            if (!search) {
                return;
            }

            state.query = search.value.trim().toLowerCase();
            apply();
        });

        apply();
    }

    /*
     * The Manage screen.
     *
     * Panel rules are tabs over a single form: every panel's inputs stay in the
     * DOM whichever tab is open, so a panel the administrator never looked at
     * still posts the values it arrived with. Showing and hiding is all the
     * tabs do.
     *
     * Like the catalog above, this writes no sentences of its own — the tab
     * badges, the country summary and the save-card copy are read off data
     * attributes the blade rendered in the administrator's locale.
     */
    function initManage(root) {
        const form = root.querySelector('[data-feature-form]');

        if (!form) {
            return;
        }

        function showPanel(panel) {
            root.querySelectorAll('[data-feature-pane]').forEach(function (pane) {
                pane.hidden = pane.dataset.featurePane !== panel;
            });

            root.querySelectorAll('[data-feature-tab]').forEach(function (tab) {
                const on = tab.dataset.featureTab === panel;

                tab.classList.toggle('is-active', on);
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }

        function ruleInput(panel, field) {
            return form.querySelector(
                '[data-feature-rule-input="' + field + '"][data-feature-panel="' + panel + '"]'
            );
        }

        function isOn(panel, field) {
            const input = ruleInput(panel, field);

            return !!input && input.checked;
        }

        function paintRuleTiles() {
            form.querySelectorAll('[data-feature-rule]').forEach(function (tile) {
                const input = tile.querySelector('[data-feature-rule-input]');

                tile.classList.toggle('is-on', !!input && input.checked);
            });
        }

        /*
         * A panel is only "open" when the feature is both shown and reachable.
         * Visible-but-not-accessible is the state worth its own word: the menu
         * entry is there and the route answers 403, which is usually exactly
         * what an operator has come to this screen to look at.
         */
        function paintTabBadges() {
            root.querySelectorAll('[data-feature-tab-badge]').forEach(function (badge) {
                const panel = badge.dataset.featureTabBadge;
                let key = 'hidden';

                if (isOn(panel, 'is_visible')) {
                    key = isOn(panel, 'is_accessible') ? 'open' : 'blocked';
                }

                badge.textContent = key === 'open'
                    ? badge.dataset.labelOpen
                    : (key === 'blocked' ? badge.dataset.labelBlocked : badge.dataset.labelHidden);

                badge.className = 'feature-mgmt-tab__pill feature-mgmt-tab__pill--' + key;
            });
        }

        function paintMatrix() {
            root.querySelectorAll('[data-feature-matrix-cell]').forEach(function (cell) {
                const on = isOn(cell.dataset.featureMatrixCell, cell.dataset.featureMatrixField);

                cell.classList.toggle('is-on', on);
                cell.innerHTML = '<i class="fa-solid ' + (on ? 'fa-check' : 'fa-xmark') + '" aria-hidden="true"></i>';
            });
        }

        function paintIdentifiers() {
            form.querySelectorAll('[data-feature-id]').forEach(function (tile) {
                const input = tile.querySelector('[data-feature-id-input]');
                const on = !!input && input.checked;
                const verify = tile.querySelector('[data-feature-verify]');

                tile.classList.toggle('is-on', on);

                /*
                 * Hidden, never removed. "Must be verified" means nothing while
                 * the method itself is off, but its stored value still has to
                 * post — dropping the input would silently clear it on save.
                 */
                if (verify) {
                    verify.hidden = !on;
                }
            });

            root.querySelectorAll('[data-feature-id-summary]').forEach(function (node) {
                const panel = node.dataset.featureIdSummary;
                const inputs = Array.from(
                    form.querySelectorAll('[data-feature-id-input][data-feature-panel="' + panel + '"]')
                );
                const allowed = inputs.filter(function (input) {
                    return input.checked;
                }).length;

                node.textContent = (node.dataset.template || '')
                    .replace(':count', allowed)
                    .replace(':total', node.dataset.total || inputs.length);
            });
        }

        function paintStatus() {
            const input = root.querySelector('[data-feature-master-input]');
            const master = root.querySelector('[data-feature-master]');
            const pill = root.querySelector('[data-feature-status]');
            const text = root.querySelector('[data-feature-status-text]');

            if (!input) {
                return;
            }

            if (master) {
                master.classList.toggle('is-off', !input.checked);
            }

            if (pill) {
                pill.classList.toggle('is-off', !input.checked);

                if (text) {
                    text.textContent = input.checked ? pill.dataset.labelOn : pill.dataset.labelOff;
                }
            }
        }

        const arrivedCountries = new Map();

        root.querySelectorAll('[data-feature-country-field]').forEach(function (field) {
            arrivedCountries.set(field, field.value);
        });

        function chipBox(node) {
            return node.closest('[data-feature-chips]');
        }

        function codesOf(box) {
            return (box.querySelector('[data-feature-country-field]').value || '')
                .split(',')
                .map(function (code) {
                    return code.trim().toUpperCase();
                })
                .filter(function (code) {
                    return code !== '';
                });
        }

        function paintChips(box) {
            const list = box.querySelector('[data-feature-chip-list]');
            const codes = codesOf(box);

            list.innerHTML = '';

            codes.forEach(function (code) {
                const chip = document.createElement('span');

                chip.className = 'feature-mgmt-chip';
                /* An ISO country code reads left to right in every locale. */
                chip.setAttribute('dir', 'ltr');
                chip.textContent = code;

                const remove = document.createElement('button');

                remove.type = 'button';
                remove.className = 'feature-mgmt-chip__remove';
                remove.dataset.featureChipRemove = code;
                remove.setAttribute('aria-label', (list.dataset.labelRemove || '').replace(':code', code));
                remove.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';

                chip.appendChild(remove);
                list.appendChild(chip);
            });

            const limited = codes.length > 0;
            const pill = root.querySelector(
                '[data-feature-country-pill="' + box.dataset.featureChips + '"]'
            );

            if (pill) {
                /*
                 * One country is a country, not "1 countries". The reference
                 * counts unconditionally; every locale here carries a
                 * singular, and picking it changes nothing on screen above
                 * one.
                 */
                const some = codes.length === 1
                    ? (pill.dataset.labelOne || pill.dataset.labelSome)
                    : pill.dataset.labelSome;

                pill.querySelector('[data-feature-country-text]').textContent = limited
                    ? (some || '').replace(':count', codes.length)
                    : pill.dataset.labelAll;

                /*
                 * A globe for "everywhere", a pin for "only these". The pill
                 * carries the count either way; the glyph is what reads at a
                 * glance across three panels.
                 */
                pill.querySelector('[data-feature-country-glyph]').className =
                    'fa-solid ' + (limited ? 'fa-location-dot' : 'fa-globe');

                pill.classList.toggle('is-limited', limited);
            }

            const input = box.querySelector('[data-feature-chip-input]');

            input.placeholder = limited
                ? input.dataset.placeholderSome
                : input.dataset.placeholderEmpty;
        }

        function writeCodes(box, codes) {
            const unique = codes.filter(function (code, index) {
                return codes.indexOf(code) === index;
            });

            box.querySelector('[data-feature-country-field]').value = unique.join(',');
            paintChips(box);
            markDirty();
        }

        const card = root.querySelector('[data-feature-save]');
        const saveTitle = root.querySelector('[data-feature-save-title]');
        const saveTag = root.querySelector('[data-feature-save-tag]');

        /*
         * Dirty is measured against the form as it arrived rather than tracked
         * per field, so putting a switch back the way it was clears the state
         * instead of leaving a card insisting there are unsaved changes.
         */
        function snapshot() {
            return new URLSearchParams(new FormData(form)).toString();
        }

        let clean = '';

        function markDirty() {
            if (!card) {
                return;
            }

            const dirty = snapshot() !== clean;

            card.classList.toggle('is-dirty', dirty);

            if (saveTitle) {
                saveTitle.textContent = dirty ? card.dataset.titleDirty : card.dataset.titleClean;
            }

            if (saveTag) {
                saveTag.textContent = dirty ? card.dataset.tagDirty : card.dataset.tagClean;
            }
        }

        function repaint() {
            paintRuleTiles();
            paintIdentifiers();
            paintTabBadges();
            paintMatrix();
            paintStatus();
            markDirty();
        }

        root.addEventListener('click', function (event) {
            const tab = event.target.closest('[data-feature-tab]');

            if (tab) {
                showPanel(tab.dataset.featureTab);

                return;
            }

            const remove = event.target.closest('[data-feature-chip-remove]');

            if (remove) {
                const box = chipBox(remove);
                const code = remove.dataset.featureChipRemove;

                writeCodes(box, codesOf(box).filter(function (kept) {
                    return kept !== code;
                }));

                return;
            }

            if (event.target.closest('[data-feature-discard]')) {
                form.reset();

                /*
                 * form.reset() cannot restore the country fields. On an
                 * <input type="hidden"> the value IDL attribute reflects the
                 * content attribute, so assigning .value rewrites
                 * defaultValue too — reset would put back whatever was typed
                 * last and Discard would silently do nothing. Their arrival
                 * values are kept here instead.
                 */
                root.querySelectorAll('[data-feature-country-field]').forEach(function (field) {
                    field.value = arrivedCountries.get(field) || '';
                });

                root.querySelectorAll('[data-feature-chips]').forEach(paintChips);
                repaint();
            }
        });

        root.addEventListener('keydown', function (event) {
            const input = event.target.closest('[data-feature-chip-input]');

            if (!input) {
                return;
            }

            /*
             * Enter is always swallowed here. The field sits inside the main
             * form, and the browser default would submit the whole screen on a
             * half-typed country code.
             */
            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();

                const box = chipBox(input);
                const draft = input.value.trim().toUpperCase();

                if (/^[A-Z]{2}$/.test(draft)) {
                    writeCodes(box, codesOf(box).concat([draft]));
                    input.value = '';
                }

                return;
            }

            if (event.key === 'Backspace' && input.value === '') {
                const box = chipBox(input);

                writeCodes(box, codesOf(box).slice(0, -1));
            }
        });

        form.addEventListener('change', repaint);
        form.addEventListener('input', markDirty);

        root.querySelectorAll('[data-feature-chips]').forEach(paintChips);
        repaint();

        clean = snapshot();
        markDirty();
    }

    /*
     * The Add-ons tab: publisher rail, state filter and search over the add-on
     * cards. Same shape as the Features catalog above, over its own data
     * attributes, so neither screen can disturb the other.
     */
    function initAddonCatalog(root) {
        const cards = Array.from(root.querySelectorAll('[data-addon-card]'));
        const empty = root.querySelector('[data-addon-empty]');
        const grid = root.querySelector('[data-addon-grid]');

        if (cards.length === 0) {
            return;
        }

        const state = {
            publisher: 'all',
            filter: 'all',
            query: '',
        };

        function inPublisher(card) {
            return state.publisher === 'all' || card.dataset.addonPublisher === state.publisher;
        }

        function inFilter(card, filter) {
            return filter === 'all' || card.dataset.addonFilterState === filter;
        }

        function apply() {
            let shown = 0;

            cards.forEach(function (card) {
                const visible = inPublisher(card)
                    && inFilter(card, state.filter)
                    && (state.query === '' || (card.dataset.addonSearch || '').indexOf(state.query) !== -1);

                card.hidden = !visible;

                if (visible) {
                    shown += 1;
                }
            });

            if (empty) {
                empty.hidden = shown > 0;
            }

            if (grid) {
                grid.hidden = shown === 0;
            }

            root.querySelectorAll('[data-addon-filter-count]').forEach(function (node) {
                const key = node.dataset.addonFilterCount;

                node.textContent = cards.filter(function (card) {
                    return inPublisher(card) && inFilter(card, key);
                }).length;
            });

            root.querySelectorAll('[data-addon-filter]').forEach(function (button) {
                const on = button.dataset.addonFilter === state.filter;

                button.classList.toggle('is-active', on);
                button.setAttribute('aria-pressed', on ? 'true' : 'false');
            });

            root.querySelectorAll('[data-addon-publisher-tab]').forEach(function (button) {
                const on = button.dataset.addonPublisherTab === state.publisher;

                button.classList.toggle('is-active', on);
                button.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }

        function clearSearch() {
            root.querySelectorAll('[data-addon-search]').forEach(function (input) {
                input.value = '';
            });
        }

        root.addEventListener('click', function (event) {
            const publisher = event.target.closest('[data-addon-publisher-tab]');

            if (publisher) {
                state.publisher = publisher.dataset.addonPublisherTab;
                apply();

                return;
            }

            const filter = event.target.closest('[data-addon-filter]');

            if (filter) {
                state.filter = filter.dataset.addonFilter;
                apply();

                return;
            }

            if (event.target.closest('[data-addon-clear]')) {
                state.filter = 'all';
                state.query = '';
                clearSearch();
                apply();
            }
        });

        root.addEventListener('input', function (event) {
            const search = event.target.closest('[data-addon-search]');

            if (!search) {
                return;
            }

            state.query = search.value.trim().toLowerCase();
            apply();
        });

        apply();
    }

    /*
     * The Add-ons first-run hint goes away for this administrator for good.
     * The note leaves the page at once; the request only makes it stay gone.
     */
    function initFirstRunHint(hint) {
        const dismiss = hint.querySelector('[data-addon-hint-dismiss]');
        const token = document.querySelector('meta[name="csrf-token"]');

        if (!dismiss) {
            return;
        }

        dismiss.addEventListener('click', function () {
            hint.remove();

            if (!hint.dataset.dismissUrl || !token) {
                return;
            }

            fetch(hint.dataset.dismissUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token.getAttribute('content'),
                },
            }).catch(function () {
                /* Shown again next visit; nothing else depends on it. */
            });
        });
    }

    const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    /*
     * Keeps Tab inside an open dialog. Measured, not read off attributes: a
     * control inside a hidden section has no box, and focusing it would send
     * the keyboard somewhere the eye cannot follow.
     */
    function trapFocus(container, event) {
        const items = Array.from(container.querySelectorAll(FOCUSABLE)).filter(function (el) {
            return el.getClientRects().length > 0;
        });

        if (items.length === 0) {
            return;
        }

        const first = items[0];
        const last = items[items.length - 1];

        if (event.shiftKey && (document.activeElement === first || !container.contains(document.activeElement))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    /*
     * The add-on detail drawer. Opened from a card's Open / Fix requirements
     * button or a ?addon=slug link; closed by Esc, the backdrop or ×, after
     * which focus goes back to whatever opened it.
     */
    function initAddonDrawers() {
        const drawers = Array.from(document.querySelectorAll('[data-addon-drawer]'));
        const backdrop = document.querySelector('[data-addon-drawer-backdrop]');
        const confirm = document.getElementById('featureConfirmModal');

        if (drawers.length === 0 || !backdrop) {
            return;
        }

        let open = null;
        let opener = null;

        function hide(restoreFocus) {
            if (!open) {
                return;
            }

            const back = opener;

            open.hidden = true;
            backdrop.classList.remove('is-visible');
            open = null;
            opener = null;

            if (restoreFocus && back && document.contains(back)) {
                back.focus();
            }
        }

        function show(slug, from, sectionSelector) {
            const drawer = drawers.find(function (candidate) {
                return candidate.dataset.addonDrawer === slug;
            });

            if (!drawer) {
                return;
            }

            hide(false);

            open = drawer;
            opener = from || null;
            drawer.hidden = false;
            backdrop.classList.add('is-visible');

            const section = sectionSelector ? drawer.querySelector(sectionSelector) : null;

            if (section) {
                section.scrollIntoView({ block: 'start' });
            }

            const close = drawer.querySelector('[data-addon-drawer-close]');

            if (close) {
                close.focus();
            }
        }

        document.addEventListener('click', function (event) {
            const action = event.target.closest('[data-addon-action]');

            if (action && (action.dataset.addonAction === 'open' || action.dataset.addonAction === 'fix')) {
                show(action.dataset.addonSlug, action, action.dataset.addonAction === 'fix' ? '[data-addon-drawer-requirements]' : null);

                return;
            }

            if (event.target.closest('[data-addon-drawer-close]')) {
                hide(true);
            }
        });

        backdrop.addEventListener('click', function () {
            hide(true);
        });

        document.addEventListener('keydown', function (event) {
            /* The confirm dialog can sit on top of the drawer; it handles its own keys. */
            if (!open || (confirm && confirm.classList.contains('is-visible'))) {
                return;
            }

            if (event.key === 'Escape') {
                hide(true);
            } else if (event.key === 'Tab') {
                trapFocus(open, event);
            }
        });

        const linked = new URLSearchParams(window.location.search).get('addon');

        if (linked) {
            show(linked, null, null);
        }
    }

    function initConfirm(page) {
        const backdrop = document.getElementById('featureConfirmBackdrop');
        const modal = document.getElementById('featureConfirmModal');
        const title = document.getElementById('featureConfirmTitle');
        const text = document.getElementById('featureConfirmText');
        const impact = document.getElementById('featureConfirmImpact');
        const submit = document.getElementById('featureConfirmSubmit');
        const submitLabel = document.getElementById('featureConfirmSubmitLabel');
        const submitIcon = document.getElementById('featureConfirmSubmitIcon');

        if (!modal || !backdrop || !submit) {
            return;
        }

        const copy = page.dataset;

        let pendingForm = null;
        let pendingInput = null;

        function close(revert) {
            const back = pendingInput;

            if (revert && pendingInput) {
                pendingInput.checked = !pendingInput.checked;
            }

            modal.classList.remove('is-visible', 'is-danger');
            backdrop.classList.remove('is-visible');
            modal.setAttribute('aria-hidden', 'true');
            pendingForm = null;
            pendingInput = null;

            /* Back to the switch that asked, not to the top of the page. */
            if (back && document.contains(back)) {
                back.focus();
            }
        }

        page.querySelectorAll('.feature-mgmt-toggle-form').forEach(function (form) {
            const input = form.querySelector('.feature-mgmt-switch__input');

            if (!input) {
                return;
            }

            input.addEventListener('change', function () {
                const label = form.dataset.featureLabel || '';
                const isRole = form.dataset.featureKind === 'role';
                const isCore = form.dataset.isCore === '1';
                const turningOn = input.checked;

                /*
                 * Turning an ordinary feature on is not a decision worth
                 * interrupting — nothing breaks by something becoming
                 * available. Everything else asks first: turning anything off,
                 * and either direction on a core or role switch.
                 */
                if (turningOn && !isCore && !isRole) {
                    form.submit();

                    return;
                }

                pendingForm = form;
                pendingInput = input;

                title.textContent = (turningOn ? copy.titleOn : copy.titleOff).replace(':feature', label);

                /*
                 * A form may carry its own wording - an add-on's switch says
                 * what turning an add-on off does - and otherwise reads the
                 * page's, which is every feature switch.
                 */
                const own = form.dataset;

                if (turningOn) {
                    text.textContent = own.textOn || copy.textOn;
                    impact.textContent = own.impactOn || copy.impactOn;
                    submitLabel.textContent = copy.btnOn;
                    submitIcon.className = 'fa-solid fa-bolt feature-mgmt-btn__icon';
                    modal.classList.remove('is-danger');
                } else {
                    text.textContent = own.textOff || (isCore
                        ? copy.textOffCore
                        : (isRole ? copy.textOffRole : copy.textOff));
                    impact.textContent = own.impactOff || copy.impactOff;
                    submitLabel.textContent = copy.btnOff;
                    submitIcon.className = 'fa-solid fa-triangle-exclamation feature-mgmt-btn__icon';
                    modal.classList.add('is-danger');
                }

                /* An add-on's feature says whose it is, ahead of what the switch does. */
                if (own.addonNote) {
                    text.textContent = own.addonNote + ' ' + text.textContent;
                }

                modal.classList.add('is-visible');
                backdrop.classList.add('is-visible');
                modal.setAttribute('aria-hidden', 'false');
                submit.focus();
            });
        });

        submit.addEventListener('click', function () {
            if (pendingForm) {
                pendingForm.submit();
            }
        });

        document.querySelectorAll('[data-feature-dismiss]').forEach(function (button) {
            button.addEventListener('click', function () {
                close(true);
            });
        });

        backdrop.addEventListener('click', function () {
            close(true);
        });

        document.addEventListener('keydown', function (event) {
            if (!modal.classList.contains('is-visible')) {
                return;
            }

            if (event.key === 'Escape') {
                close(true);
            } else if (event.key === 'Tab') {
                trapFocus(modal, event);
            }
        });
    }

    /* Replaces :name-style tokens; an unknown token is left as written. */
    function fillCopy(template, values) {
        return String(template || '').replace(/:([a-z]+)/g, function (match, key) {
            return Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : match;
        });
    }

    function pluralCopy(units, unit, count) {
        const forms = units[unit] || [':count', ':count'];

        return fillCopy(count === 1 ? forms[0] : forms[1], { count: count });
    }

    function readCopy(element) {
        try {
            return JSON.parse(element.dataset.copy || '{}');
        } catch (error) {
            return {};
        }
    }

    /*
     * The installer: one upload, six steps. The server verifies the package
     * and keeps it under a token for this session; this only walks the admin
     * through what the server reported, then asks for the password and
     * installs. Step 5 is one request, shown as an indeterminate bar and a
     * clock, because that is all anyone knows while it runs.
     */
    function initAddonInstaller() {
        const modal = document.querySelector('[data-addon-installer]');
        const backdrop = document.querySelector('[data-addon-installer-backdrop]');

        if (!modal || !backdrop) {
            return;
        }

        const copy = readCopy(modal);
        const units = copy.units || {};
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
        const ORDER = ['source', 'verify', 'requirements', 'license', 'install', 'enable'];
        const PURCHASE_CODE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
        const MAX_BYTES = 50 * 1024 * 1024;
        const TOKEN_PLACEHOLDER = 'T'.repeat(40);

        function find(selector) {
            return modal.querySelector(selector);
        }

        const el = {
            eyebrow: find('[data-installer-eyebrow]'),
            title: find('[data-installer-title]'),
            file: find('[data-installer-file]'),
            dropzone: find('[data-installer-dropzone]'),
            fileRow: find('[data-installer-file-row]'),
            fileName: find('[data-installer-file-name]'),
            fileSize: find('[data-installer-file-size]'),
            error: find('[data-installer-error]'),
            verify: find('[data-installer-verify]'),
            adds: find('[data-installer-adds]'),
            update: find('[data-installer-update]'),
            requirements: find('[data-installer-requirements]'),
            code: find('[data-installer-code]'),
            codeState: find('[data-installer-code-state]'),
            licenseFor: find('[data-installer-license-for]'),
            ready: find('[data-installer-ready]'),
            readyTitle: find('[data-installer-ready-title]'),
            readyText: find('[data-installer-ready-text]'),
            password: find('[data-installer-password]'),
            otp: find('[data-installer-otp]'),
            running: find('[data-installer-running]'),
            elapsed: find('[data-installer-elapsed]'),
            installed: find('[data-installer-installed]'),
            licenseResult: find('[data-installer-license-result]'),
            enable: find('[data-installer-enable]'),
            features: find('[data-installer-features]'),
            featuresLabel: find('[data-installer-features-label]'),
            outcome: find('[data-installer-outcome]'),
            outcomeTitle: find('[data-installer-outcome-title]'),
            outcomeText: find('[data-installer-outcome-text]'),
            outcomeList: find('[data-installer-outcome-list]'),
            close: find('[data-installer-close]'),
            cancel: find('[data-installer-cancel]'),
            secondary: find('[data-installer-secondary]'),
            primary: find('[data-installer-primary]'),
        };

        let state = null;
        let actions = {};

        function reset() {
            window.clearInterval(state && state.timer);

            state = {
                step: 'source',
                outcome: false,
                file: null,
                token: null,
                report: null,
                code: '',
                installed: null,
                running: false,
                timer: null,
                opener: null,
            };

            el.file.value = '';
            el.code.value = '';
            el.fileRow.hidden = true;
            el.error.hidden = true;

            if (el.password) {
                el.password.value = '';
            }

            if (el.otp) {
                el.otp.value = '';
            }

            el.enable.checked = true;

            if (el.features) {
                el.features.checked = false;
            }
        }

        function endpoint(name, token, slug) {
            return (modal.dataset[name] || '')
                .replace(TOKEN_PLACEHOLDER, token || '')
                .replace('addon-slug-placeholder', slug || '');
        }

        function send(method, href, body) {
            const headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' };
            let payload = body;

            if (body && !(body instanceof FormData)) {
                headers['Content-Type'] = 'application/json';
                payload = JSON.stringify(body);
            }

            return fetch(href, { method: method, credentials: 'same-origin', headers: headers, body: payload })
                .then(function (response) {
                    return response.json().catch(function () {
                        return {};
                    }).then(function (data) {
                        return { status: response.status, data: data };
                    });
                });
        }

        function sizeLabel(count) {
            return count >= 1048576 ? (count / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(count / 1024)) + ' KB';
        }

        function joined(parts) {
            return parts.filter(Boolean).join(', ');
        }

        function isUpdate() {
            return Boolean(state.report && state.report.update);
        }

        function needsLicense() {
            return Boolean(state.report && state.report.license.required && state.report.license.state !== 'valid');
        }

        function isBlocking(check) {
            return check.key === 'core' || String(check.key).indexOf('extension:') === 0;
        }

        function checkRow(passed, label, hint) {
            const row = document.createElement('div');
            const mark = document.createElement('span');
            const icon = document.createElement('i');
            const body = document.createElement('span');
            const name = document.createElement('span');

            row.className = 'feature-mgmt-drawer__check ' + (passed ? 'is-passing' : 'is-failing');
            mark.className = 'feature-mgmt-drawer__check-mark';
            mark.setAttribute('aria-hidden', 'true');
            icon.className = 'fa-solid ' + (passed ? 'fa-check' : 'fa-xmark');
            body.className = 'feature-mgmt-drawer__check-copy';
            name.textContent = label;

            mark.appendChild(icon);
            body.appendChild(name);

            if (hint) {
                const detail = document.createElement('span');
                detail.className = passed ? 'feature-mgmt-installer__check-hint' : 'feature-mgmt-drawer__fix';
                detail.textContent = hint;
                body.appendChild(detail);
            }

            row.appendChild(mark);
            row.appendChild(body);

            return row;
        }

        function group(container, label) {
            const heading = document.createElement('p');
            heading.className = 'feature-mgmt-installer__group';
            heading.textContent = label;
            container.appendChild(heading);
        }

        function renderReport() {
            const report = state.report;

            el.verify.replaceChildren(
                checkRow(true, copy.signature, report.fingerprint ? fillCopy(copy.signatureHint, { fingerprint: report.fingerprint }) : ''),
                checkRow(true, copy.package, fillCopy(copy.packageHint, { size: sizeLabel(report.size), files: pluralCopy(units, 'file', report.files) })),
                checkRow(report.compatible, report.compatible
                    ? fillCopy(copy.compatible, { core: report.core_version })
                    : fillCopy(copy.incompatible, { required: report.requires_core, core: report.core_version }), ''),
                checkRow(true, copy.noConflicts, copy.noConflictsHint)
            );

            el.adds.textContent = fillCopy(copy.adds, {
                list: joined([
                    pluralCopy(units, 'feature', report.features),
                    report.menus > 0 ? pluralCopy(units, 'menu', report.menus) : '',
                    report.permissions > 0 ? pluralCopy(units, 'permission', report.permissions) : '',
                ]),
            });

            el.update.hidden = !report.update;

            if (report.update) {
                el.update.textContent = fillCopy(copy.updateNote, { from: report.update.from, to: report.update.to });
            }

            el.requirements.replaceChildren();
            group(el.requirements, copy.blocking);
            report.requirements.filter(isBlocking).forEach(function (check) {
                el.requirements.appendChild(checkRow(check.passed, check.label, check.passed ? '' : check.fix));
            });

            const runtime = report.requirements.filter(function (check) {
                return !isBlocking(check);
            });

            if (runtime.length > 0) {
                group(el.requirements, copy.runtime);
                runtime.forEach(function (check) {
                    el.requirements.appendChild(checkRow(check.passed, check.label, check.passed ? '' : check.fix));
                });
            }

            el.licenseFor.textContent = fillCopy(copy.licenseFor, { domain: modal.dataset.domain });
            el.readyTitle.textContent = fillCopy(isUpdate() ? copy.readyUpdate : copy.readyInstall, { name: report.name, version: report.version });
            el.readyText.textContent = report.migrations > 0
                ? fillCopy(copy.migrationsSome, { migrations: pluralCopy(units, 'migration', report.migrations) })
                : copy.migrationsNone;

            if (el.featuresLabel) {
                el.featuresLabel.textContent = fillCopy(copy.featuresOn, { features: pluralCopy(units, 'feature', report.features) });
            }
        }

        function blockingFailures() {
            return state.report ? state.report.requirements.filter(function (check) {
                return isBlocking(check) && !check.passed;
            }) : [];
        }

        function setButton(button, spec) {
            button.hidden = !spec;

            if (spec) {
                button.textContent = spec.label;
                button.disabled = Boolean(spec.disabled);
            }
        }

        function footer(spec) {
            actions = spec;
            setButton(el.cancel, spec.cancel);
            setButton(el.secondary, spec.secondary);
            setButton(el.primary, spec.primary);
            el.close.disabled = state.running;
        }

        function render() {
            const current = ORDER.indexOf(state.step);

            modal.querySelectorAll('[data-installer-panel]').forEach(function (panel) {
                panel.hidden = state.outcome ? panel.dataset.installerPanel !== 'outcome' : panel.dataset.installerPanel !== state.step;
            });

            modal.querySelectorAll('[data-installer-step]').forEach(function (item) {
                const index = ORDER.indexOf(item.dataset.installerStep);
                const isCurrent = !state.outcome && index === current;

                item.classList.toggle('is-done', index < current || state.outcome === 'success');
                item.classList.toggle('is-current', isCurrent);
                item.classList.toggle('is-skipped', item.dataset.installerStep === 'license' && state.report !== null && !needsLicense() && index < current);

                if (isCurrent) {
                    item.setAttribute('aria-current', 'step');
                } else {
                    item.removeAttribute('aria-current');
                }
            });

            el.eyebrow.textContent = fillCopy(isUpdate() ? copy.eyebrowUpdate : copy.eyebrow, { step: current + 1, total: ORDER.length });
            el.title.textContent = state.report ? fillCopy(copy.titleNamed, { name: state.report.name, version: state.report.version }) : copy.titleDefault;

            if (state.outcome) {
                return;
            }

            const cancel = { label: copy.cancel, run: close };

            switch (state.step) {
                case 'source':
                    footer({ cancel: cancel, primary: { label: copy.continue, disabled: !state.file, run: upload } });
                    break;
                case 'verify':
                    footer({ cancel: cancel, primary: { label: copy.continue, disabled: !state.report.compatible, run: function () { go('requirements'); } } });
                    break;
                case 'requirements':
                    footer({
                        cancel: cancel,
                        secondary: { label: copy.recheck, run: recheck },
                        primary: { label: copy.continue, disabled: blockingFailures().length > 0, run: function () { go(needsLicense() ? 'license' : 'install'); } },
                    });
                    break;
                case 'license':
                    footer({
                        cancel: cancel,
                        secondary: { label: copy.activateLater, run: function () { state.code = ''; go('install'); } },
                        primary: { label: copy.activateContinue, disabled: !PURCHASE_CODE.test(el.code.value.trim()), run: function () { state.code = el.code.value.trim(); go('install'); } },
                    });
                    break;
                case 'install':
                    footer({
                        cancel: state.running ? null : cancel,
                        primary: state.running ? null : { label: isUpdate() ? copy.update : copy.install, disabled: !el.password || el.password.value === '', run: install },
                    });
                    break;
                case 'enable':
                    footer({
                        cancel: { label: copy.finishLater, run: close },
                        primary: { label: copy.finish, run: finish },
                    });
                    break;
            }
        }

        function go(step) {
            state.step = step;
            state.outcome = false;
            render();

            const focusable = {
                source: el.file,
                license: el.code,
                install: el.password,
            }[step];

            (focusable || el.primary).focus();
        }

        function outcome(tone, title, text, reasons, spec) {
            state.outcome = tone;
            el.outcome.dataset.tone = tone;
            el.outcomeTitle.textContent = title;
            el.outcomeText.textContent = text;
            el.outcomeList.replaceChildren();

            (reasons || []).forEach(function (reason) {
                const item = document.createElement('li');
                item.textContent = reason;
                el.outcomeList.appendChild(item);
            });

            render();
            footer(spec || { primary: { label: copy.close, run: close } });
            el.primary.focus();
        }

        /* While a request runs nothing can be pressed twice; after it, the step decides again. */
        function busy(isBusy) {
            modal.classList.toggle('is-busy', isBusy);

            if (isBusy) {
                el.primary.disabled = true;
                el.secondary.disabled = true;
            } else if (!state.outcome) {
                render();
            }
        }

        function inlineError(message) {
            el.error.textContent = message;
            el.error.hidden = !message;
        }

        function chooseFile(file) {
            inlineError('');
            state.file = null;
            el.fileRow.hidden = true;

            if (!file) {
                render();

                return;
            }

            if (!/\.zip$/i.test(file.name)) {
                inlineError(copy.notZip);
            } else if (file.size > MAX_BYTES) {
                inlineError(copy.tooLarge);
            } else {
                state.file = file;
                el.fileName.textContent = file.name;
                el.fileSize.textContent = sizeLabel(file.size);
                el.fileRow.hidden = false;
            }

            render();
        }

        function upload() {
            const data = new FormData();
            data.append('package', state.file);
            inlineError(copy.checking);
            busy(true);

            send('POST', modal.dataset.uploadUrl, data).then(function (result) {
                busy(false);

                if (result.status === 413) {
                    inlineError(copy.tooLarge);

                    return;
                }

                if (result.data.errors) {
                    inlineError(Object.values(result.data.errors)[0][0] || copy.network);

                    return;
                }

                if (!result.data.ok) {
                    inlineError('');
                    outcome('danger', copy.refusedTitle, copy.refusedText, result.data.reasons || [copy.network]);

                    return;
                }

                inlineError('');
                state.report = result.data.report;
                state.token = result.data.token;
                renderReport();

                if (!state.token) {
                    outcome('danger', copy.conflictTitle, copy.conflictText, state.report.conflicts);

                    return;
                }

                go('verify');
            }).catch(function () {
                busy(false);
                inlineError(copy.network);
            });
        }

        function recheck() {
            busy(true);

            send('POST', endpoint('checkUrl', state.token), null).then(function (result) {
                busy(false);

                if (!result.data.ok) {
                    state.token = null;
                    outcome('danger', copy.refusedTitle, '', result.data.reasons || [copy.network]);

                    return;
                }

                state.report = result.data.report;
                renderReport();
                go('requirements');
            }).catch(function () {
                busy(false);
                outcome('danger', copy.refusedTitle, '', [copy.network]);
            });
        }

        function fieldErrors(errors) {
            modal.querySelectorAll('[data-installer-error-for]').forEach(function (slot) {
                const messages = errors[slot.dataset.installerErrorFor];
                slot.textContent = messages ? messages[0] : '';
                slot.hidden = !messages;
            });
        }

        function running(isRunning) {
            state.running = isRunning;
            el.ready.hidden = isRunning;
            el.running.hidden = !isRunning;
            window.clearInterval(state.timer);

            if (isRunning) {
                const started = Date.now();
                const tick = function () {
                    el.elapsed.textContent = fillCopy(copy.elapsed, { seconds: Math.floor((Date.now() - started) / 1000) });
                };

                tick();
                state.timer = window.setInterval(tick, 1000);
            }

            render();
        }

        function install() {
            const body = { password: el.password.value };

            if (el.otp) {
                body.otp = el.otp.value.trim();
            }

            if (state.code) {
                body.purchase_code = state.code;
            }

            fieldErrors({});
            running(true);

            send('POST', endpoint('installUrl', state.token), body).then(function (result) {
                running(false);

                if (result.data.errors) {
                    fieldErrors(result.data.errors);
                    el.password.focus();

                    return;
                }

                state.token = null;

                if (result.data.ok) {
                    state.installed = result.data;
                    el.installed.textContent = fillCopy(copy.installed, { name: result.data.name, version: result.data.version });

                    const license = result.data.license || {};
                    el.licenseResult.hidden = !(license.activated || license.message);
                    el.licenseResult.textContent = license.activated ? fillCopy(copy.licenseBound, { domain: modal.dataset.domain }) : (license.message || '');

                    go('enable');

                    return;
                }

                if (result.data.failed) {
                    state.installed = { slug: state.report.slug };
                    outcome('danger', copy.failedTitle, copy.failedText, result.data.reasons);
                } else {
                    state.installed = { slug: state.report.slug };
                    outcome('warning', copy.blockedTitle, copy.stoppedText, result.data.reasons || [copy.network]);
                }
            }).catch(function () {
                running(false);
                state.installed = { slug: state.report.slug };
                outcome('danger', copy.failedTitle, copy.network, []);
            });
        }

        function finish() {
            const slug = state.installed.slug;
            const switchOn = el.enable.checked;
            const featuresOn = Boolean(el.features && el.features.checked && !el.features.disabled);
            const report = state.report;
            const added = joined([
                pluralCopy(units, 'feature', report.features),
                report.menus > 0 ? pluralCopy(units, 'menu', report.menus) : '',
            ]);

            busy(true);

            const switched = switchOn
                ? send('PATCH', endpoint('statusUrl', null, slug), { is_enabled: 1 }).then(function (result) { return result.data; })
                : Promise.resolve({ ok: true });

            switched.then(function (result) {
                if (!result.ok) {
                    busy(false);
                    outcome('warning', fillCopy(copy.stillOffTitle, { name: report.name }), copy.stillOffText, result.reasons || [copy.network], successActions(slug));

                    return null;
                }

                const turnedOn = featuresOn
                    ? send('POST', endpoint('featuresUrl', null, slug), null).then(function (response) { return response.data.ok === true; })
                    : Promise.resolve(false);

                return turnedOn.then(function (featuresAreOn) {
                    busy(false);
                    outcome(
                        'success',
                        fillCopy(switchOn ? copy.successOn : copy.successOff, { name: report.name }),
                        fillCopy(featuresAreOn ? copy.successTextOn : copy.successText, { list: added }),
                        el.licenseResult.hidden ? [] : [el.licenseResult.textContent],
                        successActions(slug)
                    );
                });
            }).catch(function () {
                busy(false);
                outcome('warning', fillCopy(copy.stillOffTitle, { name: report.name }), copy.stillOffText, [copy.network], successActions(slug));
            });
        }

        function successActions(slug) {
            return {
                secondary: { label: copy.goFeatures, run: function () { window.location.assign(modal.dataset.featuresTabUrl); } },
                primary: { label: copy.openAddon, run: function () { window.location.assign(endpoint('addonUrl', null, slug)); } },
            };
        }

        function open(from) {
            reset();
            state.opener = from || null;
            render();
            modal.classList.add('is-visible');
            backdrop.classList.add('is-visible');
            modal.setAttribute('aria-hidden', 'false');
            el.file.focus();
        }

        function close() {
            if (!state || state.running || !modal.classList.contains('is-visible')) {
                return;
            }

            if (state.token) {
                send('DELETE', endpoint('discardUrl', state.token), null).catch(function () {
                    /* Deleted a day later by the next upload. */
                });
            }

            /* Something on this page changed: show it as the server now has it. */
            if (state.installed) {
                window.location.assign(endpoint('addonUrl', null, state.installed.slug));

                return;
            }

            const back = state.opener;

            window.clearInterval(state.timer);
            modal.classList.remove('is-visible');
            backdrop.classList.remove('is-visible');
            modal.setAttribute('aria-hidden', 'true');

            if (back && document.contains(back)) {
                back.focus();
            }
        }

        [['cancel', el.cancel], ['secondary', el.secondary], ['primary', el.primary]].forEach(function (pair) {
            pair[1].addEventListener('click', function () {
                const spec = actions[pair[0]];

                if (spec && spec.run && !pair[1].disabled) {
                    spec.run();
                }
            });
        });

        el.close.addEventListener('click', close);
        backdrop.addEventListener('click', close);
        el.file.addEventListener('change', function () {
            chooseFile(el.file.files[0] || null);
        });

        ['dragenter', 'dragover'].forEach(function (name) {
            el.dropzone.addEventListener(name, function (event) {
                event.preventDefault();
                el.dropzone.classList.add('is-over');
            });
        });

        ['dragleave', 'drop'].forEach(function (name) {
            el.dropzone.addEventListener(name, function () {
                el.dropzone.classList.remove('is-over');
            });
        });

        el.dropzone.addEventListener('drop', function (event) {
            event.preventDefault();
            chooseFile(event.dataTransfer && event.dataTransfer.files[0] ? event.dataTransfer.files[0] : null);
        });

        el.code.addEventListener('input', function () {
            const value = el.code.value.trim();
            const valid = PURCHASE_CODE.test(value);

            el.codeState.textContent = value === '' ? '' : (valid ? copy.formatOk : copy.formatBad);
            el.codeState.classList.toggle('is-valid', valid);
            render();
        });

        [el.password, el.otp].forEach(function (input) {
            if (!input) {
                return;
            }

            input.addEventListener('input', render);
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && !el.primary.disabled && !el.primary.hidden) {
                    event.preventDefault();
                    el.primary.click();
                }
            });
        });

        document.addEventListener('click', function (event) {
            const trigger = event.target.closest('[data-addon-install]');

            if (trigger) {
                open(trigger);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (!modal.classList.contains('is-visible')) {
                return;
            }

            if (event.key === 'Escape') {
                close();
            } else if (event.key === 'Tab') {
                trapFocus(modal, event);
            }
        });
    }

    /*
     * Installing an add-on already in addons/ (or retrying a failed one), and
     * uninstalling one: a real form post, so a refusal comes back as the
     * page with its errors, and this re-opens the dialog over them.
     */
    function initAddonOperation() {
        const modal = document.querySelector('[data-addon-operation]');
        const backdrop = document.querySelector('[data-addon-operation-backdrop]');

        if (!modal || !backdrop) {
            return;
        }

        const copy = readCopy(modal);
        const form = modal.querySelector('[data-operation-form]');

        function find(selector) {
            return modal.querySelector(selector);
        }

        const el = {
            method: find('[data-operation-method]'),
            mode: find('[data-operation-mode]'),
            slug: find('[data-operation-slug]'),
            name: find('[data-operation-name]'),
            migrations: find('[data-operation-migrations]'),
            title: find('[data-operation-title]'),
            text: find('[data-operation-text]'),
            uninstall: find('[data-operation-uninstall]'),
            keep: find('[data-operation-keep]'),
            remove: find('[data-operation-delete]'),
            deleteHint: find('[data-operation-delete-hint]'),
            typed: find('[data-operation-typed]'),
            typedLabel: find('[data-operation-typed-label]'),
            typedInput: find('[data-operation-typed-input]'),
            password: form.querySelector('input[name="password"]'),
            submit: find('[data-operation-submit]'),
        };

        let opener = null;

        function sync() {
            const uninstalling = el.mode.value === 'uninstall';
            const deleting = uninstalling && el.remove.checked;

            el.typed.hidden = !deleting;
            el.typedInput.disabled = !deleting;
            modal.classList.toggle('is-danger', uninstalling);
            el.submit.textContent = uninstalling ? (deleting ? copy.submitDelete : copy.submitKeep) : copy.installSubmit;
            el.submit.disabled = deleting && el.typedInput.value.trim() !== el.slug.value;
        }

        function open(kind, slug, name, migrations, keepData, from) {
            const uninstalling = kind === 'uninstall';

            opener = from || null;
            form.action = (uninstalling ? modal.dataset.uninstallUrl : modal.dataset.installUrl).replace('addon-slug-placeholder', slug);
            el.method.value = uninstalling ? 'DELETE' : 'POST';
            el.mode.value = kind;
            el.slug.value = slug;
            el.name.value = name;
            el.migrations.value = String(migrations);
            el.title.textContent = fillCopy(uninstalling ? copy.uninstallTitle : copy.installTitle, { name: name });
            el.text.textContent = uninstalling ? copy.uninstallText : copy.installText;
            el.uninstall.hidden = !uninstalling;
            el.deleteHint.textContent = migrations > 0
                ? fillCopy(copy.deleteSome, { migrations: pluralCopy(copy.units || {}, 'migration', migrations) })
                : copy.deleteNone;
            el.typedLabel.textContent = fillCopy(copy.typeSlug, { slug: slug });
            (keepData ? el.keep : el.remove).checked = true;

            sync();
            modal.classList.add('is-visible');
            backdrop.classList.add('is-visible');
            modal.setAttribute('aria-hidden', 'false');
            (uninstalling ? el.keep : el.password).focus();
        }

        function close() {
            const back = opener;

            modal.classList.remove('is-visible');
            backdrop.classList.remove('is-visible');
            modal.setAttribute('aria-hidden', 'true');
            opener = null;

            if (back && document.contains(back)) {
                back.focus();
            }
        }

        [el.keep, el.remove].forEach(function (radio) {
            radio.addEventListener('change', sync);
        });

        el.typedInput.addEventListener('input', sync);

        form.addEventListener('submit', function (event) {
            if (el.submit.disabled) {
                event.preventDefault();

                return;
            }

            el.submit.disabled = true;
        });

        modal.querySelectorAll('[data-operation-close]').forEach(function (button) {
            button.addEventListener('click', close);
        });

        backdrop.addEventListener('click', close);

        document.addEventListener('click', function (event) {
            const action = event.target.closest('[data-addon-action]');

            if (!action || action.disabled) {
                return;
            }

            const kind = action.dataset.addonAction;
            const slug = action.dataset.addonSlug;

            if (kind === 'install' || kind === 'uninstall') {
                open(kind, slug, action.dataset.addonName || slug, parseInt(action.dataset.addonMigrations || '0', 10), true, action);
            } else if (kind === 'disable') {
                /* The card's own switch, so the same confirmation asks first. */
                const card = document.getElementById('addon-' + slug);
                const input = card ? card.querySelector('.feature-mgmt-switch__input') : null;

                if (input && input.checked && !input.disabled) {
                    input.checked = false;
                    input.dispatchEvent(new Event('change'));
                }
            }
        });

        document.addEventListener('keydown', function (event) {
            if (!modal.classList.contains('is-visible')) {
                return;
            }

            if (event.key === 'Escape') {
                close();
            } else if (event.key === 'Tab') {
                trapFocus(modal, event);
            }
        });

        if (modal.dataset.reopen) {
            open(
                modal.dataset.reopen,
                modal.dataset.reopenSlug || '',
                modal.dataset.reopenName || modal.dataset.reopenSlug || '',
                parseInt(modal.dataset.reopenMigrations || '0', 10),
                modal.dataset.reopenKeep !== '0',
                null
            );
        }
    }
})();
