/**
 * DigiKash money helpers.
 *
 * The browser must agree with the server, to the currency's own precision.
 * Before this existed, eight views each re-implemented the fee formula as a
 * `type === 'fixed' ? ... : ...` ternary and hardcoded `.toFixed(2)`, so a
 * crypto amount previewed as "0.00" and a combined fee could not be shown at
 * all.
 *
 * Mirrors App\Support\FeeCalculator and App\Support\Money. Plain global
 * script — no modules, no bundler.
 */
(function (window) {
    'use strict';

    var DEFAULT_DECIMALS = 2;

    // Fee components are stored as decimal(24,8). Mirrors Money::FEE_SCALE.
    var FEE_SCALE = 8;

    // The most places a rate is shown at when it has to go through a float.
    var RATE_PLACES = 12;

    function num(value) {
        var parsed = parseFloat(value);
        return isFinite(parsed) ? parsed : 0;
    }

    function nonNegative(value) {
        var parsed = num(value);
        return parsed > 0 ? parsed : 0;
    }

    /**
     * fee = clamp(fixed + amount * percent / 100, min, max), never above the
     * principal. Pass 0 for a component that does not apply.
     */
    function fee(amount, fixed, percent, min, max) {
        var principal = nonNegative(amount);
        var total = clampedComponents(principal, fixed, percent, min, max);

        return total > principal ? principal : total;
    }

    /**
     * The same formula without the principal cap.
     *
     * Mirrors FeeCalculator's `capToPrincipal: false`. A fee may never exceed
     * what it is charged on; a payout derived from one may — a staking plan
     * returning more than the stake is the product working. Running a yield
     * preview through fee() silently capped it at the principal, so a
     * high-return plan showed the wrong number before the customer committed.
     */
    function payout(amount, fixed, percent, min, max) {
        return clampedComponents(nonNegative(amount), fixed, percent, min, max);
    }

    function clampedComponents(principal, fixed, percent, min, max) {
        var total = nonNegative(fixed) + (principal * nonNegative(percent)) / 100;

        if (min !== null && min !== undefined && min !== '' && total < num(min)) {
            total = num(min);
        }

        if (max !== null && max !== undefined && max !== '' && total > num(max)) {
            total = num(max);
        }

        return total;
    }

    /**
     * Fee for an amount typed in the base currency, where the fee's own
     * components are denominated in the account currency.
     *
     * Mirrors FeeCalculator::totalFromBase(). The whole calculation — clamp
     * included — happens in the account currency, because that is the unit the
     * fixed component and the caps were written in; only the result comes back
     * to base. A rate of 1 collapses this to fee() exactly.
     */
    function feeFromBase(amount, rate, fixed, percent, min, max) {
        var applied = parseFloat(rate);

        if (! isFinite(applied) || applied <= 0) {
            applied = 1;
        }

        return fee(nonNegative(amount) * applied, fixed, percent, min, max) / applied;
    }

    /**
     * Resolve a fee from the legacy single amount + type pair, for any screen
     * whose endpoint has not been migrated to the two-component shape yet.
     */
    function legacyFee(amount, charge, chargeType) {
        return chargeType === 'fixed'
            ? fee(amount, charge, 0)
            : fee(amount, 0, charge);
    }

    /**
     * Round half-up at a given scale, matching PHP's number_format().
     *
     * toFixed() cannot be used directly: 1.2345 is stored as slightly less
     * than 1.2345 in binary, so (1.2345).toFixed(3) yields "1.234" while PHP
     * yields "1.235". Shifting the exponent through the *decimal string*
     * avoids the multiplication error, so the preview and the server agree at
     * exact tie values.
     */
    function roundHalfUp(value, scale) {
        var shifted = Number(value + 'e' + scale);

        if (! isFinite(shifted)) {
            return value;
        }

        return Number(Math.round(shifted) + 'e-' + scale);
    }

    /**
     * Format at the currency's own scale. `decimals` comes from the server via
     * a data-decimals attribute — never guessed, and never hardcoded to 2.
     */
    function format(amount, decimals, symbol) {
        var scale = decimals === null || decimals === undefined ? DEFAULT_DECIMALS : parseInt(decimals, 10);

        if (isNaN(scale) || scale < 0) {
            scale = DEFAULT_DECIMALS;
        }

        var formatted = roundHalfUp(num(amount), scale).toFixed(scale);

        // Thousands separators on the integer part only.
        var parts = formatted.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');

        return (symbol || '') + parts.join('.');
    }

    /**
     * Render a configured rate or flat amount without its storage padding.
     * These columns are decimal(24,8), so a 1% fee arrives as "1.00000000" and
     * would otherwise be shown to the customer exactly like that.
     */
    function trim(value) {
        var parsed = parseFloat(value);

        if (! isFinite(parsed)) {
            return '0';
        }

        // toFixed() rather than String(parseFloat(...)): the latter switches to
        // exponent notation below 1e-6, so a 0.00000001 fee would read "1e-8"
        // in the browser while PHP printed "0.00000001".
        var fixed = parsed.toFixed(FEE_SCALE);

        if (fixed.indexOf('.') === -1) {
            return fixed;
        }

        return fixed.replace(/0+$/, '').replace(/\.$/, '') || '0';
    }

    /**
     * Render an exchange rate without its storage padding. Rates are stored
     * at decimal(36,18), so a rate of one arrives as "1.000000000000000000"
     * and was shown to the customer exactly like that on the withdraw page.
     *
     * Mirrors App\Support\Money::formatRate. A plain decimal string is
     * trimmed as text so an 18-place rate keeps every digit the server sent;
     * anything else goes through the float at RATE_PLACES, below the noise a
     * double adds to a number near one.
     */
    function rate(value) {
        if (typeof value === 'string' && /^-?\d+(\.\d+)?$/.test(value.trim())) {
            var text = value.trim();

            return text.indexOf('.') === -1
                ? text
                : (text.replace(/0+$/, '').replace(/\.$/, '') || '0');
        }

        var parsed = parseFloat(value);

        if (! isFinite(parsed)) {
            return '0';
        }

        return parsed.toFixed(RATE_PLACES).replace(/0+$/, '').replace(/\.$/, '') || '0';
    }

    /**
     * Describe a fee the way a customer reads it: "0.30 USD + 2.9%", or just
     * the component that is actually configured.
     */
    function describe(fixed, percent, symbol) {
        var parts = [];

        if (parseFloat(fixed) > 0) {
            parts.push(trim(fixed) + (symbol ? ' ' + symbol : ''));
        }

        if (parseFloat(percent) > 0) {
            parts.push(trim(percent) + '%');
        }

        return parts.length ? parts.join(' + ') : '0';
    }

    /**
     * Read a server-rendered precision off an element (or its closest ancestor
     * carrying one), falling back to the page default.
     */
    function decimalsFor(element, fallback) {
        var node = element && element.closest ? element.closest('[data-decimals]') : null;
        var value = node ? node.getAttribute('data-decimals') : null;

        if (value === null || value === undefined || value === '') {
            var page = document.querySelector('[data-site-decimals]');
            value = page ? page.getAttribute('data-site-decimals') : null;
        }

        if (value === null || value === undefined || value === '') {
            return fallback === undefined ? DEFAULT_DECIMALS : fallback;
        }

        var parsed = parseInt(value, 10);
        return isNaN(parsed) ? DEFAULT_DECIMALS : parsed;
    }

    /**
     * Convert a figure the customer typed in a wallet's currency back to base.
     *
     * The forms take amounts in the unit the customer holds; every limit, fee
     * and summary behind them is base-denominated. This is the browser twin of
     * WalletService::baseAmount(), so the preview and the server agree on which
     * number the rest of the calculation starts from.
     *
     * An unusable rate collapses to 1 rather than dividing by zero — the server
     * refuses the transaction outright in that case, and a preview must not be
     * the thing that throws.
     */
    function toBase(entered, rate) {
        var applied = parseFloat(rate);

        return num(entered) / (isFinite(applied) && applied > 0 ? applied : 1);
    }

    /**
     * The inverse: express a base-denominated figure in the wallet's currency,
     * for showing a limit or a fee in the unit being typed.
     */
    function fromBase(baseValue, rate) {
        var applied = parseFloat(rate);

        return num(baseValue) * (isFinite(applied) && applied > 0 ? applied : 1);
    }

    /**
     * The largest amount a balance can fund once the fee is charged, for a
     * "Max" button.
     *
     * `feeFor(amount)` returns the fee in the same unit as `balance`. With the
     * fee deducted from the amount the answer is the balance itself. With the
     * fee added on top it is the largest `a` with `a + feeFor(a) <= balance`,
     * found by bisection over whole units of the currency's precision: the
     * fee is monotonic in the amount, so the total is too, and bisection is
     * exact where solving the formula is not — a capped or floored fee bends
     * the line, and the closed form overshoots by a cent on either side of
     * the kink. `ceiling` bounds the search when the method has a maximum.
     */
    function maxSpendable(balance, feeFor, decimals, deducted, ceiling) {
        var scale = decimals === null || decimals === undefined ? DEFAULT_DECIMALS : parseInt(decimals, 10);
        if (isNaN(scale) || scale < 0) {
            scale = DEFAULT_DECIMALS;
        }

        var factor = Math.pow(10, scale);
        var units = Math.floor(nonNegative(balance) * factor + 1e-9);

        if (ceiling !== null && ceiling !== undefined && ceiling !== '' && isFinite(parseFloat(ceiling))) {
            units = Math.min(units, Math.floor(nonNegative(ceiling) * factor + 1e-9));
        }

        if (deducted) {
            return units / factor;
        }

        var affordable = function (n) {
            var amount = n / factor;
            return amount + nonNegative(feeFor(amount)) <= nonNegative(balance) + 1e-9;
        };

        var low = 0;
        var high = units;

        while (low < high) {
            var mid = Math.ceil((low + high) / 2);
            if (affordable(mid)) {
                low = mid;
            } else {
                high = mid - 1;
            }
        }

        return low / factor;
    }

    /**
     * Point the amount field's suffix and precision at a currency. Called
     * whenever the selected wallet changes, so the unit shown next to the input
     * is always the unit the input is read in.
     */
    function bindAmountCurrency(code, decimals, selector) {
        var suffix = document.querySelectorAll(selector || '.amount-currency');
        var i;

        for (i = 0; i < suffix.length; i++) {
            suffix[i].textContent = code || '';
        }

        var inputs = document.querySelectorAll('.amount-input, .deposit-amount');

        for (i = 0; i < inputs.length; i++) {
            inputs[i].setAttribute('data-decimals', decimals === null || decimals === undefined ? DEFAULT_DECIMALS : decimals);
        }
    }

    /*
     * GF6 tax lines, mirroring App\Services\Tax\TaxEngine for the preview:
     * each rule is percent of its base (the amount, or the fee) plus its
     * fixed part, already in the paying currency, CUT to the currency's
     * places - never rounded - exactly as the server cuts it. A line that
     * cuts to zero is dropped. A rule charged by amount brackets takes the
     * percent and fixed part of the first bracket whose limit the amount does
     * not exceed (a null limit is "and above"); above its last capped bracket
     * it charges nothing. The server prices the real charge; this only shows
     * what to expect.
     */
    function cut(value, decimals) {
        var factor = Math.pow(10, decimals);

        return Math.floor(num(value) * factor + 1e-7) / factor;
    }

    function taxCharge(rule, amount) {
        var brackets = rule.brackets || [];
        var i;

        if (brackets.length === 0) {
            return rule;
        }

        for (i = 0; i < brackets.length; i++) {
            if (brackets[i].up_to === null || brackets[i].up_to === undefined || nonNegative(amount) <= num(brackets[i].up_to) + 1e-9) {
                return brackets[i];
            }
        }

        return null;
    }

    function taxLines(rules, amount, feeAmount, decimals) {
        var lines = [];
        var places = decimals === null || decimals === undefined ? DEFAULT_DECIMALS : decimals;

        // The server is handed both at the currency's places, so a typed
        // 100.009 picks the bracket 100.00 does, not the one above it.
        var principal = cut(nonNegative(amount), places);
        var feeAtPlaces = cut(nonNegative(feeAmount), places);

        (rules || []).forEach(function (rule) {
            var charge = taxCharge(rule, principal);

            if (charge === null) {
                return;
            }

            var base = rule.base === 'fee' ? feeAtPlaces : principal;
            var share = cut(base * nonNegative(charge.percent) / 100 + nonNegative(charge.fixed), places);

            if (share > 0) {
                lines.push({name: String(rule.name || ''), amount: share});
            }
        });

        return lines;
    }

    function taxTotal(lines, decimals) {
        var places = decimals === null || decimals === undefined ? DEFAULT_DECIMALS : decimals;

        return cut((lines || []).reduce(function (sum, line) {
            return sum + line.amount;
        }, 0), places);
    }

    window.DigiKashMoney = {
        taxLines: taxLines,
        taxTotal: taxTotal,
        cut: cut,
        fee: fee,
        payout: payout,
        feeFromBase: feeFromBase,
        legacyFee: legacyFee,
        format: format,
        describe: describe,
        trim: trim,
        rate: rate,
        decimalsFor: decimalsFor,
        toBase: toBase,
        fromBase: fromBase,
        maxSpendable: maxSpendable,
        bindAmountCurrency: bindAmountCurrency,
        DEFAULT_DECIMALS: DEFAULT_DECIMALS
    };
})(window);
