@extends('backend.settings.index')
@section('setting_content')
    @php
        use App\Models\Plugin;

        /**
         * Group meta, in the order the rail lists them.
         *
         * Keys are the existing `plugins.type` values; only the wording and the
         * tint are new. `icon` is a Font Awesome solid class, each one checked
         * against the bundled webfont subset — that stylesheet defines far more
         * classes than the shipped woff2 has glyphs for, and an absent one lays
         * out as a blank tile rather than failing.
         */
        $pluginGroupMeta = [
            'general' => [
                'label' => __('Core services'),
                'description' => __('Security, analytics and chat widgets used across the platform.'),
                'icon' => 'fa-plug',
                'bg' => 'var(--color-primary-soft)',
                'fg' => 'var(--color-primary)',
            ],
            'notification' => [
                'label' => __('Notifications'),
                'description' => __('Realtime, SMS and messaging channels for alerts.'),
                'icon' => 'fa-bell',
                'bg' => 'var(--tint-violet)',
                'fg' => 'var(--color-violet)',
            ],
            'exchange_rate' => [
                'label' => __('Exchange rates'),
                'description' => __('Market data feeds that refresh conversion rates.'),
                'icon' => 'fa-right-left',
                'bg' => 'var(--tint-teal)',
                'fg' => 'var(--color-teal)',
            ],
            'mobile_recharge' => [
                'label' => __('Mobile recharge'),
                'description' => __('Airtime and top-up providers for Mobile Recharge.'),
                /* fa-mobile-screen is defined in the stylesheet and absent
                   from the shipped webfont subset, so it laid out as an empty
                   tile. Checked at 36px against a glyph known to draw. */
                'icon' => 'fa-mobile-screen-button',
                'bg' => 'var(--tint-warning)',
                'fg' => 'var(--color-warning-deep)',
            ],
            'bill_payment' => [
                'label' => __('Bill payments'),
                'description' => __('API providers that pay electricity, TV and other bills.'),
                'icon' => 'fa-receipt',
                'bg' => 'var(--tint-success)',
                'fg' => 'var(--color-success-deep)',
            ],
        ];

        $groupedPlugins = $plugins->groupBy('type');
        $pluginGroups = collect(array_keys($pluginGroupMeta))
            ->merge($groupedPlugins->keys())
            ->unique()
            ->mapWithKeys(fn (string $type): array => [$type => $groupedPlugins->get($type, collect())])
            ->filter(fn ($groupPlugins): bool => $groupPlugins->isNotEmpty());
        $activePluginType = $selectedPluginType && $pluginGroups->has($selectedPluginType)
            ? $selectedPluginType
            : $pluginGroups->keys()->first();

        /*
         * Three counts, not two. "Inactive" used to mean both "switched off on
         * purpose" and "never had its keys typed in", and which of the two it
         * is happens to be the only question this page is ever opened to
         * answer.
         */
        $activeCount = $plugins->where('state', Plugin::STATE_ACTIVE)->count();
        $setupCount  = $plugins->where('state', Plugin::STATE_SETUP)->count();
        $offCount    = $plugins->where('state', Plugin::STATE_OFF)->count();
        $totalCount  = $plugins->count();
        $activePct   = $totalCount > 0 ? (int) round($activeCount / $totalCount * 100) : 0;
        $lastChecked = $plugins->max('last_tested_at');

        $filterDefs = [
            'all' => __('All'),
            Plugin::STATE_ACTIVE => __('Active'),
            Plugin::STATE_SETUP => __('Needs setup'),
            Plugin::STATE_OFF => __('Off'),
        ];

        $stateLabels = [
            Plugin::STATE_ACTIVE => __('Active'),
            Plugin::STATE_SETUP => __('Needs setup'),
            Plugin::STATE_OFF => __('Off'),
        ];
    @endphp

    <div class="settings-plugin-page"
         data-settings-plugin
         data-toggle-url="{{ route('admin.settings.plugin.toggle', ['plugin' => 0]) }}"
         data-label-on="{{ __('On') }}"
         data-label-off="{{ __('Off') }}"
         data-label-active="{{ $stateLabels[Plugin::STATE_ACTIVE] }}"
         data-label-off-state="{{ $stateLabels[Plugin::STATE_OFF] }}"
         data-msg-toggle-failed="{{ __('Could not change that integration. Please try again.') }}">

        {{--
            The banner's two decorative glows are gone with it. The handoff
            put "Test all" and "Add integration" here and neither is wired:
            plugins arrive from CorePluginSeeder and MobileRechargePluginSeeder
            so there is no route that creates one, and a test-all button would
            have to test from stored credentials, which deliberately never
            reach this page — it could only ever submit blanks and report
            every provider as failing.
        --}}
        <x-page-header
            :title="__('Integration Center')"
            :subtitle="__('Connect providers, manage credentials and switch services on or off.')"
            icon="plugins"
            :status="$setupCount > 0
                ? ['tone' => 'warning', 'label' => trans_choice('{1}:count needs setup|[2,*]:count need setup', $setupCount, ['count' => number_format($setupCount)])]
                : null"
        />

        @if($setupCount > 0)
            <div class="settings-plugin-attention" data-plugin-attention>
                <span class="settings-plugin-attention__tile" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <span class="settings-plugin-attention__text">
                    {!! __(':count — credentials are missing or still on placeholder values.', [
                        'count' => '<strong>'.e(trans_choice('{1}:count integration needs setup|[2,*]:count integrations need setup', $setupCount, ['count' => number_format($setupCount)])).'</strong>',
                    ]) !!}
                </span>
                <button type="button" class="settings-plugin-attention__action" data-plugin-show-setup>
                    {{ __('Show them') }}<i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </button>
            </div>
        @endif

        @if($plugins->isNotEmpty())
            <div class="settings-plugin-workspace">
                <nav class="settings-plugin-rail" aria-label="{{ __('Integration groups') }}">
                    <div class="settings-plugin-category-shell">
                        <div class="settings-plugin-category-nav nav nav-pills" id="plugin-group-tabs" role="tablist">
                            @foreach($pluginGroups as $type => $groupPlugins)
                                @php
                                    $groupMeta = $pluginGroupMeta[$type] ?? [
                                        'label' => __(str($type)->replace('_', ' ')->title()->toString()),
                                        'description' => __('Additional integrations installed for this platform.'),
                                        'icon' => 'fa-plug',
                                        'bg' => 'var(--color-surface-soft)',
                                        'fg' => 'var(--color-text-subtle)',
                                    ];
                                    $isActiveGroup = $activePluginType === $type;
                                    $groupSlug = str($type)->slug();
                                    $groupActive = $groupPlugins->where('state', Plugin::STATE_ACTIVE)->count();
                                    $groupSetup = $groupPlugins->where('state', Plugin::STATE_SETUP)->count();
                                @endphp
                                <button class="settings-plugin-category-tab nav-link {{ $isActiveGroup ? 'active' : '' }}"
                                        id="plugin-group-{{ $groupSlug }}-tab"
                                        data-coreui-toggle="pill"
                                        data-coreui-target="#plugin-group-{{ $groupSlug }}"
                                        data-plugin-group="{{ $groupSlug }}"
                                        type="button"
                                        role="tab"
                                        aria-controls="plugin-group-{{ $groupSlug }}"
                                        aria-selected="{{ $isActiveGroup ? 'true' : 'false' }}"
                                        style="--plugin-group-bg: {{ $groupMeta['bg'] }}; --plugin-group-fg: {{ $groupMeta['fg'] }};">
                                    <span class="settings-plugin-category-tab__icon" aria-hidden="true">
                                        <i class="fa-solid {{ $groupMeta['icon'] }}"></i>
                                    </span>
                                    <span class="settings-plugin-category-tab__copy">
                                        <span>{{ $groupMeta['label'] }}</span>
                                        <small>{{ $groupSetup > 0
                                            ? __(':active active · :need need setup', ['active' => $groupActive, 'need' => $groupSetup])
                                            : __(':active active', ['active' => $groupActive]) }}</small>
                                    </span>
                                    <span class="settings-plugin-category-tab__count" dir="ltr">{{ $groupPlugins->count() }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="settings-plugin-overview">
                        <div class="settings-plugin-overview__head">
                            <span class="settings-plugin-overview__eyebrow">{{ __('Overview') }}</span>
                            <span class="settings-plugin-overview__live">
                                <span class="settings-plugin-overview__live-dot" aria-hidden="true"></span>{{ __('Live') }}
                            </span>
                        </div>

                        <div class="settings-plugin-overview__figure">
                            <span class="settings-plugin-overview__count">
                                <span class="settings-plugin-overview__value" dir="ltr"><span data-plugin-active-count>{{ $activeCount }}</span><span class="settings-plugin-overview__total">/{{ $totalCount }}</span></span>
                                <span class="settings-plugin-overview__label">{{ __('integrations active') }}</span>
                            </span>
                            <span class="settings-plugin-overview__pct" dir="ltr" data-plugin-active-pct>{{ $activePct }}%</span>
                        </div>

                        <span class="settings-plugin-overview__bar" aria-hidden="true">
                            <span class="settings-plugin-overview__seg settings-plugin-overview__seg--active" style="flex: {{ $activeCount }}" data-plugin-seg="active"></span>
                            <span class="settings-plugin-overview__seg settings-plugin-overview__seg--setup" style="flex: {{ $setupCount }}" data-plugin-seg="setup"></span>
                            <span class="settings-plugin-overview__seg settings-plugin-overview__seg--off" style="flex: {{ $offCount }}" data-plugin-seg="off"></span>
                        </span>

                        <div class="settings-plugin-overview__legend">
                            <span class="settings-plugin-overview__row">
                                <span><span class="settings-plugin-overview__swatch settings-plugin-overview__swatch--active" aria-hidden="true"></span>{{ __('Active') }}</span>
                                <span class="settings-plugin-overview__num" dir="ltr" data-plugin-legend="active">{{ $activeCount }}</span>
                            </span>
                            <span class="settings-plugin-overview__row">
                                <span><span class="settings-plugin-overview__swatch settings-plugin-overview__swatch--setup" aria-hidden="true"></span>{{ __('Need setup') }}</span>
                                <span class="settings-plugin-overview__num settings-plugin-overview__num--setup" dir="ltr" data-plugin-legend="setup">{{ $setupCount }}</span>
                            </span>
                            <span class="settings-plugin-overview__row">
                                <span><span class="settings-plugin-overview__swatch" aria-hidden="true"></span>{{ __('Switched off') }}</span>
                                <span class="settings-plugin-overview__num settings-plugin-overview__num--off" dir="ltr" data-plugin-legend="off">{{ $offCount }}</span>
                            </span>
                        </div>

                        <span class="settings-plugin-overview__foot">
                            {{ $lastChecked
                                ? __('Last full check · :when', ['when' => $lastChecked->diffForHumans()])
                                : __('No connection test has been run yet') }}
                        </span>
                    </div>
                </nav>

                <section class="settings-plugin-registry">
                    <div class="settings-plugin-groups tab-content" id="plugin-group-tabs-content">
                        @foreach($pluginGroups as $type => $groupPlugins)
                            @php
                                $groupMeta = $pluginGroupMeta[$type] ?? [
                                    'label' => __(str($type)->replace('_', ' ')->title()->toString()),
                                    'description' => __('Additional integrations installed for this platform.'),
                                    'icon' => 'fa-plug',
                                    'bg' => 'var(--color-surface-soft)',
                                    'fg' => 'var(--color-text-subtle)',
                                ];
                                $isActiveGroup = $activePluginType === $type;
                                $groupSlug = str($type)->slug();
                            @endphp
                            <section class="settings-plugin-group tab-pane fade {{ $isActiveGroup ? 'show active' : '' }}"
                                     id="plugin-group-{{ $groupSlug }}"
                                     role="tabpanel"
                                     aria-labelledby="plugin-group-{{ $groupSlug }}-tab"
                                     data-plugin-pane="{{ $groupSlug }}"
                                     tabindex="0">
                                <header class="settings-plugin-group__header">
                                    <div class="settings-plugin-group__title">
                                        <span class="settings-plugin-group__icon" aria-hidden="true"
                                              style="--plugin-group-bg: {{ $groupMeta['bg'] }}; --plugin-group-fg: {{ $groupMeta['fg'] }};">
                                            <i class="fa-solid {{ $groupMeta['icon'] }}"></i>
                                        </span>
                                        <span class="settings-plugin-group__copy">
                                            <h6>{{ $groupMeta['label'] }}</h6>
                                            <p title="{{ $groupMeta['description'] }}">{{ $groupMeta['description'] }}</p>
                                        </span>
                                    </div>

                                    <div class="settings-plugin-group__meta">
                                        <label class="settings-plugin-search">
                                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                            <input type="search"
                                                   data-plugin-search
                                                   placeholder="{{ __('Search integrations') }}"
                                                   aria-label="{{ __('Search integrations') }}">
                                        </label>

                                        <div class="settings-plugin-filters" role="group" aria-label="{{ __('Filter by state') }}">
                                            @foreach($filterDefs as $filterKey => $filterLabel)
                                                @php
                                                    $filterCount = $filterKey === 'all'
                                                        ? $groupPlugins->count()
                                                        : $groupPlugins->where('state', $filterKey)->count();
                                                @endphp
                                                <button type="button"
                                                        class="settings-plugin-filter {{ $filterKey === 'all' ? 'is-active' : '' }}"
                                                        data-plugin-filter="{{ $filterKey }}"
                                                        aria-pressed="{{ $filterKey === 'all' ? 'true' : 'false' }}">
                                                    {{ $filterLabel }}<span class="settings-plugin-filter__count" dir="ltr" data-plugin-filter-count="{{ $filterKey }}">{{ $filterCount }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                </header>

                                <div class="settings-plugin-grid" data-plugin-grid>
                                    @foreach($groupPlugins as $plugin)
                                        @php
                                            $keyCount = collect($plugin->credentialsArray())->except('fields')->count();
                                            $missingKeys = $plugin->missingCredentialKeys();
                                            $isConfigured = $plugin->is_configured;
                                        @endphp
                                        <article class="settings-plugin-item"
                                                 data-plugin-card
                                                 data-plugin-id="{{ $plugin->id }}"
                                                 data-state="{{ $plugin->state }}"
                                                 data-search="{{ str($plugin->name.' '.$plugin->description)->lower() }}">
                                            <div class="settings-plugin-item__head">
                                                <span class="settings-plugin-logo">
                                                    <img src="{{ asset($plugin->logo) }}" alt="{{ $plugin->name }}" loading="lazy">
                                                </span>
                                                <span class="settings-plugin-status">
                                                    <span class="settings-plugin-status__dot" aria-hidden="true"></span>
                                                    <span data-plugin-status-label>{{ $stateLabels[$plugin->state] }}</span>
                                                </span>
                                            </div>

                                            <div class="settings-plugin-item__body">
                                                <h6 title="{{ $plugin->name }}">{{ $plugin->name }}</h6>
                                                <p>{{ $plugin->description }}</p>
                                            </div>

                                            <div class="settings-plugin-item__meta">
                                                <span class="settings-plugin-item__keys {{ $isConfigured ? 'is-complete' : '' }}"
                                                      @if($missingKeys !== []) title="{{ collect($missingKeys)->map(fn (string $key): string => ucwords(str_replace('_', ' ', $key)))->implode(', ') }}" @endif>
                                                    <i class="fa-solid {{ $isConfigured ? 'fa-check' : 'fa-triangle-exclamation' }}" aria-hidden="true"></i>
                                                    @if($isConfigured)
                                                        {{ __(':count of :total keys set', ['count' => $keyCount, 'total' => $keyCount]) }}
                                                    @elseif($missingKeys === [])
                                                        {{ __('No keys saved') }}
                                                    @else
                                                        {{ trans_choice('{1}:count of :total keys missing|[2,*]:count of :total keys missing', count($missingKeys), ['count' => count($missingKeys), 'total' => $keyCount]) }}
                                                    @endif
                                                </span>
                                                <span class="settings-plugin-item__sep" aria-hidden="true"></span>
                                                <span>{{ $plugin->last_tested_at
                                                    ? __('Tested :when', ['when' => $plugin->last_tested_at->diffForHumans(null, true, true)])
                                                    : __('Not tested') }}</span>
                                            </div>

                                            <div class="settings-plugin-item__footer">
                                                <button type="button"
                                                        class="settings-plugin-toggle"
                                                        role="switch"
                                                        data-plugin-toggle
                                                        aria-checked="{{ $plugin->status ? 'true' : 'false' }}"
                                                        aria-label="{{ __('Turn :plugin on or off', ['plugin' => $plugin->name]) }}">
                                                    <span class="settings-plugin-toggle__track" aria-hidden="true">
                                                        <span class="settings-plugin-toggle__knob"></span>
                                                    </span>
                                                    <span class="settings-plugin-toggle__label" data-plugin-toggle-label>{{ $plugin->status ? __('On') : __('Off') }}</span>
                                                </button>

                                                <button type="button"
                                                        class="btn edit-modal settings-plugin-item__action {{ $isConfigured ? '' : 'settings-plugin-item__action--setup' }}"
                                                        data-edit-url="{{ route('admin.settings.plugin.edit', $plugin->id) }}">
                                                    {{ $isConfigured ? __('Manage') : __('Set up') }}<i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                                </button>
                                            </div>
                                        </article>
                                    @endforeach
                                </div>

                                {{-- Shown by the filters when nothing in this group matches. --}}
                                <div class="settings-plugin-empty" data-plugin-no-results hidden>
                                    <span class="settings-plugin-empty__icon" aria-hidden="true"><i class="fa-solid fa-plug"></i></span>
                                    <span class="settings-plugin-empty__title">{{ __('No integrations match') }}</span>
                                    <span class="settings-plugin-empty__body">{{ __('Try a different filter or clear the search.') }}</span>
                                    <button type="button" class="settings-plugin-empty__action" data-plugin-clear-filters>{{ __('Clear filters') }}</button>
                                </div>
                            </section>
                        @endforeach
                    </div>
                </section>
            </div>
        @else
            <x-admin-not-found
                :title="__('No integrations found')"
                :message="__('No integrations are configured for this platform yet.')"
                icon="fa-plug"
                class="settings-plugin-empty"
            />
        @endif
    </div>

    {{-- plugin mange modal --}}
    @include('backend.settings.plugin.partials._manage')

@endsection
@push('scripts')
    @include('backend.settings.plugin.partials._scripts')
@endpush
