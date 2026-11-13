<section class="um-card" data-um-wallets>
    <div class="um-card__head">
        <h2 class="um-card__title">{{ __('Wallets') }}</h2>
        <span class="um-card__aside">{{ __(':count currencies', ['count' => count($walletsRail['rows'])]) }}</span>
    </div>

    {{-- Four wallets show; the rest scroll inside the card (user-manage.js sizes it). --}}
    <div class="um-wallets__list" data-um-wallet-list data-um-wallet-visible="4">
    @forelse($walletsRail['rows'] as $row)
        @php($wallet = $row['wallet'])
        <div class="um-wallet">
            <span class="um-wallet__tile {{ $wallet->currency->default ? 'is-default' : '' }}">
                @if($wallet->currency->flag)
                    <img src="{{ asset($wallet->currency->flag) }}" alt="" loading="lazy" onerror="this.remove()">
                @endif
                <span class="um-wallet__code">{{ $wallet->currency->code }}</span>
            </span>
            <span class="um-wallet__copy">
                <span class="um-wallet__name">
                    <span class="um-wallet__name-text" title="{{ $wallet->currency->name }}">{{ $wallet->currency->name ?? $wallet->currency->code }}</span>
                    @if($wallet->currency->default)
                        <span class="um-badge um-badge--success um-badge--xs">{{ __('Default') }}</span>
                    @endif
                </span>
                <span class="um-wallet__recent">{{ $row['recent'] ?? __('No recent activity') }}</span>
            </span>
            <span class="um-wallet__balance">{{ money($wallet->balance, $wallet->currency->code) }}</span>
        </div>
    @empty
        <div class="um-card__empty">{{ __('This user has no active wallets yet.') }}</div>
    @endforelse
    </div>

    {{-- Manage funds stays in the header primary; this card points at the ledger. --}}
    <div class="um-card__foot">
        <a href="{{ route('admin.user.manage', ['username' => $user->username, 'param' => 'transactions']) }}" class="um-btn um-btn--block"><x-icon name="history"/>{{ __('View wallet history') }}</a>
    </div>
</section>
