<div class="col-xl-5">
	<div class="single-form-card">
        <x-user-feature-header
            :title="__('Summary')"
            :subtitle="__('Check funding source, fees, and card credit before confirming.')"
            icon="fas fa-wallet"
            compact
        />
		<div class="card-main">
			<ul class="summery-list list-unstyled">
				<li class="d-flex justify-content-between">
					<span>{{ __('Source Wallet') }}</span>
					<span><strong>{{ $card->wallet->name ?? '-' }} @lang('Wallet')</strong><span class="ms-2 small">({{ money($card->wallet->balance ?? 0, $card->wallet->currency->code) }})</span></span>
				</li>
				<li class="d-flex justify-content-between">
					<span>{{ __('Amount to Top Up') }}</span>
					<span class="summary-amount">-</span>
				</li>
				<li class="d-flex justify-content-between">
					<span>{{ __('Top Up Fee') }}</span>
					<span class="summary-charge">
                        @if($cardSettings)
                            {{ money($cardSettings->fee_amount, $card->wallet->currency->code) }} + {{ feeRate($cardSettings->fee_percent) }}%
                        @else
                            0
                        @endif
                    </span>
				</li>
				<li class="d-flex justify-content-between">
					<span>{{ __('Total Deducted') }}</span>
					<span class="summary-total text-danger fw-bold">-</span>
				</li>
				<li class="d-flex justify-content-between">
					<span>{{ __('Card Will Receive') }}</span>
					<span class="wallet-added text-success fw-bold">-</span>
				</li>
				<li class="d-flex justify-content-between">
					<span>{{ __('Currency') }}</span>
					<span>
						{{ $card->wallet->currency->code ?? '' }}
						@if(!empty($fx['card_currency']))
							<i class="fa-solid fa-arrow-right mx-1 small" aria-hidden="true"></i>{{ $fx['card_currency'] }}
						@endif
					</span>
				</li>
			</ul>
			<div class="mt-2">
				@if($cardSettings)
					<span class="badge bg-info text-dark">{{ __('Approval Required Above:') }} {{ money($cardSettings->approval_threshold, $card->wallet->currency->code) }}</span>
				@else
					<span class="badge bg-success">{{ __('Instant Top-up. No approval needed.') }}</span>
				@endif
			</div>
		</div>
	</div>
</div>
