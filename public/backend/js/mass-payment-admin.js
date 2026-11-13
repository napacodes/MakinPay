"use strict";

/*
 * Mass payments admin: the batch list and one batch's detail.
 *
 * Everything here is a display aid. It submits the row filter when it
 * changes, reopens a dialog the server sent back with errors, stops a
 * double click from posting an action twice, points a payout line's dialog
 * at that line, and shows a payout account an admin asked to see.
 * MassPaymentService and MassPayoutService decide what happens; no money is
 * calculated here.
 */
(function () {
    function ready(callback) {
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", callback);
        } else {
            callback();
        }
    }

    function showModal(element) {
        if (window.coreui && window.coreui.Modal) {
            window.coreui.Modal.getOrCreateInstance(element).show();

            return;
        }

        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
            window.jQuery(element).modal("show");
        }
    }

    function bindAutosubmit() {
        document.addEventListener("change", function (event) {
            var input = event.target.closest("[data-mpa-autosubmit]");

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

    function bindSubmitOnce() {
        document.addEventListener("submit", function (event) {
            var form = event.target.closest("form[data-mpa-action-form]");

            if (!form) {
                return;
            }

            if (form.getAttribute("data-mpa-submitted") === "1") {
                event.preventDefault();

                return;
            }

            form.setAttribute("data-mpa-submitted", "1");

            Array.prototype.forEach.call(form.querySelectorAll("button[type='submit']"), function (button) {
                button.setAttribute("aria-disabled", "true");
                button.classList.add("disabled");
            });
        });
    }

    function reopenRefusedDialog() {
        var modal = document.querySelector(".mpa-modal[data-mpa-reopen]");

        if (modal) {
            showModal(modal);
        }
    }

    function lineLabel(line) {
        return line ? "(#" + line + ")" : "";
    }

    function setLineLabel(modal, line) {
        Array.prototype.forEach.call(modal.querySelectorAll("[data-mpa-line-label]"), function (label) {
            label.textContent = lineLabel(line);
        });
    }

    /*
     * A payout line's "Paid…" and "Not paid…" open one shared dialog each,
     * pointed at that line's URL. The fields are emptied so nothing typed
     * for one line is posted for another.
     */
    function bindRowActions() {
        document.addEventListener("click", function (event) {
            var trigger = event.target.closest("[data-mpa-row-action]");

            if (!trigger) {
                return;
            }

            var modal = document.querySelector("[data-mpa-row-modal='" + trigger.getAttribute("data-mpa-row-action") + "']");
            var form = modal ? modal.querySelector("form") : null;

            if (!form) {
                return;
            }

            var url = trigger.getAttribute("data-mpa-url") || "#";

            form.setAttribute("action", url);
            form.removeAttribute("data-mpa-submitted");

            Array.prototype.forEach.call(form.querySelectorAll("button[type='submit']"), function (button) {
                button.removeAttribute("aria-disabled");
                button.classList.remove("disabled");
            });

            Array.prototype.forEach.call(form.querySelectorAll("input[type='text'], textarea"), function (field) {
                field.value = "";
                field.classList.remove("is-invalid");
            });

            Array.prototype.forEach.call(form.querySelectorAll(".invalid-feedback"), function (feedback) {
                feedback.remove();
            });

            var hidden = form.querySelector("[data-mpa-action-url]");

            if (hidden) {
                hidden.value = url;
            }

            setLineLabel(modal, trigger.getAttribute("data-mpa-line"));
            showModal(modal);
        });
    }

    function clearReveal(modal) {
        var details = modal.querySelector("[data-mpa-reveal-details]");
        var status = modal.querySelector("[data-mpa-reveal-status]");

        if (details) {
            details.textContent = "";
            details.hidden = true;
        }

        if (status) {
            status.hidden = false;
            status.textContent = modal.getAttribute("data-mpa-loading") || status.textContent;
        }

        setLineLabel(modal, "");
    }

    /*
     * Reveal asks the server for one line's account and shows it in the
     * dialog only, written as text, never HTML. Closing the dialog empties it,
     * so the details do not stay in the page.
     */
    function bindReveal() {
        var modal = document.querySelector("[data-mpa-reveal-modal]");

        if (!modal) {
            return;
        }

        var status = modal.querySelector("[data-mpa-reveal-status]");
        var details = modal.querySelector("[data-mpa-reveal-details]");
        var token = document.querySelector("meta[name='csrf-token']");

        if (status) {
            modal.setAttribute("data-mpa-loading", status.textContent);
        }

        modal.addEventListener("hidden.coreui.modal", function () {
            clearReveal(modal);
        });

        document.addEventListener("click", function (event) {
            var trigger = event.target.closest("[data-mpa-reveal]");

            if (!trigger || !window.fetch) {
                return;
            }

            clearReveal(modal);
            showModal(modal);

            window.fetch(trigger.getAttribute("data-mpa-reveal"), {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Accept": "application/json",
                    "X-CSRF-TOKEN": token ? token.getAttribute("content") : "",
                    "X-Requested-With": "XMLHttpRequest"
                }
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.json();
            }).then(function (data) {
                setLineLabel(modal, data.line);

                (data.details || []).forEach(function (item) {
                    var row = document.createElement("div");
                    var term = document.createElement("dt");
                    var value = document.createElement("dd");

                    row.className = "mpa-facts__item";
                    term.textContent = item.label;
                    value.textContent = item.value;
                    value.className = "mpa-mono mpa-break";
                    value.setAttribute("dir", "ltr");

                    row.appendChild(term);
                    row.appendChild(value);
                    details.appendChild(row);
                });

                details.hidden = false;
                status.hidden = true;
            }).catch(function () {
                status.textContent = trigger.getAttribute("data-mpa-reveal-error") || "The account could not be shown. Reload the page and try again.";
            });
        });
    }

    ready(function () {
        bindAutosubmit();
        bindSubmitOnce();
        bindRowActions();
        bindReveal();
        reopenRefusedDialog();
    });
})();
