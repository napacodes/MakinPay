{{--
    Show every amount on a payment-method form in both currencies at once.

    The form legitimately mixes two units: the platform's own limits are in the
    base currency (the same threshold for every method, which is how AML caps
    are written), while the processor's limits and the fixed fee are in the
    currency the method settles in (which is how Stripe, PayPal and Adyen
    publish their fee schedules).

    Both are correct, but an admin should never have to do the conversion in
    their head — that is exactly how a EUR method ended up configured with
    base-currency numbers. Every gateway dashboard solves this the same way:
    wherever two currencies meet, show both figures and the rate.

    Mark an input with data-equivalent="to-method" or "to-base" and its
    equivalent appears underneath, live, as either the amount or the rate
    changes.

    Deliberately inline rather than @push: these forms are rendered with
    ->render() and injected into a modal over AJAX, so anything pushed to a
    layout stack is thrown away. The script is written to survive being
    injected repeatedly.
--}}
<script>
    (function () {
        'use strict';

        var BASE = @json(siteCurrency());

        function rate() {
            var input = document.getElementById('conversion_rate');
            var value = input ? parseFloat(input.value) : NaN;

            return isFinite(value) && value > 0 ? value : 1;
        }

        function methodCurrency() {
            var label = document.getElementById('currency-selected');
            var text = label ? label.textContent.trim() : '';

            return text || BASE;
        }

        /**
         * Trim storage padding without dropping real precision.
         *
         * The shared formatter already does exactly this — it is the browser
         * twin of Money::trimFee() — and the admin layout now loads it, so the
         * hand-rolled copy that used to live here is gone.
         */
        function tidy(value) {
            return DigiKashMoney.trim(value);
        }

        function render() {
            var applied = rate();
            var method = methodCurrency();

            document.querySelectorAll('[data-equivalent]').forEach(function (input) {
                // Keyed by name rather than by position: a form carries several
                // fee blocks, and walking the DOM would attach the wrong hint
                // to the wrong field.
                var hint = document.querySelector(
                    '[data-equivalent-for="' + input.getAttribute('name') + '"]'
                );

                if (! hint) {
                    return;
                }

                var amount = parseFloat(input.value);

                if (! isFinite(amount) || amount === 0) {
                    hint.textContent = '';
                    return;
                }

                var toMethod = input.getAttribute('data-equivalent') === 'to-method';
                var converted = toMethod ? amount * applied : amount / applied;
                var unit = toMethod ? method : BASE;

                // Nothing to say when the method settles in the base currency —
                // the two figures are the same number.
                hint.textContent = method === BASE && applied === 1
                    ? ''
                    : '≈ ' + tidy(converted) + ' ' + unit;
            });
        }

        // Expose it so a re-injected copy can refresh without re-binding.
        window.digikashRenderEquivalents = render;

        if (! window.digikashEquivalentsBound) {
            window.digikashEquivalentsBound = true;

            // Delegated, so inputs that arrive later in a modal are covered.
            document.addEventListener('input', function (event) {
                if (event.target.matches && event.target.matches('[data-equivalent], #conversion_rate')) {
                    window.digikashRenderEquivalents();
                }
            });

            document.addEventListener('change', function () {
                window.digikashRenderEquivalents();
            });
        }

        render();
    })();
</script>

<style>
    /* Inline for the same reason as the script: a pushed stylesheet never
       reaches the page when this form is injected over AJAX. */
    [data-equivalent-for] {
        display: block;
        min-height: 1.1em;
        margin-top: .2rem;
        font-size: .78rem;
        color: var(--cui-secondary-color, #6b7280);
    }
</style>
