@extends('backend.p2p.layout')

{{--
    P2P → Marketplace Settings.

    One settings form split into tabs — Fees, Time windows, Trade limits,
    Country access — plus the per-asset fee overrides, which sit in their own
    tab OUTSIDE the form because each override is its own record with its own
    save, and nesting forms is invalid HTML. The header's Discard and Save
    reach the form through form="p2p-settings-form".

    Styles live in backend/css/p2p-settings.css (p2ps-) and the behaviour in
    backend/js/p2p-settings.js; both are progressive — every control posts as
    a plain form field, the script only adds the tab switching, the fee and
    timeline previews, the country chips and the unsaved-changes state.
--}}

@section('title', __('Marketplace Settings'))

@section('p2p_title')
    {{ __('Marketplace Settings') }}
@endsection

@section('p2p_subtitle')
    {{ __('Fees, settlement windows, trade limits and country access for every new P2P order.') }}
@endsection

@section('p2p_icon', 'cil-settings')

@php
    $allowedSelected = old('allowed_countries', $allowedSelected ?? []);
    $blockedSelected = old('blocked_countries', $blockedSelected ?? []);
    $trimmed         = static fn ($value): ?string => $value === null || $value === '' ? null : \App\Support\Money::trimFee($value);
    $makerFee        = old('maker_fee_pct', $trimmed($settings->maker_fee_pct));
    $takerFee        = old('taker_fee_pct', $trimmed($settings->taker_fee_pct));
    $orderExpiry     = old('order_expiry_minutes', $settings->order_expiry_minutes);
    $disputeWindow   = old('dispute_window_minutes', $settings->dispute_window_minutes);
    $minAmount       = old('min_amount', $trimmed($settings->min_amount));
    $maxAmount       = old('max_amount', $trimmed($settings->max_amount));
    $p2pEnabled      = app(\App\Services\FeatureManager::class)->isEnabled('p2p_marketplace');
    $currencyCode    = siteCurrency('code') ?? 'BDT';
    $allowedCount    = count($allowedSelected);
    $blockedCount    = count($blockedSelected);
    $updatedAgo      = $settings->updated_at ? $settings->updated_at->diffForHumans() : __('never');
    $activeOverrides = $assetFees->where('is_active', true)->count();

    $describeFlat = static fn ($fixed): string => (float) ($fixed ?? 0) > 0
        ? __('+ :amount flat', ['amount' => \App\Support\Money::trimFee($fixed)])
        : __('No flat component');

    if ($allowedCount > 0) {
        $accessValue = __('Allow list');
        $accessNote  = trans_choice(':count country allowed|:count countries allowed', $allowedCount, ['count' => $allowedCount]);
    } elseif ($blockedCount > 0) {
        $accessValue = __('Open');
        $accessNote  = trans_choice(':count country blocked|:count countries blocked', $blockedCount, ['count' => $blockedCount]);
    } else {
        $accessValue = __('Open');
        $accessNote  = __('Every country may trade');
    }

    $p2pHeaderStatus = $p2pEnabled
        ? ['tone' => 'success', 'label' => __('Live · Accepting trades')]
        : ['tone' => 'danger', 'label' => __('Paused')];

    $p2pHeaderMeta = [
        __('Last saved :when', ['when' => $updatedAgo]),
        __('Default currency :code', ['code' => $currencyCode]),
    ];

    $p2pHeaderStats = [
        [
            'label' => __('Maker fee'),
            'value' => \App\Support\Money::trimFee($makerFee).'%',
            'note'  => $describeFlat(old('maker_fee_fixed', $settings->maker_fee_fixed)),
            'tone'  => 'brand',
            'icon'  => 'fa-percent',
        ],
        [
            'label' => __('Taker fee'),
            'value' => \App\Support\Money::trimFee($takerFee).'%',
            'note'  => $describeFlat(old('taker_fee_fixed', $settings->taker_fee_fixed)),
            'tone'  => 'brand',
            'icon'  => 'fa-percent',
        ],
        [
            'label' => __('Settlement window'),
            'value' => __(':minutes min', ['minutes' => (int) $orderExpiry + (int) $disputeWindow]),
            'note'  => __(':pay to pay · :dispute to dispute', ['pay' => (int) $orderExpiry, 'dispute' => (int) $disputeWindow]),
            'tone'  => 'neutral',
            'icon'  => 'fa-clock',
        ],
        [
            'label' => __('Country access'),
            'value' => $accessValue,
            'note'  => $accessNote,
            'tone'  => $allowedCount > 0 ? 'warning' : 'success',
            'icon'  => 'fa-earth-americas',
        ],
    ];

    $tabs = [
        ['key' => 'fees',      'group' => __('Trading rules'), 'label' => __('Fees'),            'icon' => 'fa-percent',        'errors' => ['maker_fee_pct', 'maker_fee_fixed', 'maker_fee_min', 'maker_fee_max', 'taker_fee_pct', 'taker_fee_fixed', 'taker_fee_min', 'taker_fee_max']],
        ['key' => 'windows',   'group' => null,                'label' => __('Time windows'),    'icon' => 'fa-clock',          'errors' => ['order_expiry_minutes', 'dispute_window_minutes']],
        ['key' => 'limits',    'group' => null,                'label' => __('Trade limits'),    'icon' => 'fa-layer-group',    'errors' => ['min_amount', 'max_amount']],
        ['key' => 'countries', 'group' => __('Compliance'),    'label' => __('Country access'),  'icon' => 'fa-earth-americas', 'errors' => ['allowed_countries', 'allowed_countries.*', 'blocked_countries', 'blocked_countries.*']],
        ['key' => 'assets',    'group' => __('Overrides'),     'label' => __('Per-asset fees'),  'icon' => 'fa-coins',          'errors' => []],
    ];

    $tabLabels = collect($tabs)->pluck('label', 'key')->all();

    $tabErrorCount = static fn (array $keys): int => collect($keys)->sum(fn (string $key): int => count($errors->get($key)));
    $p2pSettingsCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/p2p-settings.css'));
    $p2pSettingsJsVersion  = config('app.version').'-'.filemtime(public_path('backend/js/p2p-settings.js'));
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/admin-savebar.css?v='.config('app.version').'-'.filemtime(public_path('backend/css/admin-savebar.css'))) }}">
    <link rel="stylesheet" href="{{ asset('backend/css/p2p-settings.css?v='.$p2pSettingsCssVersion) }}">
