{{--
    A payment's status pill, with a Review chip when it waits in needs_review:
    those are the ones that must not be resolved before somebody checks.
--}}
@php
    $bpaStatus = $payment->status;
    $bpaStage  = $payment->dispatchState();
@endphp
<span class="bpa-status">
    <span class="bpa-pill bpa-pill--{{ $bpaStatus?->color() ?? 'secondary' }}">{{ $bpaStatus?->label() ?? __('Unknown') }}</span>
    @if($bpaStage === \App\Enums\BillPayment\BillPaymentDispatchState::NeedsReview && ! ($bpaStatus?->isTerminal() ?? false))
        <span class="bpa-pill bpa-pill--danger">{{ __('Review') }}</span>
    @endif
</span>
<span class="bpa-muted d-block">{{ $bpaStage->label() }}</span>
