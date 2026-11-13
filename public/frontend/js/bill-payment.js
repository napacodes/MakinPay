"use strict";

/*
 * Bill payments, customer side.
 *
 * The pay page asks the server for the price whenever the wallet, amount or
 * package changes, and shows exactly the strings it gets back. There is no
 * fee arithmetic here: BillPaymentPricer is the only place a figure is worked
 * out, and the hidden expected_total is set to the total the customer was
 * shown, so the server refuses the payment if the price moved in between.
 *
 * The biller's account-number pattern is a hint only. It is PCRE on the
 * server and may not compile as a browser RegExp, so a pattern that does not
 * compile simply gives no hint; the server decides either way.
 */
(function () {
    var QUOTE_DELAY_MS = 300;

    function ready(callback) {
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", callback);
        } else {
            callback();
        }
    }

    function formatAmount(value, decimals, currency) {
        if (value === null || value === undefined || value === "") {
            return "-";
        }

        var formatted = window.DigiKashMoney
            ? window.DigiKashMoney.format(value, decimals, "")
            : String(value);

        return currency ? formatted + " " + currency : formatted;
    }

    /*
     * The dashboard's premium select picks an option with jQuery's
     * trigger("change"), which never reaches a native listener. A handler
     * bound through jQuery hears that and a native change alike.
     */
    function onChange(element, handler) {
        if (window.jQuery) {
            window.jQuery(element).on("change", handler);

            return;
        }

        element.addEventListener("change", handler);
    }

    function bindAutosubmit() {
        var selects = document.querySelectorAll("[data-bp-autosubmit]");

        Array.prototype.forEach.call(selects, function (select) {
            onChange(select, function () {
                if (select.form) {
                    select.form.submit();
                }
            });
        });
    }

    function bindPrint() {
        var buttons = document.querySelectorAll("[data-bp-print]");

        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener("click", function () {
                window.print();
            });
        });
    }

    function bindCopy() {
        var buttons = document.querySelectorAll("[data-bp-copy]");

        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener("click", function () {
                var source = document.querySelector(button.getAttribute("data-bp-copy"));
                var label = button.querySelector("[data-bp-copy-text]");

                if (!source) {
                    return;
                }

                var text = source.textContent.trim();
                var done = function () {
                    if (!label) {
                        return;
                    }

                    var original = label.getAttribute("data-original") || label.textContent;

                    label.setAttribute("data-original", original);
                    label.textContent = button.getAttribute("data-copied-label") || original;

                    window.setTimeout(function () {
                        label.textContent = original;
                    }, 2000);
                };

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(done).catch(function () {
                        selectText(source);
                    });

                    return;
                }

                selectText(source);
            });
        });
    }

    function selectText(element) {
        var range = document.createRange();
        var selection = window.getSelection();

        range.selectNodeContents(element);
        selection.removeAllRanges();
        selection.addRange(range);
    }

    function bindCustomerNumberHint(form) {
        var input = form.querySelector("[data-bp-customer-number]");
        var hint = form.querySelector("[data-bp-number-hint]");
        var pattern = form.getAttribute("data-pattern") || "";
        var matcher = null;

        if (!input || !hint || pattern === "") {
            return;
        }

        try {
            matcher = new RegExp("^(?:" + pattern + ")$", "u");
        } catch (error) {
            // Not a pattern the browser understands: no hint, the server decides.
            return;
        }

        var check = function () {
            var value = input.value.replace(/\s+/g, "");

            if (value === "" || matcher.test(value)) {
                hint.hidden = true;
                hint.textContent = "";

                return;
            }

            hint.textContent = form.getAttribute("data-pattern-message") || "";
            hint.hidden = hint.textContent === "";
        };

        input.addEventListener("blur", check);
        input.addEventListener("input", function () {
            if (!hint.hidden) {
                check();
            }
        });
    }

    /*
     * Ask the biller's provider whose account this is, when the customer
     * leaves the account number field or picks another package. Advice only:
     * the server asks again (from its cache) when the form is submitted and
     * refuses a number the provider does not know, whatever this shows.
     */
    function bindCustomerLookup(form) {
        var verifyUrl = form.getAttribute("data-verify-url") || "";
        var input = form.querySelector("[data-bp-customer-number]");
        var nameSlot = form.querySelector("[data-bp-customer-name]");
        var hint = form.querySelector("[data-bp-number-hint]");
        var tokenInput = form.querySelector("input[name=\"_token\"]");
        var sequence = 0;
        var lastAsked = null;
        var problemShown = false;

        if (verifyUrl === "" || !input || !nameSlot) {
            return;
        }

        function clearName() {
            nameSlot.textContent = "";
            nameSlot.hidden = true;
        }

        function showName(name) {
            var template = form.getAttribute("data-verify-name-label") || ":name";

            nameSlot.textContent = template.replace(":name", name);
            nameSlot.hidden = false;
        }

        function showProblem(message) {
            if (!hint || !message) {
                return;
            }

            problemShown = true;
            hint.textContent = message;
            hint.hidden = false;
        }

        function selectedItemId() {
            var item = form.querySelector("[data-bp-item]:checked");

            return item ? item.value : "";
        }

        function lookup() {
            var number = input.value.replace(/\s+/g, "");
            var itemId = selectedItemId();
            var asked = number + "|" + itemId;
            var current;

            if (number === "") {
                lastAsked = null;
                clearName();

                return;
            }

            if (asked === lastAsked) {
                return;
            }

            lastAsked = asked;
            current = ++sequence;
            clearName();

            var body = new URLSearchParams();

            body.set("customer_number", number);

            if (itemId !== "") {
                body.set("biller_item_id", itemId);
            }

            fetch(verifyUrl, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Accept": "application/json",
                    "Content-Type": "application/x-www-form-urlencoded",
                    "X-CSRF-TOKEN": tokenInput ? tokenInput.value : "",
                    "X-Requested-With": "XMLHttpRequest"
                },
                body: body.toString()
            })
                .then(function (response) {
                    return response.json().then(function (result) {
                        return { ok: response.ok, status: response.status, body: result };
                    });
                })
                .then(function (result) {
                    if (current !== sequence) {
                        return;
                    }

                    if (result.ok && result.body && result.body.verified && result.body.customer_name) {
                        showName(result.body.customer_name);

                        return;
                    }

                    if (result.status === 422 && result.body && result.body.errors && result.body.errors.customer_number) {
                        showProblem(result.body.errors.customer_number[0]);
                    }

                    if (!result.ok) {
                        // A refused or throttled check is asked again on the next blur.
                        lastAsked = null;
                    }
                })
                .catch(function () {
                    if (current === sequence) {
                        lastAsked = null;
                    }
                });
        }

        input.addEventListener("blur", lookup);
        input.addEventListener("input", function () {
            if (!nameSlot.hidden) {
                clearName();
            }

            if (problemShown && hint) {
                problemShown = false;
                hint.textContent = "";
                hint.hidden = true;
            }
        });

        Array.prototype.forEach.call(form.querySelectorAll("[data-bp-item]"), function (radio) {
            onChange(radio, function () {
                if (input.value.trim() !== "") {
                    lookup();
                }
            });
        });

        // A number filled in from My bills or an earlier payment is checked once, as a blur would.
        if (input.value.trim() !== "") {
            lookup();
        }
    }

    /*
     * "Save to My bills": the name field shows only while saving is ticked,
     * and is cleared when it is not, so an unticked box never sends a name.
     */
    function bindSaveToggle(form) {
        var toggle = form.querySelector("[data-bp-save-toggle]");
        var nameBox = form.querySelector("[data-bp-save-name]");

        if (!toggle || !nameBox) {
            return;
        }

        toggle.addEventListener("change", function () {
            var nameInput = nameBox.querySelector("input");

            nameBox.hidden = !toggle.checked;

            if (nameInput) {
                nameInput.disabled = !toggle.checked;
            }
        });
    }

    /* Removing one of "My bills" asks first; the payments themselves stay. */
    function bindForget() {
        Array.prototype.forEach.call(document.querySelectorAll("form[data-bp-forget]"), function (form) {
            form.addEventListener("submit", function (event) {
                var message = form.getAttribute("data-confirm");

                if (message && !window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });
    }

    function bindPayForm(form) {
        var quoteUrl = form.getAttribute("data-quote-url");
        var walletSelect = form.querySelector("[data-bp-wallet]");
        var amountInput = form.querySelector("[data-bp-amount]");
        var expectedTotal = form.querySelector("[data-bp-expected-total]");
        var submitButton = form.querySelector("[data-bp-submit]");
        var summary = document.querySelector("[data-bp-summary]");
        var alertBox = document.querySelector("[data-bp-quote-error]");
        var feeRule = document.querySelector("[data-bp-quote-text=\"fee_description\"]");
        var timer = null;
        var sequence = 0;

        function selectedItem() {
            return form.querySelector("[data-bp-item]:checked");
        }

        function setFigure(name, value, decimals, currency) {
            var target = document.querySelector("[data-bp-quote=\"" + name + "\"]");

            if (target) {
                target.textContent = formatAmount(value, decimals, currency);
            }
        }

        /*
         * GF6: a line per tax rule, after the fee and before the total,
         * from the server's quote. None when no rule applies.
         */
        function setTaxLines(tax, decimals, currency) {
            var totalRow = summary ? summary.querySelector(".bp-summary__total") : null;

            if (!summary) {
                return;
            }

            summary.querySelectorAll("[data-bp-tax-line]").forEach(function (row) {
                row.remove();
            });

            ((tax && tax.lines) || []).forEach(function (line) {
                var row = document.createElement("li");
                var name = document.createElement("span");
                var amount = document.createElement("span");

                row.setAttribute("data-bp-tax-line", "");
                name.textContent = line.name;
                amount.setAttribute("dir", "ltr");
                amount.textContent = formatAmount(line.amount, decimals, currency);
                row.appendChild(name);
                row.appendChild(amount);
                summary.insertBefore(row, totalRow);
            });
        }

        function clearFigures() {
            ["bill_amount", "fee", "total", "balance", "balance_after"].forEach(function (name) {
                setFigure(name, null);
            });
            setTaxLines(null);

            if (expectedTotal) {
                expectedTotal.value = "";
            }
        }

        function showAlert(message, isWarning) {
            if (!alertBox) {
                return;
            }

            alertBox.textContent = message || "";
            alertBox.classList.toggle("is-warning", Boolean(isWarning));
            alertBox.hidden = !message;
        }

        function render(quote) {
            var decimals = quote.decimals;

            setFigure("bill_amount", quote.bill_amount, decimals, quote.bill_currency);
            setFigure("fee", quote.fee, decimals, quote.currency);
            setTaxLines(quote.tax, decimals, quote.currency);
            setFigure("total", quote.total, decimals, quote.currency);
            setFigure("balance", quote.balance, decimals, quote.currency);
            setFigure("balance_after", quote.balance_after, decimals, quote.currency);

            if (feeRule && quote.fee_description) {
                feeRule.textContent = quote.fee_description === "0"
                    ? (form.getAttribute("data-no-fee") || "")
                    : quote.fee_description;
            }

            if (expectedTotal) {
                expectedTotal.value = quote.total;
            }

            showAlert(quote.sufficient_balance ? "" : form.getAttribute("data-short-message"), true);
        }

        function canQuote() {
            if (!walletSelect || walletSelect.value === "") {
                return false;
            }

            if (amountInput) {
                return amountInput.value.trim() !== "";
            }

            return selectedItem() !== null;
        }

        function requestQuote() {
            var current = ++sequence;

            if (!canQuote()) {
                clearFigures();
                showAlert("");

                return;
            }

            var params = new URLSearchParams();

            params.set("wallet_id", walletSelect.value);

            if (amountInput) {
                params.set("amount", amountInput.value.trim());
            }

            if (selectedItem()) {
                params.set("biller_item_id", selectedItem().value);
            }

            if (summary) {
                summary.classList.add("is-loading");
            }

            fetch(quoteUrl + "?" + params.toString(), {
                credentials: "same-origin",
                headers: {
                    "Accept": "application/json",
                    "X-Requested-With": "XMLHttpRequest"
                }
            })
                .then(function (response) {
                    return response.json().then(function (body) {
                        return { ok: response.ok, body: body };
                    });
                })
                .then(function (result) {
                    if (current !== sequence) {
                        return;
                    }

                    if (result.ok) {
                        render(result.body);

                        return;
                    }

                    clearFigures();
                    showAlert((result.body && result.body.message) || form.getAttribute("data-error-message"), false);
                })
                .catch(function () {
                    if (current !== sequence) {
                        return;
                    }

                    clearFigures();
                    showAlert(form.getAttribute("data-error-message"), false);
                })
                .then(function () {
                    if (current === sequence && summary) {
                        summary.classList.remove("is-loading");
                    }
                });
        }

        function scheduleQuote() {
            window.clearTimeout(timer);
            timer = window.setTimeout(requestQuote, QUOTE_DELAY_MS);
        }

        if (walletSelect) {
            onChange(walletSelect, scheduleQuote);
        }

        if (amountInput) {
            amountInput.addEventListener("input", scheduleQuote);
        }

        Array.prototype.forEach.call(form.querySelectorAll("[data-bp-item]"), function (radio) {
            onChange(radio, scheduleQuote);
        });

        form.addEventListener("submit", function () {
            if (submitButton) {
                window.setTimeout(function () {
                    submitButton.disabled = true;
                    submitButton.textContent = form.getAttribute("data-processing-label") || submitButton.textContent;
                }, 0);
            }
        });

        bindCustomerNumberHint(form);
        bindSaveToggle(form);
        bindCustomerLookup(form);
        requestQuote();
    }

    ready(function () {
        bindAutosubmit();
        bindPrint();
        bindCopy();
        bindForget();

        Array.prototype.forEach.call(document.querySelectorAll("[data-bp-pay-form]"), bindPayForm);
    });
})();
