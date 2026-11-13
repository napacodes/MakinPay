<script>
    $(document).ready(function () {
        'use strict';

        const accountInfo = {};
        const siteCurrency = "{{ siteCurrency() }}";
        const baseDecimals = {{ currencyDecimals() }};

        // Which side of the typed amount the fee lands on - the admin's
        // choice, read once. The server charges from the same setting, so
        // the preview and the ledger cannot disagree about it.
        const feeDeducted = @json(\App\Enums\FeeMode::withdraw()->value) === 'deducted';

        // Utility: Debounce function to limit API calls
        const debounce = (func, delay) => {
            let timer;
            return (...args) => {
                clearTimeout(timer);
                timer = setTimeout(() => func.apply(null, args), delay);
            };
        };

        // Fetch and populate withdrawal accounts
        const fetchWithdrawAccounts = debounce(walletId => {
            if (!walletId) return;
            const url = `${"{{ route('user.withdraw.account.index') }}"}?wallet_id=${encodeURIComponent(walletId)}`;
            $.ajax({
                url,
                type: "GET",
                success: response => updateWithdrawAccountOptions(response),
                error: () => updateWithdrawAccountOptions([], true)
            });
        }, 300);

        // Fetch withdrawal accounts on page load
        const walletSelect = $('.wallet-select');
        if (walletSelect.val() !== null) {
            fetchWithdrawAccounts(walletSelect.val());
        }

        // Update withdrawal account dropdown
        const updateWithdrawAccountOptions = (accounts, isError = false) => {
            const $accountSelect = $('.withdraw-account-select');
            if (isError || !Array.isArray(accounts) || !accounts.length) {
                $accountSelect.html('<option disabled selected>Error or no accounts available</option>');
                return;
            }
            const options = accounts.map(account =>
                `<option value="${account.id}">${capitalize(account.name)}</option>`
            ).join('');
            $accountSelect.html('<option disabled selected>Select Account</option>' + options);
        };

        // Fetch account details
        const fetchAccountInfo = accountId => {
            if (!accountId) return;
            const url = "{{ route('user.withdraw.account.info', ':id') }}".replace(':id', encodeURIComponent(accountId));
            $.ajax({
                url,
                type: "GET",
                success: response => updateAccountInfo(response),
                error: () => showError('.account-info', 'Error loading account info')
            });
        };

        /**
         * A charge can have both a fixed and a percentage component, so
         * describe whichever are configured.
         */
        const describeCharge = data =>
            DigiKashMoney.describe(data.charge_fixed, data.charge_percent, data.currency || siteCurrency);

        // Update account details UI
        const updateAccountInfo = data => {
            if (!data) return;
            let accountInfoText = `Charge: ${describeCharge(data)}`;
            if (data.destination) {
                accountInfoText += ` | {{ __('To') }}: ${data.destination}`;
            }
            $('.account-info').text(accountInfoText);
            Object.assign(accountInfo, data);

            // The amount field is read in the payout method's currency, so its
            // suffix and precision follow the selected account rather than
            // staying on base.
            DigiKashMoney.bindAmountCurrency(
                data.currency || siteCurrency,
                data.decimals === undefined ? baseDecimals : data.decimals
            );

            // Max needs a rate and a fee to work from, so it waits for them.
            $('.use-max-btn').prop('disabled', !data.conversion_rate);
        };

        /**
         * The fee for a base-denominated amount, from the same formula the
         * server uses (App\Support\FeeCalculator). The fixed component and the
         * caps are denominated in the method's own currency.
         */
        const feeInBase = (baseAmount) => accountInfo.charge_fixed === undefined
            ? DigiKashMoney.legacyFee(baseAmount, accountInfo.charge, accountInfo.charge_type)
            : DigiKashMoney.feeFromBase(
                baseAmount, accountInfo.conversion_rate,
                accountInfo.charge_fixed, accountInfo.charge_percent, accountInfo.charge_min, accountInfo.charge_max
            );

        /**
         * GF6: the tax lines on a payout, in the method's currency, from the
         * rules the account info carried. The server prices the real charge.
         */
        const taxLinesFor = (net, fee, scale) => DigiKashMoney.taxLines(accountInfo.tax_rules, net, fee, scale);
        const taxTotalFor = (net, fee, scale) => DigiKashMoney.taxTotal(taxLinesFor(net, fee, scale), scale);

        /**
         * Fill in the most the selected wallet can send once the fee is
         * charged. The wallet and the payout method share a currency - the
         * account list is filtered by the wallet - so the balance is already
         * in the unit the field is typed in.
         */
        const useMax = () => {
            const { conversion_rate, decimals, max_limit } = accountInfo;
            if (!conversion_rate) return;

            const option = $('.wallet-select').find(':selected');
            const balance = parseFloat(option.data('balance')) || 0;
            const scale = decimals === undefined ? baseDecimals : decimals;

            const feeInMethodCurrency = (amount) =>
                DigiKashMoney.fromBase(feeInBase(DigiKashMoney.toBase(amount, conversion_rate)), conversion_rate);
            const ceiling = max_limit === null || max_limit === undefined || max_limit === ''
                ? null
                : DigiKashMoney.fromBase(parseFloat(max_limit), conversion_rate);

            // GF6: the tax is debited on top in both fee modes, so with a rule
            // in play the most is searched for even when the fee is deducted -
            // what goes beside the typed figure is then the tax alone.
            const taxed = (accountInfo.tax_rules || []).length > 0;
            const besideTyped = (amount) => {
                const fee = DigiKashMoney.cut(feeInMethodCurrency(amount), scale);
                const net = feeDeducted ? DigiKashMoney.cut(amount, scale) - fee : amount;
                const tax = taxed ? taxTotalFor(net, fee, scale) : 0;

                return feeDeducted ? tax : fee + tax;
            };

            const most = DigiKashMoney.maxSpendable(balance, besideTyped, scale, feeDeducted && !taxed, ceiling);

            $('.amount-input').val(most > 0 ? most.toFixed(scale) : '').trigger('input');
        };

        // Update withdrawal summary
        const updateSummary = () => {
            const amount = parseFloat($('.amount-input').val()) || 0;
            const {
                processing_time,
                conversion_rate,
                currency,
                decimals,
                min_limit,
                max_limit
            } = accountInfo;

            if (!conversion_rate || !currency) return;

            const walletDecimals = decimals === undefined ? baseDecimals : decimals;

            // The customer types in the payout method's currency; the server
            // converts to base before anything else, so the preview does too or
            // the fee shown and the fee charged start from different numbers.
            const baseAmount = DigiKashMoney.toBase(amount, conversion_rate);

            // Limits are stored in base but shown in the unit being typed.
            const inMethodCurrency = (value) =>
                DigiKashMoney.format(DigiKashMoney.fromBase(value, conversion_rate), walletDecimals);

            // effectiveMaxAmount() is null when neither the platform nor the
            // processor states a ceiling. Comparing against that coerces to 0
            // in JavaScript, so every amount read as "above the maximum" and
            // the preview never appeared — an uncapped method looked broken.
            const floor = parseFloat(min_limit) || 0;
            const ceiling = max_limit === null || max_limit === undefined || max_limit === ''
                ? null
                : parseFloat(max_limit);
            const ceilingLabel = ceiling === null
                ? `{{ __('No limit') }}`
                : `${inMethodCurrency(ceiling)} ${currency}`;

            if (baseAmount < floor || (ceiling !== null && baseAmount > ceiling)) {
                showError('.withdraw-amount-info', `Amount must be between ${inMethodCurrency(floor)} ${currency} and ${ceilingLabel}`);
                return;
            } else {
                showSuccess('.withdraw-amount-info', `Min: ${inMethodCurrency(floor)} ${currency} | Max: ${ceilingLabel}`);
            }

            const fee = feeInBase(baseAmount);

            // Fee on top: the typed figure is paid out and the fee is debited
            // beside it. Fee deducted: the typed figure is debited and the fee
            // comes out of it. The server does the same sum from the same
            // setting.
            const debited = feeDeducted ? baseAmount : baseAmount + fee;
            const received = feeDeducted ? baseAmount - fee : baseAmount;

            if (received <= 0) {
                showError('.withdraw-amount-info', `{{ __('The fee is larger than the amount. Enter a larger amount.') }}`);
                return;
            }

            updateSummaryUI({
                processing_time,
                amount: baseAmount,
                fee,
                debited,
                received,
                conversion_rate,
                currency,
                walletDecimals
            });
        };

        // Update summary UI
        const updateSummaryUI = ({
                                     processing_time,
                                     amount,
                                     fee,
                                     debited,
                                     received,
                                     conversion_rate,
                                     currency,
                                     walletDecimals
                                 }) => {
            $('.processing-time').text(`${processing_time}`);
            // Every figure is shown in the currency the customer typed and
            // will actually be debited in.
            const inMethodCurrency = (value) =>
                DigiKashMoney.format(DigiKashMoney.fromBase(value, conversion_rate), walletDecimals);

            // GF6: each tax rule as its own line, on what is paid out or on the
            // fee, debited on top whichever side the fee is on.
            // The server cuts the fee to the currency's places before it takes
            // it off a deducted payout, so the payout the bracket is picked on
            // is the typed figure less that cut fee - not less the raw float.
            const methodFee = DigiKashMoney.cut(DigiKashMoney.fromBase(fee, conversion_rate), walletDecimals);
            const methodReceived = feeDeducted
                ? DigiKashMoney.cut(DigiKashMoney.fromBase(debited, conversion_rate), walletDecimals) - methodFee
                : DigiKashMoney.fromBase(received, conversion_rate);
            const taxes = taxLinesFor(methodReceived, methodFee, walletDecimals);
            const methodDebited = DigiKashMoney.fromBase(debited, conversion_rate) + DigiKashMoney.taxTotal(taxes, walletDecimals);

            $('[data-summary-tax]').empty();
            taxes.forEach((line) => {
                $('[data-summary-tax]').append(
                    $('<li class="d-flex justify-content-between" data-summary-tax-line></li>')
                        .append($('<span></span>').text(line.name))
                        .append($('<span></span>').text(`${DigiKashMoney.format(line.amount, walletDecimals)} ${currency}`))
                );
            });

            $('.summary-amount').text(`${inMethodCurrency(amount)} ${currency}`);
            $('.summary-charge').text(`${inMethodCurrency(fee)} ${currency}`);
            // Rates keep their own precision.
            $('.conversion-rate').text(`1 ${siteCurrency} = ${DigiKashMoney.rate(conversion_rate)} ${currency}`);
            $('.received-withdraw-amount').text(`${inMethodCurrency(received)} ${currency}`);
            $('.my-wallet-decreased').text(`${DigiKashMoney.format(methodDebited, walletDecimals)} ${currency}`);
        };

        // Show error message
        const showError = (selector, message) => {
            $(selector).text(message).addClass('text-danger').removeClass('text-success');
        };

        // Show success message
        const showSuccess = (selector, message) => {
            $(selector).text(message).addClass('text-success').removeClass('text-danger');
        };

        // Utility: Capitalize string
        const capitalize = str => str.charAt(0).toUpperCase() + str.slice(1);

        // Event bindings
        $(document).on('change', '.wallet-select', function () {
            fetchWithdrawAccounts(this.value);
        });
        $(document).on('change', '.withdraw-account-select', function () {
            fetchAccountInfo(this.value);
        });
        $(document).on('input', '.amount-input', updateSummary);
        $(document).on('click', '.use-max-btn', useMax);
    });
</script>
