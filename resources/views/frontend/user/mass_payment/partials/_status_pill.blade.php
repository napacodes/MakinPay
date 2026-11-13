{{-- One batch status, in the customer's words. Expects $status (MassPaymentBatchStatus). --}}
@php
    $mpPillIcon = match ($status) {
        \App\Enums\MassPayment\MassPaymentBatchStatus::Completed          => 'fa-check',
        \App\Enums\MassPayment\MassPaymentBatchStatus::PartiallyCompleted => 'fa-circle-half-stroke',
        \App\Enums\MassPayment\MassPaymentBatchStatus::Failed             => 'fa-rotate',
        \App\Enums\MassPayment\MassPaymentBatchStatus::NeedsReview        => 'fa-pause',
        \App\Enums\MassPayment\MassPaymentBatchStatus::Draft              => 'fa-pen',
        \App\Enums\MassPayment\MassPaymentBatchStatus::Discarded,
        \App\Enums\MassPayment\MassPaymentBatchStatus::Expired            => 'fa-ban',
        \App\Enums\MassPayment\MassPaymentBatchStatus::ClosedAfterReview  => 'fa-lock',
        default                                                           => 'fa-clock',
    };
@endphp
<span class="mp-pill mp-pill--{{ $status->color() }}" data-mp-status-pill="{{ $status->value }}">
    <i class="fa-solid {{ $mpPillIcon }}" aria-hidden="true"></i>
    <span data-mp-status-label>{{ $status->customerLabel() }}</span>
</span>
