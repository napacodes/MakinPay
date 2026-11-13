@php use App\Enums\TrxType; @endphp
@extends('frontend.layouts.user.index')
@section('title', __('Crypto Deposit'))
@push('styles')
    <link rel="stylesheet"
          href="{{ asset('assets/frontend/css/crypto-deposit.css?v=' . filemtime(public_path('assets/frontend/css/crypto-deposit.css'))) }}">
@endpush
@section('content')
    @include('frontend.user.partials._feature_summary_statistics', ['trxType' => TrxType::DEPOSIT])

    <div class="row">
        {{-- Setup column --}}
        <div class="col-lg-12 col-xl-7">
            <div class="single-form-card">
                <x-user-feature-header
                    :title="__('Crypto Deposit')"
                    :subtitle="__('Deposit stablecoins directly to your wallet. Pick a coin and network, send to the address, and your balance is credited automatically.')"
                    icon="fas fa-coins"
                >
                    <a class="btn btn-light-primary btn-sm" href="{{ route('user.deposit.create') }}">
                        <i class="fas fa-wallet"></i> {{ __('Other Methods') }}
                    </a>
                    <a class="btn btn-light-success btn-sm"
                       href="{{ route('user.transaction.index', ['type' => TrxType::DEPOSIT]) }}">
                        <i class="fas fa-list"></i> {{ __('History') }}
                    </a>
                </x-user-feature-header>

                <div class="card-main">
                    @if(empty($coins))
                        <x-user-not-found
                            :title="__('Crypto deposit unavailable')"
                            :message="__('You do not have a supported crypto wallet yet, or instant deposits are temporarily offline. Try another deposit method.')"
                            icon="fa-coins"
                            :action-url="route('user.deposit.create')"
                            :action-label="__('Other Methods')"
                            action-icon="fa-wallet"
                        />
                    @else
                        {{-- Coin — native select (data-no-premium-select): its network
                             sibling is rebuilt on change and the themed dropdown
                             mispositions inside this narrow card. --}}
                        <div class="single-select-inner style-border">
                            <label>{{ __('Coin') }}</label>
                            <select class="form-select" id="crypto-coin" data-no-premium-select>
                                @foreach($coins as $coin)
                                    <option value="{{ $coin['code'] }}">{{ $coin['symbol'] }} {{ $coin['code'] }}
                                        — {{ $coin['name'] }}</option>
                                @endforeach
                            </select>
                            <span class="small color-base fw-500 mt-2 d-block">
                                {{ __('More coins are available through the other deposit methods.') }}
                            </span>
                        </div>

                        {{-- Amount --}}
                        <div class="single-input-inner style-border mt-3">
                            <label class="form-label">{{ __('Amount You Will Send') }}</label>
                            <div class="input-group input-group-right">
                                <input type="text" class="form-control" id="crypto-amount"
                                       oninput="this.value = validateDouble(this.value)"
                                       placeholder="{{ __('Enter amount') }}">
                                <span class="input-group-text input-group-text-right"
                                      id="crypto-coin-suffix">USDT</span>
                            </div>
                            <span class="small color-base fw-500" id="crypto-amount-info"></span>
                        </div>

                        {{-- Network — native select (data-no-premium-select), rebuilt when the coin changes --}}
                        <div class="single-select-inner style-border mt-3">
                            <label>{{ __('Select Network') }}</label>
                            <select class="form-select" id="crypto-network" data-no-premium-select></select>
                            <span class="crypto-network-hint">
                                <i class="fas fa-shield-alt"></i>
                                {{ __('Choose the network you will send the coin on. Sending on the wrong network will lose your funds.') }}
                            </span>
                        </div>

                        <button type="button" class="btn btn-base w-100 mt-4" id="crypto-generate-btn" disabled>
                            <x-icon name="check" height="20"/>
                            {{ __('Generate Deposit Address') }}
                        </button>

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
                                        {{ __('This build settles on testnet. Claim free test coins from the Circle faucet, then send them to the address you generate here — the deposit completes on its own.') }}
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
                    @endif
                </div>
            </div>
        </div>

        {{-- Deposit details column --}}
        <div class="col-lg-12 col-xl-5">
            <div class="single-form-card">
                <x-user-feature-header
                    :title="__('Deposit Details')"
                    :subtitle="__('Send the exact amount to the address below.')"
                    icon="fas fa-qrcode"
                    compact
                />
                <div class="card-main">
                    {{-- Placeholder (before an address is generated) --}}
                    <div id="crypto-placeholder" class="text-center py-4">
                        <div class="mb-2"><i class="fas fa-qrcode fa-3x text-muted"></i></div>
                        <p class="text-muted mb-0">
                            {{ __('Select a coin and network, then generate an address. Your deposit address and QR code will appear here.') }}
                        </p>
                    </div>

                    {{-- Result (populated after address generation) --}}
                    <div id="crypto-result" class="d-none">
                        <div class="crypto-warn mb-2" role="alert">
                            <i class="fas fa-triangle-exclamation crypto-warn__icon"></i>
                            <p class="crypto-warn__text">
                                {{ __('Send only') }} <strong id="crypto-result-coin"></strong>
                                {{ __('on') }} <strong id="crypto-result-network"></strong>.
                                {{ __('Any other coin or network is unrecoverable.') }}
                            </p>
                        </div>

                        <div class="text-center">
                            <div class="crypto-deposit-qr" id="crypto-result-qr"></div>
                        </div>

                        <div class="single-input-inner style-border">
                            <label class="form-label d-block">{{ __('Deposit Address') }}</label>
                            <div class="input-group">
                                <input type="text" class="form-control font-monospace" id="crypto-result-address"
                                       readonly>
                                <button type="button" class="btn btn-base" id="crypto-copy-address">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>

                        <ul class="summery-list list-unstyled mt-3">
                            <li class="d-flex justify-content-between">
                                <span>{{ __('Amount to Send') }}</span>
                                <strong><span id="crypto-result-amount"></span> <span
                                        id="crypto-result-currency"></span></strong>
                            </li>
                            <li class="d-flex justify-content-between">
                                <span>{{ __('Network') }}</span>
                                <strong id="crypto-result-network-2"></strong>
                            </li>
                            <li class="d-flex justify-content-between">
                                <span>{{ __('Transaction ID') }}</span>
                                <strong id="crypto-result-trx"></strong>
                            </li>
                            <li class="d-flex justify-content-between">
                                <span>{{ __('Status') }}</span>
                                <strong id="crypto-result-status">
                                    <span class="spinner-border spinner-border-sm" role="status"></span>
                                    {{ __('Waiting') }}
                                </strong>
                            </li>
                        </ul>

                        <button type="button" class="btn btn-light-primary w-100 mt-3" id="crypto-restart-btn">
                            <i class="fas fa-arrow-left"></i> {{ __('New Deposit') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('scripts')
    <script>
        (function ($) {
            'use strict';

            const generateUrl = @json(route('user.deposit.crypto.generate'));
            const csrfToken = $('meta[name="csrf-token"]').attr('content');
            const coins = @json($coins ?? []);

            const coinMap = {};
            coins.forEach(function (coin) {
                coinMap[coin.code] = coin;
            });

            let selectedCoin = null;
            let selectedNetwork = null;
            let poller = null;

            const $amount = $('#crypto-amount');
            const $network = $('#crypto-network');
            const $generateBtn = $('#crypto-generate-btn');
            const chooseNetworkLabel = @json(__('Choose Network'));

            function refreshGenerateState() {
                const amount = parseFloat($amount.val()) || 0;
                $generateBtn.prop('disabled', !(selectedCoin && selectedNetwork && amount > 0));
            }

            function populateNetworks(coinCode) {
                const coin = coinMap[coinCode];
                selectedCoin = coin ? coin.code : null;
                selectedNetwork = null;

                $network.empty().append(
                    $('<option>', {value: '', disabled: true, selected: true, text: chooseNetworkLabel})
                );

                if (coin) {
                    coin.networks.forEach(function (network) {
                        $network.append(
                            $('<option>', {value: network.code, text: network.label + ' — ' + network.chain})
                        );
                    });
                    $('#crypto-coin-suffix').text(coin.code);
                }

                refreshGenerateState();
            }

            $('#crypto-coin').on('change', function () {
                populateNetworks($(this).val());
            });

            $network.on('change', function () {
                selectedNetwork = $(this).val() || null;
                refreshGenerateState();
            });

            $amount.on('input', refreshGenerateState);

            // Preselect the first coin and populate its networks.
            if ($('#crypto-coin').length) {
                populateNetworks($('#crypto-coin').val());
            }

            $generateBtn.on('click', function () {
                const amount = parseFloat($amount.val()) || 0;
                if (!selectedCoin || !selectedNetwork || amount <= 0) {
                    return;
                }

                const originalHtml = $generateBtn.html();
                $generateBtn.prop('disabled', true)
                    .html('<span class="spinner-border spinner-border-sm"></span> {{ __('Generating...') }}');

                $.ajax({
                    url: generateUrl,
                    method: 'POST',
                    data: {
                        _token: csrfToken,
                        coin: selectedCoin,
                        network: selectedNetwork,
                        amount: amount
                    },
                    success: function (data) {
                        showResult(data);
                    },
                    error: function (xhr) {
                        const message = (xhr.responseJSON && (xhr.responseJSON.message ||
                                (xhr.responseJSON.errors && Object.values(xhr.responseJSON.errors)[0][0])))
                            || '{{ __('Could not generate a deposit address. Please try again.') }}';
                        $('#crypto-amount-info').text(message).addClass('text-danger').removeClass('text-success');
                    },
                    complete: function () {
                        $generateBtn.html(originalHtml);
                        refreshGenerateState();
                    }
                });
            });

            function showResult(data) {
                $('#crypto-placeholder').addClass('d-none');
                $('#crypto-result').removeClass('d-none');

                $('#crypto-result-coin').text(data.currency);
                $('#crypto-result-network').text(data.network);
                $('#crypto-result-network-2').text(data.network);
                $('#crypto-result-qr').html(data.qr);
                $('#crypto-result-address').val(data.address);
                $('#crypto-result-amount').text(data.amount);
                $('#crypto-result-currency').text(data.currency);
                $('#crypto-result-trx').text(data.trx_id);
                $('#crypto-result-status').html('<span class="spinner-border spinner-border-sm" role="status"></span> {{ __('Waiting') }}');

                startPolling(data.status_url);
            }

            function startPolling(statusUrl) {
                if (poller) {
                    clearInterval(poller);
                }
                poller = setInterval(function () {
                    $.getJSON(statusUrl, function (response) {
                        if (response.status === 'completed') {
                            clearInterval(poller);
                            $('#crypto-result-status').html('<span class="text-success"><i class="fas fa-circle-check"></i> {{ __('Completed') }}</span>');
                            if (response.redirect) {
                                window.location.href = response.redirect;
                            }
                        } else if (response.status === 'failed' || response.status === 'canceled') {
                            clearInterval(poller);
                            $('#crypto-result-status').html('<span class="text-danger"><i class="fas fa-circle-xmark"></i> {{ __('Failed') }}</span>');
                        }
                    });
                }, 10000);
            }

            $('#crypto-copy-address').on('click', function () {
                const address = $('#crypto-result-address').val();
                const $button = $(this);

                function markCopied() {
                    $button.html('<i class="fas fa-check"></i>');
                    setTimeout(function () {
                        $button.html('<i class="fas fa-copy"></i>');
                    }, 2000);
                }

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(address).then(markCopied);
                } else {
                    const input = document.getElementById('crypto-result-address');
                    input.select();
                    document.execCommand('copy');
                    markCopied();
                }
            });

            $('#crypto-restart-btn').on('click', function () {
                if (poller) {
                    clearInterval(poller);
                }
                $('#crypto-result').addClass('d-none');
                $('#crypto-placeholder').removeClass('d-none');
                $amount.val('');
                $('#crypto-amount-info').text('');
                populateNetworks($('#crypto-coin').val());
            });
        })(jQuery);
    </script>
@endpush
