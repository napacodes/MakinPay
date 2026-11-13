@extends('backend.layouts.app')

@section('title', __('Feature Management'))

@push('styles')
    {{-- Versioned like every other hand-written sheet: the CDN in front of a
         server caches this path for hours, and an unversioned link kept
         serving the old file after a deploy. --}}
    <link rel="stylesheet" href="{{ asset('backend/css/feature-management.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/feature-management.css'))) }}">
@endpush

@section('content')
    @php
        use App\Models\FeatureAccessRule;

        /**
         * Category tints and glyphs, keyed by the catalog's own category keys.
         *
         * The labels and the order come from config/feature_catalog.php — the
         * source of truth for this layer — so a category added there appears
         * here with no change to this view. Only the presentation is local.
         */
        $categoryMeta = [
            'money_movement' => ['icon' => 'fa-wallet', 'bg' => 'var(--color-primary-soft)', 'fg' => 'var(--color-primary)'],
            'p2p'            => ['icon' => 'fa-handshake', 'bg' => 'var(--color-palette-fff1f2)', 'fg' => 'var(--color-rose)'],
            'business'       => ['icon' => 'fa-store', 'bg' => 'var(--tint-teal)', 'fg' => 'var(--color-teal)'],
            'cards'          => ['icon' => 'fa-credit-card', 'bg' => 'var(--tint-cyan)', 'fg' => 'var(--color-cyan)'],
            'engagement'     => ['icon' => 'fa-share-nodes', 'bg' => 'var(--tint-violet)', 'fg' => 'var(--color-violet)'],
        ];

        $featureIconMap = [
            'deposit_money'       => 'fa-wallet',
            'withdraw_money'      => 'fa-arrow-up',
            'send_money'          => 'fa-paper-plane',
            'request_money'       => 'fa-hand-holding-dollar',
            'exchange_money'      => 'fa-right-left',
            'wallet_earn'         => 'fa-chart-line',
            'mobile_recharge'     => 'fa-mobile-screen-button',
            'bill_payment'        => 'fa-receipt',
            'bank_transfer'       => 'fa-building-columns',
            'payment_link'        => 'fa-link',
            'merchant_payment'    => 'fa-store',
            'agent_program'       => 'fa-user-tie',
            'subscription_system' => 'fa-layer-group',
            'p2p_marketplace'     => 'fa-handshake',
            'virtual_card'        => 'fa-credit-card',
            'referral_program'    => 'fa-share-nodes',
            'user_ranks'          => 'fa-award',
            'vouchers'            => 'fa-gift',
            'remittance'          => 'fa-globe',
            'vendor_marketplace'  => 'fa-store',
            'gift_cards'          => 'fa-gift',
        ];

        $panelMeta = [
            /*
             * --color-primary-subtle reads like a pale fill and is not one:
             * role-branding-theme rewrites it at runtime to a mid-tone, so
             * the User chip drew indigo text on lavender. The pale wash the
             * reference asks for is the palette entry itself.
             */
            FeatureAccessRule::PANEL_USER     => ['label' => __('User'), 'icon' => 'fa-user', 'bg' => 'var(--color-palette-eef2ff)', 'fg' => 'var(--color-primary-hover)'],
            FeatureAccessRule::PANEL_MERCHANT => ['label' => __('Merchant'), 'icon' => 'fa-store', 'bg' => 'var(--tint-teal)', 'fg' => 'var(--color-palette-0f766e)'],
            FeatureAccessRule::PANEL_AGENT    => ['label' => __('Agent'), 'icon' => 'fa-user-tie', 'bg' => 'var(--color-palette-fff7ed)', 'fg' => 'var(--color-warning-deep)'],
        ];

        $catalogCategories = (array) config('feature_catalog.categories', []);

        /*
         * Decorated once. The rail, the filter counts and the cards all read
         * these same rows, so a feature cannot be counted in one place and
         * drawn differently in another.
         */
        $decorated = $features->map(function ($feature) use ($featureIconMap, $panelMeta, $effective, $featureAddon) {
            $isRole = config("feature_catalog.features.{$feature->key}.manage_mode") === 'role_toggle';

            $declaredPanels = (array) config("feature_catalog.features.{$feature->key}.panels", []);
            $rulePanels     = $feature->accessRules->pluck('panel')->all();

            /*
             * A panel with no saved rule falls back to what the catalog
             * declares — which is what FeatureManager does at runtime. Reading
             * only the saved rules would show every unedited feature as
             * hidden everywhere.
             */
            $catalogDefaults = collect($declaredPanels)
                ->filter(fn ($on, $panel): bool => (bool) $on && ! in_array($panel, $rulePanels, true))
                ->keys();

            $visiblePanels = $feature->accessRules
                ->where('is_visible', true)
                ->pluck('panel')
                ->merge($catalogDefaults)
                ->unique();

            $accessiblePanels = $feature->accessRules
                ->where('is_visible', true)
                ->where('is_accessible', true)
                ->pluck('panel')
                ->merge($catalogDefaults)
                ->unique();

            return (object) [
                'model'     => $feature,
                'isRole'    => $isRole,
                'isCore'    => (bool) $feature->is_core,
                'isEnabled' => (bool) ($effective[$feature->key] ?? $feature->is_enabled),
                'addon'     => $featureAddon[$feature->key] ?? null,
                'isLocked'  => (bool) ($featureAddon[$feature->key]['locked'] ?? false),
                'icon'      => $featureIconMap[$feature->key] ?? (str_starts_with((string) $feature->icon, 'fa-') ? $feature->icon : 'fa-sliders'),
                'panels'    => collect(array_keys($panelMeta))
                    ->filter(fn (string $panel): bool => array_key_exists($panel, $declaredPanels) || in_array($panel, $rulePanels, true))
                    ->map(fn (string $panel): array => [
                        'key'        => $panel,
                        'visible'    => $visiblePanels->contains($panel),
                        'accessible' => $accessiblePanels->contains($panel),
                    ])
                    ->values(),
            ];
        });

        $byCategory = $decorated->groupBy(fn ($row): string => (string) $row->model->category);

        $categories = collect($catalogCategories)
            ->map(fn (array $meta, string $key): array => $meta + ['key' => $key])
            ->sortBy('order')
            ->filter(fn (array $meta): bool => $byCategory->has($meta['key']))
            ->values();

        $requestedCategory = (string) request()->query('category', '');
        $activeCategory    = $categories->firstWhere('key', $requestedCategory)
            ? $requestedCategory
            : (string) ($categories->first()['key'] ?? '');

        $filterDefs = [
            'all'      => __('All'),
            'enabled'  => __('Enabled'),
            'disabled' => __('Disabled'),
            'core'     => __('Core'),
        ];
    @endphp

    {{--
        Every string the confirm dialog can show is written here, in the
        administrator's own locale. The script that fills it assembles no
        sentences of its own — it used to, and an Arabic admin got an English
        dialog on top of an Arabic page.
    --}}
    <div class="feature-mgmt-page feature-mgmt-page--catalog"
         data-feature-catalog
         data-active-category="{{ $activeCategory }}"
         data-title-on="{{ __('Turn on :feature?', ['feature' => ':feature']) }}"
         data-title-off="{{ __('Turn off :feature?', ['feature' => ':feature']) }}"
         data-text-on="{{ __('The feature becomes available according to its panel rules.') }}"
         data-text-off="{{ __('The feature will be hidden across all panels.') }}"
         data-text-off-core="{{ __('This is a core feature. Business-critical flows that depend on it will stop working.') }}"
         data-text-off-role="{{ __('Every surface for this role — registration, login and dashboard — will be hidden.') }}"
         data-impact-on="{{ __('Menus, widgets and guarded routes follow this switch immediately.') }}"
         data-impact-off="{{ __('Menus, widgets and guarded routes stop responding immediately for all users.') }}"
         data-btn-on="{{ __('Turn on') }}"
         data-btn-off="{{ __('Turn off') }}">

        @php
            /*
             * The reference names an actor here ("by Admin"). We do not
             * store one: the features table carries only updated_at, so the
             * line says when and stops rather than naming somebody it
             * cannot know.
             */
            $lastChange = $features->max('updated_at');

            $enabledPercent = $summary['total'] > 0
                ? (int) round($summary['enabled'] / $summary['total'] * 100)
                : 0;

            /*
             * The overview bar is a partition of every feature, so its
             * segments sum to the total and each one carries the colour of
             * the legend swatch that counts it.
             *
             * Core features and role switches are slices *of* the enabled
             * set, not siblings of it — 20 enabled already includes the 2
             * core and the 1 role switch. Laying all four legend numbers
             * side by side would count those three twice and overrun the
             * bar, so they are drawn inside the enabled run instead.
             *
             * Zero-count segments are dropped rather than sized to nothing:
             * the bar has a 3px gap, and an empty segment still takes one.
             */
            $enabledRows = $decorated->where('isEnabled', true);

            $overviewSegments = collect([
                ['key' => 'on', 'count' => $enabledRows->filter(fn ($row): bool => ! $row->isCore && ! $row->isRole)->count()],
                ['key' => 'core', 'count' => $enabledRows->where('isCore', true)->count()],
                ['key' => 'role', 'count' => $enabledRows->filter(fn ($row): bool => $row->isRole && ! $row->isCore)->count()],
                ['key' => 'off', 'count' => $decorated->where('isEnabled', false)->count()],
            ])->filter(fn (array $segment): bool => $segment['count'] > 0)->values();
        @endphp

        {{--
            The header says what this module is and whether the catalog is
            whole. What the catalog contains — the enabled ratio and the
            disabled, core and role-switch counts — is the rail's overview
            card, beside the category rows it describes.
        --}}
        <x-page-header
            :title="__('Feature Management')"
            :eyebrow="__('System config')"
            :subtitle="__('Control what users, merchants and agents can see and use — from one place.')"
            icon="layer"
            tile="soft"
            {{--
                The pill states the catalog's real state. Board 2c shows
                "All systems live", which is only true while nothing is
                switched off — a fixed success pill above three disabled
                features would be the header lying about the page.
            --}}
            :status="(int) $summary['disabled'] === 0
                ? ['tone' => 'success', 'label' => __('All systems live')]
                : ['tone' => 'warning', 'label' => __(':count disabled', ['count' => number_format((int) $summary['disabled'])])]"
            {{--
                No stats strip. These four figures live in the rail's overview
                card, where this page had them before the migration — the rail
                is a column of five category rows and nothing else, so taking
                its card away left it looking half-built while the header grew
                a strip it did not need.
            --}}
        >
            {{--
                No actions and no ⋯, and neither is an oversight.

                "Sync catalog" linked to this very page, and
                FeatureManagementController::index() calls sync() on every
                load — so the button did what arriving here had already done,
                while quietly dropping the active category from the URL. The ⋯
                held one item, a link to the activity log, which has its own
                entry in the admin menu and a tile on the control panel.

                The page's real controls are the switches below.

                The Add-ons tab is the exception: installing one is the
                tab's own primary action, so it gets the header's one
                primary button - and only there, for an admin who may.
            --}}
            @if($tab === 'addons' && auth('admin')->user()?->can('addon-manage'))
                <x-slot:actions>
                    <button type="button" class="btn btn-primary" data-addon-install>
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>{{ __('Install add-on') }}
                    </button>
                </x-slot:actions>
            @endif
        </x-page-header>

        {{--
            Links, not script tabs: the tab lives in the URL, so a feature card
            can deep-link to its add-on and the browser's back button works.
            The category travels with the Features tab.
        --}}
        <nav class="ph-tabs" aria-label="{{ __('Feature management sections') }}">
            <a href="{{ route('admin.features.index', ['tab' => 'features', 'category' => request()->query('category')]) }}"
               class="ph-tab"
               @if($tab === 'features') aria-current="page" @endif>
                {{ __('Features') }}
                <span class="ph-tab__count" dir="ltr">{{ number_format($summary['total']) }}</span>
            </a>
            <a href="{{ route('admin.features.index', ['tab' => 'addons']) }}"
               class="ph-tab"
               data-addon-tab
               @if($tab === 'addons') aria-current="page" @endif>
                {{ __('Add-ons') }}
                <span class="ph-tab__count" dir="ltr">{{ number_format($addonSummary['total']) }}</span>
                @if($addonSummary['attention'] > 0)
                    <span class="ph-tab__count ph-tab__count--warning"
                          title="{{ trans_choice(':count add-on needs attention|:count add-ons need attention', $addonSummary['attention'], ['count' => $addonSummary['attention']]) }}">
                        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span dir="ltr">{{ $addonSummary['attention'] }}</span>
                    </span>
                @endif
            </a>
        </nav>

        @if($tab === 'addons')
            @include('backend.feature_management._addons_catalog')
        @elseif($features->isNotEmpty())
            <div class="feature-mgmt-workspace">
                <nav class="feature-mgmt-rail" aria-label="{{ __('Feature categories') }}">
                    <div class="feature-mgmt-cats" role="tablist">
                        @foreach($categories as $category)
                            @php
                                $rows      = $byCategory->get($category['key'], collect());
                                $meta      = $categoryMeta[$category['key']] ?? ['icon' => 'fa-sliders', 'bg' => 'var(--color-surface-soft)', 'fg' => 'var(--color-text-subtle)'];
                                $catOn     = $rows->where('isEnabled', true)->count();
                                $catOff    = $rows->count() - $catOn;
                                $isCurrent = $activeCategory === $category['key'];
                            @endphp
                            <button type="button"
                                    class="feature-mgmt-cat {{ $isCurrent ? 'is-active' : '' }}"
                                    role="tab"
                                    data-feature-category="{{ $category['key'] }}"
                                    aria-selected="{{ $isCurrent ? 'true' : 'false' }}"
                                    style="--fm-cat-bg: {{ $meta['bg'] }}; --fm-cat-fg: {{ $meta['fg'] }};">
                                <span class="feature-mgmt-cat__icon" aria-hidden="true"><i class="fa-solid {{ $meta['icon'] }}"></i></span>
                                <span class="feature-mgmt-cat__copy">
                                    <span class="feature-mgmt-cat__label">{{ __($category['label']) }}</span>
                                    <span class="feature-mgmt-cat__meta">{{ $catOff > 0
                                        ? __(':on enabled · :off off', ['on' => $catOn, 'off' => $catOff])
                                        : __(':on enabled', ['on' => $catOn]) }}</span>
                                </span>
                                <span class="feature-mgmt-cat__count" dir="ltr">{{ $rows->count() }}</span>
                            </button>
                        @endforeach
                    </div>

                    {{--
                        The overview card, as it was before this page moved to
                        <x-page-header>. The header states what the module is;
                        this states where the catalog stands, beside the rows
                        it describes.
                    --}}
                    <div class="feature-mgmt-overview">
                        <div class="feature-mgmt-overview__head">
                            <span class="feature-mgmt-overview__eyebrow">{{ __('Overview') }}</span>
                            <span class="feature-mgmt-overview__live">
                                <span class="feature-mgmt-overview__live-dot" aria-hidden="true"></span>{{ __('Live') }}
                            </span>
                        </div>

                        <div class="feature-mgmt-overview__figure">
                            <span class="feature-mgmt-overview__count">
                                <span class="feature-mgmt-overview__value" dir="ltr">{{ $summary['enabled'] }}<span class="feature-mgmt-overview__total">/{{ $summary['total'] }}</span></span>
                                <span class="feature-mgmt-overview__label">{{ __('features enabled') }}</span>
                            </span>
                            <span class="feature-mgmt-overview__pct" dir="ltr">{{ $enabledPercent }}%</span>
                        </div>

                        <span class="feature-mgmt-overview__bar" aria-hidden="true">
                            @foreach($overviewSegments as $segment)
                                <span class="feature-mgmt-overview__seg feature-mgmt-overview__seg--{{ $segment['key'] }}"
                                      style="flex: {{ $segment['count'] }}"></span>
                            @endforeach
                        </span>

                        <div class="feature-mgmt-overview__legend">
                            <span class="feature-mgmt-overview__row">
                                <span><span class="feature-mgmt-overview__swatch feature-mgmt-overview__swatch--on" aria-hidden="true"></span>{{ __('Enabled') }}</span>
                                <span class="feature-mgmt-overview__num" dir="ltr">{{ $summary['enabled'] }}</span>
                            </span>
                            <span class="feature-mgmt-overview__row">
                                <span><span class="feature-mgmt-overview__swatch" aria-hidden="true"></span>{{ __('Disabled') }}</span>
                                <span class="feature-mgmt-overview__num feature-mgmt-overview__num--off" dir="ltr">{{ $summary['disabled'] }}</span>
                            </span>
                            <span class="feature-mgmt-overview__row">
                                <span><span class="feature-mgmt-overview__swatch feature-mgmt-overview__swatch--core" aria-hidden="true"></span>{{ __('Core features') }}</span>
                                <span class="feature-mgmt-overview__num feature-mgmt-overview__num--core" dir="ltr">{{ $summary['core'] }}</span>
                            </span>
                            <span class="feature-mgmt-overview__row">
                                <span><span class="feature-mgmt-overview__swatch feature-mgmt-overview__swatch--role" aria-hidden="true"></span>{{ __('Role switches') }}</span>
                                <span class="feature-mgmt-overview__num feature-mgmt-overview__num--role" dir="ltr">{{ $summary['roles'] }}</span>
                            </span>
                        </div>

                        {{--
                            The reference names an actor here ("by Admin"). We
                            do not store one: the features table carries only
                            updated_at, so the line says when and stops rather
                            than naming somebody it cannot know.
                        --}}
                        <span class="feature-mgmt-overview__foot">
                            {{ $lastChange
                                ? __('Last change · :when', ['when' => $lastChange->diffForHumans()])
                                : __('Every change takes effect immediately.') }}
                        </span>
                    </div>
                </nav>

                <section class="feature-mgmt-catalog-panel">
                    @foreach($categories as $category)
                        @php
                            $meta      = $categoryMeta[$category['key']] ?? ['icon' => 'fa-sliders', 'bg' => 'var(--color-surface-soft)', 'fg' => 'var(--color-text-subtle)'];
                            $isCurrent = $activeCategory === $category['key'];
                        @endphp
                        <header class="feature-mgmt-catalog-panel__header"
                                data-feature-category-head="{{ $category['key'] }}"
                                @unless($isCurrent) hidden @endunless>
                            <div class="feature-mgmt-catalog-panel__identity">
                                <span class="feature-mgmt-catalog-panel__icon" aria-hidden="true"
                                      style="--fm-cat-bg: {{ $meta['bg'] }}; --fm-cat-fg: {{ $meta['fg'] }};">
                                    <i class="fa-solid {{ $meta['icon'] }}"></i>
                                </span>
                                <div class="feature-mgmt-catalog-panel__copy">
                                    <h2 class="feature-mgmt-catalog-panel__title">{{ __($category['label']) }}</h2>
                                    <p class="feature-mgmt-catalog-panel__desc"
                                       title="{{ __($category['description'] ?? '') }}">{{ __($category['description'] ?? '') }}</p>
                                </div>
                            </div>

                            <div class="feature-mgmt-catalog-panel__tools">
                                <label class="feature-mgmt-search">
                                    <i class="fa-solid fa-magnifying-glass feature-mgmt-search__icon" aria-hidden="true"></i>
                                    <input type="search"
                                           @if($isCurrent) id="featureSearchInput" @endif
                                           class="feature-mgmt-search__input"
                                           data-feature-search-input
                                           placeholder="{{ __('Search features') }}"
                                           aria-label="{{ __('Search features') }}"
                                           autocomplete="off">
                                </label>

                                <div class="feature-mgmt-filters" role="group" aria-label="{{ __('Filter by state') }}">
                                    @foreach($filterDefs as $filterKey => $filterLabel)
                                        <button type="button"
                                                class="feature-mgmt-filter {{ $filterKey === 'all' ? 'is-active' : '' }}"
                                                data-feature-filter="{{ $filterKey }}"
                                                aria-pressed="{{ $filterKey === 'all' ? 'true' : 'false' }}">
                                            {{ $filterLabel }}<span class="feature-mgmt-filter__count" dir="ltr" data-feature-filter-count="{{ $filterKey }}">0</span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        </header>
                    @endforeach

                    <div class="feature-mgmt-grid feature-mgmt-grid--flat" data-feature-grid>
                        @foreach($decorated as $row)
                            @php
                                $feature  = $row->model;
                                $catTint  = $categoryMeta[$feature->category] ?? ['bg' => 'var(--color-surface-soft)', 'fg' => 'var(--color-text-subtle)'];
                                $stateKey = $row->isEnabled ? 'enabled' : 'disabled';
                            @endphp
                            <article class="feature-mgmt-item feature-mgmt-item--flat {{ $row->isRole ? 'feature-mgmt-role-card' : '' }} {{ $row->isEnabled ? '' : 'is-disabled' }} {{ $row->isLocked ? 'is-locked' : '' }}"
                                     id="card-{{ $feature->key }}"
                                     data-feature-card
                                     data-feature-category="{{ $feature->category }}"
                                     data-feature-state="{{ $stateKey }}"
                                     data-feature-core="{{ $row->isCore ? '1' : '0' }}"
                                     data-feature-search="{{ strtolower($feature->key.' '.$feature->label.' '.$feature->description) }}"
                                     style="--fm-cat-bg: {{ $catTint['bg'] }}; --fm-cat-fg: {{ $catTint['fg'] }};">
                                <div class="feature-mgmt-item__head">
                                    <div class="feature-mgmt-item__identity">
                                        <span class="feature-mgmt-title-icon" aria-hidden="true">
                                            <i class="fa-solid {{ $row->icon }}"></i>
                                        </span>
                                        <div class="feature-mgmt-item__names">
                                            <h4 class="feature-mgmt-item__title">
                                                <span class="feature-mgmt-item__title-text">{{ __($feature->label) }}</span>
                                                @if($row->isCore)
                                                    <span class="feature-mgmt-badge feature-mgmt-badge--warning"
                                                          title="{{ __('Disabling affects business-critical flows') }}">
                                                        <i class="fa-solid fa-certificate" aria-hidden="true"></i>{{ __('CORE') }}
                                                    </span>
                                                @endif
                                                @if($row->isRole)
                                                    <span class="feature-mgmt-badge feature-mgmt-badge--role">{{ __('ROLE') }}</span>
                                                @endif
                                                @if($row->addon)
                                                    <a class="feature-mgmt-badge feature-mgmt-badge--brand feature-mgmt-badge--addon"
                                                       href="{{ route('admin.features.index', ['tab' => 'addons', 'addon' => $row->addon['slug']]) }}"
                                                       title="{{ __('Comes with the :name add-on', ['name' => $row->addon['name']]) }}">
                                                        <i class="fa-solid fa-puzzle-piece" aria-hidden="true"></i>{{ __('Add-on · :name', ['name' => $row->addon['name']]) }}
                                                    </a>
                                                @endif
                                                @if($row->isLocked)
                                                    <span class="feature-mgmt-badge feature-mgmt-badge--neutral">
                                                        <i class="fa-solid fa-lock" aria-hidden="true"></i>{{ __('LOCKED') }}
                                                    </span>
                                                @endif
                                            </h4>
                                            <span class="feature-mgmt-item__key" dir="ltr">{{ $feature->key }}</span>
                                        </div>
                                    </div>

                                    <form action="{{ route('admin.features.toggle', $feature) }}"
                                          method="POST"
                                          class="feature-mgmt-toggle-form feature-mgmt-card-switch-form"
                                          data-feature-label="{{ __($feature->label) }}"
                                          @if($row->isRole) data-feature-kind="role" @endif
                                          @if($row->addon) data-addon-note="{{ __('Part of the :name add-on.', ['name' => $row->addon['name']]) }}" @endif
                                          data-is-core="{{ $row->isCore ? '1' : '0' }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_enabled" value="0">
                                        {{-- The switch says it. A word beside it said it twice. --}}
                                        <label class="feature-mgmt-sw feature-mgmt-card-switch {{ $row->isLocked ? 'is-disabled' : '' }}"
                                               aria-label="{{ __('Toggle :feature', ['feature' => __($feature->label)]) }}"
                                               @if($row->isLocked) title="{{ $row->addon['reason'] }}" @endif>
                                            <input class="feature-mgmt-sw__input feature-mgmt-switch__input"
                                                   type="checkbox"
                                                   role="switch"
                                                   name="is_enabled"
                                                   value="1"
                                                   @if($row->isLocked) disabled aria-describedby="lock-{{ $feature->key }}" @endif
                                                   {{ $row->isEnabled ? 'checked' : '' }}>
                                            <span class="feature-mgmt-sw__track" aria-hidden="true"></span>
                                        </label>
                                    </form>
                                </div>

                                <p class="feature-mgmt-item__description">{{ __($feature->description) }}</p>

                                <div class="feature-mgmt-item__footer">
                                    @if($row->isLocked)
                                        <span class="feature-mgmt-note feature-mgmt-note--danger" id="lock-{{ $feature->key }}">
                                            <i class="fa-solid fa-lock" aria-hidden="true"></i><span>{{ $row->addon['reason'] }}</span>
                                        </span>
                                        <a href="{{ route('admin.features.index', ['tab' => 'addons', 'addon' => $row->addon['slug']]) }}" class="feature-mgmt-manage-btn">
                                            {{ $row->addon['cta'] }}<i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                        </a>
                                    @else
                                    <div class="feature-mgmt-item__panels">
                                        @if($row->isRole)
                                            <span class="feature-mgmt-panel-chip feature-mgmt-panel-chip--role">
                                                <i class="fa-solid fa-lock" aria-hidden="true"></i>{{ __('On / off only') }}
                                            </span>
                                        @else
                                            @foreach($row->panels as $panel)
                                                @php
                                                    $pm    = $panelMeta[$panel['key']];
                                                    $state = ! $panel['visible']
                                                        ? __(':panel: hidden', ['panel' => $pm['label']])
                                                        : ($panel['accessible']
                                                            ? __(':panel: visible & accessible', ['panel' => $pm['label']])
                                                            : __(':panel: visible, blocked', ['panel' => $pm['label']]));
                                                @endphp
                                                <span class="feature-mgmt-panel-chip {{ $panel['visible'] ? '' : 'feature-mgmt-panel-chip--muted' }}"
                                                      title="{{ $state }}"
                                                      @if($panel['visible']) style="--fm-panel-bg: {{ $pm['bg'] }}; --fm-panel-fg: {{ $pm['fg'] }};" @endif>
                                                    <i class="fa-solid {{ $pm['icon'] }}" aria-hidden="true"></i>{{ $pm['label'] }}
                                                </span>
                                            @endforeach
                                        @endif
                                    </div>

                                    @unless($row->isRole)
                                        <a href="{{ route('admin.features.edit', $feature) }}"
                                           class="feature-mgmt-manage-btn"
                                           title="{{ __('Manage') }}">
                                            {{ __('Manage') }}<i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                        </a>
                                    @endunless
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div class="feature-mgmt-empty" id="featureSearchEmpty" data-feature-empty hidden>
                        <span class="feature-mgmt-empty__icon" aria-hidden="true"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <span class="feature-mgmt-empty__title">{{ __('No matching features') }}</span>
                        <span class="feature-mgmt-empty__body">{{ __('Try a different keyword or clear the filter.') }}</span>
                        <button type="button" class="feature-mgmt-empty__action" data-feature-clear>{{ __('Clear filters') }}</button>
                    </div>
                </section>
            </div>
        @else
            <x-admin-not-found
                :title="__('No features registered yet')"
                :message="__('Run the feature seeder or update the feature catalog to register platform controls.')"
                icon="fa-sliders"
            />
        @endif
    </div>

    {{-- Confirmation modal, re-used by every inline toggle. --}}
    <div class="feature-mgmt-modal-backdrop" id="featureConfirmBackdrop"></div>
    <div class="feature-mgmt-modal" id="featureConfirmModal" aria-hidden="true">
        <div class="feature-mgmt-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="featureConfirmTitle">
            <button type="button" class="feature-mgmt-modal__close" data-feature-dismiss aria-label="{{ __('Close') }}">
                <i class="fa-solid fa-xmark"></i>
            </button>

            <div class="feature-mgmt-modal__header">
                <div class="feature-mgmt-modal__icon" aria-hidden="true">
                    <i class="fa-solid fa-triangle-exclamation feature-mgmt-modal__icon-glyph feature-mgmt-modal__icon-glyph--danger"></i>
                    <i class="fa-solid fa-bolt feature-mgmt-modal__icon-glyph feature-mgmt-modal__icon-glyph--success"></i>
                </div>
                <span class="feature-mgmt-modal__eyebrow" id="featureConfirmEyebrow">{{ __('Feature Control') }}</span>
            </div>

            <div class="feature-mgmt-modal__body">
                <h3 class="feature-mgmt-modal__title" id="featureConfirmTitle"></h3>
                <p class="feature-mgmt-modal__text" id="featureConfirmText"></p>
            </div>

            <div class="feature-mgmt-modal__impact">
                <span class="feature-mgmt-modal__impact-dot" aria-hidden="true"></span>
                <div class="feature-mgmt-modal__impact-copy">
                    <span class="feature-mgmt-modal__impact-label">{{ __('Effect') }}</span>
                    <span id="featureConfirmImpact"></span>
                </div>
            </div>

            <div class="feature-mgmt-modal__actions">
                <button type="button" class="feature-mgmt-btn feature-mgmt-btn--ghost" data-feature-dismiss>
                    {{ __('Cancel') }}
                </button>
                <button type="button" class="feature-mgmt-btn feature-mgmt-btn--primary" id="featureConfirmSubmit">
                    <i class="fa-solid fa-check feature-mgmt-btn__icon" id="featureConfirmSubmitIcon" aria-hidden="true"></i>
                    <span id="featureConfirmSubmitLabel"></span>
                </button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('backend/js/feature-management.js') }}"></script>
@endpush
