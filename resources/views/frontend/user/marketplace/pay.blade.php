@extends('frontend.layouts.user.index')
@section('title', __('Pay :vendor', ['vendor' => $vendor->display_name]))

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/frontend/css/marketplace.css') }}?v={{ filemtime(public_path('assets/frontend/css/marketplace.css')) }}">
@endpush

@section('content')

    <div class="single-form-card">
        <x-user-feature-header
            :title="__('Make Payment')"
            :subtitle="__('Pay a business directly from your wallet balance — instant and secure.')"
            icon="fas fa-paper-plane"
        >
            <a class="btn btn-light-primary btn-sm" href="{{ route('user.marketplace.index') }}">
                <i class="fa-solid fa-arrow-left"></i> {{ __('All Businesses') }}
            </a>
        </x-user-feature-header>

        <div class="card-main">
            @if($wallets->isEmpty())
                <x-user-not-found
                    :title="__('No wallet available')"
                    :message="__('You need an active wallet before you can pay a business. Add money to get started.')"
                    icon="fa-wallet"
                    :action-url="route('user.deposit.create')"
                    :action-label="__('Deposit Money')"
                    action-icon="fa-plus"
                />
            @else
                <div class="mkt-pay-cols">

                    {{-- ── LEFT: one payment panel — recipient header + form body ── --}}
                    <div class="mkt-pay-form-col">
                        <div class="mkt-panel mkt-panel--flush">

                            <div class="mkt-pay-recipient">
                                <span class="mkt-biz-card__avatar mkt-avatar--{{ $vendor->avatarVariant() }}" aria-hidden="true">
                                    {{ $vendor->initials() }}
                                </span>
                                <div class="mkt-biz-card__identity">
                                    <span class="mkt-eyebrow">{{ __('You are paying') }}</span>
                                    <h2 class="mkt-biz-card__name">
                                        {{ $vendor->display_name }}
                                        <i class="fa-solid fa-circle-check" title="{{ __('Verified business') }}"
                                           data-bs-toggle="tooltip" data-bs-placement="top"
                                           aria-label="{{ __('Verified business') }}" role="img"></i>
                                    </h2>
                                    <p class="mkt-biz-card__owner">{{ __('Owner') }} · {{ $vendor->user?->name }}</p>
                                    <div class="mkt-vendor-card__meta">
                                        <span class="mkt-chip mkt-chip--neutral">{{ $vendor->categoryLabel() }}</span>
                                        <span class="mkt-chip mkt-chip--success">
                                            <i class="fa-solid fa-coins" aria-hidden="true"></i>
                                            {{ (int) $vendor->completed_sales_count }} {{ trans_choice('sale|sales', (int) $vendor->completed_sales_count) }}
                                        </span>
                                        <span class="mkt-chip mkt-chip--neutral">
                                            {{ __('Member since') }} {{ ($vendor->approved_at ?? $vendor->created_at)->format('M Y') }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <form id="mkt-pay-form" class="mkt-pay-body" action="{{ route('user.marketplace.checkout', $vendor) }}" method="POST"
                                  data-commission-pct="{{ $commissionPct }}"
                                  data-commission-fixed="{{ $commissionFixed }}">
                                @csrf

                                <div class="d-flex flex-column gap-3">
                                    <div class="mkt-field">
                                        <label for="wallet_id">{{ __('Pay from wallet') }}</label>
                                        <select name="wallet_id" id="wallet_id" class="form-select @error('wallet_id') is-invalid @enderror" required>
                                            @foreach($wallets as $wallet)
                                                <option value="{{ $wallet->id }}"
                                                        data-balance="{{ (float) $wallet->balance }}"
                                                        data-code="{{ $wallet->currency->code }}"
                                                        @selected(old('wallet_id') == $wallet->id)>
                                                    {{ $wallet->currency->code }} — {{ __('Balance') }}: {{ money($wallet->balance, $wallet->currency->code, false) }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('wallet_id')<span class="mkt-field-error">{{ $message }}</span>@enderror
                                    </div>

                                    <div class="mkt-field">
                                        <label for="amount">{{ __('Amount') }}</label>
                                        <div class="mkt-amount-wrap @error('amount') is-invalid @enderror" data-amount-wrap>
                                            <input type="number" step="0.01" min="0.01" name="amount" id="amount"
                                                   inputmode="decimal" placeholder="0.00" value="{{ old('amount') }}"
                                                   aria-describedby="amount-messages" required>
                                            <span class="mkt-amount-wrap__code" data-amount-code>&mdash;</span>
                                        </div>
                                        <div id="amount-messages" role="alert" aria-live="polite">
                                            @error('amount')<div class="mkt-field-error">{{ $message }}</div>@enderror
                                            <div class="mkt-alert-insufficient" data-insufficient hidden>
                                                <span><i class="fa-solid fa-triangle-exclamation"></i> <span data-insufficient-text></span></span>
                                                <a href="{{ route('user.deposit.create') }}">
                                                    <i class="fa-solid fa-plus"></i> {{ __('Deposit') }}
                                                </a>
                                            </div>
                                        </div>
                                        @if($minOrder > 0 || $maxOrder)
                                            <p class="mkt-field-hint">
                                                @if($minOrder > 0 && $maxOrder)
                                                    {{ __('Enter between :min and :max', ['min' => money($minOrder, null, false), 'max' => money($maxOrder, null, false)]) }}
                                                @elseif($minOrder > 0)
                                                    {{ __('Minimum :min', ['min' => money($minOrder, null, false)]) }}
                                                @else
                                                    {{ __('Maximum :max', ['max' => money($maxOrder, null, false)]) }}
                                                @endif
                                            </p>
                                        @endif
                                    </div>

                                    <div class="mkt-field">
                                        <label for="remarks">{{ __('Note') }} <span class="text-optional">({{ __('optional') }})</span></label>
                                        <input type="text" name="remarks" id="remarks" maxlength="255"
                                               class="form-control @error('remarks') is-invalid @enderror"
                                               value="{{ old('remarks') }}" placeholder="{{ __('e.g. Invoice #1024, October internet bill') }}">
                                        @error('remarks')<span class="mkt-field-error">{{ $message }}</span>@enderror
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    {{-- ── RIGHT: the single money breakdown + CTA ── --}}
                    <div class="mkt-pay-summary-col">
                        <div class="mkt-panel">
                            <div class="mkt-summary-card__eyebrow">
                                <i class="fa-solid fa-receipt"></i> {{ __('Payment summary') }}
                            </div>

                            <div class="mkt-summary-rows">
                                <div class="mkt-summary-row mkt-summary-row--charge">
                                    <span>{{ __('You pay') }}</span><b data-sum-gross>0.00</b>
                                </div>
                                <div class="mkt-summary-row">
                                    <span>{{ __('Service fee') }} (<span data-fee-pct>0</span>%)</span><b data-sum-fee>0.00</b>
                                </div>
                                <div class="mkt-summary-row mkt-summary-row--total">
                                    <span>{{ __(':vendor receives', ['vendor' => \Illuminate\Support\Str::limit($vendor->display_name, 22)]) }}</span>
                                    <span data-sum-net>0.00</span>
                                </div>
                                <div class="mkt-summary-row mkt-summary-row--after">
                                    <span>{{ __('Wallet after payment') }}</span><span data-sum-after>&mdash;</span>
                                </div>
                            </div>

                            <p class="mkt-summary-note">
                                <i class="fa-solid fa-circle-info"></i>
                                <span>{{ __('You are charged exactly the amount you enter. The service fee is deducted from the business payout.') }}</span>
                            </p>

                            <button type="submit" form="mkt-pay-form" class="mkt-summary-cta" data-pay-cta disabled>
                                <i class="fa-solid fa-paper-plane"></i>
                                <span>{{ __('Make Payment') }}</span>
                            </button>

                            <p class="mkt-secure-line">
                                <i class="fa-solid fa-lock"></i>
                                <span>{{ __('Instant & secure · goes directly to the business wallet') }}</span>
                            </p>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

@endsection

@push('scripts')
    <script>
        (function ($) {
            var $form = $('#mkt-pay-form');
            if (!$form.length) { return; }

            var pct     = parseFloat($form.data('commission-pct')) || 0;
            var fixed   = parseFloat($form.data('commission-fixed')) || 0;
            var $wallet = $('#wallet_id');
            var $amount = $('#amount');

            function fmt(n) {
                if (!isFinite(n)) { n = 0; }
                return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            function walletInfo() {
                var $opt = $wallet.find('option:selected');
                return {
                    balance: parseFloat($opt.data('balance')) || 0,
                    code: String($opt.data('code') || '')
                };
            }

            function recalc() {
                var info  = walletInfo();
                var gross = parseFloat($amount.val()) || 0;
                var fee   = gross > 0 ? Math.min(gross, gross * pct / 100 + fixed) : 0;
                var net   = gross - fee;
                var after = info.balance - gross;
                var insufficient = gross > info.balance;

                $('[data-amount-code]').text(info.code);
                $('[data-fee-pct]').text(pct.toLocaleString(undefined, { maximumFractionDigits: 2 }));
                $('[data-sum-gross]').text(fmt(gross) + ' ' + info.code);
                $('[data-sum-fee]').text(fmt(fee) + ' ' + info.code);
                $('[data-sum-net]').text(fmt(net) + ' ' + info.code);

                $('[data-sum-after]')
                    .text(fmt(after) + ' ' + info.code)
                    .toggleClass('is-negative', after < 0);

                $('[data-insufficient]').prop('hidden', !insufficient);
                $('[data-insufficient-text]').text(
                    '{{ __('Amount exceeds your wallet balance of') }} ' + fmt(info.balance) + ' ' + info.code + '.'
                );
                $('[data-amount-wrap]').toggleClass('is-invalid', insufficient);

                $('[data-pay-cta]').prop('disabled', !(gross > 0 && !insufficient));
            }

            $amount.on('input change', recalc);
            $wallet.on('change', recalc);
            recalc();
        })(jQuery);
    </script>
@endpush
