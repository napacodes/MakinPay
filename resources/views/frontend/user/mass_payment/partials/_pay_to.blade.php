{{--
    "Pay to": the two kinds of batch as two cards, at the top of both new-batch
    pages. The current kind is a plain card; the other is a link to its own
    page, left out when the customer cannot open it.
    Expects $current ('wallets' | 'accounts'), $payoutsOpen and $walletsOpen.
--}}
@php
    $mpWalletsOpen = $walletsOpen ?? true;
    $mpPayoutsOpen = $payoutsOpen ?? false;
@endphp
@if(($current === 'wallets' && $mpPayoutsOpen) || ($current === 'accounts' && $mpWalletsOpen))
    <nav class="mp-payto" aria-label="{{ __('Pay to') }}" data-mp-pay-to>
        <span class="mp-payto__label">{{ __('Pay to') }}</span>
        <div class="mp-payto__cards">
            @if($current === 'wallets')
                <span class="mp-payto__card is-current" aria-current="page">
                    <span class="mp-payto__title"><i class="fa-solid fa-wallet" aria-hidden="true"></i> {{ __(':site wallets', ['site' => setting('site_title')]) }}</span>
                    <span class="mp-payto__desc">{{ __('Instant. Recipients are found by phone, email or wallet ID.') }}</span>
                </span>
                <a class="mp-payto__card" href="{{ route('user.mass-payout.create') }}" data-mp-pay-to-accounts>
                    <span class="mp-payto__title"><i class="fa-solid fa-building-columns" aria-hidden="true"></i> {{ __('Bank & mobile money') }}</span>
                    <span class="mp-payto__desc">{{ __('Paid out through a payout method. May wait for approval.') }}</span>
                </a>
            @else
                <a class="mp-payto__card" href="{{ route('user.mass-payment.create') }}" data-mp-pay-to-wallets>
                    <span class="mp-payto__title"><i class="fa-solid fa-wallet" aria-hidden="true"></i> {{ __(':site wallets', ['site' => setting('site_title')]) }}</span>
                    <span class="mp-payto__desc">{{ __('Instant. Recipients are found by phone, email or wallet ID.') }}</span>
                </a>
                <span class="mp-payto__card is-current" aria-current="page">
                    <span class="mp-payto__title"><i class="fa-solid fa-building-columns" aria-hidden="true"></i> {{ __('Bank & mobile money') }}</span>
                    <span class="mp-payto__desc">{{ __('Paid out through a payout method. May wait for approval.') }}</span>
                </span>
            @endif
        </div>
    </nav>
@endif
