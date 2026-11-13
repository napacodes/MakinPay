@extends('frontend.layouts.user.index')
@section('title', __('Withdraw Money'))
@section('content')
    @include('frontend.user.partials._feature_summary_statistics', ['trxType' => \App\Enums\TrxType::WITHDRAW])

    <div class="row">
        @if(!$isWithdrawEnabledToday)
            <div class="container">
                <div class="alert alert-warning d-flex align-items-center" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <div>
                        <strong>{{ __('Withdrawal Unavailable') }}:</strong>
                        {{ __('Withdrawals are disabled on ') }}
                        {{ implode(', ', $withdrawOffDays) }}.
                        {{ __('Please try again on an enabled day.') }}
                    </div>
                </div>
            </div>
        @endif
        {{-- Withdrawal Form --}}
        <div class="col-lg-12 col-xl-7">
            <div class="single-form-card">
                <x-user-feature-header
                    :title="__('Withdraw Money')"
                    :subtitle="__('Move funds to your saved withdrawal account.')"
                    icon="fas fa-money-bill-wave"
                >
                    <a class="btn btn-light-success btn-sm"
                       href="{{ route('user.transaction.index', ['type' => \App\Enums\TrxType::WITHDRAW]) }}">
                        <i class="fas fa-list"></i> {{ __('History') }}
                    </a>
                    <a class="btn btn-light-primary btn-sm" href="{{ route('user.withdraw.account.index') }}">
                        <i class="fas fa-university"></i> {{ __('Accounts') }}
                    </a>
                </x-user-feature-header>

                <div class="card-main">
                    <form action="{{ route('user.withdraw.store') }}" method="POST" onsubmit="disableSubmitButton(this, '{{ __('Processing...') }}')">
                        @csrf

                        {{-- Wallet Selector --}}
                        <div class="single-select-inner style-border">
                            <label>{{ __('Source Wallet') }}</label>
                            @include('frontend.user.partials._unavailable_currency_notice', ['wallets' => $wallets])
                            <select class="form-select wallet-select" name="wallet_id" required>
                                <option disabled selected>{{ __('Select Wallet') }}</option>
                                @foreach($wallets as $wallet)
                                    {{-- Not selectable while its rate is unusable; the server would
                                         refuse the withdrawal and the preview would be nonsense. --}}
                                    <option value="{{ $wallet->id }}"
                                            data-balance="{{ $wallet->balance }}"
                                            data-decimals="{{ currencyDecimals($wallet->currency->code) }}"
                                            @selected($wallet->uuid == request('uuid'))
                                            @disabled(! $wallet->currency->isConvertible())>{{ $wallet->name }}
                                        - {{ money($wallet->balance, $wallet->currency->code) }}@unless($wallet->currency->isConvertible()) — {{ __('unavailable, rate not set') }}@endunless</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Withdrawal Account --}}
                        <div class="single-select-inner style-border">
                            <label>{{ __('Withdrawal Account') }}</label>
                            <select class="form-select withdraw-account-select" name="account_id" required>
                                <option disabled selected>{{ __('First Select Wallet') }}</option>
                            </select>
                            <span class="small color-base fw-500 account-info"></span>
                        </div>

                        {{-- Amount Input --}}
                        <div class="single-input-inner style-border mb-0 mt-3">
                            <label class="form-label">{{ __('Withdraw Amount') }}</label>
                            <div class="input-group">
                                <input type="text" class="form-control amount-input" name="amount"
                                       placeholder="{{ __('Enter Amount') }}"
                                       oninput="this.value = validateDouble(this.value)" required>
                                {{-- The amount is typed in the selected wallet's own currency; the
                                     script rewrites this the moment a wallet is chosen. Showing
                                     the base currency here made a BTC holder convert by hand. --}}
                                <span class="input-group-text amount-currency">{{ siteCurrency() }}</span>
                                {{-- Fills in the most the wallet can send once the fee is charged,
                                     so emptying a wallet never needs a calculator. --}}
                                <button type="button" class="btn btn-light-primary use-max-btn" disabled>{{ __('Max') }}</button>
                            </div>
                            <span class="small color-base fw-500 withdraw-amount-info"></span>
                        </div>


                        @if(auth()->user()->hasWalletPin())
                            {{-- Wallet PIN Confirmation --}}
                            <div class="single-input-inner style-border mb-0 mt-3">
                                <label class="form-label">{{ __('Wallet PIN') }}</label>
                                <input type="password" class="form-control" name="wallet_pin"
                                       inputmode="numeric" maxlength="6" autocomplete="off"
                                       placeholder="{{ __('Enter your 6-digit wallet PIN') }}" required>
                                <span class="small color-base fw-500">{{ __('Confirm this withdrawal with your wallet PIN.') }}</span>
                            </div>
                        @endif

                        {{-- Submit Button --}}
                        <button type="submit" class="btn btn-base w-100 mt-4 submit-btn">
                            <x-icon name="check" height="20"/>
                            {{ __('Withdraw Now') }}
                        </button>
                    </form>


                </div>
            </div>
        </div>

        {{-- Summary Section --}}
        @include('frontend.user.withdraw.partials._summary')
    </div>
@endsection
@push('scripts')
    @include('frontend.user.withdraw.partials._script')
@endpush
