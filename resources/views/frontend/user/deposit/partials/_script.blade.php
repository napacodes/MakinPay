@php use App\Enums\MethodType; @endphp
<script>
    $(document).ready(function () {
        'use strict';

        // DOM Elements
        const elements = {
            depositMethodList: $('.deposit-method-list'),
            depositMethodInfo: $('.deposit-method-info'),
            depositAmountInfo: $('.deposit-amount-info'),
            walletSelect: $('.wallet-select'),
            depositInput: $('.deposit-amount'),
            summaryTax: $('[data-summary-tax]'),
            summary: {
                methodName: $('.summary-method-name'),
                amount: $('.summary-amount'),
                charge: $('.summary-charge'),
                chargeAlt: $('.summary-charge-alt'),
                total: $('.summary-total'),
                rate: $('.summary-rate'),
                added: $('.wallet-added'),
                payable: $('.summary-payable'),
            },
        };

        const templates = {
            defaultOption: `<option disabled selected>{{ __('Select Deposit Method') }}</option>`,
            noMethodOption: `<option disabled selected>{{ __('Select Another Wallet') }}</option>`,
        };

        let depositMethods = [];
        let selectedMethod = null;
        const siteCurrency = '{{ siteCurrency() }}';
        let walletCurrency = null;
        
      
        
        if (elements.walletSelect.val() !== null) {
            walletAjaxRequest(elements.walletSelect.val());
        }

        /**
         * Update deposit methods dropdown and info
         * @param {Array} methods
         */
        const updateDepositMethods = (methods) => {
            const {depositMethodList, depositMethodInfo} = elements;

            if (methods.length) {
                depositMethodList.html(templates.defaultOption);
                methods.forEach(method =>
                    depositMethodList.append(`<option value="${method.id}">${method.name}</option>`)
                );
                depositMethodInfo.text('');
            } else {
                depositMethodList.html(templates.noMethodOption);
                depositMethodInfo.text('{{ __('No Payment Method Available For This Wallet') }}');
            }
        };

        /**
         * Update deposit amount info and summary details
         * @param {number} amount
         */
        const updateDepositAmountInfo = (entered) => {
            if (!selectedMethod) return;

            const {min_deposit, max_deposit} = selectedMethod;
            const {depositAmountInfo} = elements;
            const rate = parseFloat(selectedMethod.conversion_rate) || 1;
            const walletDecimals = selectedMethod.decimals === undefined ? {{ currencyDecimals() }} : selectedMethod.decimals;
            const code = walletCurrency || siteCurrency;

            // The customer types in the method's currency; the limits are
            // stored in base. Compare after converting, and show the limits in
            // the unit being typed rather than asking for a mental conversion.
            const baseAmount = DigiKashMoney.toBase(entered, rate);
            const inMethodCurrency = (value) =>
                DigiKashMoney.format(DigiKashMoney.fromBase(value, rate), walletDecimals);

            if (baseAmount < min_deposit || baseAmount > max_deposit) {
                depositAmountInfo.text(`{{ __('Amount must be between') }} ${inMethodCurrency(min_deposit)} ${code} {{ __('and') }} ${inMethodCurrency(max_deposit)} ${code}`);
                resetSummary();
            } else {
                depositAmountInfo.text(`{{ __('Min Deposit') }}: ${inMethodCurrency(min_deposit)} ${code}, {{ __('Max Deposit') }}: ${inMethodCurrency(max_deposit)} ${code}`);
                updateSummary(baseAmount);
            }
        };

        /**
         * Update summary details
         * @param {number} amount
         */
        const updateSummary = (amount) => {
            if (!selectedMethod) {
                resetSummary();
                return;
            }

            const conversionRate = parseFloat(selectedMethod.conversion_rate) || 1;

            // Same formula as App\Support\FeeCalculator. The fixed component
            // and the caps are denominated in the method's own currency, so
            // the rate has to take part in the calculation.
            const charge = selectedMethod.charge_fixed === undefined
                ? DigiKashMoney.legacyFee(amount, selectedMethod.charge, selectedMethod.charge_type)
                : DigiKashMoney.feeFromBase(
                    amount,
                    conversionRate,
                    selectedMethod.charge_fixed,
                    selectedMethod.charge_percent,
                    selectedMethod.charge_min,
                    selectedMethod.charge_max
                );

            const total = amount + charge;
            const base = {{ currencyDecimals() }};
            const walletDecimals = selectedMethod.decimals === undefined ? base : selectedMethod.decimals;

            const {summary} = elements;
            summary.methodName.text(selectedMethod.name);
            // Every figure is shown in the currency the customer typed and
            // will actually pay in.
            const inMethodCurrency = (value) =>
                DigiKashMoney.format(DigiKashMoney.fromBase(value, conversionRate), walletDecimals);

            summary.amount.text(`${inMethodCurrency(amount)} ${walletCurrency || siteCurrency}`);
            summary.charge.text(`${inMethodCurrency(charge)} ${walletCurrency || siteCurrency}`);
            // The base-currency equivalent, for operators reconciling against
            // the platform's reporting unit.
            summary.chargeAlt.text(
                walletCurrency && walletCurrency !== siteCurrency && charge > 0
                    ? `≈ ${DigiKashMoney.format(charge, base)} ${siteCurrency}`
                    : ''
            );
            // GF6: each tax rule as its own line, on the amount or on the fee,
            // paid on top of both.
            const taxes = DigiKashMoney.taxLines(
                selectedMethod.tax_rules,
                DigiKashMoney.fromBase(amount, conversionRate),
                DigiKashMoney.fromBase(charge, conversionRate),
                walletDecimals
            );
            const payable = DigiKashMoney.fromBase(total, conversionRate) + DigiKashMoney.taxTotal(taxes, walletDecimals);

            elements.summaryTax.empty();
            taxes.forEach((line) => {
                elements.summaryTax.append(
                    $('<li class="d-flex justify-content-between" data-summary-tax-line></li>')
                        .append($('<span></span>').text(line.name))
                        .append($('<span></span>').text(`${DigiKashMoney.format(line.amount, walletDecimals)} ${walletCurrency || siteCurrency}`))
                );
            });

            summary.total.text(`${DigiKashMoney.format(payable, walletDecimals)} ${walletCurrency || siteCurrency}`);
            // A rate keeps its own precision; formatting it at amount scale is
            // what collapsed live crypto rates to "0.00".
            summary.rate.text(`1 ${siteCurrency} = ${DigiKashMoney.rate(selectedMethod.conversion_rate || conversionRate)} ${walletCurrency}`);
            summary.added.text(`${DigiKashMoney.format(amount * conversionRate, walletDecimals)} ${walletCurrency}`);
            summary.payable.text(`${DigiKashMoney.format(payable, walletDecimals)} ${walletCurrency}`);
        };

        /**
         * Show the phone field for a method whose gateway sends the payer's
         * number on. A hidden field is disabled so it is never submitted.
         * @param {string} mode none | optional | required
         */
        const togglePhoneField = (mode) => {
            const field = $('#deposit-customer-phone');
            const collected = mode === 'optional' || mode === 'required';

            field.toggleClass('d-none', !collected);
            field.find('input').prop('disabled', !collected).prop('required', mode === 'required');
            field.find('.deposit-customer-phone-optional').toggleClass('d-none', mode === 'required');
        };

        /**
         * Reset summary details
         */
        const resetSummary = () => {
            const {summary} = elements;
            elements.summaryTax.empty();
            Object.entries(summary).forEach(([key, el]) => {
                // The converted-charge line is a supplement, not a value of its
                // own — blank reads better than "N/A" hanging under the charge.
                el.text(key === 'chargeAlt' ? '' : '{{ __('N/A') }}');
            });
        };

        // Event Handlers
        elements.walletSelect.on('change', function () {
            const walletId = $(this).val();
            walletAjaxRequest(walletId);
        });
        
        
        

        elements.depositMethodList.on('change', function () {
            const paymentMethodId = $(this).val();
            selectedMethod = depositMethods.find(method => method.id == paymentMethodId);
            walletCurrency = selectedMethod?.currency;
            togglePhoneField(selectedMethod?.customer_phone || 'none');

            if (selectedMethod) {
                // Shared helper, so the storage padding on a decimal(24,8)
                // column is never shown to the customer as "1.00000000%".
                // The fixed part is in the method's currency, so label it that way.
                elements.depositMethodInfo.text(`{{ __('Charge') }}: ${DigiKashMoney.describe(selectedMethod.charge_fixed, selectedMethod.charge_percent, selectedMethod.currency || siteCurrency)}`);

                // The amount field is read in this method's currency, so its
                // suffix and precision follow the selection rather than staying
                // on base.
                DigiKashMoney.bindAmountCurrency(
                    selectedMethod.currency || siteCurrency,
                    selectedMethod.decimals === undefined ? {{ currencyDecimals() }} : selectedMethod.decimals
                );

                updateDepositAmountInfo(parseFloat(elements.depositInput.val()) || 0);
                $('#manual-deposit-credentials').html('');
                
                if (selectedMethod.type === `{{ MethodType::MANUAL }}`) {

                    $.ajax({
                        url: `{{ route('user.deposit.credentials', ':method') }}`.replace(':method', paymentMethodId),
                        method: 'GET',
                        headers: {
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                        },
                        success: (data) => {
                            // Insert the rendered view into the DOM
                            $('#manual-deposit-credentials').html(data);
                        },
                    })

                }
            }
        });

        elements.depositInput.on('keyup', function () {
            const amount = parseFloat($(this).val()) || 0;
            updateDepositAmountInfo(amount);
        });
        
        function walletAjaxRequest(walletId) {
            const url = `{{ route('user.wallet.supported-payment-methods', ':wallet') }}`.replace(':wallet', walletId);
            
            $.ajax({
                url,
                method: 'GET',
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: (data) => {
                    depositMethods = data;
                    updateDepositMethods(data);
                    selectedMethod = null;
                    togglePhoneField('none');
                    resetSummary();
                },
                error: () => {
                    alert('{{ __('Failed to load payment methods. Please try again later.') }}');
                }
            });
        }
    });

</script>
