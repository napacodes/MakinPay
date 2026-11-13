@extends('frontend.layouts.user.index')
@section('title', __('Virtual Card Withdraw'))
@section('content')
	<div class="row">
		<div class="col-xl-7 mx-auto">
			<div class="single-form-card">
                <x-user-feature-header
                    :title="__('Withdraw from Virtual Card')"
                    :subtitle="__('Transfer card balance back to the linked wallet with full fee visibility.')"
                    icon="fas fa-arrow-up"
                >
                    <a class="btn btn-light-success btn-sm" href="{{ route('user.transaction.index', ['type' => \App\Enums\TrxType::CARD_WITHDRAW]) }}">
                        <i class="fas fa-list"></i> {{ __('History') }}
                    </a>
                </x-user-feature-header>
				<div class="card-main">
					{{-- Card Info --}}
					<div class="mb-4">
						<div class="credit-card-mockup position-relative rounded-4 shadow-sm p-3 text-white visa-pro-card">
							<div class="d-flex justify-content-between align-items-center mb-2">
								<span class="fw-bold fs-5">{{ $card->card_name ?? 'Virtual Card' }}</span>
								<span>
									<x-icon name="{{ strtolower($card->brand) }}" height="36"/>
								</span>
							</div>
							<div class="fs-5 text-monospace mb-2">
								•••• •••• •••• <b>{{ $card->last4 }}</b>
							</div>
							<div class="d-flex justify-content-between small">
								<span>{{ __('Expiry') }}: {{ $card->expiry_month }}/{{ $card->expiry_year }}</span>
								<span>{{ $card->wallet->currency->code ?? '' }}</span>
							</div>
						</div>
					</div>
					{{-- Withdraw Form --}}
					<div class="alert alert-info d-flex align-items-center mb-3" role="alert">
						<i class="fa-solid fa-wallet me-2"></i>
						<span>
							{{ __('Funds will be transferred to:') }}
							<strong>{{ $card->wallet->name ?? '-' }} @lang('Wallet')</strong>
							<span class="ms-2 small">({{ __('Available on Card:') }} {{ money($card->meta['balance'] ?? 0, $fx['card_currency'] ?? $card->wallet->currency->code) }})</span>
						</span>
					</div>
					<form action="{{ route('user.virtual-card.withdraw-store') }}" method="post" onsubmit="disableSubmitButton(this, '{{ __('Processing...') }}')">
						@csrf
						<input type="hidden" name="card_id" value="{{ $card->id ?? '' }}">
						<div class="single-input-inner style-border mb-3">
							<label class="form-label">{{ __('Amount') }}</label>
							<div class="input-group">
								<input type="text" step="0.01"
								       min="{{ $cardSettings->min_amount ?? '0.01' }}"
								       @if(!empty($cardSettings->max_amount)) max="{{ $cardSettings->max_amount }}" @endif
								       class="form-control" name="amount"
								       oninput="this.value = validateDouble(this.value)"
								       placeholder="{{ __('Enter Amount') }}"
								       required>
								<span class="input-group-text">{{ $fx['card_currency'] ?? $card->wallet->currency->code ?? siteCurrency() }}</span>
							</div>
							<small class="text-muted">
								@if($cardSettings)
									{{ __('Min:') }} {{ money($cardSettings->min_amount, $card->wallet->currency->code) }}
									| {{ __('Max:') }} {{ money($cardSettings->max_amount, $card->wallet->currency->code) }}
								@else
									{{ __('No withdraw limits. Any amount allowed.') }}
								@endif
							</small>
							@if(!empty($fx['card_currency']))
								<div class="small text-muted mt-1" data-vc-fx-note>
									<i class="fa-solid fa-arrow-right-arrow-left me-1" aria-hidden="true"></i>
									{{ __('Enter the amount in :card. It is converted at 1 :card = :rate :wallet before the fee, and your wallet receives', [
										'card'   => $fx['card_currency'],
										'wallet' => $card->wallet->currency->code,
										'rate'   => money($fx['rate'], $card->wallet->currency->code, false),
									]) }}
									<strong class="wallet-receive">-</strong>
								</div>
							@endif
						</div>
						<button type="submit" class="btn btn-base w-100 submit-btn">
							<x-icon name="check" height="20"/>
							{{ __('Withdraw Now') }}
						</button>
					</form>
				</div>
			</div>
		</div>
		@include('frontend.user.virtual_card.withdraw.partials._summary')
	</div>
@endsection

@push('scripts')
	<script>
        "use strict";
        $(document).ready(function () {
            function calculateSummary() {
                var amount = parseFloat($("input[name='amount']").val()) || 0;
                var feeFixed = parseFloat(@json($cardSettings?->fee_amount ?? 0));
                var feePercent = parseFloat(@json($cardSettings?->fee_percent ?? 0));
                var currencySymbol = @json($card->wallet->currency->symbol ?? '$');
                // The wallet currency's own precision, rendered by the server.
                // Hardcoding 2 here showed a BTC withdrawal as 0.00 while the
                // server moved the real amount.
                var decimals = parseInt(@json(currencyDecimals($card->wallet->currency->code ?? null)), 10);
                var money = function (value) {
                    return DigiKashMoney.format(value, decimals, currencySymbol);
                };
                // The amount is entered in the card's currency. It is
                // converted to the wallet's currency at the server's stored
                // rate, the fee comes off that, and the rest reaches the
                // wallet — the same order the server applies.
                var cardCurrency = @json($fx['card_currency'] ?? null);
                var cardRate = parseFloat(@json($fx['rate'] ?? '1')) || 1;
                var cardDecimals = parseInt(@json($fx['decimals'] ?? currencyDecimals($card->wallet->currency->code ?? null)), 10);
                var cardSymbol = @json($fx['symbol'] ?? ($card->wallet->currency->symbol ?? '$'));
                var cardMoney = function (value) {
                    return DigiKashMoney.format(value, cardDecimals, cardSymbol);
                };
                var fee = 0;
                var walletGross = amount;
                var walletReceive = amount;
                if (amount > 0) {
                    walletGross = cardCurrency ? amount * cardRate : amount;
                    // Same formula as FeeCalculator, via the shared helper, so
                    // the preview cannot drift from what is actually charged.
                    fee = DigiKashMoney.fee(walletGross, feeFixed, feePercent);
                    walletReceive = Math.max(0, walletGross - fee);
                }
                $(".summary-amount").text(amount > 0 ? cardMoney(amount) : '-');
                $(".summary-charge").text(amount > 0 ? money(fee) : '-');
                $(".summary-total").text(amount > 0 ? '-' + cardMoney(amount) : '-');
                $(".wallet-added").text(amount > 0 ? '+' + money(walletReceive) : '-');
                $(".wallet-receive").text(amount > 0 ? money(walletReceive) : '-');
            }
            $("input[name='amount']").on('input', calculateSummary);
            calculateSummary();
        });
	</script>
@endpush
