{{--
    The Add-ons tab. Every row comes from App\Services\Addons\AddonCatalog,
    which decides each add-on's state once; this view only draws it. Nothing
    here claims more than the backend knows: no update server, no screenshots,
    no "last ran" times — see docs/ui/addons/GAP.md §2.1.
--}}
@php
    $publisherDefs = [
        'all'   => ['label' => __('All add-ons'), 'meta' => __('Everything in addons/'), 'icon' => 'fa-puzzle-piece', 'bg' => 'var(--color-palette-eef2ff)', 'fg' => 'var(--color-primary-hover)', 'count' => $addonSummary['total']],
        'coevs' => ['label' => __('Coevs'), 'meta' => __('Signed packages'), 'icon' => 'fa-circle-check', 'bg' => 'var(--tint-teal)', 'fg' => 'var(--color-teal)', 'count' => $addonSummary['publishers']['coevs']],
        'other' => ['label' => __('Not verified'), 'meta' => __('Copied in by hand'), 'icon' => 'fa-building', 'bg' => 'var(--color-surface-soft)', 'fg' => 'var(--color-text-subtle)', 'count' => $addonSummary['publishers']['other']],
    ];

    $addonFilterDefs = [
        'all'           => __('All'),
        'active'        => __('Active'),
        'disabled'      => __('Disabled'),
        'not_installed' => __('Not installed'),
    ];

    $bucketDefs = [
        'active'    => __('Active'),
        'attention' => __('Need attention'),
        'disabled'  => __('Disabled'),
        'absent'    => __('Not installed'),
    ];

    $activeCount   = $addonSummary['buckets']['active'];
    $activePercent = $addonSummary['total'] > 0 ? (int) round($activeCount / $addonSummary['total'] * 100) : 0;

    $canManageAddons  = (bool) auth('admin')->user()?->can('addon-manage');
    $showFirstRunHint = ! (auth('admin')->user()?->hasDismissedNotice('addons-first-run') ?? true);
@endphp

@if($showFirstRunHint)
    <div class="feature-mgmt-hint" data-addon-hint data-dismiss-url="{{ route('admin.notice.dismiss', 'addons-first-run') }}">
        <span class="feature-mgmt-hint__icon" aria-hidden="true"><i class="fa-solid fa-puzzle-piece"></i></span>
        <p class="feature-mgmt-hint__copy">
            <strong>{{ __('Add-ons live here now.') }}</strong>
            {{ __('Optional modules plug features and admin screens into this install. Their features appear in the Features tab, switched off until you turn them on.') }}
        </p>
        <button type="button" class="feature-mgmt-btn feature-mgmt-btn--ghost feature-mgmt-hint__dismiss" data-addon-hint-dismiss>{{ __('Got it') }}</button>
    </div>
@endif

