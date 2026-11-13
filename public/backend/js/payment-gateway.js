"use strict";

$(document).ready(function () {
    if ($("#edit-payment-gateway-modal").length && typeof editFormByModal === "function") {
        editFormByModal("edit-payment-gateway-modal", "edit-payment-gateway-append", false, true);
    }
});

/**
 * Renders a diagnostics outcome using the panel's own state classes so the
 * result reads as part of the modal rather than a stock Bootstrap alert.
 */
function renderGatewayTestResult(result, state, message) {
    const icons = {
        success: "fa-solid fa-circle-check",
        warning: "fa-solid fa-triangle-exclamation",
        error: "fa-solid fa-circle-xmark",
        info: "fa-solid fa-spinner fa-spin",
    };

    result
        .removeClass("d-none is-success is-warning is-error is-info")
        .addClass("is-" + state)
        .empty()
        .append($("<i>", {class: "pgm-test-result__icon " + icons[state], "aria-hidden": "true"}))
        .append($("<span>", {class: "pgm-test-result__message", text: message}));
}

$(document).on("click", "[data-gateway-test]", function () {
    const button = $(this);
    const form = button.closest("form");
    const result = form.find("[data-gateway-test-result]");
    const label = button.find("[data-gateway-test-label]");
    const icon = button.find(".pgm-test-btn__icon i");
    const defaultLabel = label.text();
    const defaultIconClass = icon.attr("class");
    const testUrl = button.data("test-url");
    const testingLabel = button.data("testing-label") || "Checking...";
    const testingMessage = button.data("testing-message") || "Validating gateway credentials...";
    const fallbackMessage = button.data("fallback-message") || "Unable to validate this gateway right now.";

    if (!testUrl || button.prop("disabled")) {
        return;
    }

    button.prop("disabled", true).addClass("is-loading");
    icon.attr("class", "fa-solid fa-spinner fa-spin");
    label.text(testingLabel);
    renderGatewayTestResult(result, "info", testingMessage);

    $.ajax({
        url: testUrl,
        method: "POST",
        data: $.param(form.serializeArray().filter(function (field) {
            return field.name !== "_method";
        })),
        success: function (response) {
            const state = response.status === "success"
                ? "success"
                : (response.status === "warning" ? "warning" : "error");

            renderGatewayTestResult(result, state, response.message || "Gateway test completed.");
        },
        error: function (xhr) {
            const message = xhr.responseJSON && xhr.responseJSON.message
                ? xhr.responseJSON.message
                : fallbackMessage;

            renderGatewayTestResult(result, "error", message);
        },
        complete: function () {
            button.prop("disabled", false).removeClass("is-loading");
            icon.attr("class", defaultIconClass);
            label.text(defaultLabel);
        },
    });
});
