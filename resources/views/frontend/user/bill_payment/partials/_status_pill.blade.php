{{-- One payment status, in the customer's words. Expects $status (BillPaymentStatus). --}}
@php
    $bpPillIcon = match ($status) {
        \App\Enums\BillPayment\BillPaymentStatus::Completed => 'fa-check',
        \App\Enums\BillPayment\BillPaymentStatus::Failed    => 'fa-rotate',
        default                                              => 'fa-clock',
    };
@endphp
<span class="bp-pill bp-pill--{{ $status->color() }}">
    <i class="fa-solid {{ $bpPillIcon }}" aria-hidden="true"></i>
    {{ $status->customerLabel() }}
</span>
