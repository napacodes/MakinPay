@extends('frontend.layouts.user.index')
@section('title', __('Deposit'))
@section('content')
    @include('frontend.user.partials._feature_summary_statistics', ['trxType' => \App\Enums\TrxType::DEPOSIT])

    <div class="row">
        <div class="col-lg-12 col-xl-7">
            <div class="single-form-card">
                <x-user-feature-header
                    :title="__('Add Money')"
                    :subtitle="__('Choose a wallet and payment method to fund your balance.')"
                    icon="fas fa-wallet"
                >
                    <a class="btn btn-light-warning btn-sm" href="{{ route('user.deposit.crypto') }}">
                        <i class="fas fa-coins"></i> {{ __('Crypto') }}
                    </a>
                    {{-- Ways to deposit an add-on contributes (App\Support\DepositEntryPoints). --}}
                    @foreach(app(\App\Support\DepositEntryPoints::class)->for(auth()->user()) as $entryPoint)
                        <a class="btn btn-light-primary btn-sm" href="{{ $entryPoint['url'] }}" data-deposit-entry-point>
                            <i class="{{ $entryPoint['icon'] }}"></i> {{ $entryPoint['label'] }}
                        </a>
                    @endforeach
                    <a class="btn btn-light-success btn-sm" href="{{ route('user.transaction.index', ['type' => \App\Enums\TrxType::DEPOSIT]) }}">
                        <i class="fas fa-list"></i> {{ __('History') }}
                    </a>
                </x-user-feature-header>
                <div class="card-main">
                    <form action="{{ route('user.deposit.store') }}" method="post" enctype="multipart/form-data" onsubmit="disableSubmitButton(this, '{{ __('Processing...') }}')">
                        @csrf
                        <div class="single-select-inner style-border">
                            <label>{{ __('Your Wallets') }}</label>
                            @include('frontend.user.partials._unavailable_currency_notice', ['wallets' => $wallets])
                            <select class="form-select  wallet-select" name="wallet_id">
                                <option disabled selected>{{ __('Select Wallet') }}</option>
                                @foreach($wallets as $wallet)
                                    {{-- A wallet whose rate is unusable is offered but not selectable:
                                         the server would refuse the deposit anyway, and letting it be
                                         picked draws a preview built from a placeholder rate. --}}
                                    <option value="{{ $wallet->id }}"
                                            @selected($wallet->uuid == request('uuid'))
                                            @disabled(! $wallet->currency->isConvertible())>{{ __(':name Wallet', ['name' => $wallet->name]) }}@unless($wallet->currency->isConvertible()) — {{ __('unavailable, rate not set') }}@endunless</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="single-select-inner style-border mt-3">
                            <label>{{ __('Payment Method') }}</label>
                            <select class="form-select  deposit-method-list" name="payment_method">
                                <option disabled selected>{{ __('First Select Wallet') }}</option>
                            </select>
                            <span class="small color-base fw-500 deposit-method-info span-consistent"></span>
                        </div>

                        {{-- Shown only for a method whose gateway sends the payer's phone on
                             (CustomerPhone). A mobile-money gateway charges this number, so the
                             customer approves the payment on the phone it names. --}}
                        <div class="single-input-inner style-border mt-3 d-none" id="deposit-customer-phone">
                            <label class="form-label" for="deposit_customer_phone">
                                {{ __('Phone Number') }}
                                <span class="deposit-customer-phone-optional">({{ __('optional') }})</span>
                            </label>
                            <input type="tel" class="form-control" id="deposit_customer_phone" name="customer_phone"
                                   inputmode="tel" autocomplete="tel" maxlength="32" disabled
                                   value="{{ old('customer_phone', auth()->user()->phone) }}"
                                   placeholder="{{ __('e.g. +256 770 123456') }}">
                            <span class="small color-base fw-500 span-consistent">{{ __('Mobile money payments are requested from this number. Include the country code.') }}</span>
                        </div>

                        <div id="manual-deposit-credentials">

                        </div>

                        <div class="single-input-inner style-border mb-0 mt-3">
                            <label class="form-label">{{ __('Deposit Money') }}</label>
                            <div class="input-group input-group-right">
                                <input type="text" class="form-control deposit-amount" name="amount"
                                       oninput="this.value = validateDouble(this.value)"
                                       placeholder="{{ __('Enter Amount') }}">
                                {{-- The amount is typed in the selected wallet's own currency; the
                                     script rewrites this the moment a wallet is chosen. Showing
                                     the base currency here made a BTC holder convert by hand. --}}
                                <span class="input-group-text input-group-text-right amount-currency">{{ siteCurrency() }}</span>
                            </div>
                            <span class="small color-base fw-500 deposit-amount-info span-consistent"></span>
                        </div>
                        <button type="submit" class="btn btn-base w-100 mt-4 submit-btn">
                            <x-icon name="check" height="20"/>
                            {{ __('Deposit Now') }}</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Summary Section --}}
        @include('frontend.user.deposit.partials._summary')
    </div>
@endsection
@push('scripts')
    @include('frontend.user.deposit.partials._script')
@endpush

