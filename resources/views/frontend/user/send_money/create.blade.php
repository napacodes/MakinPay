@extends('frontend.layouts.user.index')
@section('title', __('Send Money'))
@section('content')
    @include('frontend.user.partials._feature_summary_statistics', ['trxType' => \App\Enums\TrxType::SEND_MONEY])

    <div class="row">
        <div class="col-lg-12 col-xl-7">
            <div class="single-form-card">
                <x-user-feature-header
                    :title="__('Send Money')"
                    :subtitle="__('Transfer funds to another user wallet with instant balance checks.')"
                    icon="fas fa-paper-plane"
                >
                    <a class="btn btn-light-success btn-sm" href="{{ route('user.transaction.index', ['type' => \App\Enums\TrxType::SEND_MONEY]) }}">
                        <i class="fas fa-list"></i> {{ __('History') }}
                    </a>
                </x-user-feature-header>

                <div class="card-main">
                    <form action="{{ route('user.send-money.store') }}" method="POST" onsubmit="disableSubmitButton(this, '{{ __('Processing...') }}')">
                        @csrf
                        {{-- Recipient Input --}}
                        <div class="single-input-inner style-border">
                            <label class="form-label">{{ __('Recipient') }}</label>
                            <input type="text" class="form-control recipient-input" name="recipient"
                                   value="{{ old('recipient', $prefilledRecipient ?? '') }}"
                                   placeholder="{{ $recipientPlaceholder ?? __('Enter Email or Wallet ID') }}">
                            <span class="small color-base fw-500 recipient-info"></span>
                        </div>

                        {{-- Wallet Selector --}}
                        <div class="single-select-inner style-border">
                            <label>{{ __('Your Wallets') }}</label>
                            <select class="form-select wallet-select" name="wallet_id">
                                <option disabled selected>{{ __('Select Wallet') }}</option>
                            </select>
                            <span class="small color-base fw-500 wallet-info"></span>
                        </div>

                        {{-- Amount Input --}}
                        <div class="single-input-inner style-border mb-0 mt-3">
                            <label class="form-label">{{ __('Send Money') }}</label>
                            <div class="input-group">
                                <input type="text" class="form-control amount-input" name="amount"
                                       placeholder="{{ __('Enter Amount') }}"
                                       oninput="this.value = validateDouble(this.value)">
                                {{-- The amount is typed in the selected wallet's own currency; the
                                     script rewrites this the moment a wallet is chosen. Showing
                                     the base currency here made a BTC holder convert by hand. --}}
                                <span class="input-group-text amount-currency">{{ siteCurrency() }}</span>
                            </div>
                            <span class="small color-base fw-500 send-amount-info"></span>
                        </div>

                        <div class="single-input-inner style-border mb-0 mt-3">
                            <label class="form-label">{{ __('Note (Optional)') }}</label>
                            <textarea class=" rounded" name="note" id="" cols="10" rows="10"></textarea>
                        </div>

                        @if(auth()->user()->hasWalletPin())
                            {{-- Wallet PIN Confirmation. Shown only to a customer who has set
                                 one, matching the withdraw and QR cash-out forms. --}}
                            <div class="single-input-inner style-border mb-0 mt-3">
                                <label class="form-label">{{ __('Wallet PIN') }}</label>
                                <input type="password" class="form-control" name="wallet_pin"
                                       inputmode="numeric" maxlength="6" autocomplete="off"
                                       placeholder="{{ __('Enter your 6-digit wallet PIN') }}" required>
                                <span class="small color-base fw-500">{{ __('Confirm this transfer with your wallet PIN.') }}</span>
                            </div>
                        @endif

                        {{-- Submit Button --}}
                        <button type="submit" class="btn btn-base w-100 mt-4 submit-btn">
                            <x-icon name="check" height="20"/>
                            {{ __('Send Now') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        @include('frontend.user.send_money.partials._summary')
    </div>
@endsection

@push('scripts')
   @include('frontend.user.send_money.partials._script')
@endpush
