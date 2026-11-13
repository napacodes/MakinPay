"use strict";

/*
 * Bill payments admin: the payment queue and detail, billers, categories
 * and providers.
 *
 * Everything here is a display aid. The pattern tester, the inherited-fee
 * line, the section toggles and the filled-in action dialogs help the admin;
 * the server's form requests and BillPaymentService decide what happens. No
 * money is calculated here: every amount shown is a string the server wrote.
 */
(function () {
    function ready(callback) {
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", callback);
        } else {
            callback();
        }
    }

    function format(template, values) {
        return String(template || "").replace(/:([a-z_]+)/g, function (match, key) {
            return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : match;
        });
    }

    function isZero(value) {
        return /^\s*0*(\.0*)?\s*$/.test(String(value || ""));
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

    /* A status switch in a list posts its own little form. */
    function bindAutosubmit() {
        document.addEventListener("change", function (event) {
            var input = event.target.closest("[data-bpa-autosubmit]");

            if (!input || !input.form) {
                return;
            }

            input.disabled = true;

            if (typeof input.form.requestSubmit === "function") {
                input.form.requestSubmit();
            } else {
                input.form.submit();
            }
        });
    }

    function bindConfirm() {
        document.addEventListener("submit", function (event) {
            var form = event.target.closest("form[data-bpa-confirm]");

            if (form && !window.confirm(form.getAttribute("data-bpa-confirm"))) {
                event.preventDefault();
            }
        });
    }

    /* ---------- Customer number: presets and the "Try a number" tester ---------- */

    function bindPattern(form) {
        var preset = form.querySelector("[data-bpa-pattern-preset]");
        var pattern = form.querySelector("[data-bpa-pattern]");
        var tester = form.querySelector("[data-bpa-tester]");

        if (!pattern || !tester) {
            return;
        }

        var testInput = tester.querySelector("[data-bpa-tester-input]");
        var result = tester.querySelector("[data-bpa-tester-result]");

        function runTest() {
            var body = pattern.value.trim();
            var number = testInput.value.replace(/\s+/g, "");
            var state = "";
            var text;

            if (body === "") {
                text = tester.getAttribute("data-text-none");
            } else if (number === "") {
                text = tester.getAttribute("data-text-empty");
            } else {
                try {
                    var expression = new RegExp("^(?:" + body + ")$", "u");

                    state = expression.test(number) ? "is-match" : "is-miss";
                    text = tester.getAttribute(state === "is-match" ? "data-text-match" : "data-text-miss");
                } catch (error) {
                    text = tester.getAttribute("data-text-unknown");
                }
            }

            result.classList.remove("is-match", "is-miss");

            if (state !== "") {
                result.classList.add(state);
            }

            result.textContent = text;
        }

        if (preset) {
            preset.addEventListener("change", function () {
                var option = preset.options[preset.selectedIndex];
                var value = option ? option.getAttribute("data-pattern") : "";

                if (value) {
                    pattern.value = value;
                }

                runTest();
            });
        }

        pattern.addEventListener("input", function () {
            if (preset) {
                var match = Array.prototype.find.call(preset.options, function (option) {
                    return option.getAttribute("data-pattern") && option.getAttribute("data-pattern") === pattern.value.trim();
                });

                preset.value = match ? match.value : "custom";
            }

            runTest();
        });

        testInput.addEventListener("input", runTest);
        runTest();
    }

    /* ---------- Amount: open range or packages ---------- */

    function bindAmountType(form) {
        var radios = form.querySelectorAll("[data-bpa-amount-type]");

        function sync() {
            var checked = form.querySelector("[data-bpa-amount-type]:checked");
            var type = checked ? checked.value : "open";

            form.querySelectorAll("[data-bpa-amount-section]").forEach(function (section) {
                section.hidden = section.getAttribute("data-bpa-amount-section") !== type;
            });
        }

        radios.forEach(function (radio) {
            radio.addEventListener("change", sync);
        });

        sync();
    }

    function bindItems(form) {
        var container = form.querySelector("[data-bpa-items]");

        if (!container) {
            return;
        }

        var list = container.querySelector("[data-bpa-items-list]");
        var template = container.querySelector("[data-bpa-item-template]");
        var nextIndex = parseInt(container.getAttribute("data-next-index"), 10) || 0;

        /* Never reuse an index already on the page: rows removed before a failed save leave gaps. */
        list.querySelectorAll("input[name^='items[']").forEach(function (input) {
            var match = /^items\[(\d+)\]/.exec(input.name);

            if (match) {
                nextIndex = Math.max(nextIndex, parseInt(match[1], 10) + 1);
            }
        });

        container.addEventListener("click", function (event) {
            if (event.target.closest("[data-bpa-item-add]")) {
                list.insertAdjacentHTML("beforeend", template.innerHTML.replace(/__INDEX__/g, String(nextIndex)));
                nextIndex += 1;

                var added = list.lastElementChild;
                var firstInput = added ? added.querySelector("input[type='text']") : null;

                if (firstInput) {
                    firstInput.focus();
                }

                return;
            }

            var remove = event.target.closest("[data-bpa-item-remove]");

            if (!remove) {
                return;
            }

            var row = remove.closest("[data-bpa-item]");

            if (!row) {
                return;
            }

            if (list.querySelectorAll("[data-bpa-item]").length > 1) {
                row.remove();

                return;
            }

            /* The last row is cleared rather than removed, so there is always one to fill. */
            row.querySelectorAll("input[type='text'], input[type='hidden'][name$='[id]']").forEach(function (input) {
                input.value = "";
            });
        });
    }

    /* ---------- Fees: per-part override and the inherited rule ---------- */

    function bindFees(form) {
        var fees = form.querySelector("[data-bpa-fees]");

        if (!fees) {
            return;
        }

        var provider = form.querySelector("[data-bpa-provider]");
        var currency = form.querySelector("[data-bpa-currency]");
        var currencyLabel = fees.querySelector("[data-bpa-currency-label]");
        var percentLine = fees.querySelector("[data-bpa-inherited='percent']");
        var fixedLine = fees.querySelector("[data-bpa-inherited='fixed']");

        fees.querySelectorAll("[data-bpa-fee-override]").forEach(function (toggle) {
            toggle.addEventListener("change", function () {
                var input = form.querySelector(toggle.getAttribute("data-bpa-fee-override"));

                if (!input) {
                    return;
                }

                input.disabled = !toggle.checked;

                if (toggle.checked) {
                    input.focus();
                }
            });
        });

        function describe() {
            var option = provider ? provider.options[provider.selectedIndex] : null;
            var code = currency ? currency.value.toUpperCase() : "";

            if (currencyLabel && code !== "") {
                currencyLabel.textContent = code;
            }

            if (!option || option.value === "") {
                percentLine.textContent = fees.getAttribute("data-text-no-provider");
                fixedLine.textContent = fees.getAttribute("data-text-no-provider");

                return;
            }

            var percent = option.getAttribute("data-fee-percent") || "0";
            var fixed = option.getAttribute("data-fee-fixed") || "0";
            var feeCurrency = (option.getAttribute("data-fee-currency") || "").toUpperCase();

            percentLine.textContent = isZero(percent)
                ? fees.getAttribute("data-text-percent-none")
                : format(fees.getAttribute("data-text-percent"), { percent: percent });

            if (isZero(fixed)) {
                fixedLine.textContent = fees.getAttribute("data-text-fixed-none");
            } else if (feeCurrency === "" || feeCurrency !== code) {
                fixedLine.textContent = format(fees.getAttribute("data-text-fixed-foreign"), { currency: feeCurrency || "?" });
            } else {
                fixedLine.textContent = format(fees.getAttribute("data-text-fixed"), { amount: fixed, currency: feeCurrency });
            }
        }

        if (provider) {
            provider.addEventListener("change", describe);
        }

        if (currency) {
            currency.addEventListener("change", describe);
        }

        describe();
    }

    /* ---------- Category dialog ---------- */

    function bindCategoryModal() {
        var modal = document.querySelector("[data-bpa-category-modal]");

        if (!modal) {
            return;
        }

        var form = modal.querySelector("[data-bpa-category-form]");
        var method = form.querySelector("[data-bpa-category-method]");
        var idField = form.querySelector("[data-bpa-category-id]");
        var title = modal.querySelector("[data-bpa-category-title]");
        var iconSelect = form.querySelector("[data-bpa-icon-select]");
        var codeHint = form.querySelector("[data-bpa-category-code-hint]");

        function field(name) {
            return form.querySelector("[name='" + name + "']:not([type='hidden'])");
        }

        function previewIcon() {
            var wanted = iconSelect.value || "bill-other";

            modal.querySelectorAll("[data-bpa-icon-preview]").forEach(function (preview) {
                preview.hidden = preview.getAttribute("data-bpa-icon-preview") !== wanted;
            });
        }

        function clearErrors() {
            form.querySelectorAll(".is-invalid").forEach(function (input) {
                input.classList.remove("is-invalid");
            });

            form.querySelectorAll(".invalid-feedback, .text-danger.small").forEach(function (message) {
                message.remove();
            });
        }

        function open(values) {
            clearErrors();

            form.setAttribute("action", values.action);
            method.value = values.method;
            idField.value = values.id || "";
            title.textContent = values.title;

            field("name").value = values.name || "";
            field("code").value = values.code || "";
            field("code").readOnly = values.system === "1";

            if (codeHint) {
                codeHint.textContent = codeHint.getAttribute(values.system === "1" ? "data-text-system" : "data-text-default");
            }
            field("description").value = values.description || "";
            field("sort_order").value = values.sortOrder || "";
            field("status").checked = values.status !== "0";
            iconSelect.value = values.icon || "";
            previewIcon();

            showModal(modal);
        }

        document.addEventListener("click", function (event) {
            if (event.target.closest("[data-bpa-category-create]")) {
                open({
                    action: modal.getAttribute("data-store-action"),
                    method: "POST",
                    title: modal.getAttribute("data-title-create"),
                    status: "1"
                });

                return;
            }

            var edit = event.target.closest("[data-bpa-category-edit]");

            if (edit) {
                open({
                    action: edit.getAttribute("data-action"),
                    method: "PUT",
                    id: edit.getAttribute("data-id"),
                    title: modal.getAttribute("data-title-edit"),
                    name: edit.getAttribute("data-name"),
                    code: edit.getAttribute("data-code"),
                    icon: edit.getAttribute("data-icon"),
                    description: edit.getAttribute("data-description"),
                    sortOrder: edit.getAttribute("data-sort-order"),
                    status: edit.getAttribute("data-status"),
                    system: edit.getAttribute("data-system")
                });
            }
        });

        iconSelect.addEventListener("change", previewIcon);

        /* After a failed save the server renders the dialog filled in; show it again. */
        if (modal.hasAttribute("data-bpa-reopen")) {
            showModal(modal);
        }
    }

    /* ---------- Payments: copy to clipboard ---------- */

    function copyText(text, near) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            /* Inside an open dialog the textarea has to live in it, or the focus trap takes the selection away. */
            var host = (near && near.closest(".modal")) || document.body;
            var area = document.createElement("textarea");

            area.value = text;
            area.setAttribute("readonly", "");
            area.style.position = "fixed";
            area.style.opacity = "0";
            host.appendChild(area);
            area.select();

            try {
                if (document.execCommand("copy")) {
                    resolve();
                } else {
                    reject(new Error("copy refused"));
                }
            } catch (error) {
                reject(error);
            }

            area.remove();
        });
    }

    function bindCopy() {
        document.addEventListener("click", function (event) {
            var button = event.target.closest("[data-bpa-copy]");

            if (!button) {
                return;
            }

            var text = button.getAttribute("data-bpa-copy") || "";

            if (text === "") {
                return;
            }

            copyText(text, button).then(function () {
                button.classList.add("is-copied");

                window.setTimeout(function () {
                    button.classList.remove("is-copied");
                }, 1500);
            }).catch(function () {
                /* The value is on screen; it can still be selected by hand. */
            });
        });
    }

    /* ---------- Payments: the mark paid and mark failed dialogs ---------- */

    /*
     * One dialog of each kind serves the whole page. The button that opens it
     * carries the payment's data (payments/_trigger.blade.php); the dialog is
     * filled from that and its form pointed at that payment's URL.
     */
    function bindActionModals() {
        var modals = {};

        document.querySelectorAll("[data-bpa-modal]").forEach(function (modal) {
            modals[modal.getAttribute("data-bpa-modal")] = modal;
        });

        if (Object.keys(modals).length === 0) {
            return;
        }

        /* Which data-bpa-*-url on the trigger each dialog posts to. */
        var actionUrls = {
            "mark-paid": "paid-url",
            "mark-failed": "failed-url",
            "confirm-not-paid": "confirm-url"
        };

        function data(trigger, key) {
            return trigger.getAttribute("data-bpa-" + key) || "";
        }

        function clearForm(form, modal) {
            form.querySelectorAll("input[type='text'], textarea").forEach(function (field) {
                field.value = "";
                field.classList.remove("is-invalid");
            });

            form.querySelectorAll("input[type='checkbox']").forEach(function (box) {
                box.checked = false;
                box.classList.remove("is-invalid");
            });

            form.querySelectorAll(".invalid-feedback").forEach(function (message) {
                message.remove();
            });

            modal.querySelectorAll("[data-bpa-reason]").forEach(function (preset) {
                preset.classList.remove("is-active");
                preset.setAttribute("aria-pressed", "false");
            });
        }

        function fill(modal, trigger, keepValues) {
            var kind = modal.getAttribute("data-bpa-modal");
            var form = modal.querySelector("[data-bpa-action-form]");

            form.setAttribute("action", data(trigger, actionUrls[kind] || "failed-url"));

            modal.querySelectorAll("[data-bpa-fill]").forEach(function (target) {
                if (!target.hasAttribute("data-bpa-default")) {
                    target.setAttribute("data-bpa-default", target.textContent.trim());
                }

                target.textContent = data(trigger, target.getAttribute("data-bpa-fill")) || target.getAttribute("data-bpa-default");
            });

            modal.querySelectorAll("[data-bpa-fill-copy]").forEach(function (button) {
                button.setAttribute("data-bpa-copy", data(trigger, button.getAttribute("data-bpa-fill-copy")));
            });

            modal.querySelectorAll("[data-bpa-fill-value]").forEach(function (input) {
                input.value = data(trigger, input.getAttribute("data-bpa-fill-value"));
            });

            modal.querySelectorAll("[data-bpa-show-if]").forEach(function (element) {
                element.hidden = data(trigger, element.getAttribute("data-bpa-show-if")) === "";
            });

            /* The token box exists only for a biller that issues one; hidden, it posts nothing. */
            var tokenField = modal.querySelector("[data-bpa-token-field]");

            if (tokenField) {
                var issuesToken = data(trigger, "token-label") !== "";
                var tokenInput = tokenField.querySelector("input");

                tokenField.hidden = !issuesToken;
                tokenInput.required = issuesToken;
                tokenInput.disabled = !issuesToken;
            }

            var refundLine = modal.querySelector("[data-bpa-refund-line]");

            if (refundLine) {
                refundLine.textContent = format(refundLine.getAttribute("data-text"), { total: data(trigger, "total") });
            }

            if (!keepValues) {
                clearForm(form, modal);
            }
        }

        Object.keys(modals).forEach(function (kind) {
            var modal = modals[kind];

            modal.addEventListener("shown.coreui.modal", function () {
                var first = modal.querySelector("input[required]:not([disabled]), textarea[required]");

                if (first) {
                    first.focus();
                }
            });
        });

        document.addEventListener("click", function (event) {
            var trigger = event.target.closest("[data-bpa-open]");

            if (!trigger) {
                return;
            }

            var modal = modals[trigger.getAttribute("data-bpa-open")];

            if (!modal) {
                return;
            }

            event.preventDefault();
            fill(modal, trigger, false);
            showModal(modal);
        });

        document.addEventListener("click", function (event) {
            var preset = event.target.closest("[data-bpa-reason]");

            if (!preset) {
                return;
            }

            var modal = preset.closest("[data-bpa-modal]");
            var input = modal ? modal.querySelector("[data-bpa-reason-input]") : null;

            if (!input) {
                return;
            }

            modal.querySelectorAll("[data-bpa-reason]").forEach(function (other) {
                other.classList.toggle("is-active", other === preset);
                other.setAttribute("aria-pressed", other === preset ? "true" : "false");
            });

            input.value = preset.getAttribute("data-bpa-reason");
            input.focus();
        });

        /*
         * A refused save comes back with the typed values and the errors in
         * the dialog. Reopen it for the payment it was about, if that
         * payment's button is still on the page; the uuid is only compared.
         */
        Object.keys(modals).forEach(function (kind) {
            var modal = modals[kind];
            var uuid = modal.getAttribute("data-bpa-reopen");

            if (!uuid) {
                return;
            }

            var trigger = Array.prototype.find.call(document.querySelectorAll("[data-bpa-open]"), function (candidate) {
                return candidate.getAttribute("data-bpa-open") === kind && candidate.getAttribute("data-bpa-uuid") === uuid;
            });

            if (trigger) {
                fill(modal, trigger, true);
                showModal(modal);
            }
        });
    }

    /* A decision is sent once: the second click on a slow connection does nothing. */
    function bindSubmitOnce() {
        document.addEventListener("submit", function (event) {
            var form = event.target.closest("[data-bpa-action-form]");

            if (!form || event.defaultPrevented) {
                return;
            }

            form.querySelectorAll("button[type='submit']").forEach(function (button) {
                button.disabled = true;
            });
        });
    }

    ready(function () {
        bindAutosubmit();
        bindConfirm();
        bindCategoryModal();
        bindCopy();
        bindActionModals();
        bindSubmitOnce();

        document.querySelectorAll("[data-bpa-biller-form]").forEach(function (form) {
            bindPattern(form);
            bindAmountType(form);
            bindItems(form);
            bindFees(form);
        });

        if (typeof window.handleImagePreview === "function") {
            window.handleImagePreview();
        }
    });
})();
