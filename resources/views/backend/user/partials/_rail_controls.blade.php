@php
    /*
     * Each row is one of the per-user switches from config/userFeatures.php
     * (layer B). The three verification rows read the user's real state
     * through UserFeature::dynamic_status rather than the stored column.
     */
    $controlIcons = [
        'account_status'     => 'badge-account',
        'email_verification' => 'mail-notify',
        'kyc_verification'   => 'kyc',
        'deposit'            => 'wallet-plus',
        'withdraw'           => 'withdraw-1',
        'send_money'         => 'send',
        'request_money'      => 'payment',
        'exchange_money'     => 'money-cog',
        'mobile_recharge'    => 'custom-landing',
        'gift_cards'         => 'gift',
        'remittance'         => 'send-money',
        'mass_payment'       => 'user-group',
        'mass_payout'        => 'withdraw-2',
    ];
    $controlLabels = [
        'account_status'     => __('Account status'),
        'email_verification' => __('Email verified'),
        'kyc_verification'   => __('KYC override'),
        'deposit'            => __('Deposits'),
        'withdraw'           => __('Withdrawals'),
        'send_money'         => __('Send money'),
        'request_money'      => __('Request money'),
        'exchange_money'     => __('Exchange'),
        'mobile_recharge'    => __('Mobile recharge'),
        'gift_cards'         => __('Gift cards'),
        'remittance'         => __('Remittance'),
        'mass_payment'       => __('Mass payments'),
        'mass_payout'        => __('Mass payouts'),
    ];
    /* The design's one-line hints; a feature the design never named keeps its config description. */
    $controlHints = [
        'account_status'     => __('Disable to block sign-in and all wallet activity.'),
        'email_verification' => __('Mark the email address as verified.'),
        'kyc_verification'   => __('Treat as verified without documents (audited).'),
        'deposit'            => __('Allow adding money through any gateway.'),
        'withdraw'           => __('Allow payouts to external methods.'),
        'send_money'         => __('Peer-to-peer transfers to other users.'),
        'request_money'      => __('Create payment requests to other users.'),
        'exchange_money'     => __('Convert between wallet currencies.'),
        'mobile_recharge'    => __('Airtime top-ups from wallet balance.'),
    ];
    $enabledFeatures = $user->features->filter(fn ($feature) => (bool) $feature->dynamic_status)->count();
@endphp

<section class="um-card um-card--fill" data-um-controls>
    <div class="um-card__head">
        <h2 class="um-card__title">{{ __('Account controls') }}</h2>
        <span class="um-card__aside"><strong class="is-brand" data-um-on-count>{{ $enabledFeatures }}</strong> {{ __('of :total on', ['total' => $user->features->count()]) }}</span>
    </div>

    <div class="um-controls">
        @foreach($user->features as $feature)
            @php($on = (bool) $feature->dynamic_status)
            <div class="um-control {{ $on ? 'is-on' : '' }}" data-um-control="{{ $feature->feature }}">
                <span class="um-tile um-tile--sm"><x-icon :name="$controlIcons[$feature->feature] ?? 'layer'"/></span>
                <span class="um-control__copy">
                    <span class="um-control__label" id="um-control-{{ $feature->id }}">{{ $controlLabels[$feature->feature] ?? __(ucwords(str_replace('_', ' ', $feature->feature))) }}</span>
                    <span class="um-control__hint">{{ $controlHints[$feature->feature] ?? __($feature->description) }}</span>
                </span>
                <button type="button"
                        class="um-switch"
                        role="switch"
                        aria-checked="{{ $on ? 'true' : 'false' }}"
                        aria-labelledby="um-control-{{ $feature->id }}"
                        data-um-feature="{{ $feature->feature }}"></button>
            </div>
        @endforeach
    </div>
</section>
