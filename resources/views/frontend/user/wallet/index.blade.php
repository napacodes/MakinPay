@php
    use App\Constants\CurrencyRole;

    $walletRoleIcons = [
        CurrencyRole::SENDER => 'fa-paper-plane',
        CurrencyRole::REQUEST_MONEY => 'fa-hand-holding-usd',
        CurrencyRole::EXCHANGE => 'fa-exchange-alt',
        CurrencyRole::VOUCHER => 'fa-ticket-alt',
        CurrencyRole::GIFT_CARD => 'fa-gift',
        CurrencyRole::PAYMENT => 'fa-credit-card',
        CurrencyRole::WITHDRAW => 'fa-arrow-up',
    ];
@endphp
@extends('frontend.layouts.user.index')
@section('title', __('My Wallets'))
@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card single-form-card">
                <x-user-feature-header
                    :title="__('My Wallets')"
                    :subtitle="__('Review balances, supported roles, and recent wallet activity.')"
                    icon="fas fa-wallet"
                    class="wallet-page-header"
                >
                    <a href="{{ route('user.settings.wallet-pin') }}"
                       class="btn btn-light-primary btn-sm d-flex align-items-center wallet-page-action wallet-page-action--pin">
                        <i class="fas fa-key me-1" aria-hidden="true"></i>
                        {{ __('Wallet PIN') }}
                    </a>
                    <button type="button" class="btn btn-light-primary btn-sm d-flex align-items-center wallet-page-action" data-bs-toggle="modal"
                            data-bs-target="#addWalletModal">
                        <x-icon name="wallet-1" height="20" width="20" class="me-1"/>
                        {{ __('Create Wallet') }}
                    </button>
                </x-user-feature-header>
                <div class="card-body wallet-page__body">
                    <div class="wallet-grid">
                        @forelse($wallets as $wallet)
                            @php
                                $amountColor = $wallet->latestTransaction?->amount_flow->color($wallet->latestTransaction->status);
                                $amountSign = $wallet->latestTransaction?->amount_flow->sign($wallet->latestTransaction->status);
                                $receiveMoneyUrl = $wallet->receiveMoneyUrl();
                                $walletUuid = (string) $wallet->uuid;
                                // Only group plain runs; ids that already carry separators are readable as-is.
                                $formattedWalletId = str_contains($walletUuid, '-')
                                    ? $walletUuid
                                    : implode(' ', str_split($walletUuid, 3));
                                $isDefaultWallet = $wallet->currency->code === siteCurrency();
                                $currencyFlag = $wallet->currency->flag;
                                $currencySymbol = (string) $wallet->currency->symbol;
                                // Prefer a real glyph, else the whole code: trimming it would
                                // turn USDC into USD, which names a different currency.
                                $currencyMark = mb_strlen($currencySymbol) > 0 && mb_strlen($currencySymbol) <= 2
                                    ? $currencySymbol
                                    : mb_substr($wallet->currency->code, 0, 4);
                                $currencyHue = crc32(mb_strtoupper($wallet->currency->code)) % 360;
                                $currencyRoles = $wallet->currency->activeRoles;
                                $visibleCurrencyRoles = $currencyRoles->take(4);
                                $remainingCurrencyRoleCount = max(0, $currencyRoles->count() - $visibleCurrencyRoles->count());
                            @endphp

                            <article @class(['wallet-card', 'wallet-card--default' => $isDefaultWallet])
                                     style="--wallet-accent-hue: {{ $currencyHue }}">
                                <div class="wallet-header">
                                    <div class="wallet-currency-info">
                                        <span @class([
                                                  'wallet-currency-img-wrap',
                                                  'wallet-currency-img-wrap--monogram' => blank($currencyFlag),
                                              ]) style="--wallet-monogram-hue: {{ $currencyHue }}">
                                            <span @class([
                                                      'wallet-currency-monogram',
                                                      'wallet-currency-monogram--glyph' => mb_strlen($currencyMark) <= 1,
                                                      'wallet-currency-monogram--long' => mb_strlen($currencyMark) >= 4,
                                                  ]) aria-hidden="true">{{ $currencyMark }}</span>
                                            @if(filled($currencyFlag))
                                                <img src="{{ asset($currencyFlag) }}" class="wallet-currency-img"
                                                     alt="{{ $wallet->currency->code }}" loading="lazy"
                                                     onerror="this.remove()">
                                            @endif
                                        </span>
                                        <div class="wallet-card__identity">
                                            <div class="wallet-card__title-row">
                                                <h6>{{ $wallet->currency->code }}</h6>
                                                @if($isDefaultWallet)
                                                    <span class="wallet-default-pill">
                                                        <i class="fas fa-check-circle" aria-hidden="true"></i>
                                                        {{ __('Default') }}
                                                    </span>
                                                @endif
                                            </div>
                                            <p class="wallet-card__currency-name">
                                                {{ $wallet->currency->name }}
                                            </p>
                                        </div>
                                    </div>

                                    <div class="wallet-card__balance-panel">
                                        <span>
                                            <i class="fas fa-wallet" aria-hidden="true"></i>
                                            {{ __('Available balance') }}
                                        </span>
                                        <h5 class="wallet-balance mb-0">{{ money($wallet->balance, $wallet->currency->code) }}</h5>
                                        <p>
                                            <i class="fas fa-exchange-alt" aria-hidden="true"></i>
                                            1 {{ siteCurrency() }}
                                            = {{ \App\Support\Money::formatRate($wallet->currency->getRawOriginal('exchange_rate')) }} {{ $wallet->currency->code }}
                                        </p>
                                    </div>
                                </div>

                                <div class="wallet-id-chip">
                                    <span class="wallet-id-chip__mark" aria-hidden="true">
                                        <i class="fas fa-microchip"></i>
                                    </span>
                                    <span class="wallet-id-chip__content">
                                        <span>{{ __('Wallet ID') }}</span>
                                        <strong>{{ $formattedWalletId }}</strong>
                                    </span>
                                    <button type="button"
                                            class="wallet-id-copy copyNow"
                                            data-clipboard-text="{{ $wallet->uuid }}"
                                            data-bs-toggle="tooltip"
                                            title="{{ __('Copy Wallet ID') }}"
                                            aria-label="{{ __('Copy Wallet ID') }}">
                                        <i class="fas fa-copy" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <div class="wallet-card__context">
                                    @if($currencyRoles->isNotEmpty())
                                    <div class="wallet-card__support-row">
                                        <span class="wallet-card__section-label">{{ __('Available services') }}</span>
                                        <div class="wallet-roles" aria-label="{{ __('Supported wallet roles') }}">
                                            @foreach($visibleCurrencyRoles as $role)
                                                @php
                                                    $roleLabel = __(str($role->role_name)->replace('_', ' ')->title()->toString());
                                                @endphp
                                                <span class="wallet-role-icon bg-{{ CurrencyRole::getBadgesColor($role->role_name) }}"
                                                      title="{{ $roleLabel }}"
                                                      aria-label="{{ $roleLabel }}"
                                                      role="img"
                                                      data-bs-toggle="tooltip">
                                                    <i class="fas {{ $walletRoleIcons[$role->role_name] ?? 'fa-wallet' }}" aria-hidden="true"></i>
                                                </span>
                                            @endforeach
                                            @if($remainingCurrencyRoleCount > 0)
                                                <span class="wallet-role-icon wallet-role-icon--more"
                                                      title="{{ __(':count more services', ['count' => $remainingCurrencyRoleCount]) }}"
                                                      aria-label="{{ __(':count more services', ['count' => $remainingCurrencyRoleCount]) }}"
                                                      role="img"
                                                      data-bs-toggle="tooltip">
                                                    +{{ $remainingCurrencyRoleCount }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                    @endif

                                    <div class="wallet-transaction-info">
                                        <span class="wallet-card__section-label">{{ __('Latest activity') }}</span>
                                        @if($wallet->latestTransaction)
                                            <p class="small mb-0">
                                                <span class="wallet-activity-mark" aria-hidden="true">
                                                    <i class="fas fa-bolt"></i>
                                                </span>
                                                {{-- Formatted in the transaction's own currency, not the
                                                     wallet's. `amount` is base-denominated for flows that
                                                     convert a customer-entered figure, so dressing it in the
                                                     wallet's symbol labelled a USD figure as BTC. --}}
                                                <span class="fw-bold {{ $amountColor }}">
                                                    {{ $amountSign.money($wallet->latestTransaction->amount, $wallet->latestTransaction->currency) }}
                                                </span>
                                                <span class="text-muted">{{ __('via') }}</span>
                                                <span class="fw-bold text-{{ $wallet->latestTransaction->trx_type->badgeColor() }}">
                                                    {{ $wallet->latestTransaction->trx_type->label() }}
                                                </span>
                                            </p>
                                        @else
                                            <p class="small mb-0 text-muted">
                                                <span class="wallet-activity-mark wallet-activity-mark--muted" aria-hidden="true">
                                                    <i class="fas fa-clock"></i>
                                                </span>
                                                {{ __('No recent activity.') }}
                                            </p>
                                        @endif
                                    </div>
                                </div>

                                <div class="wallet-actions">
                                    <a href="{{ route('user.deposit.create', ['uuid' => $wallet->uuid]) }}"
                                       class="wallet-btn-icon wallet-btn-icon--primary"
                                       aria-label="{{ __('Deposit to :currency wallet', ['currency' => $wallet->currency->code]) }}">
                                        <span class="wallet-btn-icon__mark" aria-hidden="true">
                                            <i class="fas fa-arrow-down"></i>
                                        </span>
                                        <span class="wallet-btn-icon__label">{{ __('Deposit') }}</span>
                                    </a>
                                    @if($wallet->status && $wallet->is_sender)
                                        <button type="button"
                                                class="wallet-btn-icon wallet-btn-icon--secondary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#walletReceiveQrModal{{ $wallet->id }}"
                                                aria-label="{{ __('Receive QR') }}">
                                            <span class="wallet-btn-icon__mark" aria-hidden="true">
                                                <i class="fas fa-qrcode"></i>
                                            </span>
                                            <span class="wallet-btn-icon__label">{{ __('Receive') }}</span>
                                        </button>
                                    @endif
                                    @if($wallet->is_withdraw)
                                        <a href="{{ route('user.withdraw.create', ['uuid' => $wallet->uuid]) }}"
                                           class="wallet-btn-icon wallet-btn-icon--secondary"
                                           aria-label="{{ __('Withdraw from :currency wallet', ['currency' => $wallet->currency->code]) }}">
                                            <span class="wallet-btn-icon__mark" aria-hidden="true">
                                                <i class="fas fa-arrow-up"></i>
                                            </span>
                                            <span class="wallet-btn-icon__label">{{ __('Withdraw') }}</span>
                                        </a>
                                    @endif
                                </div>
                            </article>

                            @if($wallet->status && $wallet->is_sender)
                                <div class="modal fade wallet-receive-qr-modal"
                                     id="walletReceiveQrModal{{ $wallet->id }}"
                                     tabindex="-1"
                                     aria-labelledby="walletReceiveQrModalLabel{{ $wallet->id }}"
                                     aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered wallet-receive-qr-modal__dialog">
                                        <div class="modal-content border-0">
                                            <div class="wallet-receive-qr-modal__hero">
                                                <div class="wallet-receive-qr-modal__hero-copy">
                                                    <span class="wallet-receive-qr-modal__mark" aria-hidden="true">
                                                        <i class="fas fa-qrcode"></i>
                                                    </span>
                                                    <div>
                                                        <span class="wallet-receive-qr-modal__eyebrow">{{ __('Receive QR') }}</span>
                                                        <h6 class="modal-title" id="walletReceiveQrModalLabel{{ $wallet->id }}">
                                                            {{ __('My QR Code') }}
                                                        </h6>
                                                        <small>{{ __('Receive to your :wallet wallet', ['wallet' => $wallet->currency->code]) }}</small>
                                                    </div>
                                                </div>
                                                <button type="button"
                                                        class="btn-close wallet-receive-qr-modal__close"
                                                        data-bs-dismiss="modal"
                                                        aria-label="{{ __('Close') }}"></button>
                                            </div>

                                            <div class="modal-body wallet-receive-qr-modal__body">
                                                <div class="wallet-receive-qr-modal__summary">
                                                    <div>
                                                        <span>{{ __('Wallet ID') }}</span>
                                                        <strong>{{ $wallet->uuid }}</strong>
                                                    </div>
                                                    <div>
                                                        <span>{{ __('Receive Currency') }}</span>
                                                        <strong>{{ $wallet->currency->code }}</strong>
                                                    </div>
                                                </div>

                                                <div class="wallet-receive-qr-modal__scan-card">
                                                    <div class="wallet-receive-qr-modal__code"
                                                         id="walletReceiveQrCode{{ $wallet->id }}">
                                                        {!! $wallet->receiveMoneyQrCodeSvg(196) !!}
                                                    </div>
                                                    <p class="wallet-receive-qr-modal__hint">
                                                        {{ __('Share this QR or link with the payer.') }}
                                                    </p>
                                                </div>

                                                <div class="input-group input-group-sm wallet-receive-qr-modal__url">
                                                    <input type="text"
                                                           class="form-control form-control-sm"
                                                           value="{{ $receiveMoneyUrl }}"
                                                           readonly
                                                           aria-label="{{ __('Receive QR link') }}">
                                                </div>
                                            </div>

                                            <div class="modal-footer wallet-receive-qr-modal__footer">
                                                <button type="button"
                                                        class="btn btn-sm btn-primary wallet-receive-qr-modal__primary"
                                                        data-wallet-qr-share
                                                        data-wallet-qr-link="{{ $receiveMoneyUrl }}"
                                                        data-wallet-qr-title="{{ __(':site_title :currency Receive QR', ['site_title' => setting('site_title'), 'currency' => $wallet->currency->code]) }}"
                                                        data-wallet-qr-text="{{ __('Use this :site_title QR link to send money to my :currency wallet.', ['site_title' => setting('site_title'), 'currency' => $wallet->currency->code]) }}"
                                                        data-toast-message="{{ __('Receive link ready to share') }}">
                                                    <i class="fas fa-share-alt me-1"></i>{{ __('Share QR') }}
                                                </button>
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-primary wallet-receive-qr-modal__secondary"
                                                        data-wallet-qr-copy
                                                        data-wallet-qr-link="{{ $receiveMoneyUrl }}"
                                                        data-toast-message="{{ __('Receive link copied') }}">
                                                    <i class="fas fa-copy me-1"></i>{{ __('Copy Link') }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        @empty
                            <div class="wallet-grid__empty">
                                <x-user-not-found
                                    :title="$hasUnavailableWallets ? __('No active wallets') : __('No wallets yet')"
                                    :message="$hasUnavailableWallets
                                        ? __('Your existing wallets are unavailable right now. Their balances and history remain safe; create an active wallet or contact support.')
                                        : __('Create your first wallet to manage balances, deposits, withdrawals, and receive QR links.')"
                                    icon="fa-wallet"
                                />
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
