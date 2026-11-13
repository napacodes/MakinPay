"use strict";

/**
 * Roles index.
 *
 * The filters are a real GET form — the server decides which roles the page
 * shows. This only narrows what is already on screen while somebody types, so
 * the table reacts without a round trip.
 *
 * Narrowing is safe because it can only ever hide: the rendered set is the
 * server's answer for the current term, and typing more letters can only
 * shrink it. Clearing the box is the one case that needs rows back, so that
 * submits. The type segment submits for the same reason — a page opened at
 * `?type=custom` has no protected rows to reveal, so filtering it in the
 * browser would quietly show an answer that is not the whole one.
 */
(function () {
    const root = document.querySelector("[data-role-index]");

    if (!root) {
        return;
    }

    const form = root.querySelector("[data-role-filter-form]");
    const search = root.querySelector("[data-role-search]");
    const rows = Array.from(root.querySelectorAll("[data-role-row]"));
    const empty = root.querySelector("[data-role-empty]");

    if (!search || rows.length === 0) {
        return;
    }

    const submitted = search.value.trim();

    function fill(template, values) {
        return Object.keys(values).reduce(
            (text, key) => text.split(":" + key).join(values[key]),
            template || ""
        );
    }

    function apply() {
        const term = search.value.trim().toLowerCase();
        let shown = 0;

        rows.forEach((row) => {
            const matches = term === "" || (row.dataset.search || "").indexOf(term) !== -1;

            row.hidden = !matches;

            if (matches) {
                shown += 1;
            }
        });

        if (empty) {
            empty.textContent = fill(root.dataset.labelNoMatch, { term: search.value.trim() });
            empty.hidden = shown > 0;
        }
    }

    search.addEventListener("input", () => {
        /* Emptying the box asks for rows the server did not send. */
        if (search.value.trim() === "" && submitted !== "" && form) {
            form.submit();

            return;
        }

        apply();
    });

    apply();
})();