<div class="feature-mgmt-workspace" data-addon-catalog>
    <nav class="feature-mgmt-rail" aria-label="{{ __('Add-on publishers') }}">
        <div class="feature-mgmt-cats" role="tablist">
            @foreach($publisherDefs as $publisherKey => $publisher)
                <button type="button"
                        class="feature-mgmt-cat {{ $publisherKey === 'all' ? 'is-active' : '' }}"
                        role="tab"
                        data-addon-publisher-tab="{{ $publisherKey }}"
                        aria-selected="{{ $publisherKey === 'all' ? 'true' : 'false' }}"
                        style="--fm-cat-bg: {{ $publisher['bg'] }}; --fm-cat-fg: {{ $publisher['fg'] }};">
                    <span class="feature-mgmt-cat__icon" aria-hidden="true"><i class="fa-solid {{ $publisher['icon'] }}"></i></span>
                    <span class="feature-mgmt-cat__copy">
                        <span class="feature-mgmt-cat__label">{{ $publisher['label'] }}</span>
                        <span class="feature-mgmt-cat__meta">{{ $publisher['meta'] }}</span>
                    </span>
                    <span class="feature-mgmt-cat__count" dir="ltr">{{ $publisher['count'] }}</span>
                </button>
            @endforeach
        </div>

        <div class="feature-mgmt-overview">
            <div class="feature-mgmt-overview__head">
                <span class="feature-mgmt-overview__eyebrow">{{ __('Overview') }}</span>
            </div>

            <div class="feature-mgmt-overview__figure">
                <span class="feature-mgmt-overview__count">
                    <span class="feature-mgmt-overview__value" dir="ltr">{{ $activeCount }}<span class="feature-mgmt-overview__total">/{{ $addonSummary['total'] }}</span></span>
                    <span class="feature-mgmt-overview__label">{{ __('add-ons active') }}</span>
                </span>
                <span class="feature-mgmt-overview__pct" dir="ltr">{{ $activePercent }}%</span>
            </div>

            {{-- A partition: every add-on is in exactly one segment. --}}
            <span class="feature-mgmt-overview__bar" aria-hidden="true">
                @foreach($addonSummary['buckets'] as $bucket => $count)
                    @if($count > 0)
                        <span class="feature-mgmt-overview__seg feature-mgmt-overview__seg--addon-{{ $bucket }}" style="flex: {{ $count }}"></span>
                    @endif
                @endforeach
            </span>

            <div class="feature-mgmt-overview__legend">
                @foreach($bucketDefs as $bucket => $label)
                    <span class="feature-mgmt-overview__row">
                        <span><span class="feature-mgmt-overview__swatch feature-mgmt-overview__swatch--addon-{{ $bucket }}" aria-hidden="true"></span>{{ $label }}</span>
                        <span class="feature-mgmt-overview__num" dir="ltr">{{ $addonSummary['buckets'][$bucket] }}</span>
                    </span>
                @endforeach
            </div>

            <span class="feature-mgmt-overview__foot">{{ __('DigiKash :version', ['version' => $coreVersion]) }}</span>
        </div>
    </nav>

    <section class="feature-mgmt-catalog-panel">
        <header class="feature-mgmt-catalog-panel__header">
            <div class="feature-mgmt-catalog-panel__identity">
                <span class="feature-mgmt-catalog-panel__icon" aria-hidden="true"
                      style="--fm-cat-bg: var(--color-palette-eef2ff); --fm-cat-fg: var(--color-primary-hover);">
                    <i class="fa-solid fa-puzzle-piece"></i>
                </span>
                <div class="feature-mgmt-catalog-panel__copy">
                    <h2 class="feature-mgmt-catalog-panel__title">{{ __('Add-ons') }}</h2>
                    <p class="feature-mgmt-catalog-panel__desc">{{ __('Optional modules, licensed per domain. Each one plugs features and admin screens into this install.') }}</p>
                </div>
            </div>

            @if($addonRows !== [])
                <div class="feature-mgmt-catalog-panel__tools">
                    <label class="feature-mgmt-search">
                        <i class="fa-solid fa-magnifying-glass feature-mgmt-search__icon" aria-hidden="true"></i>
                        <input type="search"
                               class="feature-mgmt-search__input"
                               data-addon-search
                               placeholder="{{ __('Search add-ons') }}"
                               aria-label="{{ __('Search add-ons') }}"
                               autocomplete="off">
                    </label>

                    <div class="feature-mgmt-filters" role="group" aria-label="{{ __('Filter by state') }}">
                        @foreach($addonFilterDefs as $filterKey => $filterLabel)
                            <button type="button"
                                    class="feature-mgmt-filter {{ $filterKey === 'all' ? 'is-active' : '' }}"
                                    data-addon-filter="{{ $filterKey }}"
                                    aria-pressed="{{ $filterKey === 'all' ? 'true' : 'false' }}">
                                {{ $filterLabel }}<span class="feature-mgmt-filter__count" dir="ltr" data-addon-filter-count="{{ $filterKey }}">{{ $filterKey === 'all' ? $addonSummary['total'] : $addonSummary['filters'][$filterKey] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        </header>

        @if($addonRows === [])
            <div class="feature-mgmt-empty" data-addon-none>
                <span class="feature-mgmt-empty__icon" aria-hidden="true"><i class="fa-solid fa-puzzle-piece"></i></span>
                <span class="feature-mgmt-empty__title">{{ __('No add-ons yet') }}</span>
                <span class="feature-mgmt-empty__body">{{ __('Add-ons you install appear here, with their state, license and requirements.') }}</span>
                @if($canManageAddons)
                    <button type="button" class="feature-mgmt-empty__action" data-addon-install>{{ __('Upload a .zip') }}</button>
                @endif
            </div>
        @else
            <div class="feature-mgmt-grid feature-mgmt-grid--flat" data-addon-grid>
                @foreach($addonRows as $row)
                    @php
                        $tint      = $categoryMeta[$row['category'] ?? ''] ?? ['bg' => 'var(--color-palette-eef2ff)', 'fg' => 'var(--color-primary-hover)'];
                        $switch    = $row['switch'];
                        $reasonId  = 'addon-'.$row['slug'].'-reason';
                        $featureCt = count($row['features']);
                    @endphp
                    <article class="feature-mgmt-item feature-mgmt-item--flat feature-mgmt-addon {{ $switch['checked'] ? '' : 'is-disabled' }}"
                             id="addon-{{ $row['slug'] }}"
                             data-addon-card
                             data-addon-slug="{{ $row['slug'] }}"
                             data-addon-state="{{ $row['state'] }}"
                             data-addon-filter-state="{{ $row['filter'] }}"
                             data-addon-publisher="{{ $row['verified'] ? 'coevs' : 'other' }}"
                             data-addon-search="{{ strtolower($row['name'].' '.$row['slug'].' '.$row['publisher'].' '.$row['description']) }}"
                             style="--fm-cat-bg: {{ $tint['bg'] }}; --fm-cat-fg: {{ $tint['fg'] }};">
                        <div class="feature-mgmt-item__head">
                            <div class="feature-mgmt-item__identity">
                                <span class="feature-mgmt-title-icon" aria-hidden="true"><i class="fa-solid fa-puzzle-piece"></i></span>
                                <div class="feature-mgmt-item__names">
                                    <h4 class="feature-mgmt-item__title">
                                        <span class="feature-mgmt-item__title-text">{{ $row['name'] }}</span>
                                        @if($row['verified'])
                                            <span class="feature-mgmt-badge feature-mgmt-badge--brand" title="{{ __('Installed from a package signed by Coevs') }}">
                                                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>{{ $row['publisher'] }}
                                            </span>
                                        @else
                                            <span class="feature-mgmt-badge feature-mgmt-badge--neutral" title="{{ __('Not installed from a signed package') }}">
                                                <i class="fa-solid fa-building" aria-hidden="true"></i>{{ $row['publisher'] }}
                                            </span>
                                        @endif
                                    </h4>
                                    <span class="feature-mgmt-item__key" dir="ltr">{{ $row['version'] !== '' ? 'v'.$row['version'].' · ' : '' }}{{ $row['slug'] }}</span>
                                </div>
                            </div>

                            @if($switch['shown'])
                                <form action="{{ route('admin.features.addons.status', $row['slug']) }}"
                                      method="POST"
                                      class="feature-mgmt-toggle-form feature-mgmt-card-switch-form"
                                      data-feature-label="{{ $row['name'] }}"
                                      data-text-on="{{ __('The add-on starts running. Its features stay off until you turn them on in the Features tab.') }}"
                                      data-text-off="{{ trans_choice('Its :count feature is hidden in every panel and its admin screens close. Settings and data are kept.|Its :count features are hidden in every panel and its admin screens close. Settings and data are kept.', $featureCt, ['count' => $featureCt]) }}"
                                      data-impact-on="{{ __('Its routes, menus and scheduled work load on the next request.') }}"
                                      data-impact-off="{{ __('Its routes, menus and scheduled work stop on the next request.') }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="is_enabled" value="0">
                                    <label class="feature-mgmt-sw feature-mgmt-card-switch {{ $switch['disabled'] ? 'is-disabled' : '' }}"
                                           aria-label="{{ __('Turn :feature on or off', ['feature' => $row['name']]) }}"
                                           @if($switch['reason']) title="{{ $switch['reason'] }}" @endif>
                                        <input class="feature-mgmt-sw__input feature-mgmt-switch__input"
                                               type="checkbox"
                                               role="switch"
                                               name="is_enabled"
                                               value="1"
                                               @checked($switch['checked'])
                                               @disabled($switch['disabled'])
                                               @if($switch['reason']) aria-describedby="{{ $reasonId }}" @endif>
                                        <span class="feature-mgmt-sw__track" aria-hidden="true"></span>
                                        @if($switch['reason'])
                                            <span class="visually-hidden" id="{{ $reasonId }}">{{ $switch['reason'] }}</span>
                                        @endif
                                    </label>
                                </form>
                            @endif

                            @if($row['installed'] && $canManageAddons)
                                <div class="dropdown feature-mgmt-addon__more">
                                    <button type="button"
                                            class="feature-mgmt-more"
                                            data-coreui-toggle="dropdown"
                                            aria-expanded="false"
                                            aria-label="{{ __('More actions for :name', ['name' => $row['name']]) }}">
                                        <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-end ph-menu">
                                        @if($row['detail'] !== null)
                                            <button type="button" class="dropdown-item" data-addon-action="open" data-addon-slug="{{ $row['slug'] }}">
                                                <i class="fa-solid fa-sliders" aria-hidden="true"></i>{{ __('Open') }}
                                            </button>
                                        @endif
                                        @if($switch['shown'] && $switch['checked'])
                                            <button type="button" class="dropdown-item is-warning" data-addon-action="disable" data-addon-slug="{{ $row['slug'] }}">
                                                <i class="fa-solid fa-circle-pause" aria-hidden="true"></i>{{ __('Disable') }}
                                            </button>
                                        @endif
                                        <div class="dropdown-divider"></div>
                                        <button type="button"
                                                class="dropdown-item is-danger"
                                                data-addon-action="uninstall"
                                                data-addon-slug="{{ $row['slug'] }}"
                                                data-addon-name="{{ $row['name'] }}"
                                                data-addon-migrations="{{ $row['detail']['migrations'] ?? 0 }}">
                                            <i class="fa-solid fa-trash-can" aria-hidden="true"></i>{{ __('Uninstall…') }}
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>

                        @if($row['description'])
                            <p class="feature-mgmt-item__description">{{ $row['description'] }}</p>
                        @endif

                        @if($row['pills'] !== [])
                            <div class="feature-mgmt-states">
                                @foreach($row['pills'] as $pill)
                                    <span class="feature-mgmt-state feature-mgmt-state--{{ $pill['tone'] }}">
                                        <i class="fa-solid {{ $pill['icon'] }}" aria-hidden="true"></i>{{ $pill['text'] }}
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        @if($featureCt > 0 || $row['menus'] > 0)
                            <p class="feature-mgmt-addon__adds">
                                {{ trans_choice('Adds :count feature|Adds :count features', $featureCt, ['count' => $featureCt]) }}@if($row['menus'] > 0) · {{ trans_choice(':count admin menu entry|:count admin menu entries', $row['menus'], ['count' => $row['menus']]) }}@endif
                            </p>
                        @endif

                        <div class="feature-mgmt-item__footer">
                            @if($row['note'])
                                <span class="feature-mgmt-note feature-mgmt-note--{{ $row['note']['tone'] }}">
                                    <i class="fa-solid {{ $row['note']['icon'] }}" aria-hidden="true"></i><span>{{ $row['note']['text'] }}</span>
                                </span>
                            @else
                                <span></span>
                            @endif

                            @if($row['action'])
                                <button type="button"
                                        class="feature-mgmt-manage-btn"
                                        data-addon-action="{{ $row['action']['kind'] }}"
                                        data-addon-slug="{{ $row['slug'] }}"
                                        data-addon-name="{{ $row['name'] }}"
                                        @disabled($row['action']['disabled'])>
                                    {{ $row['action']['label'] }}<i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                </button>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="feature-mgmt-empty" data-addon-empty hidden>
                <span class="feature-mgmt-empty__icon" aria-hidden="true"><i class="fa-solid fa-magnifying-glass"></i></span>
                <span class="feature-mgmt-empty__title">{{ __('No matching add-ons') }}</span>
                <span class="feature-mgmt-empty__body">{{ __('Try a different keyword or clear the filter.') }}</span>
                <button type="button" class="feature-mgmt-empty__action" data-addon-clear>{{ __('Clear filters') }}</button>
            </div>

            <div class="feature-mgmt-modal-backdrop" data-addon-drawer-backdrop></div>
            @foreach($addonRows as $row)
                @if($row['detail'] !== null)
                    @include('backend.feature_management._addon_drawer', ['row' => $row])
                @endif
            @endforeach
        @endif

        @if($canManageAddons)
            @include('backend.feature_management._addon_installer')
        @endif
    </section>
</div>
