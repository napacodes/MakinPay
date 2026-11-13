{{--
    One payment in a list, linking to its receipt. Expects $payment (BillPayment)
    and $categoryIcons (category code => icon key). Reads the payment's own
    snapshot, so a biller renamed or removed since still shows as it was paid.
--}}
<a class="bp-payment" href="{{ route('user.bill-payment.show', $payment) }}" data-bp-payment="{{ $payment->uuid }}">
    @include('frontend.user.bill_payment.partials._logo', [
        'logo' => $payment->biller?->logo,
        'icon' => $categoryIcons[$payment->category_code] ?? null,
        'size' => 'sm',
    ])

    <span class="bp-payment__body">
        <span class="bp-payment__title">{{ $payment->biller_name }}</span>
        <span class="bp-payment__meta">
            <span>{{ $payment->customer_number_label }} <span class="bp-mono" dir="ltr">{{ $payment->customer_number }}</span></span>
            <span class="bp-payment__dot" aria-hidden="true"></span>
            <span dir="ltr">{{ $payment->created_at?->format('d M Y, H:i') }}</span>
        </span>
    </span>

    <span class="bp-payment__side">
        <span class="bp-payment__amount" dir="ltr">{{ money($payment->total_amount, $payment->currency, false) }} {{ $payment->currency }}</span>
        @include('frontend.user.bill_payment.partials._status_pill', ['status' => $payment->status])
    </span>

    <i class="fa-solid fa-chevron-right bp-arrow" aria-hidden="true"></i>
</a>
