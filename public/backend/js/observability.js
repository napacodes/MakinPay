"use strict";

/*
 * System Health — admin behaviour.
 *
 * jQuery only, matching the rest of this admin panel. No build step and no
 * framework: this file loads straight from public/ like every other script
 * in this panel.
 */
(function ($) {
    /* ------------------------------------------------------------------
     * Issues list: selection and the bulk bar
     * ---------------------------------------------------------------- */

    function refreshBulkBar() {
        var $page = $("[data-obs-issues]");

        if (!$page.length) {
            return;
        }

        var ids = $page.find("[data-obs-select]:checked").map(function () {
            return this.value;
        }).get();

        $page.find("[data-obs-bulk]").toggleClass("d-none", ids.length === 0);
        $page.find("[data-obs-bulk-count]").text(ids.length);

        /*
         * One hidden input per selection rather than a joined string: the form
         * request validates every id against the table, and a CSV would have to
         * be split somewhere before it could be checked.
         */
        var $holder = $page.find("[data-obs-bulk-ids]").empty();

        ids.forEach(function (id) {
            $holder.append($("<input>").attr({ type: "hidden", name: "groups[]" }).val(id));
        });
    }

    $(document).on("change", "[data-obs-select]", refreshBulkBar);

    $(document).on("change", "[data-obs-select-all]", function () {
        $("[data-obs-select]").prop("checked", this.checked);
        refreshBulkBar();
    });

    /*
     * Removing a filter pill rewrites the query string rather than mutating
     * hidden state, so the URL stays the whole description of what is being
     * shown and can be handed to somebody else.
     */
    $(document).on("click", "[data-obs-remove-token]", function () {
        var token = String($(this).data("obs-remove-token"));
        var $input = $("#obs-query");

        if (!$input.length) {
            return;
        }

        $input.val($input.val().split(/\s+/).filter(function (part) {
            return part !== "" && part !== token;
        }).join(" "));

        $input.closest("form").trigger("submit");
    });

    /* ------------------------------------------------------------------
     * Editor pane
     * ---------------------------------------------------------------- */

    /*
     * The copy button reads the pane already on the screen rather than a
     * duplicate of it in a data attribute. A four-hundred-line report would
     * otherwise sit in the document twice, and the copy could drift from what
     * the reader is looking at.
     *
     * Line numbers live in their own element and are skipped, so what lands on
     * the clipboard is the code — pasteable straight back into a file.
     */
    $(document).on("click", "[data-obs-copy-code]", function () {
        var $button = $(this);

        var lines = $button.closest("[data-obs-editor]").find(".obs-editor__text").map(function () {
            return this.textContent;
        }).get();

        writeToClipboard(lines.join("\n"), function () {
            var $label = $button.find("span");
            var original = $label.text();

            $button.addClass("is-done");
            $label.text($button.data("obs-copied-label") || "Copied");

            window.setTimeout(function () {
                $button.removeClass("is-done");
                $label.text(original);
            }, 1600);
        });
    });

    function writeToClipboard(text, onDone) {
        if (!navigator.clipboard) {
            window.prompt("Copy", text);

            return;
        }

        navigator.clipboard.writeText(text).then(onDone);
    }

    /* ------------------------------------------------------------------
     * Debug report
     * ---------------------------------------------------------------- */

    $(document).on("click", "[data-obs-copy-report]", function () {
        var url = $(this).data("obs-report-url");

        $.get(url).done(function (report) {
            if (!navigator.clipboard) {
                window.prompt("Copy the report", report);

                return;
            }

            navigator.clipboard.writeText(report).then(function () {
                notify("success", "Copied", "The debug report is on your clipboard.");
            });
        }).fail(function (response) {
            notify("error", "Export blocked", response.responseText || "The report could not be built.");
        });
    });

    function notify(status, title, text) {
        if (typeof window.Notify === "undefined") {
            return;
        }

        new window.Notify({ status: status, title: title, text: text, autoclose: true });
    }

    $(function () {
        refreshBulkBar();
    });
})(jQuery);
