<script>
    'use strict';

    // Pass siteCurrency dynamically from Blade
    const siteCurrency = '{{ siteCurrency() }}';

    // Which side of the typed amount the fee lands on - the admin's choice,
    // read once. The server prices from the same setting, so the preview and
    // the ledger cannot disagree about it.
    const feeDeducted = @json(\App\Enums\FeeMode::exchange()->value) === 'deducted';

    // GF6: the exchange tax rules, fixed part in the site currency.
    const exchangeTaxRules = @json($taxRules ?? []);

    const exchangeInfo = {
        sourceWalletId: null,
        destinationWalletId: null,
        sourceCurrency: null,
        destinationCurrency: null,
        conversionRate: 1,
        defaultRate: 1,
        destinationDefaultRate: 1,
        feeFixed: 0,
        feePercent: 0,
        feeMin: null,
        feeMax: null,
        sourceDecimals: {{ currencyDecimals() }},
        destinationDecimals: {{ currencyDecimals() }},
        minLimit: 0,
        maxLimit: 0,
    };

    /**
     * A rate keeps its own precision and drops insignificant zeros, so a live
     * BTC rate reads as 0.0000094118 rather than 0.00.
     */
    const formatRate = (rate) => DigiKashMoney.rate(rate);

    const selectors = {
        sourceWalletSelect: '.source-wallet-select',
        destinationWalletSelect: '.destination-wallet-select',
        amountInput: '.amount-input',
        sendAmountInfo: '.send-amount-info',
        toWalletWarning: '.to-wallet-warning',
        exchangeFee: '.exchange-fee',
        summaryAmount: '.summary-amount',
        summaryFee: '.summary-fee',
        defaultRate: '.default-summary-rate',
        exchangeRate: '.exchange-summary-rate',
        summaryDebited: '.summary-debited',
        summaryCredited: '.summary-credited',
        summaryTax: '[data-summary-tax]',
    };

    // Cache DOM elements
    const $sourceWalletSelect = $(selectors.sourceWalletSelect);
    const $destinationWalletSelect = $(selectors.destinationWalletSelect);
    const $amountInput = $(selectors.amountInput);
    const $sendAmountInfo = $(selectors.sendAmountInfo);
    const $toWalletWarning = $(selectors.toWalletWarning);
    const $exchangeFee = $(selectors.exchangeFee);
    const $summaryAmount = $(selectors.summaryAmount);
    const $summaryFee = $(selectors.summaryFee);
    const $defaultRate = $(selectors.defaultRate);
    const $useMaxBtn = $('.use-max-btn');
    const $exchangeRate = $(selectors.exchangeRate);
    const $summaryDebited = $(selectors.summaryDebited);
    const $summaryCredited = $(selectors.summaryCredited);
    const $summaryTax = $(selectors.summaryTax);

    /**
     * GF6: the tax lines on the paying side, in the source currency: on what
     * converts or on the fee, the fixed part - and each amount bracket's limit
     * and fixed part - at the source wallet's rate.
     */
    const atSourceRate = (value) => (parseFloat(value) || 0) * (exchangeInfo.defaultRate || 1);
    const exchangeTaxes = (net, fee) => DigiKashMoney.taxLines(
        exchangeTaxRules.map((rule) => Object.assign({}, rule, {
            fixed: atSourceRate(rule.fixed),
            brackets: (rule.brackets || []).map((bracket) => ({
                up_to: bracket.up_to === null ? null : atSourceRate(bracket.up_to),
                percent: bracket.percent,
                fixed: atSourceRate(bracket.fixed),
            })),
        })),
        net,
        fee,
        exchangeInfo.sourceDecimals
    );

    // Debounce function
    const debounce = (func, delay) => {
        let debounceTimer;
        return function () {
            const context = this;
            const args = arguments;
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => func.apply(context, args), delay);
        };
    };

    // Update fee display
    const updateFeeDisplay = () => {
        // Shared helper, so a decimal(24,8) column is never shown as
        // "1.00000000%" to the customer.
        const feeText = DigiKashMoney.describe(exchangeInfo.feeFixed, exchangeInfo.feePercent, siteCurrency);

        $exchangeFee.text(feeText === '0' ? '' : `Fee: ${feeText}`);
    };

    /**
     * The amount field is typed in the source wallet's currency; the role's
     * limits are stored in base. Convert the amount rather than the limits when
     * comparing, and convert the limits only to show them — a customer typing
     * BTC needs to see the limits in BTC, not the base-currency figures they
     * would have had to convert by hand.
     */
    const toBase = (entered) => entered / (exchangeInfo.defaultRate || 1);

    const inSourceCurrency = (baseValue) => DigiKashMoney.format(
        baseValue * (exchangeInfo.defaultRate || 1),
        exchangeInfo.sourceDecimals
    );

    const limitLabel = () => {
        const min = inSourceCurrency(exchangeInfo.minLimit);
        const max = isFinite(exchangeInfo.maxLimit) ? inSourceCurrency(exchangeInfo.maxLimit) : '∞';
        const unit = exchangeInfo.sourceCurrency || siteCurrency;

        return `Min: ${min} ${unit} | Max: ${max} ${unit}`;
    };

    const validateAmountLimits = (entered) => {
        if (!entered) {
            $sendAmountInfo.text(limitLabel()).removeClass('text-danger').addClass('text-success');
            return false;
        }

        const baseAmount = toBase(entered);

        if (baseAmount < exchangeInfo.minLimit || baseAmount > exchangeInfo.maxLimit) {
            $sendAmountInfo
                .text(`Amount must be between ${limitLabel().replace('Min: ', '').replace(' | Max: ', ' and ')}`)
                .addClass('text-danger')
                .removeClass('text-success');
            return false;
        }

        $sendAmountInfo.text(limitLabel()).removeClass('text-danger').addClass('text-success');
        return true;
    };

    // Check if wallets are not the same
    const checkWalletsNotSame = () => {
        if (exchangeInfo.sourceWalletId === exchangeInfo.destinationWalletId) {
            $toWalletWarning.text('Source and destination wallets cannot be the same.');
        } else {
            $toWalletWarning.text('');
        }
    };

    // Update summary
    const updateSummary = () => {
        // What the customer typed, in the source wallet's own currency.
        const entered = parseFloat($amountInput.val()) || null;

        exchangeInfo.defaultRate = parseFloat(
            $sourceWalletSelect.find(':selected').data('default-conversion-rate')
        ) || 1;

        exchangeInfo.destinationDefaultRate = parseFloat(
            $destinationWalletSelect.find(':selected').data('default-conversion-rate')
        ) || 1;

        if (!validateAmountLimits(entered)) {
            clearSummary();
            return;
        }

        if (!entered || !exchangeInfo.sourceCurrency || !exchangeInfo.destinationCurrency) {
            clearSummary();
            return;
        }

        exchangeInfo.conversionRate = exchangeInfo.destinationDefaultRate / exchangeInfo.defaultRate;

        // The server converts the entered figure to base at the same rate
        // before doing anything else, so the preview does too — otherwise the
        // fee shown and the fee charged are computed from different numbers.
        const baseAmount = toBase(entered);

        const fee = feeInBase(baseAmount);

        // Fee on top: the whole typed figure converts and the fee is debited
        // beside it. Fee deducted: the typed figure is debited and what is
        // left after the fee converts. The server does the same sum from the
        // same setting.
        const debited = feeDeducted ? baseAmount : baseAmount + fee;
        const converted = feeDeducted ? baseAmount - fee : baseAmount;
        const unit = exchangeInfo.sourceCurrency;

        if (converted <= 0) {
            $sendAmountInfo
                .text(`{{ __('The fee is larger than the amount. Enter a larger amount.') }}`)
                .addClass('text-danger')
                .removeClass('text-success');
            clearSummary();
            return;
        }

        // Every figure on the paying side is shown in the currency the customer
        // typed and will actually be debited in.
        $summaryAmount.text(`${inSourceCurrency(baseAmount)} ${unit}`);
        $summaryFee.text(`${inSourceCurrency(fee)} ${unit}`);
        // Rates are not amounts: formatting them at amount scale is what turned
        // "1 USD = 0.0000094 BTC" into "1 USD = 0.00 BTC".
        $defaultRate.text(`1 ${siteCurrency} = ${formatRate(exchangeInfo.defaultRate)} ${exchangeInfo.sourceCurrency}`);
        $exchangeRate.text(`1 ${exchangeInfo.sourceCurrency} = ${formatRate(exchangeInfo.conversionRate)} ${exchangeInfo.destinationCurrency}`);
        // GF6: each tax rule as its own line, debited on top of the paying
        // side whichever side the fee is on.
        const rate = exchangeInfo.defaultRate || 1;
        const taxes = exchangeTaxes(converted * rate, fee * rate);
        const debitedInSource = debited * rate + DigiKashMoney.taxTotal(taxes, exchangeInfo.sourceDecimals);

        $summaryTax.empty();
        taxes.forEach((line) => {
            $summaryTax.append(
                $('<li class="d-flex justify-content-between" data-summary-tax-line></li>')
                    .append($('<span></span>').text(line.name))
                    .append($('<span></span>').text(`${DigiKashMoney.format(line.amount, exchangeInfo.sourceDecimals)} ${unit}`))
            );
        });

        $summaryDebited.text(`${DigiKashMoney.format(debitedInSource, exchangeInfo.sourceDecimals)} ${unit}`);
        $summaryCredited.text(`${DigiKashMoney.format(converted * exchangeInfo.destinationDefaultRate, exchangeInfo.destinationDecimals)} ${exchangeInfo.destinationCurrency}`);
    };

    /**
     * The fee for a base-denominated amount, from the same formula the server
     * uses (App\Support\FeeCalculator). The exchange role belongs to the
     * source wallet's currency, so its fixed fee and caps are in that currency
     * and the rate takes part in the calculation.
     */
    const feeInBase = (baseAmount) => DigiKashMoney.feeFromBase(
        baseAmount, exchangeInfo.defaultRate,
        exchangeInfo.feeFixed, exchangeInfo.feePercent, exchangeInfo.feeMin, exchangeInfo.feeMax
    );

    /**
     * Fill in the most the source wallet can exchange once the fee is
     * charged, in the wallet's own currency.
     */
    const useMax = () => {
        const option = $sourceWalletSelect.find(':selected');
        const balance = parseFloat(option.data('balance')) || 0;

        if (!exchangeInfo.sourceCurrency) return;

        const feeInSourceCurrency = (amount) => feeInBase(toBase(amount)) * (exchangeInfo.defaultRate || 1);
        const ceiling = isFinite(exchangeInfo.maxLimit) ? exchangeInfo.maxLimit * (exchangeInfo.defaultRate || 1) : null;

        // GF6: with a tax rule in play the tax goes beside the typed figure
        // in both fee modes, so the most is searched for either way.
        const taxed = exchangeTaxRules.length > 0;
        const besideTyped = (amount) => {
            const fee = feeInSourceCurrency(amount);
            const net = feeDeducted ? amount - fee : amount;
            const tax = taxed ? DigiKashMoney.taxTotal(exchangeTaxes(net, fee), exchangeInfo.sourceDecimals) : 0;

            return feeDeducted ? tax : fee + tax;
        };

        const most = DigiKashMoney.maxSpendable(balance, besideTyped, exchangeInfo.sourceDecimals, feeDeducted && !taxed, ceiling);

        $amountInput.val(most > 0 ? most.toFixed(exchangeInfo.sourceDecimals) : '');
        updateSummary();
    };

    // Clear summary
    const clearSummary = () => {
        $summaryAmount.text('');
        $summaryFee.text('');
        $defaultRate.text('');
        $exchangeRate.text('');
        $summaryDebited.text('');
        $summaryCredited.text('');
        $summaryTax.empty();
    };

    // Event listeners
    $sourceWalletSelect.on('change', function () {
        const selectedOption = $(this).find(':selected');
        exchangeInfo.sourceWalletId = selectedOption.val();
        exchangeInfo.sourceCurrency = selectedOption.data('currency');
        exchangeInfo.feeFixed = parseFloat(selectedOption.data('fee-fixed')) || 0;
        exchangeInfo.feePercent = parseFloat(selectedOption.data('fee-percent')) || 0;
        exchangeInfo.feeMin = selectedOption.data('fee-min') ?? null;
        exchangeInfo.feeMax = selectedOption.data('fee-max') ?? null;
        exchangeInfo.sourceDecimals = DigiKashMoney.decimalsFor(selectedOption[0]);
        exchangeInfo.defaultRate = parseFloat(selectedOption.data('default-conversion-rate')) || 1;
        exchangeInfo.minLimit = parseFloat(selectedOption.data('min-limit')) || 0;
        exchangeInfo.maxLimit = parseFloat(selectedOption.data('max-limit')) || Infinity;

        // The amount field is denominated in whichever wallet is selected, so
        // its suffix and its precision follow the selection.
        $('.amount-currency').text(exchangeInfo.sourceCurrency || siteCurrency);
        $amountInput.attr('data-decimals', exchangeInfo.sourceDecimals);
        $useMaxBtn.prop('disabled', !exchangeInfo.sourceCurrency);

        validateAmountLimits(parseFloat($amountInput.val()) || null);
        updateFeeDisplay();
        updateSummary();
        checkWalletsNotSame();
    });

    $destinationWalletSelect.on('change', function () {
        const selectedOption = $(this).find(':selected');
        exchangeInfo.destinationWalletId = selectedOption.val();
        exchangeInfo.destinationCurrency = selectedOption.data('currency');
        exchangeInfo.destinationDecimals = DigiKashMoney.decimalsFor(selectedOption[0]);

        checkWalletsNotSame();
        updateSummary();
    });

    $amountInput.on('keyup', debounce(updateSummary, 300));
    $useMaxBtn.on('click', useMax);

</script>
