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
        summaryRate: '.summary-rate',
        recipientWalletAdded: '.recipient-wallet-added',
        myWalletDecreased: '.my-wallet-decreased',
    };

    const baseDecimals = {{ currencyDecimals() }};

    const resetDataOnRecipientChange = () => {
        sendMoneyInfo = {};
        inputValues.walletId = null;
        $(selectors.walletSelect).html('<option disabled selected>{{ __("Select Wallet") }}</option>');
        $(selectors.walletInfo).text('');
        $(selectors.recipientInfo).text('').removeClass('text-danger text-success');
        updateSummary();
    };

    const updateSummary = () => {
        // What the customer typed, in the requesting wallet's own currency.
        const {amount: entered} = inputValues;
        const {
            fee, fee_type, fee_fixed, fee_percent, fee_min, fee_max,
            currency_rate, wallet_currency, min_limit, max_limit, decimals
        } = sendMoneyInfo;

        if (!currency_rate || !wallet_currency || !entered) return;

        const walletDecimals = decimals === undefined ? baseDecimals : decimals;

        // The server converts to base before doing anything else, so the
        // preview does too — otherwise the fee shown and the fee charged are
        // computed from different numbers.
        const amount = DigiKashMoney.toBase(entered, currency_rate);

        // Limits are stored in base but shown in the unit being typed.
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

        // Same formula as App\Support\FeeCalculator.
        const feeAmount = fee_fixed === undefined
            ? DigiKashMoney.legacyFee(amount, fee, fee_type)
            : DigiKashMoney.feeFromBase(amount, currency_rate, fee_fixed, fee_percent, fee_min, fee_max);

        const total = amount + feeAmount;

        // Every figure is shown in the currency the customer typed in.
        $(selectors.summaryAmount).text(`${inWallet(amount)} ${wallet_currency}`);
        $(selectors.summaryCharge).text(`${inWallet(feeAmount)} ${wallet_currency}`);
        $(selectors.summaryTotal).text(`${inWallet(total)} ${wallet_currency}`);
        $(selectors.summaryRate).text(`1 {{ siteCurrency() }} = ${DigiKashMoney.rate(currency_rate)} ${wallet_currency}`);
        $(selectors.recipientWalletAdded).text(`${inWallet(amount)} ${wallet_currency}`);
        $(selectors.myWalletDecreased).text(`${inWallet(total)} ${wallet_currency}`);
    };

    const fetchRecipientInfo = () => {
        if (!inputValues.recipient) return;

        const url = "{{ route('user.wallet.validate.recipient', [CurrencyRole::REQUEST_MONEY, ':value']) }}"
            .replace(':value', inputValues.recipient);

        $.get(url, (data) => {
            if (data.status === 'success') {
                const {currency_role, wallet_currency, currency_rate, type} = data;

                $(selectors.recipientInfo).text(data.message).removeClass('text-danger').addClass('text-success');
                $(selectors.walletSelect).html(data.available_wallets);

                if (type === 'wallet_uuid') {
                    sendMoneyInfo = {...currency_role, wallet_currency, currency_rate};
                    updateWalletFee(currency_role);
                }

                updateSummary();
            } else {
                handleValidationError(data.message);
            }
        }).fail(() => {
            handleValidationError('{{ __("Unable to validate payer. Please try again.") }}');
        });
    };

    const handleValidationError = (message) => {
        resetDataOnRecipientChange();
        $(selectors.recipientInfo).text(message).addClass('text-danger').removeClass('text-success');
    };

    const fetchWalletInfo = () => {
        if (!inputValues.walletId) return;

        const url = "{{ route('user.wallet.info', [CurrencyRole::REQUEST_MONEY, ':walletId']) }}"
            .replace(':walletId', inputValues.walletId);

        $.get(url, (data) => {
            sendMoneyInfo = {...data.data};
            updateWalletFee(data.data);
            updateSummary();
        });
    };

    const updateWalletFee = (walletData) => {
        // A fee can now have both components, so describe whichever are set.
        const code = walletData.code || sendMoneyInfo.wallet_currency || '{{ siteCurrency() }}';
        const feeText = DigiKashMoney.describe(walletData.fee_fixed, walletData.fee_percent, code);

        $(selectors.walletInfo).text(`{{ __('Fee') }}: ${feeText}`);

        // The amount field is read in this wallet's currency, so its suffix and
        // precision follow the selection rather than staying on base.
        const walletDecimals = walletData.decimals === undefined ? baseDecimals : walletData.decimals;
        const rate = sendMoneyInfo.currency_rate || 1;

        DigiKashMoney.bindAmountCurrency(code, walletDecimals);

        $(selectors.sendAmountInfo).text(
            `Min: ${DigiKashMoney.format(DigiKashMoney.fromBase(walletData.min_limit, rate), walletDecimals)} ${code}`
            + ` | Max: ${DigiKashMoney.format(DigiKashMoney.fromBase(walletData.max_limit, rate), walletDecimals)} ${code}`
        );
    };

    $(document)
        .on('change', '.recipient-input', function () {
            inputValues.recipient = $(this).val();
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
</script>
