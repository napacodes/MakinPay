{{-- A draft's preview figures, shared by mass payments and mass payouts. Expects $toLabel, and from the page $batch, $funding and $mpMoney. --}}
<div class="mp-cards" data-mp-summary>
    <div class="mp-card"><span class="mp-card__label">{{ __('Rows') }}</span><span class="mp-card__value" data-mp-count="rows">{{ (int) $batch->row_count }}</span></div>
    <div class="mp-card mp-card--success"><span class="mp-card__label">{{ __('Ready to pay') }}</span><span class="mp-card__value" data-mp-count="valid">{{ (int) $batch->valid_count }}</span></div>
    <div class="mp-card mp-card--danger"><span class="mp-card__label">{{ __('Invalid') }}</span><span class="mp-card__value" data-mp-count="invalid">{{ (int) $batch->invalid_count }}</span></div>
    <div class="mp-card mp-card--warning"><span class="mp-card__label">{{ __('Warnings') }}</span><span class="mp-card__value" data-mp-count="warnings">{{ (int) $batch->warning_count }}</span></div>
    <div class="mp-card"><span class="mp-card__label">{{ $toLabel }}</span><span class="mp-card__value" dir="ltr">{{ $mpMoney($batch->total_amount) }}</span></div>
    <div class="mp-card"><span class="mp-card__label">{{ __('Fees') }}</span><span class="mp-card__value" dir="ltr">{{ $mpMoney($batch->total_fee) }}</span></div>
    <div class="mp-card mp-card--primary"><span class="mp-card__label">{{ __('Total to reserve') }}</span><span class="mp-card__value" dir="ltr" data-mp-total>{{ $mpMoney($batch->total_payable) }}</span></div>
    @if($funding['short_by'] !== null)
        <div class="mp-card mp-card--danger" data-mp-short-by><span class="mp-card__label">{{ __('Short by') }}</span><span class="mp-card__value" dir="ltr">{{ $mpMoney($funding['short_by']) }}</span></div>
    @else
        <div class="mp-card" data-mp-balance-after><span class="mp-card__label">{{ __('Balance after') }}</span><span class="mp-card__value" dir="ltr">{{ $mpMoney($funding['after']) }}</span></div>
    @endif
</div>
