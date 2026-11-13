"use strict";

/**
 * Permission editor for one role.
 *
 * Every module is already in the document; this only shows one at a time and
 * keeps the tallies honest. The checkboxes are real form controls, so the page
 * saves correctly whether or not this file ever runs — nothing here is load
 * bearing for the POST.
 *
 * There is no mirror of the working set: the checkboxes already hold it, and a
 * second copy is only somewhere for the two to disagree. `saved` is kept
 * because it is the one thing the DOM stops remembering the moment a switch
 * moves.
 */
(function () {
    const root = document.querySelector("[data-role-permissions]");

    if (!root) {
        return;
    }

    function parseList(raw) {
        try {
            const parsed = JSON.parse(raw || "[]");

            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    const canEdit = root.dataset.canEdit === "1";
    const isProtected = root.dataset.protected === "1";
    const isCreate = root.dataset.mode === "create";
    const totalPermissions = Number(root.dataset.totalPermissions) || 1;
    const totalModules = Number(root.dataset.totalModules) || 0;
    const nameField = root.querySelector("[data-role-name]");

    const readOnly = new Set(parseList(root.dataset.readOnly));

    const inputs = Array.from(root.querySelectorAll("[data-role-perm]"));
    const groupButtons = Array.from(root.querySelectorAll("[data-role-group]"));
    const sections = Array.from(root.querySelectorAll("[data-role-section]"));
    const panes = Array.from(root.querySelectorAll("[data-role-pane]"));
    const groupKeys = groupButtons.map((button) => button.dataset.roleGroup);

    /*
     * The save state, Discard, Save and the granted meter live in
     * <x-page-header> now, which sits outside this form — the editor used to
     * draw its own head with all of them, and the role's identity ended up on
     * screen twice. They resolve from the screen wrapper that holds the
     * header and the form; `root` still owns everything inside the form.
     */
    const screen = root.closest("[data-role-screen]") || root;

    const progressText = screen.querySelector("[data-role-progress-text]");
    const progressFill = screen.querySelector("[data-role-progress-fill]");
    const modulesText = screen.querySelector("[data-role-modules-text]");
    const groupsGranted = root.querySelector("[data-role-groups-granted]");
    const changes = screen.querySelector("[data-role-changes]");
    const changeText = screen.querySelector("[data-role-change-text]");
    const saveButton = screen.querySelector("[data-role-save]");
    const discardButton = screen.querySelector("[data-role-discard]");
    const groupSearch = root.querySelector("[data-role-group-search]");
    const groupsEmpty = root.querySelector("[data-role-groups-empty]");

    /* What the server sent. Discard restores exactly this. */
    const saved = new Set(inputs.filter((input) => input.checked).map((input) => input.value));

    const initialGroup = groupButtons.find((button) => button.classList.contains("is-active")) || groupButtons[0];

    let activeGroup = initialGroup ? initialGroup.dataset.roleGroup : null;
    let groupFilter = "all";
    let saving = false;

    function fill(template, values) {
        return Object.keys(values).reduce(
            (text, key) => text.split(":" + key).join(values[key]),
            template || ""
        );
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    /**
     * "<strong>20</strong> of 143 granted" — the count carries the weight,
     * the rest of the sentence does not. The template is escaped before the
     * markup goes in, so the only tags in the result are the ones added here.
     */
    function fillCount(template, count, total) {
        return fill(escapeHtml(template), {
            count: "<strong>" + Number(count) + "</strong>",
            total: Number(total),
        });
    }

    function fillState(granted, total) {
        if (granted === 0) {
            return "none";
        }

        return granted === total ? "full" : "partial";
    }

    function inputsFor(key) {
        return inputs.filter((input) => input.dataset.rolePermGroup === key);
    }

    function grantedCount() {
        return inputs.reduce((count, input) => count + (input.checked ? 1 : 0), 0);
    }

    function diffCount() {
        return inputs.reduce(
            (count, input) => count + (input.checked === saved.has(input.value) ? 0 : 1),
            0
        );
    }

    function paintRow(input) {
        const row = input.closest(".role-mgmt-perm");

        if (row) {
            row.classList.toggle("is-on", input.checked);
        }
    }

    /**
     * Which quick set describes the module as it stands: every switch on,
     * none on, or exactly the read-only ones on. Anything else lights no
     * segment, which is the honest answer.
     */
    function presetFor(groupInputs) {
        const total = groupInputs.length;
        const granted = groupInputs.reduce((count, input) => count + (input.checked ? 1 : 0), 0);

        if (total === 0 || granted === 0) {
            return "none";
        }

        if (granted === total) {
            return "all";
        }

        return groupInputs.every((input) => input.checked === readOnly.has(input.value)) ? "view" : null;
    }

    function paintGroup(key) {
        const groupInputs = inputsFor(key);
        const total = groupInputs.length;
        const granted = groupInputs.reduce((count, input) => count + (input.checked ? 1 : 0), 0);
        const state = fillState(granted, total);

        const tally = root.querySelector('[data-role-group-tally="' + key + '"]');

        if (tally) {
            const count = tally.querySelector(".role-mgmt-group__count");

            if (count) {
                count.textContent = granted + "/" + total;
            }

            tally.dataset.fill = state;
        }

        const enabled = root.querySelector('[data-role-group-enabled="' + key + '"]');

        if (enabled) {
            enabled.innerHTML = fillCount(root.dataset.labelEnabled, granted, total);
            enabled.classList.toggle("is-on", granted > 0);
        }

        const grantAll = root.querySelector('[data-role-grant-all="' + key + '"]');

        if (grantAll) {
            grantAll.setAttribute("aria-checked", total > 0 && granted === total ? "true" : "false");
            grantAll.dataset.fill = state;
        }

        const preset = presetFor(groupInputs);

        root.querySelectorAll('[data-role-preset-group="' + key + '"]').forEach((button) => {
            const isActive = button.dataset.rolePreset === preset;

            button.classList.toggle("is-active", isActive);
            button.setAttribute("aria-pressed", isActive ? "true" : "false");
        });

        const button = root.querySelector('[data-role-group="' + key + '"]');

        if (button) {
            button.dataset.granted = String(granted);
            button.dataset.total = String(total);
        }
    }

    /**
     * A section's tally counts the modules it is currently showing, so it
     * agrees with the rows under it once a search has thinned them out.
     */
    function paintSections() {
        sections.forEach((section) => {
            const buttons = Array.from(section.querySelectorAll("[data-role-group]")).filter((button) => !button.hidden);
            const granted = buttons.reduce((count, button) => count + Number(button.dataset.granted || 0), 0);
            const total = buttons.reduce((count, button) => count + Number(button.dataset.total || 0), 0);
            const tally = section.querySelector("[data-role-section-tally]");

            if (tally) {
                tally.textContent = granted + "/" + total;
            }

            section.hidden = buttons.length === 0;
        });
    }

    function paintSummary() {
        const granted = grantedCount();
        const diff = diffCount();
        const touched = groupKeys.filter((key) => inputsFor(key).some((input) => input.checked)).length;

        if (progressText) {
            /*
             * The header's ratio cell reads "<strong>20</strong> / 143". The
             * editor's old meter wrote a whole sentence here; writing that
             * into the ratio would push the cell's layout around on every
             * click.
             */
            progressText.innerHTML = fillCount(root.dataset.labelRatio, granted, totalPermissions);
        }

        if (progressFill) {
            const percent = Math.round((granted / totalPermissions) * 100);

            progressFill.style.width = percent + "%";

            const bar = progressFill.parentElement;

            if (bar && bar.getAttribute("role") === "progressbar") {
                bar.setAttribute("aria-valuenow", String(percent));
            }
        }

        if (modulesText) {
            modulesText.textContent = fill(root.dataset.labelModules, {
                count: touched,
                total: totalModules,
            });
        }

        if (groupsGranted) {
            groupsGranted.innerHTML = fillCount(root.dataset.labelGrantedShort, granted, totalPermissions);
        }

        if (changeText) {
            if (isProtected) {
                changeText.textContent = root.dataset.labelLocked;
            } else if (diff > 0) {
                changeText.textContent = root.dataset.labelDirty;
            } else {
                /*
                 * A new role has nothing saved to differ from, so "all
                 * changes saved" would be a strange thing to tell someone
                 * who has not saved anything yet.
                 */
                changeText.textContent = isCreate
                    ? root.dataset.labelNew
                    : root.dataset.labelClean;
            }
        }

        if (changes) {
            /*
             * .ph-state carries its tone as a modifier and its dot as a
             * ::before, so there is no is-dirty class to toggle: a protected
             * role keeps the neutral base, a dirty form goes warning, a clean
             * one goes success.
             */
            changes.classList.toggle("ph-state--warning", diff > 0 && !isProtected);
            changes.classList.toggle("ph-state--success", diff === 0 && !isProtected && !isCreate);
        }

        /*
         * On an existing role there is nothing to save until something moves.
         * On a new one there always is — the name and description are part of
         * the same form, and disabling Save until a switch is touched would
         * make the create button look broken.
         */
        if (saveButton) {
            saveButton.disabled = !canEdit || (!isCreate && diff === 0);
        }

        if (discardButton) {
            discardButton.disabled = !canEdit || diff === 0;
        }
    }

    function applyGroupFilter() {
        const term = (groupSearch ? groupSearch.value : "").trim().toLowerCase();
        let shown = 0;

        groupButtons.forEach((button) => {
            const granted = Number(button.dataset.granted || 0);
            const matchesTerm = term === "" || (button.dataset.search || "").indexOf(term) !== -1;
            const matchesState =
                groupFilter === "all" ||
                (groupFilter === "granted" && granted > 0) ||
                (groupFilter === "none" && granted === 0);

            button.hidden = !(matchesTerm && matchesState);

            if (!button.hidden) {
                shown += 1;
            }
        });

        paintSections();
        paintSteps();

        if (groupsEmpty) {
            groupsEmpty.hidden = shown > 0;
        }
    }

    function repaint() {
        groupKeys.forEach(paintGroup);
        paintSummary();
        applyGroupFilter();
    }

    function selectGroup(key) {
        if (!key) {
            return;
        }

        activeGroup = key;

        groupButtons.forEach((button) => {
            const isActive = button.dataset.roleGroup === key;

            button.classList.toggle("is-active", isActive);
            button.setAttribute("aria-pressed", isActive ? "true" : "false");
        });

        panes.forEach((pane) => {
            pane.hidden = pane.dataset.rolePane !== key;
        });

        paintSteps();
    }

    function visibleGroups() {
        return groupButtons
            .filter((button) => !button.hidden)
            .map((button) => button.dataset.roleGroup);
    }

    /* Previous and Next stop at the ends of the list rather than wrapping. */
    function paintSteps() {
        const visible = visibleGroups();
        const at = visible.indexOf(activeGroup);

        root.querySelectorAll("[data-role-step]").forEach((button) => {
            button.disabled = button.dataset.roleStep === "prev"
                ? at <= 0
                : at === -1 || at >= visible.length - 1;
        });
    }

    /**
     * Step through the modules the rail is actually showing, in the order the
     * rail shows them.
     *
     * Walking the full list would land on a module the filter has hidden,
     * leaving the rail with nothing marked active.
     */
    function stepGroup(direction) {
        const visible = visibleGroups();
        const next = visible.indexOf(activeGroup) + direction;

        if (next < 0 || next >= visible.length) {
            return;
        }

        selectGroup(visible[next]);
    }

    function setGroupInputs(key, decide) {
        if (!canEdit) {
            return;
        }

        inputsFor(key).forEach((input) => {
            input.checked = decide(input);
            paintRow(input);
        });

        repaint();
    }

    root.addEventListener("change", (event) => {
        const input = event.target.closest("[data-role-perm]");

        if (!input) {
            return;
        }

        paintRow(input);
        repaint();
    });

    /*
     * The foot names the role, and the name is editable in the same form —
     * so the sentence follows the field rather than the record.
     */
    if (nameField) {
        nameField.addEventListener("input", () => {
            const label = nameField.value.trim();

            root.querySelectorAll("[data-role-foot-name]").forEach((node) => {
                node.textContent = label;
            });
        });
    }

    root.addEventListener("click", (event) => {
        const group = event.target.closest("[data-role-group]");

        if (group) {
            selectGroup(group.dataset.roleGroup);

            return;
        }

        const filter = event.target.closest("[data-role-group-filter]");

        if (filter) {
            groupFilter = filter.dataset.roleGroupFilter;

            root.querySelectorAll("[data-role-group-filter]").forEach((button) => {
                const isActive = button === filter;

                button.classList.toggle("is-active", isActive);
                button.setAttribute("aria-pressed", isActive ? "true" : "false");
            });

            applyGroupFilter();

            return;
        }

        const grantAll = event.target.closest("[data-role-grant-all]");

        if (grantAll) {
            const key = grantAll.dataset.roleGrantAll;
            const turnOn = inputsFor(key).some((input) => !input.checked);

            setGroupInputs(key, () => turnOn);

            return;
        }

        const preset = event.target.closest("[data-role-preset]");

        if (preset) {
            const key = preset.dataset.rolePresetGroup;
            const mode = preset.dataset.rolePreset;

            setGroupInputs(key, (input) => {
                if (mode === "all") {
                    return true;
                }

                if (mode === "none") {
                    return false;
                }

                return readOnly.has(input.value);
            });

            return;
        }

        const step = event.target.closest("[data-role-step]");

        if (step) {
            stepGroup(step.dataset.roleStep === "prev" ? -1 : 1);

            return;
        }

    });

    /*
     * Discard is in the header, outside the form, so the delegated click
     * handler above — which is bound to the form — never sees it.
     */
    if (discardButton) {
        discardButton.addEventListener("click", () => {
            inputs.forEach((input) => {
                input.checked = saved.has(input.value);
                paintRow(input);
            });

            repaint();
        });
    }

    if (groupSearch) {
        groupSearch.addEventListener("input", applyGroupFilter);
    }

    root.addEventListener("submit", () => {
        saving = true;
    });

    window.addEventListener("beforeunload", (event) => {
        const typed = isCreate && nameField !== null && nameField.value.trim() !== "";

        if (saving || (diffCount() === 0 && !typed)) {
            return;
        }

        event.preventDefault();
        event.returnValue = "";
    });

    selectGroup(activeGroup);
    repaint();
})();