@endpush

@section('p2p_state')
    <span class="ph-state ph-state--success" data-p2ps-state aria-live="polite"
          data-saved-text="{{ __('All changes saved') }}"
          data-dirty-text="{{ __('Unsaved changes') }}">
        <span data-p2ps-state-text>{{ __('All changes saved') }}</span>
    </span>
@endsection

{{-- No actions in the header on purpose. It states what the page is and
     whether anything is unsaved; Discard and Save belong to the form and
     live once, in the save bar at its foot. --}}

@section('p2p_content')
    <div class="p2ps" data-p2ps>

        @if($errors->any())
            <div class="p2ps-errors" role="alert">
                <span class="p2ps-errors__icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span>
                <div class="p2ps-errors__body">
                    <strong>{{ trans_choice(':n field needs attention|:n fields need attention', $errors->count(), ['n' => $errors->count()]) }}</strong>
                    <ul>
                        @foreach($errors->all() as $msg)
                            <li>{{ $msg }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <div class="p2ps-layout">

            {{-- ── Section rail (tabs) ── --}}
            <aside class="p2ps-rail">
                <nav class="p2ps-tabs" role="tablist" aria-label="{{ __('Settings sections') }}" aria-orientation="vertical" data-p2ps-tablist>
                    @foreach($tabs as $tab)
                        @if($tab['group'] !== null)
                            <span class="p2ps-tabs__group">{{ $tab['group'] }}</span>
                        @endif
                        @php
                            $tabErrors = $tabErrorCount($tab['errors']);
                        @endphp
                        <button type="button"
                                class="p2ps-tab"
                                role="tab"
                                id="p2ps-tab-{{ $tab['key'] }}"
                                aria-controls="p2ps-pane-{{ $tab['key'] }}"
                                aria-selected="false"
                                tabindex="-1"
                                data-p2ps-tab="{{ $tab['key'] }}"
                                data-error-count="{{ $tabErrors }}">
                            <i class="fa-solid {{ $tab['icon'] }}" aria-hidden="true"></i>
                            <span class="p2ps-tab__label">{{ $tab['label'] }}</span>
                            @if($tab['key'] === 'assets')
                                <span class="p2ps-tab__count">{{ $assetFees->count() }}</span>
                            @endif
                            <span class="p2ps-tab__dot" data-p2ps-tab-dot aria-hidden="true"></span>
                            <span class="p2ps-tab__errors" data-p2ps-tab-errors>{{ $tabErrors }}</span>
                        </button>
                    @endforeach
                </nav>

                <div class="p2ps-rail__note">
                    <span class="p2ps-rail__note-title">
                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                        {{ __('How changes apply') }}
                    </span>
                    <p>{{ __('Saved values apply to orders opened from now on. Trades already in progress keep the fee, expiry and dispute window they started with.') }}</p>
                </div>
            </aside>

            {{-- ── Panes ── --}}
            <div class="p2ps-panes">
                <form method="POST" action="{{ route('admin.p2p.settings.update') }}" id="p2p-settings-form" data-settings-form>
                    @csrf
                    @method('PUT')

                    {{-- ── Fees ── --}}
                    <section class="p2ps-pane" id="p2ps-pane-fees" role="tabpanel" aria-labelledby="p2ps-tab-fees" data-p2ps-pane="fees" data-ss-section="{{ $tabLabels['fees'] ?? '' }}" hidden>
                        <div class="p2ps-card">
                            <header class="p2ps-card__head">
                                <span class="p2ps-card__icon p2ps-card__icon--brand"><i class="fa-solid fa-percent" aria-hidden="true"></i></span>
                                <div class="p2ps-card__title">
                                    <h2>{{ __('Platform fees') }}</h2>
                                    <span>{{ __('Commission charged to each side of a trade, in the asset being traded.') }}</span>
                                </div>
                                <span class="p2ps-card__aside">
                                    {{ __('Formula') }}
                                    <code dir="ltr">{{ __('fee = fixed + amount × pct') }}</code>
                                </span>
                            </header>

                            <div class="p2ps-card__body p2ps-grid-2">
                                @foreach(['maker' => ['title' => __('Maker fee'), 'who' => __('Trader who posts the offer (seller)'), 'role' => __('Earns'), 'tone' => 'teal'],
                                          'taker' => ['title' => __('Taker fee'), 'who' => __('Trader who accepts the offer (buyer)'), 'role' => __('Pays'),  'tone' => 'brand']] as $side => $copy)
                                    <div class="p2ps-panel">
                                        <div class="p2ps-panel__head">
                                            <div>
                                                <span class="p2ps-panel__title">{{ $copy['title'] }}</span>
                                                <span class="p2ps-panel__sub">{{ $copy['who'] }}</span>
                                            </div>
                                            <span class="p2ps-role p2ps-role--{{ $copy['tone'] }}">{{ $copy['role'] }}</span>
                                        </div>

                                        <div class="p2ps-grid-2 p2ps-grid-2--tight">
                                            <div class="p2ps-field">
                                                <label for="{{ $side }}_fee_pct">{{ __('Percentage') }} <span class="p2ps-req" aria-hidden="true">*</span></label>
                                                <div class="p2ps-input @error($side.'_fee_pct') is-invalid @enderror">
                                                    <input type="number" step="0.0001" min="0" max="100"
                                                           name="{{ $side }}_fee_pct" id="{{ $side }}_fee_pct"
                                                           data-settings-fee="{{ $side }}"
                                                           value="{{ $side === 'maker' ? $makerFee : $takerFee }}"
                                                           inputmode="decimal" required>
                                                    <span class="p2ps-input__suffix">%</span>
                                                </div>
                                                @error($side.'_fee_pct')<span class="p2ps-error">{{ $message }}</span>@enderror
                                            </div>

                                            <div class="p2ps-field">
                                                <label for="{{ $side }}_fee_fixed">{{ __('Fixed component') }}</label>
                                                <div class="p2ps-input @error($side.'_fee_fixed') is-invalid @enderror">
                                                    <input type="number" step="0.00000001" min="0"
                                                           name="{{ $side }}_fee_fixed" id="{{ $side }}_fee_fixed"
                                                           data-settings-fee-fixed="{{ $side }}"
                                                           value="{{ old($side.'_fee_fixed', $trimmed($settings->{$side.'_fee_fixed'})) }}"
                                                           placeholder="0.00" inputmode="decimal">
                                                    <span class="p2ps-input__suffix">{{ __('asset') }}</span>
                                                </div>
                                                @error($side.'_fee_fixed')<span class="p2ps-error">{{ $message }}</span>@enderror
                                            </div>
                                        </div>

                                        <div class="p2ps-field">
                                            <span class="p2ps-field__label">{{ __('Floor and cap') }} <span class="p2ps-optional">· {{ __('optional') }}</span></span>
                                            <div class="p2ps-grid-2 p2ps-grid-2--tight">
                                                <div class="p2ps-input p2ps-input--prefixed @error($side.'_fee_min') is-invalid @enderror">
                                                    <span class="p2ps-input__prefix">{{ __('Min') }}</span>
                                                    <input type="number" step="0.00000001" min="0"
                                                           name="{{ $side }}_fee_min" id="{{ $side }}_fee_min"
                                                           data-settings-fee-min="{{ $side }}"
                                                           value="{{ old($side.'_fee_min', $trimmed($settings->{$side.'_fee_min'})) }}"
                                                           placeholder="{{ __('No minimum') }}" inputmode="decimal"
                                                           aria-label="{{ __(':side minimum fee', ['side' => $copy['title']]) }}">
                                                </div>
                                                <div class="p2ps-input p2ps-input--prefixed @error($side.'_fee_max') is-invalid @enderror">
                                                    <span class="p2ps-input__prefix">{{ __('Max') }}</span>
                                                    <input type="number" step="0.00000001" min="0"
                                                           name="{{ $side }}_fee_max" id="{{ $side }}_fee_max"
                                                           data-settings-fee-max="{{ $side }}"
                                                           value="{{ old($side.'_fee_max', $trimmed($settings->{$side.'_fee_max'})) }}"
                                                           placeholder="{{ __('No maximum') }}" inputmode="decimal"
                                                           aria-label="{{ __(':side maximum fee', ['side' => $copy['title']]) }}">
                                                </div>
                                            </div>
                                            <span class="p2ps-hint">{{ __('A flat floor and ceiling on the total fee, in the traded asset — not a percentage. Leave blank for none.') }}</span>
                                            @error($side.'_fee_min')<span class="p2ps-error">{{ $message }}</span>@enderror
                                            @error($side.'_fee_max')<span class="p2ps-error">{{ $message }}</span>@enderror
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            {{-- Live preview: the flat part is charged in the asset being traded,
                                 so the sample is in units of that asset, never the site currency. --}}
                            <div class="p2ps-preview" data-fee-calc>
                                <div class="p2ps-preview__lead">
                                    <span class="p2ps-preview__tag">{{ __('Preview') }}</span>
                                    <span>{{ __('On a trade of') }}</span>
                                    <label class="p2ps-input p2ps-input--sm">
                                        <input type="number" min="0" step="any" value="1000"
                                               data-fee-calc-base aria-label="{{ __('Sample trade amount') }}">
                                        <span class="p2ps-input__suffix">{{ __('units') }}</span>
                                    </label>
                                </div>
                                <dl class="p2ps-preview__figures">
                                    <div><dt>{{ __('Maker receives') }}</dt><dd data-fee-calc-maker>0</dd></div>
                                    <div><dt>{{ __('Taker pays') }}</dt><dd data-fee-calc-taker>0</dd></div>
                                    <div><dt>{{ __('Platform revenue') }}</dt><dd class="is-positive" data-fee-calc-total>0</dd></div>
                                </dl>
                            </div>
                        </div>
                    </section>

                    {{-- ── Time windows ── --}}
                    <section class="p2ps-pane" id="p2ps-pane-windows" role="tabpanel" aria-labelledby="p2ps-tab-windows" data-p2ps-pane="windows" data-ss-section="{{ $tabLabels['windows'] ?? '' }}" hidden>
                        <div class="p2ps-card">
                            <header class="p2ps-card__head">
                                <span class="p2ps-card__icon p2ps-card__icon--info"><i class="fa-solid fa-clock" aria-hidden="true"></i></span>
                                <div class="p2ps-card__title">
                                    <h2>{{ __('Time windows') }}</h2>
                                    <span>{{ __('How long a buyer has to pay, and how long either side can raise a dispute.') }}</span>
                                </div>
                            </header>

                            <div class="p2ps-card__body p2ps-grid-2">
                                <div class="p2ps-field">
                                    <label for="order_expiry_minutes">{{ __('Order expiry') }} <span class="p2ps-req" aria-hidden="true">*</span></label>
                                    <div class="p2ps-input @error('order_expiry_minutes') is-invalid @enderror">
                                        <input type="number" min="5" max="2880" name="order_expiry_minutes" id="order_expiry_minutes"
                                               data-settings-expiry value="{{ $orderExpiry }}" inputmode="numeric" required>
                                        <span class="p2ps-input__suffix">{{ __('minutes') }}</span>
                                    </div>
                                    <span class="p2ps-hint">{{ __('Buyer payment deadline · 5 to 2,880 minutes (48h)') }}</span>
                                    @error('order_expiry_minutes')<span class="p2ps-error">{{ $message }}</span>@enderror
                                </div>

                                <div class="p2ps-field">
                                    <label for="dispute_window_minutes">{{ __('Dispute window') }} <span class="p2ps-req" aria-hidden="true">*</span></label>
                                    <div class="p2ps-input @error('dispute_window_minutes') is-invalid @enderror">
                                        <input type="number" min="10" max="4320" name="dispute_window_minutes" id="dispute_window_minutes"
                                               data-settings-dispute value="{{ $disputeWindow }}" inputmode="numeric" required>
                                        <span class="p2ps-input__suffix">{{ __('minutes') }}</span>
                                    </div>
                                    <span class="p2ps-hint">{{ __('Time to raise a dispute after payment · 10 to 4,320 min (72h)') }}</span>
                                    @error('dispute_window_minutes')<span class="p2ps-error">{{ $message }}</span>@enderror
                                </div>
                            </div>

                            <div class="p2ps-timeline" data-timing-preview>
                                <div class="p2ps-timeline__head">
                                    <span>{{ __('Settlement timeline') }}</span>
                                    <span>{{ __('Total') }} <b><span data-timing-total>{{ (int) $orderExpiry + (int) $disputeWindow }}</span> {{ __('min') }}</b> {{ __('from order to final') }}</span>
                                </div>
                                <div class="p2ps-timeline__bar" aria-hidden="true">
                                    <span class="p2ps-timeline__seg p2ps-timeline__seg--pay" data-timing-seg-pay style="width: 25%;"></span>
                                    <span class="p2ps-timeline__seg p2ps-timeline__seg--dispute" data-timing-seg-dispute style="width: 75%;"></span>
                                </div>
                                <div class="p2ps-timeline__legend">
                                    <span><i class="p2ps-timeline__key p2ps-timeline__key--pay" aria-hidden="true"></i>{{ __('Order opened → payment due in') }} <b><span data-timing-pay>{{ (int) $orderExpiry }}</span> {{ __('min') }}</b></span>
                                    <span><i class="p2ps-timeline__key p2ps-timeline__key--dispute" aria-hidden="true"></i>{{ __('Paid → dispute possible for') }} <b><span data-timing-dispute>{{ (int) $disputeWindow }}</span> {{ __('min') }}</b></span>
                                    <span>{{ __('Funds released') }}</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    {{-- ── Trade limits ── --}}
                    <section class="p2ps-pane" id="p2ps-pane-limits" role="tabpanel" aria-labelledby="p2ps-tab-limits" data-p2ps-pane="limits" data-ss-section="{{ $tabLabels['limits'] ?? '' }}" hidden>
                        <div class="p2ps-card">
                            <header class="p2ps-card__head">
                                <span class="p2ps-card__icon p2ps-card__icon--teal"><i class="fa-solid fa-layer-group" aria-hidden="true"></i></span>
                                <div class="p2ps-card__title">
                                    <h2>{{ __('Trade limits') }}</h2>
                                    <span>{{ __('Default smallest and largest amount a single order may carry. Offers can tighten these, never loosen them.') }}</span>
                                </div>
                            </header>

                            <div class="p2ps-card__body p2ps-grid-2">
                                <div class="p2ps-field">
                                    <label for="min_amount">{{ __('Minimum per order') }}</label>
                                    <div class="p2ps-input @error('min_amount') is-invalid @enderror">
                                        <input type="number" step="0.00000001" min="0" name="min_amount" id="min_amount"
                                               value="{{ $minAmount }}" inputmode="decimal" placeholder="0.00">
                                        <span class="p2ps-input__suffix">{{ $currencyCode }}</span>
                                    </div>
                                    <span class="p2ps-hint">{{ __('Smallest amount a trader can buy or sell in one order.') }}</span>
                                    @error('min_amount')<span class="p2ps-error">{{ $message }}</span>@enderror
                                </div>

                                <div class="p2ps-field">
                                    <label for="max_amount">{{ __('Maximum per order') }}</label>
                                    <div class="p2ps-input @error('max_amount') is-invalid @enderror">
                                        <input type="number" step="0.00000001" min="0" name="max_amount" id="max_amount"
                                               value="{{ $maxAmount }}" inputmode="decimal" placeholder="{{ __('No cap') }}">
                                        <span class="p2ps-input__suffix">{{ $currencyCode }}</span>
                                    </div>
                                    <span class="p2ps-hint">{{ __('Leave blank for no upper limit. Must exceed the minimum.') }}</span>
                                    @error('max_amount')<span class="p2ps-error">{{ $message }}</span>@enderror
                                </div>
                            </div>
                        </div>
                    </section>

                    {{-- ── Country access ── --}}
                    <section class="p2ps-pane" id="p2ps-pane-countries" role="tabpanel" aria-labelledby="p2ps-tab-countries" data-p2ps-pane="countries" data-ss-section="{{ $tabLabels['countries'] ?? '' }}" hidden>
                        <div class="p2ps-card">
                            <header class="p2ps-card__head">
                                <span class="p2ps-card__icon p2ps-card__icon--warning"><i class="fa-solid fa-earth-americas" aria-hidden="true"></i></span>
                                <div class="p2ps-card__title">
                                    <h2>{{ __('Country access') }}</h2>
                                    <span>{{ __('Who may trade, by the country on their profile. A country cannot appear in both lists.') }}</span>
                                </div>
                            </header>

                            <div class="p2ps-card__body p2ps-grid-2">
                                @foreach(['allowed' => ['title' => __('Allow list'), 'sub' => __('When empty, every country not blocked may trade.'), 'empty' => __('No countries on the allow list'), 'emptySub' => __('The marketplace is open to everyone except the blocked countries.'), 'placeholder' => __('Search countries to allow…'), 'selected' => $allowedSelected, 'tone' => 'success', 'unit' => ':count selected'],
                                          'blocked' => ['title' => __('Block list'), 'sub' => __('Traders from these countries cannot open or accept offers.'), 'empty' => __('No countries blocked'), 'emptySub' => __('Every country on the allow list, or every country when it is empty, may trade.'), 'placeholder' => __('Search countries to block…'), 'selected' => $blockedSelected, 'tone' => 'danger', 'unit' => ':count blocked']] as $key => $copy)
                                    <div class="p2ps-panel p2ps-picker" data-country-picker="{{ $key }}">
                                        <div class="p2ps-panel__head">
                                            <div>
                                                <label class="p2ps-panel__title" for="{{ $key }}_countries">{{ $copy['title'] }}</label>
                                                <span class="p2ps-panel__sub">{{ $copy['sub'] }}</span>
                                            </div>
                                            <span class="p2ps-count p2ps-count--{{ $copy['tone'] }}"
                                                  data-country-count="{{ $key }}"
                                                  data-count-template="{{ __($copy['unit']) }}">{{ __($copy['unit'], ['count' => count($copy['selected'])]) }}</span>
                                        </div>

                                        <label class="p2ps-picker__search">
                                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                            <input type="search" data-country-search="{{ $key }}"
                                                   placeholder="{{ $copy['placeholder'] }}"
                                                   aria-label="{{ $copy['placeholder'] }}">
                                        </label>

                                        <div class="p2ps-picker__chips" data-country-chips="{{ $key }}"
                                             data-empty-title="{{ $copy['empty'] }}"
                                             data-empty-text="{{ $copy['emptySub'] }}"
                                             data-remove-label="{{ __('Remove') }}"></div>

                                        <select name="{{ $key }}_countries[]" id="{{ $key }}_countries"
                                                class="p2ps-picker__native @error($key.'_countries') is-invalid @enderror"
                                                multiple data-country-select="{{ $key }}" tabindex="-1" aria-hidden="true">
                                            @foreach($countryOptions as $country)
                                                <option value="{{ $country['code'] }}" {{ in_array($country['code'], $copy['selected'], true) ? 'selected' : '' }}>
                                                    {{ $country['name'] }} ({{ $country['code'] }})
                                                </option>
                                            @endforeach
                                        </select>

                                        <ul class="p2ps-picker__options" role="listbox" aria-multiselectable="true"
                                            aria-label="{{ $copy['title'] }}" data-country-options="{{ $key }}">
                                            @foreach($countryOptions as $country)
                                                @php
                                                    $isSelected = in_array($country['code'], $copy['selected'], true);
                                                @endphp
                                                <li class="p2ps-option {{ $isSelected ? 'is-selected' : '' }}"
                                                    role="option" tabindex="0"
                                                    aria-selected="{{ $isSelected ? 'true' : 'false' }}"
                                                    data-country-value="{{ $country['code'] }}"
                                                    data-country-name="{{ $country['name'] }}"
                                                    data-country-search-text="{{ strtolower($country['name'].' '.$country['code']) }}">
                                                    <span class="p2ps-option__check"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
                                                    <span class="p2ps-option__label">{{ $country['name'] }}</span>
                                                    <span class="p2ps-option__code" dir="ltr">{{ $country['code'] }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                        <div class="p2ps-picker__nomatch is-hidden" data-country-empty="{{ $key }}">
                                            {{ __('No countries match your search') }}
                                        </div>

                                        @error($key.'_countries')<span class="p2ps-error">{{ $message }}</span>@enderror
                                        @error($key.'_countries.*')<span class="p2ps-error">{{ $message }}</span>@enderror
                                    </div>
                                @endforeach
                            </div>

                            <div class="p2ps-notice">
                                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                                <span><b>{{ __('Compliance note.') }}</b> {{ __('A country cannot appear in both lists. If both are empty, the marketplace is open to all countries by default.') }}</span>
                            </div>
                        </div>
                    </section>

                    {{-- The one place Discard and Save live, at the foot of the
                         form in reading order and stuck to the bottom of the
                         viewport so a long pane never puts them out of reach.
                         The header repeats neither: two pairs on one screen
                         read as two different saves. --}}
                    {{-- The admin's one save bar, from admin-savebar.css. It is
                         the same control on Site Settings and on Marketplace
                         Settings, so it is declared once and this page only
                         writes its markup. --}}
                    <div class="ss-savebar" data-settings-savebar data-ss-savebar hidden
                         data-ss-form-id="p2p-settings-form"
                         data-ss-in="{{ __('in') }}"
                         data-ss-nothing="{{ __('Nothing is applied until you save.') }}"
                         data-ss-unsaved-one="{{ __(':count unsaved change') }}"
                         data-ss-unsaved-many="{{ __(':count unsaved changes') }}">
                        <div class="ss-savebar__inner" role="status" aria-live="polite">
                            <span class="ss-savebar__icon" aria-hidden="true"><i class="fa-solid fa-pen"></i></span>
                            <span class="ss-savebar__copy">
                                <strong class="ss-savebar__title" data-ss-savebar-title>{{ __('Unsaved changes') }}</strong>
                                <span class="ss-savebar__detail" data-ss-savebar-detail>{{ __('Nothing is applied until you save.') }}</span>
                            </span>
                            <button type="button" class="ss-savebar__btn ss-savebar__btn--ghost" data-p2ps-discard disabled>
                                {{ __('Discard') }}
                            </button>
                            <button type="submit" class="ss-savebar__btn ss-savebar__btn--primary" data-p2ps-save>
                                {{ __('Save changes') }}
                                <kbd class="ss-savebar__kbd" aria-hidden="true">Ctrl S</kbd>
                            </button>
                        </div>
                    </div>
                </form>

                {{-- ── Per-asset fee overrides ──
                     Outside the settings form on purpose: each override is its own
                     record with its own save, and nesting forms is invalid HTML. --}}
                <section class="p2ps-pane" id="p2ps-pane-assets" role="tabpanel" aria-labelledby="p2ps-tab-assets" data-p2ps-pane="assets" data-ss-section="{{ $tabLabels['assets'] ?? '' }}" hidden>
                    <div class="p2ps-card" id="p2p-asset-fees">
                        <header class="p2ps-card__head">
                            <span class="p2ps-card__icon p2ps-card__icon--violet"><i class="fa-solid fa-coins" aria-hidden="true"></i></span>
                            <div class="p2ps-card__title">
                                <h2>{{ __('Per-asset fees') }}</h2>
                                <span>{{ __('Replace the default fees for one asset. A flat 0.0005 is roughly thirty dollars of BTC and a twentieth of a cent of USDT.') }}</span>
                            </div>
                            @can('p2p-manage')
                                <button type="button" class="ph-btn ph-btn--secondary p2ps-card__action"
                                        data-coreui-toggle="modal" data-coreui-target="#p2pAssetFeeModal"
                                        data-asset-fee-create>
                                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                                    {{ __('Add override') }}
                                </button>
                            @endcan
                        </header>

                        @if($assetFees->isEmpty())
                            <div class="p2ps-card__body">
                                <x-admin-not-found
                                    :title="__('No asset overrides yet')"
                                    :message="__('Every asset currently trades on the default maker and taker fee. Add an override for any asset whose settlement cost differs.')"
                                    icon="fa-coins"
                                />
                            </div>
                        @else
                            <div class="p2ps-table-wrap">
                                <table class="p2ps-table">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Asset') }}</th>
                                            <th>{{ __('Maker fee') }}</th>
                                            <th>{{ __('Taker fee') }}</th>
                                            <th>{{ __('Status') }}</th>
                                            <th class="p2ps-table__end">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($assetFees as $assetFee)
                                            @php
                                                $code = $assetFee->currency?->code ?: __('Unknown');
                                            @endphp
                                            <tr>
                                                <td>
                                                    <div class="p2ps-asset">
                                                        <span class="p2ps-asset__tile" dir="ltr">{{ \Illuminate\Support\Str::limit($code, 4, '') }}</span>
                                                        <span class="p2ps-asset__copy">
                                                            <b>{{ $code }}</b>
                                                            <span>{{ $assetFee->currency?->type ?: __('—') }} · {{ __('Updated :when', ['when' => $assetFee->updated_at?->diffForHumans() ?? __('never')]) }}</span>
                                                        </span>
                                                    </div>
                                                </td>
                                                @foreach(['maker', 'taker'] as $side)
                                                    <td>
                                                        <span class="p2ps-fee">
                                                            <b>{{ \App\Support\Money::describeFee($assetFee->{$side.'_fee_fixed'}, $assetFee->{$side.'_fee_pct'}, $code) }}</b>
                                                            @if($assetFee->{$side.'_fee_min'} !== null || $assetFee->{$side.'_fee_max'} !== null)
                                                                <span>
                                                                    {{ __('Min') }} {{ $assetFee->{$side.'_fee_min'} !== null ? \App\Support\Money::trimFee($assetFee->{$side.'_fee_min'}) : __('—') }}
                                                                    · {{ __('Max') }} {{ $assetFee->{$side.'_fee_max'} !== null ? \App\Support\Money::trimFee($assetFee->{$side.'_fee_max'}) : __('—') }}
                                                                </span>
                                                            @endif
                                                        </span>
                                                    </td>
                                                @endforeach
                                                <td>
                                                    @if($assetFee->is_active)
                                                        <span class="p2ps-pill p2ps-pill--success">{{ __('Active') }}</span>
                                                    @else
                                                        <span class="p2ps-pill p2ps-pill--warning">{{ __('Using default') }}</span>
                                                    @endif
                                                </td>
                                                <td class="p2ps-table__end">
                                                    @can('p2p-manage')
                                                        <div class="p2ps-row-tools">
                                                            <button type="button" class="p2ps-row-btn"
                                                                    data-coreui-toggle="modal" data-coreui-target="#p2pAssetFeeModal"
                                                                    data-asset-fee-edit="{{ route('admin.p2p.asset-fees.show', $assetFee) }}"
                                                                    data-asset-fee-action="{{ route('admin.p2p.asset-fees.update', $assetFee) }}"
                                                                    data-asset-fee-code="{{ $code }}">
                                                                <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                                                                {{ __('Edit') }}
                                                            </button>
                                                            <form method="POST" action="{{ route('admin.p2p.asset-fees.destroy', $assetFee) }}"
                                                                  data-confirm="{{ __('Remove this override? :code will go back to the default fee.', ['code' => $code]) }}">
                                                                @csrf
                                                                @method('DELETE')
                                                                <button type="submit" class="p2ps-row-btn p2ps-row-btn--danger">
                                                                    <i class="fa-solid fa-arrow-rotate-left" aria-hidden="true"></i>
                                                                    {{ __('Reset') }}
                                                                </button>
                                                            </form>
                                                        </div>
                                                    @endcan
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="p2ps-card__foot">
                                <span>{{ __('Every other asset trades on the default maker and taker fee.') }}</span>
                                <span>{{ trans_choice(':count override|:count overrides', $assetFees->count(), ['count' => $assetFees->count()]) }} · {{ __(':count active', ['count' => $activeOverrides]) }}</span>
                            </div>
                        @endif
                    </div>
                </section>
            </div>
        </div>
    </div>

    @can('p2p-manage')
        @include('backend.p2p.settings.partials._asset_fee_modal')
    @endcan
@endsection

@push('scripts')
    <script src="{{ asset('backend/js/admin-savebar.js?v='.config('app.version').'-'.filemtime(public_path('backend/js/admin-savebar.js'))) }}"></script>
    <script src="{{ asset('backend/js/p2p-settings.js?v='.$p2pSettingsJsVersion) }}"></script>
@endpush
