@extends('backend.layouts.app')

@section('title', __('Marketplace Settings'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/admin-savebar.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/admin-savebar.css'))) }}">
    <link rel="stylesheet" href="{{ asset('backend/css/marketplace-settings.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/marketplace-settings.css'))) }}">
@endpush

@php
    $enabled         = (bool) old('enabled', $settings->enabled);
    $autoRelease     = (bool) old('auto_release', $settings->auto_release);
    $splitProvider   = (string) old('split_provider', $settings->split_provider ?? 'wallet');
    $commissionPct   = old('commission_pct', $settings->commission_pct);
    $commissionFixed = old('commission_fixed', $settings->commission_fixed);
    $minOrder        = old('min_order_amount', $settings->min_order_amount);
    $maxOrder        = old('max_order_amount', $settings->max_order_amount);
    $holdMinutes     = old('payout_hold_minutes', $settings->payout_hold_minutes);
    $currencyCode    = siteCurrency('code') ?? 'USD';
    $updatedAgo      = $settings->updated_at ? $settings->updated_at->diffForHumans() : __('never');

    $holdHuman = static function ($minutes): string {
        $minutes = (int) $minutes;

        if ($minutes >= 1440) {
            return __(':n days', ['n' => rtrim(rtrim(number_format($minutes / 1440, 1), '0'), '.')]);
        }
        if ($minutes >= 60) {
            return __(':n hours', ['n' => rtrim(rtrim(number_format($minutes / 60, 1), '0'), '.')]);
        }

        return __(':n minutes', ['n' => $minutes]);
    };

    /*
     * The value each navigation row carries. It is the whole reason this page
     * has no stats strip in its header: the column already states the
     * configuration, so repeating four of those numbers above it was the same
     * fact twice. The script keeps these in step while fields are edited.
     */
    $navValues = [
        'general'    => $enabled ? __('Live') : __('Paused'),
        'commission' => number_format((float) $commissionPct, 2) . '%'
            . ((float) $commissionFixed > 0 ? ' +' . number_format((float) $commissionFixed, 2) : ''),
        'payouts'    => $autoRelease ? __('Instant') : __('Hold') . ' · ' . $holdHuman($holdMinutes),
    ];

    /*
     * Which section owns which field. A rejected save must never hide its own
     * reason, so the first section holding an error is the one that opens.
     */
    $sectionFields = [
        'general'    => ['enabled', 'min_order_amount', 'max_order_amount'],
        'commission' => ['commission_pct', 'commission_fixed'],
        'payouts'    => ['split_provider', 'auto_release', 'payout_hold_minutes'],
    ];

    $sectionErrors = [];
    foreach ($sectionFields as $sectionKey => $fields) {
        $count = 0;
        foreach ($fields as $field) {
            $count += count($errors->get($field));
        }
        $sectionErrors[$sectionKey] = $count;
    }

    $activeSection = 'general';
    foreach ($sectionErrors as $sectionKey => $count) {
        if ($count > 0) {
            $activeSection = $sectionKey;
            break;
        }
    }

    $panels = [
        'general' => [
            __('General'),
            __('Whether the marketplace takes orders, and the size of an order it will take'),
            __('Applies at checkout'),
        ],
        'commission' => [
            __('Commission'),
            __('What the platform keeps from each marketplace order'),
            __('Applies to new orders'),
        ],
        'payouts' => [
            __('Payouts'),
            __('Which rail carries the vendor share, and when they can touch it'),
            __('Applies to new orders'),
        ],
        'custody' => [
            __('Commission account'),
            __('The protected wallet that holds every commission this platform collects'),
            __('Changed by provisioning'),
        ],
        'history' => [
            __('Custody history'),
            __('Every change of the account that receives commission, kept permanently'),
            __(':n entries', ['n' => $revisions->count()]),
        ],
    ];
@endphp

@section('content')
    <div class="mps-screen" data-mps-screen data-mps-active="{{ $activeSection }}">
        <x-page-header
            :title="__('Marketplace Settings')"
            :subtitle="__('Everything that decides how a vendor payment is split, settled and paid out.')"
            :eyebrow="__('Marketplace')"
            icon="cil-settings"
            :back="route('admin.marketplace.orders.index')"
            :back-label="__('Back to Marketplace')"
            :status="['tone' => $settings->enabled ? 'success' : 'danger', 'label' => $settings->enabled ? __('Live') : __('Offline')]"
            :meta="[
                ['label' => __('Updated :when', ['when' => $updatedAgo])],
                ['label' => __('Default currency') . ' ' . $currencyCode],
                ['label' => __('Master switch: vendor_marketplace in Feature Management'), 'url' => route('admin.features.index')],
            ]"
        >
            <x-slot:state>
                <span class="ph-state ph-state--success" data-mps-state>
                    <span data-mps-state-clean>@lang('All changes saved')</span>
                    <span data-mps-state-dirty hidden>@lang('Unsaved changes')</span>
                </span>
            </x-slot:state>
            {{-- No Save here. It belongs to the form and lives once, in the
                 save bar at the foot; a pair in each place reads as two
                 different saves. The header states what changed instead. --}}
            <x-slot:actions>
                <a href="{{ route('admin.marketplace.vendors.index') }}" class="ph-btn ph-btn--secondary">
                    <i class="fa-solid fa-store" aria-hidden="true"></i>@lang('Manage vendors')
                </a>
            </x-slot:actions>
            <x-slot:menu>
                <a class="dropdown-item" href="{{ route('admin.marketplace.settings.commission-account.show') }}">@lang('Commission ledger')</a>
                <a class="dropdown-item" href="{{ route('admin.marketplace.orders.index') }}">@lang('Marketplace orders')</a>
            </x-slot:menu>
        </x-page-header>

        @if($errors->any())
            <div class="mps-errors" role="alert">
                <span class="mps-errors__icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span>
                <div>
                    <h6>@lang(':n field(s) need attention', ['n' => $errors->count()])</h6>
                    <ul>
                        @foreach($errors->all() as $msg)
                            <li>{{ $msg }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <div class="mps-workspace">

            {{-- ════ Settings navigation ════ --}}
            <div class="mps-aside">
                <nav class="mps-nav" aria-label="{{ __('Marketplace settings sections') }}" data-mps-nav>
                    <div class="mps-nav__filter">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <input type="search" id="mps-filter" data-mps-filter
                               placeholder="{{ __('Filter settings') }}" aria-label="{{ __('Filter settings') }}" autocomplete="off">
                    </div>

                    <div class="mps-nav__group" data-mps-group>
                        <span class="mps-nav__caption">@lang('Setup')</span>

                        <button type="button" class="mps-nav__item" data-mps-link="general"
                                data-mps-terms="general availability order limits enabled checkout"
                                aria-current="{{ $activeSection === 'general' ? 'true' : 'false' }}">
                            <span class="mps-nav__bar" aria-hidden="true"></span>
                            <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                            <span class="mps-nav__label">@lang('General')</span>
                            <span class="mps-nav__dot" data-mps-dot="general" hidden></span>
                            @if($sectionErrors['general'] > 0)
                                <span class="mps-nav__count mps-nav__count--danger">{{ $sectionErrors['general'] }}</span>
                            @else
                                <span class="mps-nav__value" data-mps-value="general"
                                      data-on="{{ __('Live') }}" data-off="{{ __('Paused') }}">{{ $navValues['general'] }}</span>
                            @endif
                        </button>

                        <button type="button" class="mps-nav__item" data-mps-link="commission"
                                data-mps-terms="commission rate fixed fee split platform share"
                                aria-current="{{ $activeSection === 'commission' ? 'true' : 'false' }}">
                            <span class="mps-nav__bar" aria-hidden="true"></span>
                            <i class="fa-solid fa-percent" aria-hidden="true"></i>
                            <span class="mps-nav__label">@lang('Commission')</span>
                            <span class="mps-nav__dot" data-mps-dot="commission" hidden></span>
                            @if($sectionErrors['commission'] > 0)
                                <span class="mps-nav__count mps-nav__count--danger">{{ $sectionErrors['commission'] }}</span>
                            @else
                                <span class="mps-nav__value mps-nav__value--mono" data-mps-value="commission" dir="ltr">{{ $navValues['commission'] }}</span>
                            @endif
                        </button>

                        <button type="button" class="mps-nav__item" data-mps-link="payouts"
                                data-mps-terms="payouts settlement rail wallet stripe hold instant release timing"
                                aria-current="{{ $activeSection === 'payouts' ? 'true' : 'false' }}">
                            <span class="mps-nav__bar" aria-hidden="true"></span>
                            <i class="fa-solid fa-right-left" aria-hidden="true"></i>
                            <span class="mps-nav__label">@lang('Payouts')</span>
                            <span class="mps-nav__dot" data-mps-dot="payouts" hidden></span>
                            @if($sectionErrors['payouts'] > 0)
                                <span class="mps-nav__count mps-nav__count--danger">{{ $sectionErrors['payouts'] }}</span>
                            @else
                                <span class="mps-nav__value" data-mps-value="payouts"
                                      data-instant="{{ __('Instant') }}" data-hold="{{ __('Hold') }} · ">{{ $navValues['payouts'] }}</span>
                            @endif
                        </button>
                    </div>

                    <div class="mps-nav__group" data-mps-group>
                        <span class="mps-nav__caption">@lang('Money')</span>
                        <button type="button" class="mps-nav__item" data-mps-link="custody"
                                data-mps-terms="commission account custody protected wallet ledger"
                                aria-current="{{ $activeSection === 'custody' ? 'true' : 'false' }}">
                            <span class="mps-nav__bar" aria-hidden="true"></span>
                            <i class="fa-solid fa-lock" aria-hidden="true"></i>
                            <span class="mps-nav__label">@lang('Commission account')</span>
                            <span class="mps-nav__value {{ $commissionAccountIsProtected ? 'mps-nav__value--ok' : 'mps-nav__value--warn' }}">
                                {{ $commissionAccountIsProtected ? __('Protected') : __('Action required') }}
                            </span>
                        </button>
                    </div>

                    <div class="mps-nav__group" data-mps-group>
                        <span class="mps-nav__caption">@lang('Records')</span>
                        <button type="button" class="mps-nav__item" data-mps-link="history"
                                data-mps-terms="custody history revisions audit log changes"
                                aria-current="{{ $activeSection === 'history' ? 'true' : 'false' }}">
                            <span class="mps-nav__bar" aria-hidden="true"></span>
                            <i class="fa-solid fa-clock" aria-hidden="true"></i>
                            <span class="mps-nav__label">@lang('Custody history')</span>
                            <span class="mps-nav__count">{{ $revisions->count() }}</span>
                        </button>
                    </div>

                    <p class="mps-nav__empty" data-mps-empty hidden>@lang('Nothing matches that.')</p>
                </nav>

                <div class="mps-related">
                    <span class="mps-nav__caption">@lang('Related')</span>
                    <a href="{{ route('admin.marketplace.orders.index') }}">@lang('Marketplace orders')</a>
                    <a href="{{ route('admin.marketplace.vendors.index') }}">@lang('Vendor directory')</a>
                    <a href="{{ route('admin.marketplace.settings.commission-account.show') }}">@lang('Commission ledger')</a>
                </div>
            </div>

            {{-- ════ Panel column ════ --}}
            <div class="mps-panels">

                <div class="mps-panel__head">
                    <div class="mps-panel__title">
                        <h2 data-mps-panel-title>{{ $panels[$activeSection][0] }}</h2>
                        <span data-mps-panel-desc>{{ $panels[$activeSection][1] }}</span>
                    </div>
                    <span class="mps-panel__hint" data-mps-panel-hint>{{ $panels[$activeSection][2] }}</span>
                </div>

                {{--
                    novalidate is load-bearing. Fields of a section that is not
                    open are still in the document and still submitted, and a
                    browser refuses to report a constraint violation on a
                    control it cannot focus — the save would fail in silence.
                    Every rule lives in MarketplaceSettingRequest instead.
                --}}
                <form method="POST" action="{{ route('admin.marketplace.settings.update') }}"
                      id="marketplace-settings-form" class="mps-form" novalidate data-mps-form>
                    @csrf
                    @method('PUT')

                    {{-- ── General ── --}}
                    <section class="mps-panel" data-mps-panel="general" data-ss-section="{{ $panels['general'][0] }}" aria-label="{{ __('General') }}" @if($activeSection !== 'general') hidden @endif>
                        <div class="mps-card">
                            <div class="mps-card__head">
                                <div class="mps-card__title">
                                    <h3>@lang('Availability')</h3>
                                    <span>@lang('Day-to-day operational switch for buyer checkout')</span>
                                </div>
                                <a href="{{ route('admin.features.index') }}" class="mps-card__aside">@lang('Feature-level control')</a>
                            </div>

                            <div class="mps-switch-row {{ $enabled ? '' : 'mps-switch-row--off' }}" data-mps-switch-row>
                                <div class="mps-switch-row__copy">
                                    <label for="enabled">@lang('Accept marketplace orders')</label>
                                    <span data-mps-avail="on" @if(! $enabled) hidden @endif>@lang('Buyers can pay vendors right now. Marketplace pages are visible.')</span>
                                    <span data-mps-avail="off" @if($enabled) hidden @endif>@lang('Checkout is paused and marketplace pages are hidden from buyers.')</span>
                                </div>
                                <div class="form-check form-switch">
                                    <input type="hidden" name="enabled" value="0">
                                    <input type="checkbox" role="switch" class="form-check-input @error('enabled') is-invalid @enderror"
                                           name="enabled" id="enabled" value="1" @checked($enabled) data-mps-enabled>
                                </div>
                            </div>
                            @error('enabled')<span class="mps-error">{{ $message }}</span>@enderror

                            <p class="mps-note">
                                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                                <span>
                                    @lang('Turning this off hides every marketplace page and rejects new checkouts. Orders already paid continue to settle.')
                                    @lang('To remove the module from this site entirely, disable') <span class="mps-code" dir="ltr">vendor_marketplace</span> @lang('in Feature Management.')
                                </span>
                            </p>
                        </div>

                        <div class="mps-card">
                            <div class="mps-card__title">
                                <h3>@lang('Order limits')</h3>
                                <span>@lang('Smallest and largest single payment a buyer may place')</span>
                            </div>

                            <div class="mps-grid-2">
                                <div class="mps-field">
                                    <label for="min_order_amount">@lang('Minimum order amount')</label>
                                    <div class="mps-input @error('min_order_amount') is-invalid @enderror">
                                        <input type="number" step="0.00000001" name="min_order_amount" id="min_order_amount"
                                               value="{{ $minOrder }}" inputmode="decimal" placeholder="0.00"
                                               @error('min_order_amount') aria-invalid="true" aria-describedby="min_order_amount_error" @enderror>
                                        <span class="mps-input__suffix mps-input__suffix--mono" dir="ltr">{{ $currencyCode }}</span>
                                    </div>
                                    <span class="mps-hint">@lang('Orders below this amount are rejected at checkout.')</span>
                                    @error('min_order_amount')<span class="mps-error" id="min_order_amount_error">{{ $message }}</span>@enderror
                                </div>

                                <div class="mps-field">
                                    <label for="max_order_amount">@lang('Maximum order amount')</label>
                                    <div class="mps-input @error('max_order_amount') is-invalid @enderror">
                                        <input type="number" step="0.00000001" name="max_order_amount" id="max_order_amount"
                                               value="{{ $maxOrder }}" inputmode="decimal" placeholder="{{ __('No cap') }}"
                                               @error('max_order_amount') aria-invalid="true" aria-describedby="max_order_amount_error" @enderror>
                                        <span class="mps-input__suffix mps-input__suffix--mono" dir="ltr">{{ $currencyCode }}</span>
                                    </div>
                                    <span class="mps-hint">@lang('Leave blank for no upper limit. Must exceed the minimum.')</span>
                                    @error('max_order_amount')<span class="mps-error" id="max_order_amount_error">{{ $message }}</span>@enderror
                                </div>
                            </div>
                        </div>
                    </section>

                    {{-- ── Commission ── --}}
                    <section class="mps-panel mps-panel--split" data-mps-panel="commission" data-ss-section="{{ $panels['commission'][0] }}" aria-label="{{ __('Commission') }}" @if($activeSection !== 'commission') hidden @endif>
                        <div class="mps-card">
                            <div class="mps-card__title">
                                <h3>@lang('Platform share')</h3>
                                <span>@lang('Taken from every marketplace order before the vendor is paid')</span>
                            </div>

                            <div class="mps-grid-2">
                                <div class="mps-field">
                                    <label for="commission_pct">@lang('Commission rate') <span class="req" aria-hidden="true">*</span></label>
                                    <div class="mps-input @error('commission_pct') is-invalid @enderror">
                                        <input type="number" step="0.0001" name="commission_pct" id="commission_pct"
                                               value="{{ $commissionPct }}" inputmode="decimal" data-mps-pct
                                               @error('commission_pct') aria-invalid="true" aria-describedby="commission_pct_error" @enderror>
                                        <span class="mps-input__suffix">%</span>
                                    </div>
                                    <span class="mps-hint">@lang('Percentage of the order kept by the platform.')</span>
                                    @error('commission_pct')<span class="mps-error" id="commission_pct_error">{{ $message }}</span>@enderror
                                </div>

                                <div class="mps-field">
                                    <label for="commission_fixed">@lang('Fixed fee') <span class="opt">(@lang('optional'))</span></label>
                                    <div class="mps-input @error('commission_fixed') is-invalid @enderror">
                                        <input type="number" step="0.00000001" name="commission_fixed" id="commission_fixed"
                                               value="{{ $commissionFixed }}" inputmode="decimal" placeholder="0.00" data-mps-fixed
                                               @error('commission_fixed') aria-invalid="true" aria-describedby="commission_fixed_error" @enderror>
                                        <span class="mps-input__suffix mps-input__suffix--mono" dir="ltr">{{ $currencyCode }}</span>
                                    </div>
                                    <span class="mps-hint">@lang('Flat amount added on top of the percentage.')</span>
                                    @error('commission_fixed')<span class="mps-error" id="commission_fixed_error">{{ $message }}</span>@enderror
                                </div>
                            </div>

                            <p class="mps-note mps-note--solid">
                                <span class="mps-note__lead">
                                    <i class="fa-solid fa-store" aria-hidden="true"></i>
                                    <span>
                                        @lang('Vendors with a rate override on their profile keep their own rate.')
                                        <a href="{{ route('admin.marketplace.vendors.index') }}">@lang(':n vendor(s) use overrides', ['n' => $vendorOverrideCount])</a>
                                    </span>
                                </span>
                                <span class="mps-note__tail">@lang('Applies to new orders only')</span>
                            </p>
                        </div>

                        <div class="mps-card mps-card--rail" data-mps-calc>
                            <div class="mps-card__head">
                                <h3>@lang('Split preview')</h3>
                                <span class="mps-card__live">@lang('Live')</span>
                            </div>

                            <div class="mps-field">
                                <label for="mps_sample">@lang('Sample order amount')</label>
                                <div class="mps-input mps-input--prefix mps-input--sm">
                                    <span class="mps-input__suffix mps-input__suffix--mono" dir="ltr">{{ $currencyCode }}</span>
                                    <input type="number" step="1" id="mps_sample" value="1000" inputmode="decimal" data-mps-sample>
                                </div>
                            </div>

                            <div class="mps-bar" aria-hidden="true">
                                <span class="mps-bar__vendor" data-mps-bar-vendor></span>
                                <span class="mps-bar__platform" data-mps-bar-platform></span>
                            </div>

                            <div class="mps-split">
                                <div class="mps-split__row">
                                    <span class="mps-split__key"><span class="mps-split__swatch mps-split__swatch--vendor" aria-hidden="true"></span>@lang('Vendor receives')</span>
                                    <span class="mps-split__value" data-mps-vendor dir="ltr">0.00</span>
                                </div>
                                <div class="mps-split__row">
                                    <span class="mps-split__key"><span class="mps-split__swatch mps-split__swatch--platform" aria-hidden="true"></span>@lang('Platform earns')</span>
                                    <span class="mps-split__value" data-mps-platform dir="ltr">0.00</span>
                                </div>
                                <div class="mps-split__row mps-split__row--total">
                                    <span class="mps-split__key">@lang('Effective take rate')</span>
                                    <span class="mps-split__value" data-mps-rate dir="ltr">0.00%</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    {{-- ── Payouts ── --}}
                    <section class="mps-panel" data-mps-panel="payouts" data-ss-section="{{ $panels['payouts'][0] }}" aria-label="{{ __('Payouts') }}" @if($activeSection !== 'payouts') hidden @endif>
                        <div class="mps-card">
                            <div class="mps-card__title" id="mps-rail-title">
                                <h3>@lang('Settlement rail')</h3>
                                <span>@lang("Where the vendor's share of each payment lands")</span>
                            </div>

                            <div class="mps-choices" role="radiogroup" aria-labelledby="mps-rail-title">
                                @foreach ($splitProviders as $splitCode => $splitLabel)
                                    @php($isStripe = $splitCode === 'stripe_connect')
                                    <label class="mps-choice">
                                        <input type="radio" class="mps-choice__input" name="split_provider"
                                               value="{{ $splitCode }}" @checked($splitProvider === $splitCode) data-mps-rail>
                                        <span class="mps-choice__card">
                                            <span class="mps-choice__top">
                                                <span class="mps-choice__icon {{ $isStripe ? 'mps-choice__icon--violet' : '' }}">
                                                    <i class="fa-solid {{ $isStripe ? 'fa-credit-card' : 'fa-wallet' }}" aria-hidden="true"></i>
                                                </span>
                                                <span class="mps-choice__radio" aria-hidden="true"></span>
                                            </span>
                                            <span class="mps-choice__body">
                                                <span class="mps-choice__name">{{ $splitLabel }}</span>
                                                <span class="mps-choice__desc">
                                                    @if($isStripe)
                                                        @lang("Card payments settle straight into each vendor's connected Stripe account. Vendors who have not finished onboarding fall back to the internal wallet.")
                                                    @else
                                                        @lang("Vendor share is credited to the vendor's wallet on this platform. Works for every gateway and every vendor.")
                                                    @endif
                                                </span>
                                            </span>
                                            @if($isStripe)
                                                <span class="mps-choice__foot mps-choice__foot--warn"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>@lang('Requires the Stripe gateway to be enabled')</span>
                                            @else
                                                <span class="mps-choice__foot mps-choice__foot--ok"><i class="fa-solid fa-check" aria-hidden="true"></i>@lang('No vendor onboarding required')</span>
                                            @endif
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            @error('split_provider')<span class="mps-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="mps-card">
                            <div class="mps-card__title">
                                <h3>@lang('Release timing')</h3>
                                <span>@lang('When the vendor can access their share after a paid order')</span>
                            </div>

                            <div class="mps-grid-3">
                                <div class="mps-field">
                                    <span class="mps-field__label" id="mps-mode-label">@lang('Payout mode') <span class="req" aria-hidden="true">*</span></span>
                                    <div class="mps-segment" role="radiogroup" aria-labelledby="mps-mode-label">
                                        <label class="mps-segment__option">
                                            <input type="radio" class="mps-choice__input" name="auto_release" value="1" @checked($autoRelease) data-mps-mode>
                                            <span class="mps-segment__face">@lang('Instant')</span>
                                        </label>
                                        <label class="mps-segment__option">
                                            <input type="radio" class="mps-choice__input" name="auto_release" value="0" @checked(! $autoRelease) data-mps-mode>
                                            <span class="mps-segment__face">@lang('Hold')</span>
                                        </label>
                                    </div>
                                    <span class="mps-hint" data-mps-mode-help
                                          data-instant="{{ __('The share is available the moment the order is confirmed.') }}"
                                          data-hold="{{ __('The share is reserved until the window ends.') }}">
                                        {{ $autoRelease ? __('The share is available the moment the order is confirmed.') : __('The share is reserved until the window ends.') }}
                                    </span>
                                    @error('auto_release')<span class="mps-error">{{ $message }}</span>@enderror
                                </div>

                                <div class="mps-field" data-mps-hold-field data-disabled="{{ $autoRelease ? 'true' : 'false' }}">
                                    <label for="payout_hold_minutes">@lang('Hold duration')</label>
                                    <div class="mps-input @error('payout_hold_minutes') is-invalid @enderror">
                                        <input type="number" name="payout_hold_minutes" id="payout_hold_minutes"
                                               value="{{ $holdMinutes }}" inputmode="numeric" placeholder="0" data-mps-hold
                                               data-units="{{ __('days') }}|{{ __('hours') }}|{{ __('minutes') }}"
                                               @error('payout_hold_minutes') aria-invalid="true" aria-describedby="payout_hold_minutes_error" @enderror>
                                        <span class="mps-input__suffix">@lang('min')</span>
                                    </div>
                                    <div class="mps-presets">
                                        <button type="button" class="mps-preset" data-mps-preset="60">@lang('1 hour')</button>
                                        <button type="button" class="mps-preset" data-mps-preset="1440">@lang('24 hours')</button>
                                        <button type="button" class="mps-preset" data-mps-preset="10080">@lang('7 days')</button>
                                        <button type="button" class="mps-preset" data-mps-preset="43200">@lang('30 days')</button>
                                    </div>
                                    <span class="mps-hint">@lang('0 to 43,200 minutes (30 days). Used when payout mode is Hold.')</span>
                                    @error('payout_hold_minutes')<span class="mps-error" id="payout_hold_minutes_error">{{ $message }}</span>@enderror
                                </div>

                                <div class="mps-result">
                                    <span class="mps-result__caption">@lang('Result')</span>
                                    <b data-mps-result-verb
                                       data-instant="{{ __('Released immediately') }}"
                                       data-hold="{{ __('Held for :duration') }}">
                                        {{ $autoRelease ? __('Released immediately') : __('Held for :duration', ['duration' => $holdHuman($holdMinutes)]) }}
                                    </b>
                                    <span data-mps-result-note
                                          data-wallet-instant="{{ __('Credited to the vendor wallet as soon as the gateway confirms.') }}"
                                          data-wallet-hold="{{ __('Reserved in the vendor wallet, then released automatically.') }}"
                                          data-stripe-instant="{{ __('Transferred to the connected Stripe account on confirmation.') }}"
                                          data-stripe-hold="{{ __('Transferred to the connected Stripe account once the window ends.') }}">
                                        @if($splitProvider === 'stripe_connect')
                                            {{ $autoRelease ? __('Transferred to the connected Stripe account on confirmation.') : __('Transferred to the connected Stripe account once the window ends.') }}
                                        @else
                                            {{ $autoRelease ? __('Credited to the vendor wallet as soon as the gateway confirms.') : __('Reserved in the vendor wallet, then released automatically.') }}
                                        @endif
                                    </span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="mps-panel" data-mps-panel="custody" data-ss-section="{{ $panels['custody'][0] }}" aria-label="{{ __('Commission account') }}" @if($activeSection !== 'custody') hidden @endif>
                        <div class="mps-card">
                            <div class="mps-card__head">
                                <div class="mps-card__title">
                                    <h3>@lang('Where commission is held')</h3>
                                    <span>@lang("A platform-owned wallet identity, not a person's account")</span>
                                </div>
                                <span class="mps-pill {{ $commissionAccountIsProtected ? 'mps-pill--success' : 'mps-pill--warning' }}">
                                    <i class="fa-solid {{ $commissionAccountIsProtected ? 'fa-lock' : 'fa-triangle-exclamation' }}" aria-hidden="true"></i>
                                    {{ $commissionAccountIsProtected ? __('Enterprise protected') : __('Action required') }}
                                </span>
                            </div>

                            <div class="mps-custody {{ $commissionAccountIsProtected ? 'mps-custody--ready' : 'mps-custody--attention' }}">
                                <div class="mps-custody__identity">
                                    <span class="mps-custody__avatar" aria-hidden="true">
                                        <i class="fa-solid {{ $commissionAccountIsProtected ? 'fa-wallet' : 'fa-triangle-exclamation' }}"></i>
                                    </span>
                                    <div class="mps-custody__copy">
                                        <span class="mps-custody__name">
                                            @if($commissionAccountIsProtected)
                                                @lang('Protected platform account')
                                            @elseif($commissionUser)
                                                @lang('Legacy personal account detected')
                                            @else
                                                @lang('Protected account not configured')
                                            @endif
                                            @if($commissionUser)
                                                <span class="mps-custody__id" dir="ltr">#{{ $commissionUser->id }}</span>
                                            @endif
                                        </span>

                                        @if($commissionUser)
                                            @if($commissionAccountIsProtected)
                                                <span class="mps-custody__sub">@lang('Marketplace Commission') · @lang('Platform-owned wallet identity')</span>
                                                <span class="mps-custody__guards">
                                                    <span><i class="fa-solid fa-check" aria-hidden="true"></i> @lang('Login disabled')</span>
                                                    <span><i class="fa-solid fa-check" aria-hidden="true"></i> @lang('Admin impersonation blocked')</span>
                                                    <span><i class="fa-solid fa-check" aria-hidden="true"></i> @lang('Deletion protected')</span>
                                                </span>
                                            @else
                                                <span class="mps-custody__sub">{{ $commissionUser->name }} · {{ $commissionUser->email }}</span>
                                                <span class="mps-custody__sub">@lang('A real person can log in and spend commission funds.')</span>
                                            @endif
                                        @else
                                            <span class="mps-custody__sub">@lang('Commission-bearing purchases stay fail-closed until custody is configured.')</span>
                                        @endif
                                    </div>
                                </div>

                                @if($commissionAccountIsProtected)
                                    <a href="{{ route('admin.marketplace.settings.commission-account.show') }}" class="mps-custody__action">
                                        <i class="fa-solid fa-chart-line" aria-hidden="true"></i>
                                        <span>@lang('View balances & ledger')</span>
                                    </a>
                                @else
                                    <button type="submit" form="commission-account-provision-form" class="mps-custody__action mps-custody__action--primary">
                                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                                        <span>{{ $commissionUser ? __('Switch future commissions') : __('Create protected account') }}</span>
                                    </button>
                                @endif
                            </div>

                            <p class="mps-note">
                                <i class="fa-solid fa-lock" aria-hidden="true"></i>
                                <span>
                                    <b>@lang('Custody rule.')</b> @lang('Only future orders switch accounts. Existing balances are never moved automatically, and refunds reverse the wallet originally credited.')
                                </span>
                            </p>
                        </div>
                    </section>

                    {{-- ── Custody history ── --}}
                    <section class="mps-panel" data-mps-panel="history" data-ss-section="{{ $panels['history'][0] }}" aria-label="{{ __('Custody history') }}" @if($activeSection !== 'history') hidden @endif>
                        <div class="mps-card mps-card--flush">
                            @if($revisions->isEmpty())
                                <div class="mps-empty">
                                    <i class="fa-solid fa-clock" aria-hidden="true"></i>
                                    <b>@lang('No custody changes yet')</b>
                                    <span>@lang('Provisioning or switching the commission account writes a permanent entry here.')</span>
                                </div>
                            @else
                                <div class="table-responsive">
                                    <table class="mps-log">
                                        <thead>
                                            <tr>
                                                <th>@lang('When')</th>
                                                <th>@lang('Admin')</th>
                                                <th>@lang('Action')</th>
                                                <th>@lang('Account')</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($revisions as $revision)
                                                <tr>
                                                    <td>
                                                        <span class="mps-log__when">{{ $revision->created_at?->diffForHumans() }}</span>
                                                        <span class="mps-log__exact">{{ $revision->created_at?->toDayDateTimeString() }}</span>
                                                    </td>
                                                    <td>{{ $revision->admin?->name ?? __('System') }}</td>
                                                    <td><span class="mps-tag">{{ $revision->action }}</span></td>
                                                    <td class="mps-log__accounts" dir="ltr">
                                                        @if($revision->previous_commission_user_id)
                                                            <span class="mps-log__id">#{{ $revision->previous_commission_user_id }}</span>
                                                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                                        @endif
                                                        <span class="mps-log__id mps-log__id--new">#{{ $revision->new_commission_user_id }}</span>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </section>

                    {{-- ── Save bar ──
                         The admin's one save bar, from admin-savebar.css. It
                         is the same control on Site Settings and on P2P
                         Marketplace Settings, so it is declared once and this
                         page only writes its markup. --}}
                    <div class="ss-savebar" data-mps-savebar data-ss-savebar hidden
                         data-ss-in="{{ __('in') }}"
                         data-ss-nothing="{{ __('Nothing is applied until you save.') }}"
                         data-ss-unsaved-one="{{ __(':count unsaved change') }}"
                         data-ss-unsaved-many="{{ __(':count unsaved changes') }}"
                         @if(in_array($activeSection, ['custody', 'history'], true)) data-ss-inapplicable @endif>
                        <div class="ss-savebar__inner" role="status" aria-live="polite">
                            <span class="ss-savebar__icon" aria-hidden="true"><i class="fa-solid fa-pen"></i></span>
                            <span class="ss-savebar__copy">
                                <strong class="ss-savebar__title" data-ss-savebar-title>{{ __('Unsaved changes') }}</strong>
                                <span class="ss-savebar__detail" data-ss-savebar-detail>{{ __('Nothing is applied until you save.') }}</span>
                            </span>
                            <button type="button" class="ss-savebar__btn ss-savebar__btn--ghost" data-mps-reset>@lang('Discard')</button>
                            <button type="submit" class="ss-savebar__btn ss-savebar__btn--primary">
                                @lang('Save changes')
                                <kbd class="ss-savebar__kbd" aria-hidden="true">Ctrl S</kbd>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.marketplace.settings.commission-account.provision') }}" id="commission-account-provision-form">
            @csrf
        </form>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('backend/js/admin-savebar.js?v=' . config('app.version') . '-' . filemtime(public_path('backend/js/admin-savebar.js'))) }}"></script>
    <script src="{{ asset('backend/js/marketplace-settings.js?v=' . config('app.version') . '-' . filemtime(public_path('backend/js/marketplace-settings.js'))) }}"></script>
@endpush
