{{--
    The data a mark-paid, mark-failed or confirm-not-paid dialog is filled
    from, written onto the button that opens it. Included inside that
    button's tag.

    The amount to copy is the exact figure at the currency's own precision
    (cut, never rounded), so what the operator pastes at the biller is what
    the customer was charged for. The token label comes from the live biller,
    because that is what the service checks when the payment is marked paid.
--}}
@php
    $bpaBillDecimals  = \App\Support\Money::decimalsIfKnown((string) $payment->bill_currency) ?? 8;
    $bpaWalletDecimals = \App\Support\Money::decimalsIfKnown((string) $payment->currency) ?? 8;
@endphp
data-bpa-uuid="{{ $payment->uuid }}"
data-bpa-reference="{{ $payment->reference }}"
data-bpa-biller="{{ $payment->biller_name }}"
data-bpa-customer-label="{{ $payment->customer_number_label }}"
data-bpa-customer-number="{{ $payment->customer_number }}"
data-bpa-item="{{ $payment->item_name }}"
data-bpa-amount="{{ money($payment->bill_amount, $payment->bill_currency, false).' '.$payment->bill_currency }}"
data-bpa-amount-exact="{{ \App\Support\DecimalMoney::normalize((string) $payment->bill_amount, $bpaBillDecimals) }}"
data-bpa-total="{{ money(\App\Support\DecimalMoney::normalize((string) $payment->total_amount, $bpaWalletDecimals), $payment->currency, false).' '.$payment->currency }}"
data-bpa-token-label="{{ $payment->biller?->token_label }}"
data-bpa-paid-url="{{ route('admin.bill-payment.mark-paid', $payment) }}"
data-bpa-failed-url="{{ route('admin.bill-payment.mark-failed', $payment) }}"
data-bpa-confirm-url="{{ route('admin.bill-payment.confirm-not-paid', $payment) }}"
