@extends('frontend.layouts.user.index')
@section('title', __('Review Transfer'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/remittance.css?v=' . filemtime(public_path('frontend/css/remittance.css'))) }}">
@endpush

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="single-form-card">
                @include('frontend.user.remittance.partials._page_header', [
                    'rmtPageTitle'    => __('Review Transfer'),
                    'rmtPageSubtitle' => __('Lock the rate and confirm details before sending.'),
                    'rmtPageIcon'     => 'fas fa-circle-info',
                    'rmtCurrentPage'  => 'review',
                ])
            </div>
        </div>
    </div>

    <div class="dk-rmt">
        <div class="row g-3">
            <div class="col-12 col-xl-7">

                {{-- Rate Lock Banner --}}
                @php
                    $secondsLeft = max(0, $quote->expires_at->diffInSeconds(now(), false) * -1);
                    $isUrgent    = $secondsLeft < 120;
                @endphp
                <div class="dk-rmt__lock mb-3 {{ $isUrgent ? 'is-urgent' : '' }}"
                     id="dk-rate-lock"
                     data-deadline="{{ $quote->expires_at->toIso8601String() }}">
                    <div class="dk-rmt__lock-left">
                        <i class="fa-solid {{ $isUrgent ? 'fa-triangle-exclamation' : 'fa-lock' }}"></i>
                        <div>
                            <div class="dk-rmt__lock-title" id="dk-rate-lock-title">
                                {{ $isUrgent ? __('Rate expires soon') : __('Rate locked for this quote') }}
                            </div>
                            <div class="dk-rmt__lock-sub">
                                {{ __('Confirm now to keep') }}
                                <span class="dk-mono">1 {{ $quote->source_currency }} = {{ \App\Support\Money::formatRate($quote->exchange_rate) }} {{ $quote->destination_currency }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="dk-rmt__lock-time" id="dk-rate-lock-time">--:--</div>
                </div>
                <div class="dk-rmt__alert dk-rmt__alert--warning d-none mb-3" id="dk-rate-expired-action" role="alert">
                    <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                    <div>
                        {{ __('This rate has expired. Your wallet has not been charged.') }}
                        <a href="{{ route('user.remittance.create') }}" class="dk-rmt__alert-link">{{ __('Get a new quote') }}</a>
                    </div>
                </div>

                {{-- Summary Card --}}
                <div class="dk-rmt__card mb-3" style="padding:24px;">
                    <div class="dk-rmt__rows">
                        <div class="dk-rmt__row">
                            <div class="dk-rmt__row-key">{{ __('You send') }}</div>
                            <div class="dk-rmt__row-val">{{ money($quote->send_amount, $quote->source_currency, false) }} {{ $quote->source_currency }}</div>
                        </div>
                        <div class="dk-rmt__row">
                            <div class="dk-rmt__row-key">{{ __('Transfer fee') }}</div>
                            <div class="dk-rmt__row-val">{{ money($quote->fee_amount, $quote->source_currency, false) }} {{ $quote->source_currency }}</div>
                        </div>
                        @foreach ((array) $quote->tax_breakdown as $taxLine)
                            <div class="dk-rmt__row" data-tax-line>
                                <div class="dk-rmt__row-key">{{ $taxLine['name'] ?? __('Tax') }}</div>
                                <div class="dk-rmt__row-val">{{ money($taxLine['amount'] ?? 0, $quote->source_currency, false) }} {{ $quote->source_currency }}</div>
                            </div>
                        @endforeach
                        <div class="dk-rmt__row dk-rmt__row--bold">
                            <div class="dk-rmt__row-key">{{ __('You pay total') }}</div>
                            <div class="dk-rmt__row-val">{{ money($quote->total_pay, $quote->source_currency, false) }} {{ $quote->source_currency }}</div>
                        </div>

                        <div class="dk-rmt__row-divider"></div>

                        <div class="dk-rmt__row">
                            <div class="dk-rmt__row-key">{{ __('Exchange rate') }}</div>
                            <div class="dk-rmt__row-val dk-mono">1 {{ $quote->source_currency }} = {{ \App\Support\Money::formatRate($quote->exchange_rate) }} {{ $quote->destination_currency }}</div>
                        </div>

                        <div class="dk-rmt__row-receive">
                            <div class="dk-rmt__row-receive-label">{{ __('Recipient gets') }}</div>
                            <div class="dk-rmt__row-receive-amount">
                                {{ money($quote->receive_amount, $quote->destination_currency, false) }}
                                <small>{{ $quote->destination_currency }}</small>
                            </div>
                        </div>

                        <div class="dk-rmt__row">
                            <div class="dk-rmt__row-key">{{ __('Estimated arrival') }}</div>
                            <div class="dk-rmt__row-val" style="color:var(--dk-rmt-success);font-weight:600;font-size:13px;">
                                <i class="fa-solid fa-bolt" style="font-size:11px;margin-right:4px;"></i>
                                {{ __('within 30 minutes') }}
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Confirm form --}}
                <form action="{{ route('user.remittance.confirm') }}" method="POST" id="dk-rmt-confirm-form"
                      onsubmit="return disableSubmitButton(this, '{{ __('Submitting...') }}')">
                    @csrf
                    <input type="hidden" name="quote_id" value="{{ $quote->quote_id }}">
                    <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

                    <div class="dk-rmt__card" style="display:flex;flex-direction:column;gap:18px;">
                        <div>
                            <label class="dk-rmt__label" for="remittance-wallet">{{ __('Pay from wallet') }}</label>
                            <select class="dk-rmt__select" name="wallet_id" id="remittance-wallet" required>
                                <option value="" disabled @selected(! old('wallet_id'))>{{ __('Select wallet') }}</option>
                                @foreach ($wallets as $wallet)
                                    <option value="{{ $wallet->id }}" @selected((int) old('wallet_id') === $wallet->id)>
                                        {{ $wallet->currency->code }} — {{ money($wallet->balance, $wallet->currency->code) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('wallet_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div>
                            <label class="dk-rmt__label" @if ($beneficiaries->isNotEmpty()) for="remittance-beneficiary" @endif>{{ __('Recipient') }}</label>
                            @if ($beneficiaries->isEmpty())
                                <div class="dk-rmt__alert dk-rmt__alert--warning">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                    <div>
                                        {{ __('You have no recipient matching this country, currency, and payout method.') }}
                                        <a href="{{ route('user.beneficiaries.create') }}" style="font-weight:700;color:inherit;text-decoration:underline;">{{ __('Add one now') }}</a>
                                    </div>
                                </div>
                            @else
                                <select class="dk-rmt__select" name="beneficiary_id" id="remittance-beneficiary" required>
                                    <option value="" disabled @selected(! old('beneficiary_id', $quote->beneficiary_id))>{{ __('Choose recipient') }}</option>
                                    @foreach ($beneficiaries as $beneficiary)
                                        <option value="{{ $beneficiary->id }}"
                                                @selected((int) old('beneficiary_id', $quote->beneficiary_id) === $beneficiary->id)>
                                            {{ getCountryFlagEmoji($beneficiary->country_code) }}
                                            {{ $beneficiary->full_name }} — {{ $beneficiary->masked_account }}
                                        </option>
                                    @endforeach
                                </select>
                            @endif
                            @error('beneficiary_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>

                        <details style="border-radius:10px;border:1px solid var(--dk-rmt-border);">
                            <summary style="padding:12px 14px;cursor:pointer;font-weight:600;font-size:13.5px;color:var(--dk-rmt-heading);">
                                <i class="fa-solid fa-circle-info" style="color:var(--dk-rmt-muted);margin-right:6px;"></i>
                                {{ __('Add purpose & source of funds (optional)') }}
                            </summary>
                            <div style="padding:0 14px 14px;display:flex;flex-direction:column;gap:12px;">
                                <div>
                                    <label class="dk-rmt__label" for="remittance-purpose">{{ __('Purpose of transfer') }}</label>
                                    <select class="dk-rmt__select" name="purpose" id="remittance-purpose">
                                        <option value="">{{ __('Not specified') }}</option>
                                        <option value="family_support" @selected(old('purpose') === 'family_support')>{{ __('Family support') }}</option>
                                        <option value="education" @selected(old('purpose') === 'education')>{{ __('Education') }}</option>
                                        <option value="medical" @selected(old('purpose') === 'medical')>{{ __('Medical') }}</option>
                                        <option value="business" @selected(old('purpose') === 'business')>{{ __('Business') }}</option>
                                        <option value="other" @selected(old('purpose') === 'other')>{{ __('Other') }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="dk-rmt__label" for="remittance-source-of-funds">{{ __('Source of funds') }}</label>
                                    <select class="dk-rmt__select" name="source_of_funds" id="remittance-source-of-funds">
                                        <option value="">{{ __('Not specified') }}</option>
                                        <option value="salary" @selected(old('source_of_funds') === 'salary')>{{ __('Salary') }}</option>
                                        <option value="savings" @selected(old('source_of_funds') === 'savings')>{{ __('Savings') }}</option>
                                        <option value="business_income" @selected(old('source_of_funds') === 'business_income')>{{ __('Business income') }}</option>
                                        <option value="other" @selected(old('source_of_funds') === 'other')>{{ __('Other') }}</option>
                                    </select>
                                </div>
                            </div>
                        </details>

                        <button type="submit" id="dk-rmt-confirm-button" class="dk-rmt__btn dk-rmt__btn--primary dk-rmt__btn--lg dk-rmt__btn--full submit-btn"
                                @disabled($beneficiaries->isEmpty() || $wallets->isEmpty())>
                            <i class="fa-solid fa-paper-plane"></i> {{ __('Confirm & Send') }}
                        </button>

                        <div style="text-align:center;font-size:12px;color:var(--dk-rmt-muted);margin-top:-4px;">
                            <i class="fa-solid fa-lock" style="color:var(--dk-rmt-success);margin-right:4px;"></i>
                            {{ __('Funds are debited only after you confirm.') }}
                        </div>
                    </div>
                </form>
            </div>

            {{-- What happens next rail --}}
            <div class="col-12 col-xl-5">
                <div class="dk-rmt__card mb-3">
                    <div style="font-weight:700;font-size:14px;color:var(--dk-rmt-heading);margin-bottom:14px;">
                        <i class="fa-solid fa-list-check" style="color:var(--dk-rmt-primary);margin-right:6px;"></i>
                        {{ __('What happens next') }}
                    </div>
                    <div style="display:flex;flex-direction:column;gap:14px;">
                        <div style="display:flex;gap:12px;align-items:flex-start;">
                            <span class="dk-rmt__iconbox dk-rmt__iconbox--sm"><span style="font-weight:800;font-size:12px;">1</span></span>
                            <div>
                                <div style="font-weight:600;font-size:13px;color:var(--dk-rmt-heading);">{{ __('Wallet is debited') }}</div>
                                <div style="font-size:11.5px;color:var(--dk-rmt-muted);">{{ __('We hold the funds for the transfer.') }}</div>
                            </div>
                        </div>
                        <div style="display:flex;gap:12px;align-items:flex-start;">
                            <span class="dk-rmt__iconbox dk-rmt__iconbox--sm"><span style="font-weight:800;font-size:12px;">2</span></span>
                            <div>
                                <div style="font-weight:600;font-size:13px;color:var(--dk-rmt-heading);">{{ __('We send to the payout partner') }}</div>
                                <div style="font-size:11.5px;color:var(--dk-rmt-muted);">{{ __('Usually within seconds of confirm.') }}</div>
                            </div>
                        </div>
                        <div style="display:flex;gap:12px;align-items:flex-start;">
                            <span class="dk-rmt__iconbox dk-rmt__iconbox--sm dk-rmt__iconbox--success"><span style="font-weight:800;font-size:12px;">3</span></span>
                            <div>
                                <div style="font-weight:600;font-size:13px;color:var(--dk-rmt-heading);">{{ __('Recipient is paid out') }}</div>
                                <div style="font-size:11.5px;color:var(--dk-rmt-muted);">{{ __('Most corridors arrive in 30 minutes.') }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dk-rmt__card" style="background:var(--dk-rmt-soft);">
                    <div style="font-weight:700;font-size:14px;color:var(--dk-rmt-heading);margin-bottom:10px;">
                        <i class="fa-solid fa-stopwatch" style="color:var(--dk-rmt-primary);margin-right:6px;"></i>
                        {{ __('Quote details') }}
                    </div>
                    <div style="font-size:12.5px;color:var(--dk-rmt-body);display:flex;flex-direction:column;gap:6px;">
                        <div><strong style="color:var(--dk-rmt-heading);">{{ __('Quote ID:') }}</strong> <span class="dk-mono">{{ $quote->quote_id }}</span></div>
                        <div><strong style="color:var(--dk-rmt-heading);">{{ __('Locked rate:') }}</strong> <span class="dk-mono">1 {{ $quote->source_currency }} = {{ \App\Support\Money::formatRate($quote->exchange_rate) }}</span></div>
                        @if ($quote->mid_market_rate)
                            <div><strong style="color:var(--dk-rmt-heading);">{{ __('Mid-market reference:') }}</strong> <span class="dk-mono">{{ \App\Support\Money::formatRate($quote->mid_market_rate) }}</span></div>
                        @endif
                        <div><strong style="color:var(--dk-rmt-heading);">{{ __('Payout method:') }}</strong> {{ $quote->payout_method->label() }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            const lock     = document.getElementById('dk-rate-lock');
            const timeEl   = document.getElementById('dk-rate-lock-time');
            const titleEl  = document.getElementById('dk-rate-lock-title');
            const submitButton = document.getElementById('dk-rmt-confirm-button');
            const expiredAction = document.getElementById('dk-rate-expired-action');
            if (! lock) { return; }
            const deadline = new Date(lock.dataset.deadline);
            const formCanSubmit = submitButton && ! submitButton.disabled;
            const urgentMsg  = '{{ __('Rate expires soon') }}';
            const normalMsg  = '{{ __('Rate locked for this quote') }}';
            const expiredMsg = '{{ __('Rate expired') }}';

            function expireQuote() {
                lock.classList.add('is-urgent');
                titleEl.textContent = expiredMsg;
                expiredAction.classList.remove('d-none');

                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.setAttribute('aria-disabled', 'true');
                }
            }

            function tick() {
                const left = Math.max(0, Math.floor((deadline - new Date()) / 1000));
                const m = Math.floor(left / 60);
                const s = String(left % 60).padStart(2, '0');
                timeEl.textContent = m + ':' + s;
                if (left === 0) {
                    expireQuote();
                    return;
                }
                if (left < 120) {
                    lock.classList.add('is-urgent');
                    titleEl.textContent = urgentMsg;
                } else {
                    lock.classList.remove('is-urgent');
                    titleEl.textContent = normalMsg;
                }
                setTimeout(tick, 1000);
            }

            window.addEventListener('pageshow', function () {
                if (deadline > new Date() && formCanSubmit && submitButton) {
                    window.resetSubmitButton?.(submitButton);
                    submitButton.disabled = false;
                    submitButton.removeAttribute('aria-disabled');
                }
            });

            tick();
        })();
    </script>
@endpush
