<script>
    'use strict'

    $(document).ready(function () {
        if ($("#manageModal").length && typeof editFormByModal === "function") {
            editFormByModal("manageModal", "edit-append", false, true);
        }
    });

    /* ---------------- Integration Center: filter, search, switch ---------------- */

    /*
     * Bound to the page wrapper rather than to document, so the Mobile
     * Recharge screen — which includes this same file for the manage modal —
     * runs none of it.
     */
    (function () {
        const page = document.querySelector("[data-settings-plugin]");

        if (!page) {
            return;
        }

        const strings = {
            on: page.dataset.labelOn || "",
            off: page.dataset.labelOff || "",
            active: page.dataset.labelActive || "",
            offState: page.dataset.labelOffState || "",
            failed: page.dataset.msgToggleFailed || "",
        };

        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";

        function notify(status, text) {
            if (typeof Notify === "function") {
                new Notify({ status: status, text: text, autotimeout: 4000 });
                return;
            }

            if (status === "error" && window.console) {
                console.error(text);
            }
        }

        /* Each group pane filters on its own; they never share a state. */
        function paneState(pane) {
            if (!pane.dkState) {
                pane.dkState = { filter: "all", query: "" };
            }

            return pane.dkState;
        }

        function applyFilters(pane) {
            const state = paneState(pane);
            const cards = Array.from(pane.querySelectorAll("[data-plugin-card]"));
            let shown = 0;

            cards.forEach(function (card) {
                const matchesFilter = state.filter === "all" || card.dataset.state === state.filter;
                const matchesQuery = state.query === "" || (card.dataset.search || "").indexOf(state.query) !== -1;
                const visible = matchesFilter && matchesQuery;

                card.hidden = !visible;

                if (visible) {
                    shown += 1;
                }
            });

            const empty = pane.querySelector("[data-plugin-no-results]");

            if (empty) {
                empty.hidden = shown > 0;
            }

            const grid = pane.querySelector("[data-plugin-grid]");

            if (grid) {
                grid.hidden = shown === 0;
            }

            /*
             * Counted off the cards rather than left on the figures the server
             * rendered: a switch thrown on this page moves a plugin between
             * Active and Off, and a stale count beside the filter that would
             * now return a different set is worse than no count.
             */
            pane.querySelectorAll("[data-plugin-filter-count]").forEach(function (node) {
                const key = node.dataset.pluginFilterCount;

                node.textContent = key === "all"
                    ? cards.length
                    : cards.filter(function (card) { return card.dataset.state === key; }).length;
            });

            pane.querySelectorAll("[data-plugin-filter]").forEach(function (button) {
                const on = button.dataset.pluginFilter === state.filter;

                button.classList.toggle("is-active", on);
                button.setAttribute("aria-pressed", on ? "true" : "false");
            });
        }

        function eachPane(fn) {
            page.querySelectorAll("[data-plugin-pane]").forEach(fn);
        }

        function toggleUrlFor(id) {
            /* The blade renders the route with a 0 placeholder so the id never
               has to be concatenated into a path by hand. */
            return page.dataset.toggleUrl.replace(/\/0\/status$/, "/" + id + "/status");
        }

        page.addEventListener("click", function (event) {
            const filter = event.target.closest("[data-plugin-filter]");

            if (filter) {
                const pane = filter.closest("[data-plugin-pane]");

                paneState(pane).filter = filter.dataset.pluginFilter;
                applyFilters(pane);

                return;
            }

            const clear = event.target.closest("[data-plugin-clear-filters]");

            if (clear) {
                const pane = clear.closest("[data-plugin-pane]");
                const state = paneState(pane);
                const search = pane.querySelector("[data-plugin-search]");

                state.filter = "all";
                state.query = "";

                if (search) {
                    search.value = "";
                }

                applyFilters(pane);
            }
        });

        page.addEventListener("input", function (event) {
            const search = event.target.closest("[data-plugin-search]");

            if (!search) {
                return;
            }

            const pane = search.closest("[data-plugin-pane]");

            paneState(pane).query = search.value.trim().toLowerCase();
            applyFilters(pane);
        });

        /*
         * "Show them" filters every group to the ones missing keys and brings
         * the first group that has any to the front — those plugins can be in
         * any group, and filtering only the open one would look like the
         * button had done nothing.
         */
        page.querySelector("[data-plugin-show-setup]")?.addEventListener("click", function () {
            let target = null;

            eachPane(function (pane) {
                paneState(pane).filter = "setup";
                applyFilters(pane);

                if (!target && pane.querySelector('[data-plugin-card][data-state="setup"]')) {
                    target = pane;
                }
            });

            if (target) {
                page.querySelector('[data-plugin-group="' + target.dataset.pluginPane + '"]')?.click();
                target.scrollIntoView({ behavior: "smooth", block: "start" });
            }
        });

        /*
         * The card switch. The UI moves first and is put back if the server
         * disagrees, so the ordinary case costs no wait — and a provider the
         * server refuses to enable (reCAPTCHA with keys that would lock
         * everyone out of login) visibly snaps back rather than lying.
         */
        page.addEventListener("click", function (event) {
            const toggle = event.target.closest("[data-plugin-toggle]");

            if (!toggle) {
                return;
            }

            const card = toggle.closest("[data-plugin-card]");
            const label = toggle.querySelector("[data-plugin-toggle-label]");
            const statusLabel = card.querySelector("[data-plugin-status-label]");
            const wasOn = toggle.getAttribute("aria-checked") === "true";
            const wasState = card.dataset.state;
            const next = !wasOn;

            function paint(on, state) {
                toggle.setAttribute("aria-checked", on ? "true" : "false");
                card.dataset.state = state;

                if (label) {
                    label.textContent = on ? strings.on : strings.off;
                }

                if (statusLabel && state !== "setup") {
                    statusLabel.textContent = on ? strings.active : strings.offState;
                }
            }

            /* A plugin with no keys stays "Needs setup" whichever way the
               switch is thrown — keys are what it is waiting for. */
            paint(next, wasState === "setup" ? "setup" : (next ? "active" : "off"));
            toggle.disabled = true;

            fetch(toggleUrlFor(card.dataset.pluginId), {
                method: "POST",
                headers: {
                    "X-CSRF-TOKEN": csrf,
                    "X-Requested-With": "XMLHttpRequest",
                    Accept: "application/json",
                },
                credentials: "same-origin",
                body: new URLSearchParams({ _method: "PATCH", status: next ? "1" : "0" }),
            })
                .then(function (response) {
                    return response.json().then(function (payload) {
                        if (!response.ok) {
                            throw new Error(payload.message || strings.failed);
                        }

                        return payload;
                    });
                })
                .then(function (payload) {
                    paint(payload.enabled, payload.state);

                    if (payload.blocked) {
                        notify("warning", payload.message);
                    }

                    eachPane(function (pane) { applyFilters(pane); });
                })
                .catch(function (failure) {
                    paint(wasOn, wasState);
                    notify("error", failure.message || strings.failed);
                })
                .finally(function () {
                    toggle.disabled = false;
                });
        });

        eachPane(function (pane) { applyFilters(pane); });
    })();

    /* ---------------- Manage modal ---------------- */

    $(document).on("click", "[data-field-reveal]", function () {
        const button = $(this);
        const input = button.closest(".plugin-field__control").find(".plugin-field__input");
        const icon = button.find("i");
        const revealed = input.attr("type") === "text";

        input.attr("type", revealed ? "password" : "text");
        icon.attr("class", revealed ? "fa-solid fa-eye" : "fa-solid fa-eye-slash");
    });

    $(document).on("click", "[data-plugin-test]", function () {
        const button = $(this);
        const form = button.closest("form");
        const result = form.find("[data-plugin-test-result]");
        const label = button.find("[data-plugin-test-label]");
        const icon = button.find(".settings-plugin-test-card__button-icon i");
        const defaultLabel = label.text();
        const defaultIconClass = icon.attr("class");
        const testUrl = button.data("test-url");
        const testingLabel = button.data("testing-label") || "Testing...";
        const testingMessage = button.data("testing-message") || "Testing provider connection...";
        const fallbackMessage = button.data("fallback-message") || "Unable to test this provider right now.";

        if (!testUrl || button.prop("disabled")) {
            return;
        }

        button.prop("disabled", true).addClass("is-loading");
        icon.attr("class", "fa-solid fa-spinner fa-spin");
        label.text(testingLabel);
        result.removeClass("d-none is-success is-warning is-error")
            .addClass("is-info")
            .text(testingMessage);

        $.ajax({
            url: testUrl,
            method: "POST",
            data: $.param(form.serializeArray().filter(function (field) {
                return field.name !== "_method";
            })),
            success: function (response) {
                const status = response.status === "success"
                    ? "is-success"
                    : (response.status === "warning" ? "is-warning" : "is-error");

                result.removeClass("is-info is-success is-warning is-error")
                    .addClass(status)
                    .text(response.message || "Provider connection test completed.");
            },
            error: function (xhr) {
                const message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : fallbackMessage;

                result.removeClass("is-info is-success is-warning is-error")
                    .addClass("is-error")
                    .text(message);
            },
            complete: function () {
                button.prop("disabled", false).removeClass("is-loading");
                icon.attr("class", defaultIconClass);
                label.text(defaultLabel);
            },
        });
    });
</script>
