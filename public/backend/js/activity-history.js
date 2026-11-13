"use strict";

/*
 * Admin → Security → Activity history.
 *
 * Three jobs: open the detail drawer for a row, run the one action the drawer
 * offers, and drive the date-range button. Everything it needs to say to a
 * person arrives on the page root as a data-* attribute, because this file is
 * never passed through the translator.
 */
(function () {
    const page = document.querySelector("[data-ah-page]");

    if (!page) {
        return;
    }

    const isDemo = page.dataset.demo === "1";

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute("content") : "";
    }

    function toast(type, message) {
        if (typeof window.notifyEvs === "function") {
            window.notifyEvs(type, message);
        }
    }

    function text(node, value) {
        if (node) {
            node.textContent = value || "";
        }
    }

    /* ── Confirmed forms (sign out all sessions) ───────────────────────── */

    document.querySelectorAll("[data-ah-confirm]").forEach(function (form) {
        form.addEventListener("submit", function (event) {
            if (isDemo) {
                event.preventDefault();
                toast("error", page.dataset.i18nDemo);

                return;
            }

            if (!window.confirm(form.dataset.ahConfirm)) {
                event.preventDefault();
            }
        });
    });

    /* ── Date range ────────────────────────────────────────────────────── */

    const rangeButton = page.querySelector("[data-ah-daterange]");
    const rangeInput = page.querySelector("[data-ah-daterange-value]");

    /*
     * The field is named `range`, not `daterange`, and that is deliberate.
     * A legacy picker script is included on every admin page and writes its
     * own default into every `input[name="daterange"]` it can find, from a
     * jQuery ready handler that runs after this file. Any value written here
     * lost that race, so the button read "Last 7 days" while the form was
     * armed to submit thirty. Renaming the field puts it out of that
     * script's reach; the controller maps `range` onto the shared filter.
     */

    if (rangeButton && rangeInput && window.jQuery && window.jQuery.fn.daterangepicker && window.moment) {
        const form = rangeButton.closest("form");
        const $button = window.jQuery(rangeButton);
        const current = (rangeInput.value || "").split(",");

        const options = {
            autoUpdateInput: false,
            /* The panel opens towards the page, which is the other way in RTL. */
            opens: document.documentElement.dir === "rtl" ? "right" : "left",
            locale: { cancelLabel: rangeButton.dataset.i18nAll, format: "YYYY-MM-DD" },
        };

        if (current.length === 2 && current[0]) {
            options.startDate = window.moment(current[0]);
            options.endDate = window.moment(current[1]);
        }

        $button.daterangepicker(options);

        $button.on("apply.daterangepicker", function (event, picker) {
            rangeInput.value = picker.startDate.format("YYYY-MM-DD") + "," + picker.endDate.format("YYYY-MM-DD");
            form.submit();
        });

        /* Cancel means "all time": an empty value the controller reads as a choice. */
        $button.on("cancel.daterangepicker", function () {
            rangeInput.value = "";
            form.submit();
        });
    }

    /* ── Drawer ────────────────────────────────────────────────────────── */

    const drawer = document.querySelector("[data-ah-drawer]");
    const overlay = document.querySelector("[data-ah-overlay]");

    if (!drawer || !overlay) {
        return;
    }

    const nodes = {
        avatar: drawer.querySelector("[data-ah-avatar]"),
        name: drawer.querySelector("[data-ah-name]"),
        role: drawer.querySelector("[data-ah-role]"),
        handle: drawer.querySelector("[data-ah-handle]"),
        assess: drawer.querySelector("[data-ah-assess]"),
        riskLabel: drawer.querySelector("[data-ah-risk-label]"),
        riskReason: drawer.querySelector("[data-ah-risk-reason]"),
        facts: drawer.querySelector("[data-ah-facts]"),
        history: drawer.querySelector("[data-ah-history]"),
        account: drawer.querySelector("[data-ah-account]"),
        cta: drawer.querySelector("[data-ah-cta]"),
    };

    let lastTrigger = null;
    let currentAction = null;

    function showUrl(id) {
        /* The route was printed with id 0; swap the last segment for the real one. */
        return page.dataset.showUrl.replace(/\/0$/, "/" + id);
    }

    function open() {
        overlay.hidden = false;
        drawer.hidden = false;
        document.body.style.overflow = "hidden";
        drawer.querySelector("[data-ah-close]")?.focus();
    }

    function close() {
        overlay.hidden = true;
        drawer.hidden = true;
        document.body.style.overflow = "";
        lastTrigger?.focus();
        lastTrigger = null;
    }

    overlay.addEventListener("click", close);
    drawer.querySelector("[data-ah-close]")?.addEventListener("click", close);

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && !drawer.hidden) {
            close();
        }
    });

    /* Keep Tab inside the panel: it is a modal dialog while it is open. */
    drawer.addEventListener("keydown", function (event) {
        if (event.key !== "Tab") {
            return;
        }

        const focusable = drawer.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])');

        if (!focusable.length) {
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    function paintAccount(account) {
        text(nodes.name, account.name);
        text(nodes.handle, account.username ? "@" + account.username : "");
        text(nodes.role, account.role);

        nodes.role.className = "ah-pill" + (account.tone === "neutral" ? "" : " ah-pill--" + (account.tone === "brand" ? "merchant" : "agent"));

        nodes.avatar.className = "ah-avatar ah-avatar--lg";
        nodes.avatar.innerHTML = "";

        if (account.avatar) {
            const image = document.createElement("img");
            image.src = account.avatar;
            image.alt = "";
            image.addEventListener("error", function () { image.remove(); });
            nodes.avatar.appendChild(image);
        } else {
            nodes.avatar.textContent = account.initials || "?";
        }

        if (account.url) {
            nodes.account.href = account.url;
            nodes.account.removeAttribute("aria-disabled");
        } else {
            nodes.account.removeAttribute("href");
            nodes.account.setAttribute("aria-disabled", "true");
        }
    }

    function paintFacts(facts) {
        nodes.facts.innerHTML = "";

        facts.forEach(function (fact) {
            const row = document.createElement("div");
            row.className = "ah-fact";

            const key = document.createElement("dt");
            key.textContent = fact.key;

            const value = document.createElement("dd");
            value.textContent = fact.value;

            if (fact.mono) {
                value.classList.add("is-mono");
            }

            row.append(key, value);
            nodes.facts.appendChild(row);
        });
    }

    function paintHistory(history) {
        nodes.history.innerHTML = "";

        if (!history.length) {
            const empty = document.createElement("li");
            empty.className = "ah-history__empty";
            empty.textContent = "—";
            nodes.history.appendChild(empty);

            return;
        }

        history.forEach(function (entry) {
            const row = document.createElement("li");
            row.className = "ah-history__row";

            const dot = document.createElement("span");
            dot.className = "ah-history__dot ah-history__dot--" + entry.tone;

            const label = document.createElement("span");
            label.className = "ah-history__text";
            label.textContent = entry.text;

            const when = document.createElement("span");
            when.className = "ah-history__when";
            when.textContent = entry.when;

            row.append(dot, label, when);
            nodes.history.appendChild(row);
        });
    }

    function paint(data) {
        paintAccount(data.account);

        nodes.assess.className = "ah-assess ah-assess--" + data.risk.tone;
        text(nodes.riskLabel, data.risk.label);
        text(nodes.riskReason, data.risk.reason);

        paintFacts(data.facts);
        paintHistory(data.history);

        currentAction = data.action && data.action.url ? data.action : null;

        if (currentAction) {
            nodes.cta.hidden = false;
            nodes.cta.textContent = currentAction.label;
            nodes.cta.title = currentAction.hint || "";
            nodes.cta.className = "ah-btn ah-btn--cta"
                + (currentAction.tone === "warning" ? " is-warning" : "")
                + (currentAction.tone === "brand" ? " is-brand" : "");
        } else {
            nodes.cta.hidden = true;
        }
    }

    page.querySelectorAll("[data-ah-row]").forEach(function (row) {
        row.addEventListener("click", function () {
            lastTrigger = row;
            text(nodes.name, page.dataset.i18nLoading);
            open();

            fetch(showUrl(row.dataset.ahId), {
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin",
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error("request failed");
                    }

                    return response.json();
                })
                .then(paint)
                .catch(function () {
                    close();
                    toast("error", page.dataset.i18nError);
                });
        });
    });

    /* ── The drawer's one action ───────────────────────────────────────── */

    nodes.cta.addEventListener("click", function () {
        if (!currentAction) {
            return;
        }

        if (isDemo) {
            toast("error", page.dataset.i18nDemo);

            return;
        }

        nodes.cta.disabled = true;

        const body = new URLSearchParams({ _token: csrfToken() });

        fetch(currentAction.url, {
            method: "POST",
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            credentials: "same-origin",
            body: body,
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                toast(result.data.type || (result.ok ? "success" : "error"), result.data.message || page.dataset.i18nError);

                if (result.ok) {
                    /*
                     * The verdict, the session state and the header counts all
                     * move together, so the page is reloaded rather than three
                     * places being patched into agreement by hand.
                     */
                    window.location.reload();
                }
            })
            .catch(function () {
                toast("error", page.dataset.i18nError);
            })
            .finally(function () {
                nodes.cta.disabled = false;
            });
    });
})();
