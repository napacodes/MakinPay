"use strict";

$(document).ready(function () {
    const escapeHtml = function (value) {
        return $("<div>").text(value === null || value === undefined ? "" : value).html();
    };

    const csrfToken = function () {
        return $('meta[name="csrf-token"]').attr("content");
    };

    /*
     * Probe a provider's saved credentials and render what the provider
     * itself answered. Bound before the modal guard below returns, so the
     * button keeps working on pages that do not render the manage modal.
     */
    $(document).on("click", ".mra-provider-test", function () {
        const $btn = $(this);
        const url = $btn.data("test-url");
        const $target = $($btn.data("result-target"));
        const originalButton = $btn.html();

        if (!url || !$target.length || $btn.prop("disabled")) {
            return;
        }

        $btn.prop("disabled", true).html(
            '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' +
            escapeHtml($btn.data("testing-label") || "Testing...")
        );

        $target
            .removeClass("d-none alert-success alert-danger")
            .addClass("alert alert-info mt-3 mb-0 py-2 px-3 small")
            .text($btn.data("testing-message") || "Contacting the provider...");

        $.ajax({
            url: url,
            type: "POST",
            headers: { "X-CSRF-TOKEN": csrfToken() },
        })
            .done(function (resp) {
                const result = resp.result || {};
                const provider = resp.provider || {};
                const isOk = !!result.ok;
                const mode = result.mode ? " [" + String(result.mode).toUpperCase() + "]" : "";
                const latency =
                    result.latency_ms !== null && result.latency_ms !== undefined
                        ? " · " + result.latency_ms + "ms"
                        : "";
                const details = result.details || {};

                // The provider's own words are what make a failure actionable:
                // "Invalid client credentials" beats "it did not work".
                let extra = "";
                if (details.provider_error) {
                    extra +=
                        "<br><span class='text-body-secondary'>" +
                        escapeHtml(details.provider_error) +
                        (details.http_status ? " (HTTP " + escapeHtml(details.http_status) + ")" : "") +
                        "</span>";
                }

                $target
                    .removeClass("alert-info alert-success alert-danger")
                    .addClass("alert " + (isOk ? "alert-success" : "alert-danger"))
                    .html(
                        '<i class="fa-solid ' +
                        (isOk ? "fa-circle-check" : "fa-circle-xmark") +
                        ' me-1" aria-hidden="true"></i>' +
                        "<strong>" +
                        escapeHtml((provider.name || "Provider") + mode) +
                        "</strong>" +
                        escapeHtml(latency) +
                        "<br>" +
                        escapeHtml(result.message || "") +
                        extra
                    );
            })
            .fail(function (xhr) {
                $target
                    .removeClass("alert-info alert-success alert-danger")
                    .addClass("alert alert-danger")
                    .html(
                        '<i class="fa-solid fa-circle-xmark me-1" aria-hidden="true"></i>' +
                        escapeHtml($btn.data("fallback-message") || "The connection test could not be completed.") +
                        " (" + escapeHtml(xhr.status) + ")"
                    );
            })
            .always(function () {
                $btn.prop("disabled", false).html(originalButton);
            });
    });

    const modal = $("#manage-mobile-recharge-provider-modal");
    const modalContent = $("#manage-mobile-recharge-provider-data");

    if (!modal.length || !modalContent.length) {
        return;
    }

    $(document).on("click", ".mra-provider-manage-modal", function () {
        const url = $(this).data("edit-url");

        if (!url) {
            return;
        }

        modal.modal("show");
        modalContent.html(
            '<div class="d-flex justify-content-center py-4">' +
                '<div class="spinner-border" role="status">' +
                    '<span class="visually-hidden">Loading...</span>' +
                '</div>' +
            '</div>'
        );

        $.get(url, function (data) {
            modalContent.html(data);

            if (typeof tooltipTriger === "function") {
                tooltipTriger();
            }

            if (typeof handleImagePreview === "function") {
                handleImagePreview();
            }
        });
    });
});
