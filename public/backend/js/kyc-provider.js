"use strict";

(function ($) {
    function syncScope($form) {
        const $selected = $form.find("[data-kyc-scope]:checked");
        const coversAll = !$selected.length || $selected.val() === "all";

        $form.find("[data-kyc-all-input]").prop("disabled", !coversAll);
        $form.find("[data-kyc-country-select]").prop("disabled", coversAll);
        $form.find("[data-kyc-country-wrap]").toggleClass("d-none", coversAll);
        $form.find("[data-kyc-scope]").each(function () {
            $(this).closest(".kyc-setup__choice").toggleClass("is-selected", this.checked);
        });
    }

    $(function () {
        $("[data-kyc-provider-form]").each(function () {
            syncScope($(this));
        });
    });

    $(document).on("change", "[data-kyc-provider-form] [data-kyc-scope]", function () {
        syncScope($(this).closest("[data-kyc-provider-form]"));
    });

    $(document).on("click", "[data-kyc-provider-test]", function () {
        const $button = $(this);
        const $form = $button.closest("form");
        const $result = $form.find("[data-kyc-provider-test-result]");
        const $label = $button.find("[data-kyc-provider-test-label]");
        const $icon = $button.find(".pgm-test-btn__icon i");
        const defaultLabel = $label.text();
        const defaultIconClass = $icon.attr("class");
        const testUrl = $button.data("test-url");
        const allowedFields = ["_token", "driver", "mode", "provider_id"];
        const payload = $form.serializeArray().filter(function (field) {
            return allowedFields.includes(field.name) || field.name.startsWith("credentials[");
        });

        if (!testUrl || $button.prop("disabled")) {
            return;
        }

        $button.prop("disabled", true).addClass("is-loading");
        $icon.attr("class", "fa-solid fa-spinner fa-spin");
        $label.text($button.data("testing-label") || "Testing...");
        $result.removeClass("d-none alert-success alert-warning alert-danger")
            .addClass("alert alert-info")
            .text($button.data("testing-message") || "Testing provider connection...");

        $.ajax({
            url: testUrl,
            method: "POST",
            data: $.param(payload),
            success: function (response) {
                const status = response.status === "success"
                    ? "success"
                    : (response.status === "warning" ? "warning" : "danger");

                $result.removeClass("alert-info alert-success alert-warning alert-danger")
                    .addClass("alert-" + status)
                    .text(response.message || "Provider connection test completed.");
            },
            error: function (xhr) {
                const message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : ($button.data("fallback-message") || "Unable to test this provider right now.");

                $result.removeClass("alert-info alert-success alert-warning alert-danger")
                    .addClass("alert-danger")
                    .text(message);
            },
            complete: function () {
                $button.prop("disabled", false).removeClass("is-loading");
                $icon.attr("class", defaultIconClass);
                $label.text(defaultLabel);
            },
        });
    });

    /*
     * The card's own connection test.
     *
     * The modal's tester above serialises the surrounding <form> and swaps
     * the icon inside .pgm-test-btn__icon. A catalog card has neither, so
     * reusing that handler would post an empty payload and write its answer
     * nowhere. This one carries the saved provider on the button itself and
     * answers inside the card.
     */
    $(document).on("click", "[data-kyc-provider-card-test]", function () {
        const $button = $(this);
        const $card = $button.closest(".kyc-pcard");
        const $result = $card.find("[data-kyc-provider-card-test-result]");
        const $icon = $button.find("i").first();
        const defaultIconClass = $icon.attr("class");
        const testUrl = $button.data("test-url");
        const token = $button.attr("data-token") || $("meta[name='csrf-token']").attr("content");

        if (!testUrl || $button.prop("disabled")) {
            return;
        }

        function say(state, message) {
            $result.removeClass("d-none is-pending is-success is-warning is-danger")
                .addClass(state)
                .text(message);
        }

        $button.prop("disabled", true).addClass("is-loading");
        $icon.attr("class", "fa-solid fa-spinner fa-spin");
        say("is-pending", $button.data("testing-message") || "Testing provider connection...");

        $.ajax({
            url: testUrl,
            method: "POST",
            data: {
                _token: token,
                driver: $button.data("driver"),
                mode: $button.data("mode"),
                provider_id: $button.data("provider-id"),
            },
            success: function (response) {
                const state = response.status === "success"
                    ? "is-success"
                    : (response.status === "warning" ? "is-warning" : "is-danger");

                say(state, response.message || "Provider connection test completed.");
            },
            error: function (xhr) {
                const message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : ($button.data("fallback-message") || "Unable to test this provider right now.");

                say("is-danger", message);
            },
            complete: function () {
                $button.prop("disabled", false).removeClass("is-loading");
                $icon.attr("class", defaultIconClass);
            },
        });
    });
})(jQuery);
