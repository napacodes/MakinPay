@extends('frontend.layouts.user.index')
@section('title', __('Exchange Money'))
@section('content')
    @include('frontend.user.partials._feature_summary_statistics', ['trxType' => \App\Enums\TrxType::EXCHANGE_MONEY])

    <div class="row">
        <div class="col-lg-12 col-xl-7">
            <div class="single-form-card">
                <x-user-feature-header
                    :title="__('Exchange Money')"
                    :subtitle="__('Convert between your wallets with current rate and fee guidance.')"
                    icon="fas fa-right-left"
                >
                    <a class="btn btn-light-success btn-sm" href="{{ route('user.transaction.index', ['type' => \App\Enums\TrxType::EXCHANGE_MONEY]) }}">
                        <i class="fas fa-list"></i> {{ __('History') }}
                    </a>
                </x-user-feature-header>

                <div class="card-main">
                    <form action="{{ route('user.exchange-money.store') }}" method="POST" onsubmit="disableSubmitButton(this, '{{ __('Processing...') }}')">
                        @csrf
                        @include('frontend.user.partials._unavailable_currency_notice', ['wallets' => $userWallets])

                        {{-- Source Wallet --}}
                        <div class="single-select-inner style-border">
                            <label>{{ __('From Wallet') }}</label>
                            <select class="form-select source-wallet-select" name="source_wallet_id">
                                <option disabled selected>{{ __('Select Source Wallet') }}</option>
                                @foreach($userWallets as $wallet)
                                    <option
                                            value="{{ $wallet->uuid }}"
                                            data-currency="{{ $wallet->currency->code }}"
                                            data-balance="{{ $wallet->balance }}"
                                            data-fee-fixed="{{ $wallet->currency->roles->first()->fee_fixed }}"
                                            data-fee-percent="{{ $wallet->currency->roles->first()->fee_percent }}"
                                            data-fee-min="{{ $wallet->currency->roles->first()->fee_min }}"
                                            data-fee-max="{{ $wallet->currency->roles->first()->fee_max }}"
                                            data-decimals="{{ currencyDecimals($wallet->currency->code) }}"
                                            data-default-conversion-rate="{{ $wallet->currency->getRawOriginal('exchange_rate') }}"
                                            data-min-limit="{{ $wallet->currency->roles->first()->min_limit }}"
                                            data-max-limit="{{ $wallet->currency->roles->first()->max_limit }}"
                                            @disabled(! $wallet->currency->isConvertible())>
                                        {{ $wallet->name }}
                                        - {{ money($wallet->balance, $wallet->currency->code) }}
                                        @unless($wallet->currency->isConvertible())
                                            — {{ __('unavailable, rate not set') }}
                                        @endunless
                                    </option>
                                @endforeach
                            </select>
                            <span class="small text-success fw-500 exchange-fee"></span>
                        </div>

                        {{-- Destination Wallet --}}
                        <div class="single-select-inner style-border mt-3">
                            <label>{{ __('To Wallet') }}</label>
                            <select class="form-select destination-wallet-select" name="destination_wallet_id">
                                <option disabled selected>{{ __('Select Destination Wallet') }}</option>
                                @foreach($userWallets as $wallet)
                                    <option
                                        value="{{ $wallet->uuid }}"
                                        data-currency="{{ $wallet->currency->code }}"
                                        data-decimals="{{ currencyDecimals($wallet->currency->code) }}"
                                        data-default-conversion-rate="{{ $wallet->currency->getRawOriginal('exchange_rate') }}"
                                        @disabled(! $wallet->currency->isConvertible())>
                                        {{ $wallet->name }}
                                        @unless($wallet->currency->isConvertible())
                                            — {{ __('unavailable, rate not set') }}
                                        @endunless
                                    </option>
                                @endforeach
                            </select>
                            <span class="small text-danger fw-500 to-wallet-warning"></span>
                        </div>

                        {{-- Amount Input.
                             Denominated in the source wallet's own currency, not
                             the platform base. Asking a BTC holder to type the
                             base-currency equivalent of 0.5 BTC made them do the
                             conversion by hand before the form would do it for
                             them. The suffix and the input's own precision both
                             follow the selected wallet. --}}
                        <div class="single-input-inner style-border mt-3">
                            <label class="form-label">{{ __('Amount') }}</label>
                            <div class="input-group">
                                <input type="text" class="form-control amount-input" name="amount"
                                       placeholder="{{ __('Enter Amount') }}"
                                       data-decimals="{{ currencyDecimals(siteCurrency()) }}"
                                       oninput="this.value = validateDouble(this.value)">
                                <span class="input-group-text amount-currency">{{ siteCurrency() }}</span>
                                {{-- Fills in the most the source wallet can exchange once the fee
                                     is charged, so emptying a wallet never needs a calculator. --}}
                                <button type="button" class="btn btn-light-primary use-max-btn" disabled>{{ __('Max') }}</button>
                            </div>
                            <span class="small color-base fw-500 send-amount-info"></span>
                        </div>

                        {{-- Submit Button --}}
                        <button type="submit" class="btn btn-base w-100 mt-4 submit-btn">
                            <x-icon name="check" height="20"/>
                            {{ __('Exchange Now') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        @include('frontend.user.exchange_money.partials._summary')
    </div>
@endsection

@push('scripts')
    @include('frontend.user.exchange_money.partials._script')
@endpush
