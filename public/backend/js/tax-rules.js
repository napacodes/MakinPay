"use strict";

/*
 * Tax rules admin: the list and the rule form.
 *
 * Display aids only. A switch posts its form when flipped, a delete asks
 * first, and the form shows the country list only for a country rule. The
 * server validates and stores every rule; no tax is calculated here.
 */
(function () {
    function ready(callback) {
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", callback);
        } else {
            callback();
        }
    }

    function bindAutosubmit() {
        document.addEventListener("change", function (event) {
            var input = event.target.closest("[data-tr-autosubmit]");

            if (!input || !input.form) {
                return;
            }

            if (typeof input.form.requestSubmit === "function") {
                input.form.requestSubmit();
            } else {
                input.form.submit();
            }
        });
    }

    function bindConfirm() {
        document.addEventListener("submit", function (event) {
            var form = event.target.closest("form[data-tr-confirm]");

            if (form && !window.confirm(form.getAttribute("data-tr-confirm"))) {
                event.preventDefault();
            }
        });
    }

    function bindScope() {
        var scope = document.querySelector("[data-tr-scope]");
        var countries = document.querySelector("[data-tr-countries]");

        if (!scope || !countries) {
            return;
        }

        function sync() {
            countries.hidden = scope.value !== "countries";
        }

        scope.addEventListener("change", sync);
        sync();
    }

    /*
     * Charge by amount brackets: shows either the single rate or the bracket
     * table, and disables the inputs of the one hidden so they are not sent.
     * Rows are added from the template and removed in place; the "From"
     * column only echoes the limit above it.
     */
    function bindBrackets() {
        var section = document.querySelector("[data-tr-brackets]");
        var choices = document.querySelectorAll("[data-tr-charge-by]");

        if (!section || choices.length === 0) {
            return;
        }

        var rows = section.querySelector("[data-tr-bracket-rows]");
        var template = section.querySelector("[data-tr-bracket-template]");
        var flats = document.querySelectorAll("[data-tr-flat]");
        var next = rows.querySelectorAll("[data-tr-bracket-row]").length;

        function byBrackets() {
            var checked = document.querySelector("[data-tr-charge-by]:checked");

            return checked !== null && checked.value === "brackets";
        }

        function setEnabled(container, enabled) {
            container.hidden = !enabled;
            container.querySelectorAll("input, select, button").forEach(function (input) {
                input.disabled = !enabled;
            });
        }

        function syncFrom() {
            var previous = null;

            rows.querySelectorAll("[data-tr-bracket-row]").forEach(function (row, index) {
                var from = row.querySelector("[data-tr-bracket-from]");
                var limit = row.querySelector("[data-tr-bracket-up-to]").value.trim();

                if (index === 0) {
                    from.textContent = "0";
                } else {
                    from.textContent = previous === null ? "—" : "> " + previous;
                }

                previous = limit !== "" && !isNaN(Number(limit)) ? limit : null;
            });
        }

        function sync() {
            var brackets = byBrackets();

            setEnabled(section, brackets);
            flats.forEach(function (flat) {
                setEnabled(flat, !brackets);
            });
            syncFrom();
        }

        section.addEventListener("click", function (event) {
            if (event.target.closest("[data-tr-bracket-add]")) {
                var fragment = template.content.cloneNode(true);

                fragment.querySelectorAll("input").forEach(function (input) {
                    input.name = input.name.replace("__INDEX__", String(next));
                });
                next++;
                rows.appendChild(fragment);
                syncFrom();

                return;
            }

            var remove = event.target.closest("[data-tr-bracket-remove]");

            if (remove) {
                var row = remove.closest("[data-tr-bracket-row]");

                if (rows.querySelectorAll("[data-tr-bracket-row]").length > 1) {
                    row.remove();
                } else {
                    row.querySelectorAll("input").forEach(function (input) {
                        input.value = "";
                    });
                }
                syncFrom();
            }
        });

        section.addEventListener("input", function (event) {
            if (event.target.closest("[data-tr-bracket-up-to]")) {
                syncFrom();
            }
        });

        choices.forEach(function (choice) {
            choice.addEventListener("change", sync);
        });
        sync();
    }

    ready(function () {
        bindAutosubmit();
        bindConfirm();
        bindScope();
        bindBrackets();
    });
})();
