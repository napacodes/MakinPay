@php
    use App\Support\Money;

    $dkTreasury = $dashboard['treasury'];
@endphp

<section class="dk-card dk-treasury" aria-label="{{ __('Customer balances') }}">
    <div class="dk-card__head dk-card__head--tight">
        <h2 class="dk-card__title">{{ __('Customer balances') }}</h2>
        <a href="{{ route('admin.currency.index') }}" class="dk-link">{{ __('Currencies') }}</a>
    </div>

    @if($dkTreasury['rows'] === [])
        <div class="dk-empty">
            <span class="dk-empty__icon" aria-hidden="true"><i class="fa-solid fa-coins"></i></span>
            <div class="dk-empty__title">{{ __('No wallets yet') }}</div>
            <div class="dk-empty__body">{{ __('Enable at least one currency and customer wallets are created automatically on signup.') }}</div>
            <a href="{{ route('admin.currency.index') }}" class="dk-btn dk-btn--primary">{{ __('Enable a currency') }}</a>
        </div>
    @else
        <div class="dk-vault">
            <span class="dk-vault__orb" aria-hidden="true"></span>
            <span class="dk-vault__label">{{ __('Right now · all wallets · :code equivalent', ['code' => $dkTreasury['base']]) }}</span>
            <span class="dk-vault__total" dir="ltr" title="{{ Money::format($dkTreasury['total'], $dkTreasury['base']) }}">{{ Money::compact($dkTreasury['total'], $dkTreasury['base']) }}</span>
            <span class="dk-vault__meta">
                <span>{{ trans_choice('{1}:count wallet|[2,*]:count wallets', $dkTreasury['wallets'], ['count' => number_format($dkTreasury['wallets'])]) }}
                    · {{ trans_choice('{1}:count currency|[2,*]:count currencies', $dkTreasury['currencies'], ['count' => $dkTreasury['currencies']]) }}</span>
                @if($dkTreasury['delta'] !== null)
                    <span class="dk-vault__delta @if(! $dkTreasury['delta']['positive']) is-negative @endif" dir="ltr">{{ $dkTreasury['delta']['text'] }} {{ __('this week') }}</span>
                @endif
            </span>
            {{-- Money customers moved into savings vaults: out of the wallets above, still theirs. --}}
            @if(($dkTreasury['vaults']['count'] ?? 0) > 0)
                <span class="dk-vault__split">
                    <span class="dk-vault__line">
                        <span>{{ __('In savings vaults') }}</span>
                        <span class="dk-vault__line-value" dir="ltr" title="{{ Money::format($dkTreasury['vaults']['total'], $dkTreasury['base']) }}">{{ Money::compact($dkTreasury['vaults']['total'], $dkTreasury['base']) }}</span>
                    </span>
                    <span class="dk-vault__line">
                        <span>{{ __('Wallets + vaults') }}</span>
                        <span class="dk-vault__line-value" dir="ltr" title="{{ Money::format($dkTreasury['liability'], $dkTreasury['base']) }}">{{ Money::compact($dkTreasury['liability'], $dkTreasury['base']) }}</span>
                    </span>
                </span>
            @endif
        </div>

        <div class="dk-share" aria-hidden="true">
            @foreach($dkTreasury['rows'] as $dkWallet)
                <span class="dk-share__slice dk-swatch--{{ $dkWallet['swatch'] }}" style="width: {{ max($dkWallet['share'], 1.5) }}%"></span>
            @endforeach
        </div>

        <div class="dk-vault__rows">
            @foreach($dkTreasury['rows'] as $dkWallet)
                <div class="dk-vault__row">
                    <span class="dk-vault__swatch dk-swatch--{{ $dkWallet['swatch'] }} @unless($dkWallet['status']) is-off @endunless" aria-hidden="true"></span>
                    <span class="dk-vault__code">{{ $dkWallet['code'] }}</span>
                    <span class="dk-vault__name">{{ $dkWallet['name'] }}@if($dkWallet['is_default'])<span class="dk-vault__tag"> · {{ __('base') }}</span>@elseif(! $dkWallet['status'])<span class="dk-vault__tag"> · {{ __('disabled') }}</span>@endif</span>
                    <span class="dk-vault__amount" dir="ltr" title="{{ Money::format($dkWallet['total'], $dkTreasury['base']) }}">{{ Money::compact($dkWallet['total'], $dkTreasury['base']) }}</span>
                    <span class="dk-vault__pct" dir="ltr" title="{{ number_format($dkWallet['share'], 2) }}%">{{ $dkWallet['share_display'] }}</span>
                </div>
            @endforeach
        </div>

        @if($dkTreasury['currencies'] > $dkTreasury['shown'])
            <div class="dk-card__foot">
                <span>{{ __('Showing the top :shown of :total', ['shown' => $dkTreasury['shown'], 'total' => $dkTreasury['currencies']]) }}</span>
                <span class="dk-card__actions">
                    <a href="{{ route('admin.currency.index') }}" class="dk-btn dk-btn--outline">{{ __('All currencies') }}<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                </span>
            </div>
        @endif

        @if(($dkTreasury['vaults']['rows'] ?? []) !== [])
            <div class="dk-savings" role="group" aria-label="{{ __('In savings vaults') }}">
                <div class="dk-savings__head">
                    <span>{{ __('In savings vaults') }}</span>
                    <span>{{ trans_choice('{1}:count vault|[2,*]:count vaults', $dkTreasury['vaults']['count'], ['count' => number_format($dkTreasury['vaults']['count'])]) }}</span>
                </div>
                @foreach($dkTreasury['vaults']['rows'] as $dkSaving)
                    <div class="dk-vault__row">
                        <span class="dk-savings__mark" aria-hidden="true"></span>
                        <span class="dk-vault__code">{{ $dkSaving['code'] }}</span>
                        <span class="dk-vault__name">{{ $dkSaving['name'] }}</span>
                        <span class="dk-vault__amount" dir="ltr" title="{{ Money::format($dkSaving['native'], $dkSaving['code']) }}">{{ Money::compact($dkSaving['total'], $dkTreasury['base']) }}</span>
                        <span class="dk-savings__count">{{ trans_choice('{1}:count vault|[2,*]:count vaults', $dkSaving['vaults'], ['count' => number_format($dkSaving['vaults'])]) }}</span>
                    </div>
                @endforeach
                @if($dkTreasury['vaults']['currencies'] > $dkTreasury['vaults']['shown'])
                    <div class="dk-savings__more">{{ __('Showing the top :shown of :total', ['shown' => $dkTreasury['vaults']['shown'], 'total' => $dkTreasury['vaults']['currencies']]) }}</div>
                @endif
            </div>
        @endif
    @endif
</section>
