<div class="col-xl-5">
	<div class="single-form-card">
        <x-user-feature-header
            :title="__('Summary')"
            :subtitle="__('Review deduction, wallet credit, and approval rules before submitting.')"
            icon="fas fa-file-invoice"
            compact
        />
		<div class="card-main">
			<ul class="summery-list list-unstyled">
				<li class="d-flex justify-content-between">
					<span>{{ __('Destination Wallet') }}</span>
					<span>
						<strong>{{ $card->wallet->name ?? '-' }} @lang('Wallet')</strong>
						<span class="ms-2 small">({{ money($card->wallet->balance ?? 0, $card->wallet->currency->code) }})</span>
					</span>
				</li>
				<li class="d-flex justify-content-between">
					<span>{{ __('Amount to Withdraw') }}</span>
					<span class="summary-amount">-</span>
				</li>
				<li class="d-flex justify-content-between">
					<span>{{ __('Withdraw Fee') }}</span>
					<span class="summary-charge">
                        @if($cardSettings)
							{{ money($cardSettings->fee_amount, $card->wallet->currency->code) }} + {{ feeRate($cardSettings->fee_percent) }}%
						@else
							0
						@endif
                    </span>
				</li>
				<li class="d-flex justify-content-between">
					<span>{{ __('Total Deducted from Card') }}</span>
					<span class="summary-total text-danger fw-bold">-</span>
				</li>
				<li class="d-flex justify-content-between">
					<span>{{ __('Wallet Will Receive') }}</span>
					<span class="wallet-added text-success fw-bold">-</span>
				</li>
				<li class="d-flex justify-content-between">
					<span>{{ __('Currency') }}</span>
					<span>
						@if(!empty($fx['card_currency']))
							{{ $fx['card_currency'] }}<i class="fa-solid fa-arrow-right mx-1 small" aria-hidden="true"></i>
						@endif
						{{ $card->wallet->currency->code ?? '' }}
					</span>
				</li>
			</ul>
			<div class="mt-2">
				@if($cardSettings)
					<span class="badge bg-info text-dark">{{ __('Approval Required Above:') }} {{ money($cardSettings->approval_threshold, $card->wallet->currency->code) }}</span>
				@else
					<span class="badge bg-success">{{ __('Instant Withdraw. No approval needed.') }}</span>
				@endif
			</div>
		</div>
	</div>
</div>
