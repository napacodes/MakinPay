@extends('frontend.layouts.user.index')
@section('title', __('Crypto Deposit'))
@php
    $networkLabel = $transaction->trx_data['bitnob_deposit_label'] ?? strtoupper($transaction->trx_data['bitnob_deposit_chain'] ?? '');
@endphp
@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/frontend/css/crypto-deposit.css?v=' . filemtime(public_path('assets/frontend/css/crypto-deposit.css'))) }}">
@endpush
@section('content')
    @include('frontend.user.partials._feature_summary_statistics', ['trxType' => \App\Enums\TrxType::DEPOSIT])

    <div class="row">
        {{-- Payment column --}}
        <div class="col-lg-12 col-xl-7">
            <div class="single-form-card">
                <x-user-feature-header
                    :title="__('Deposit :currency', ['currency' => $transaction->payable_currency])"
                    :subtitle="__('Send the exact amount to the address below. Your wallet is credited automatically after network confirmation.')"
                    icon="fas fa-coins"
                >
                    <a class="btn btn-light-primary btn-sm" href="{{ route('user.deposit.crypto') }}">
                        <i class="fas fa-plus"></i> {{ __('New Deposit') }}
                    </a>
                    <a class="btn btn-light-success btn-sm" href="{{ route('user.transaction.index', ['search' => $transaction->trx_id]) }}">
                        <i class="fas fa-list"></i> {{ __('History') }}
                    </a>
                </x-user-feature-header>
                <div class="card-main">
                    <div class="crypto-warn" role="alert">
                        <i class="fas fa-triangle-exclamation crypto-warn__icon"></i>
                        <p class="crypto-warn__text">
                            {{ __('Send only') }} <strong>{{ $transaction->payable_currency }}</strong>
                            {{ __('on') }} <strong>{{ $networkLabel }}</strong>.
                            {{ __('Any other coin or network is unrecoverable.') }}
                        </p>
                    </div>

                    <div class="text-center">
                        <div class="crypto-deposit-qr">
                            {!! $qrCode !!}
                        </div>
                    </div>

                    <div class="single-input-inner style-border">
                        <label class="form-label d-block">{{ __('Deposit Address') }}</label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" id="crypto-deposit-address"
                                   value="{{ $transaction->trx_data['bitnob_deposit_wallet'] }}" readonly>
                            <button type="button" class="btn btn-base" id="crypto-copy-address">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>

                    @if($showFaucet)
                        {{-- Demo and sandbox builds settle on testnets, where only
                             test coins move — point testers at a faucet rather
                             than letting them try to send real funds. --}}
                        <div class="crypto-faucet">
                            <span class="crypto-faucet__icon"><i class="fas fa-flask"></i></span>
                            <div class="crypto-faucet__body">
                                <p class="crypto-faucet__title">
                                    {{ __('Need test coins?') }}
                                    <span class="crypto-faucet__badge">{{ __('Demo') }}</span>
                                </p>
                                <p class="crypto-faucet__text">
                                    {{ __('This build settles on testnet. Claim free test coins from the Circle faucet and send them to the address above — this deposit then completes on its own.') }}
                                </p>
                                <a class="crypto-faucet__link"
                                   href="https://faucet.circle.com/"
                                   target="_blank"
                                   rel="noopener noreferrer">
                                    {{ __('Open Circle faucet') }}
                                    <i class="fas fa-up-right-from-square"></i>
                                </a>
                            </div>
                        </div>
                    @endif

                </div>
            </div>
        </div>

        {{-- Summary column --}}
        <div class="col-lg-12 col-xl-5">
            <div class="single-form-card">
                <x-user-feature-header
                    :title="__('Deposit Summary')"
                    :subtitle="__('Keep this page open — the status updates on its own.')"
                    icon="fas fa-receipt"
                    compact
                />
                <div class="card-main">
                    <div class="crypto-amount-callout">
                        <span class="crypto-amount-callout__label">{{ __('Amount to Send') }}</span>
                        <span class="crypto-amount-callout__value">
                            {{ money($transaction->payable_amount, $transaction->payable_currency, false) }}
                            <small>{{ $transaction->payable_currency }}</small>
                        </span>
                    </div>

                    <ul class="summery-list list-unstyled mt-3">
                        <li class="d-flex justify-content-between">
                            <span>{{ __('Network') }}</span>
                            <strong>{{ $networkLabel }}</strong>
                        </li>
                        <li class="d-flex justify-content-between">
                            <span>{{ __('Transaction ID') }}</span>
                            <strong>{{ $transaction->trx_id }}</strong>
                        </li>
                        <li class="d-flex justify-content-between">
                            <span>{{ __('Status') }}</span>
                            <strong id="crypto-deposit-status">
                                <span class="spinner-border spinner-border-sm" role="status"></span>
                                {{ __('Waiting') }}
                            </strong>
                        </li>
                    </ul>

                    <ol class="crypto-steps">
                        <li>{{ __('Send the exact amount to the address on the left.') }}</li>
                        <li>{{ __('Wait for the network to confirm the transfer.') }}</li>
                        <li>{{ __('Your wallet is credited automatically — no action needed.') }}</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('scripts')
    <script>
        (function ($) {
            'use strict';

            $('#crypto-copy-address').on('click', function () {
                var address = $('#crypto-deposit-address').val();
                var $button = $(this);

                function markCopied() {
                    $button.html('<i class="fas fa-check"></i>');
                    setTimeout(function () {
                        $button.html('<i class="fas fa-copy"></i>');
                    }, 2000);
                }

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(address).then(markCopied);
                } else {
                    var input = document.getElementById('crypto-deposit-address');
                    input.select();
                    document.execCommand('copy');
                    markCopied();
                }
            });

            var statusUrl = @json(route('user.deposit.crypto.status', ['trx_id' => $transaction->trx_id]));

            var poller = setInterval(function () {
                $.getJSON(statusUrl, function (response) {
                    if (response.status === 'completed') {
                        clearInterval(poller);
                        $('#crypto-deposit-status').html('<span class="text-success"><i class="fas fa-circle-check"></i> {{ __('Completed') }}</span>');
                        if (response.redirect) {
                            window.location.href = response.redirect;
                        }
                    } else if (response.status === 'failed' || response.status === 'canceled') {
                        clearInterval(poller);
                        $('#crypto-deposit-status').html('<span class="text-danger"><i class="fas fa-circle-xmark"></i> {{ __('Failed') }}</span>');
                    }
                });
            }, 10000);
        })(jQuery);
    </script>
@endpush
