@php use App\Constants\CurrencyRole; @endphp
<script>
    'use strict';

    let sendMoneyInfo = {};
    let inputValues = {
        recipient: null,
        walletId: null,
        amount: 0,
    };

    const selectors = {
        walletSelect: '.wallet-select',
        walletInfo: '.wallet-info',
        recipientInfo: '.recipient-info',
        sendAmountInfo: '.send-amount-info',
        summaryAmount: '.summary-amount',
        summaryCharge: '.summary-charge',
        summaryTotal: '.summary-total',
        summaryTax: '[data-summary-tax]',
        summaryRate: '.summary-rate',
        recipientWalletAdded: '.recipient-wallet-added',
        myWalletDecreased: '.my-wallet-decreased',
    };

    const routes = {
        validateRecipient: @json(route('user.wallet.validate.recipient', [CurrencyRole::SENDER, '__RECIPIENT__'])),
        walletInfo: @json(route('user.wallet.info', [CurrencyRole::SENDER, '__WALLET__'])),
    };

    const prefilledRecipient = @json(old('recipient', $prefilledRecipient ?? ''));

    // Reset data when the recipient changes
    const resetDataOnRecipientChange = () => {
        sendMoneyInfo = {};
        inputValues.walletId = null;
        $(selectors.walletSelect).html('<option disabled selected>{{ __("Select Wallet") }}</option>');
        $(selectors.walletInfo).text('');
        $(selectors.recipientInfo).text('').removeClass('text-danger text-success');
        updateSummary();
    };

    // Update the transaction summary
    const updateSummary = () => {
        // What the customer typed, in the sending wallet's own currency.
        const {amount: entered} = inputValues;
        const {
            fee, fee_type, fee_fixed, fee_percent, fee_min, fee_max,
            currency_rate, wallet_currency, min_limit, max_limit, decimals, tax_rules
        } = sendMoneyInfo;

        if (!currency_rate || !wallet_currency || !entered) return;

        const base = {{ currencyDecimals() }};
        const walletDecimals = decimals === undefined ? base : decimals;

        // The server converts to base before doing anything else, so the
        // preview does too — otherwise the fee shown and the fee charged are
        // computed from different numbers.
        const amount = DigiKashMoney.toBase(entered, currency_rate);

        // Limits are stored in base but shown in the unit being typed, so the
        // customer is not asked to convert them by hand.
        const inWallet = (value) => DigiKashMoney.format(DigiKashMoney.fromBase(value, currency_rate), walletDecimals);

        if (amount < min_limit || amount > max_limit) {
            $(selectors.sendAmountInfo)
                .text(`{{ __("Amount must be between") }} ${inWallet(min_limit)} ${wallet_currency} {{ __("and") }} ${inWallet(max_limit)} ${wallet_currency}`)
                .addClass('text-danger').removeClass('text-success');
            return;
        } else {
            $(selectors.sendAmountInfo)
                .text(`Min: ${inWallet(min_limit)} ${wallet_currency} | Max: ${inWallet(max_limit)} ${wallet_currency}`)
                .removeClass('text-danger');
        }

        // Same formula as App\Support\FeeCalculator, so this preview matches
        // what the server will actually charge.
        // The role's fixed fee and caps belong to the wallet's currency, so the
        // rate takes part in the calculation exactly as it does server-side.
        const feeAmount = fee_fixed === undefined
            ? DigiKashMoney.legacyFee(amount, fee, fee_type)
            : DigiKashMoney.feeFromBase(amount, currency_rate, fee_fixed, fee_percent, fee_min, fee_max);

        const total = amount + feeAmount;

        // GF6: each tax rule as its own line, worked out in the wallet's
        // currency on the amount sent or on the fee, on top of the total.
        const walletAmount = DigiKashMoney.fromBase(amount, currency_rate);
        const walletFee = DigiKashMoney.fromBase(feeAmount, currency_rate);
        const taxes = DigiKashMoney.taxLines(tax_rules, walletAmount, walletFee, walletDecimals);
        const taxTotal = DigiKashMoney.taxTotal(taxes, walletDecimals);
        const walletTotal = DigiKashMoney.fromBase(total, currency_rate) + taxTotal;

        $(selectors.summaryTax).empty();
        taxes.forEach((line) => {
            $(selectors.summaryTax).append(
                $('<li class="d-flex justify-content-between" data-summary-tax-line></li>')
                    .append($('<span></span>').text(line.name))
                    .append($('<span></span>').text(`${DigiKashMoney.format(line.amount, walletDecimals)} ${wallet_currency}`))
            );
        });

        // Every figure is shown in the currency the customer typed and will be
        // debited in.
        $(selectors.summaryAmount).text(`${inWallet(amount)} ${wallet_currency}`);
        $(selectors.summaryCharge).text(`${inWallet(feeAmount)} ${wallet_currency}`);
        $(selectors.summaryTotal).text(`${DigiKashMoney.format(walletTotal, walletDecimals)} ${wallet_currency}`);
        $(selectors.summaryRate).text(`1 {{ siteCurrency() }} = ${DigiKashMoney.rate(currency_rate)} ${wallet_currency}`);
        $(selectors.recipientWalletAdded).text(`${inWallet(amount)} ${wallet_currency}`);
        $(selectors.myWalletDecreased).text(`${DigiKashMoney.format(walletTotal, walletDecimals)} ${wallet_currency}`);
    };

    // Handle recipient validation
    const fetchRecipientInfo = () => {
        inputValues.recipient = String(inputValues.recipient || '').trim();

        if (!inputValues.recipient) return;

        const url = routes.validateRecipient.replace('__RECIPIENT__', encodeURIComponent(inputValues.recipient));

        $.get(url, (data) => {
            if (data.status === 'success') {
                const {currency_role, wallet_currency, currency_rate, type, tax_rules} = data;

                $(selectors.recipientInfo).text(data.message).removeClass('text-danger').addClass('text-success');
                $(selectors.walletSelect).html(data.available_wallets);

                if (type === 'wallet_uuid') {
                    sendMoneyInfo = {...currency_role, wallet_currency, currency_rate, tax_rules: tax_rules || []};
                    updateWalletFee(currency_role);
                }
                updateSummary();
            } else {
                handleValidationError(data.message);
            }
        }).fail(() => {
            handleValidationError('{{ __("Unable to validate recipient. Please try again.") }}');
        });
    };

    const handleValidationError = (message) => {
        resetDataOnRecipientChange();
        $(selectors.recipientInfo).text(message).addClass('text-danger').removeClass('text-success');
    };

    // Fetch wallet information
    const fetchWalletInfo = () => {
        if (!inputValues.walletId) return;

        const url = routes.walletInfo.replace('__WALLET__', encodeURIComponent(inputValues.walletId));

        $.get(url, (data) => {
            sendMoneyInfo = {...data.data};
            updateWalletFee(data.data);
            updateSummary();
        });
    };

    const updateWalletFee = (walletData) => {
        // Shared helper, so a decimal(24,8) column is never shown as
        // "1.00000000%" to the customer.
        const feeText = DigiKashMoney.describe(walletData.fee_fixed, walletData.fee_percent, walletData.code || '{{ siteCurrency() }}');

        $(selectors.walletInfo).text(`{{ __('Fee') }}: ${feeText}`);

        // The amount field is read in this wallet's currency, so its suffix and
        // precision follow the selection rather than staying on base.
        const code = walletData.code || sendMoneyInfo.wallet_currency || '{{ siteCurrency() }}';
        const walletDecimals = walletData.decimals === undefined ? {{ currencyDecimals() }} : walletData.decimals;
        const rate = sendMoneyInfo.currency_rate || 1;

        DigiKashMoney.bindAmountCurrency(code, walletDecimals);

        $(selectors.sendAmountInfo).text(
            `Min: ${DigiKashMoney.format(DigiKashMoney.fromBase(walletData.min_limit, rate), walletDecimals)} ${code}`
            + ` | Max: ${DigiKashMoney.format(DigiKashMoney.fromBase(walletData.max_limit, rate), walletDecimals)} ${code}`
        );
    };

    // Event listeners
    $(document)
        .on('change', '.recipient-input', function () {
            inputValues.recipient = $(this).val().trim();
            resetDataOnRecipientChange();
            fetchRecipientInfo();
        })
        .on('change', selectors.walletSelect, function () {
            inputValues.walletId = $(this).val();
            fetchWalletInfo();
        })
        .on('keyup', '.amount-input', function () {
            inputValues.amount = parseFloat($(this).val()) || 0;
            updateSummary();
        });

    $(function () {
        const initialRecipient = String(prefilledRecipient || $('.recipient-input').val() || '').trim();

        if (!initialRecipient) {
            return;
        }

        $('.recipient-input').val(initialRecipient);
        inputValues.recipient = initialRecipient;
        fetchRecipientInfo();
    });
</script>
