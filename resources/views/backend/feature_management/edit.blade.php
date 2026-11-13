@extends('backend.layouts.app')

@section('title', __('Manage Feature: :name', ['name' => $feature->label]))

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/feature-management.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/feature-management.css'))) }}">
@endpush

@section('content')
    @php
        use App\Models\FeatureAccessRule;

        $categoryMeta = [
            'money_movement' => ['icon' => 'fa-wallet', 'bg' => 'var(--color-primary-soft)', 'fg' => 'var(--color-primary)'],
            'p2p'            => ['icon' => 'fa-handshake', 'bg' => 'var(--color-palette-fff1f2)', 'fg' => 'var(--color-rose)'],
            'business'       => ['icon' => 'fa-store', 'bg' => 'var(--tint-teal)', 'fg' => 'var(--color-palette-0f766e)'],
            'cards'          => ['icon' => 'fa-credit-card', 'bg' => 'var(--tint-cyan)', 'fg' => 'var(--color-cyan)'],
            'engagement'     => ['icon' => 'fa-share-nodes', 'bg' => 'var(--tint-violet)', 'fg' => 'var(--color-violet)'],
        ];

        $panelMeta = [
            FeatureAccessRule::PANEL_USER     => ['label' => __('User'), 'icon' => 'fa-user', 'bg' => 'var(--color-palette-eef2ff)', 'fg' => 'var(--color-primary-hover)'],
            FeatureAccessRule::PANEL_MERCHANT => ['label' => __('Merchant'), 'icon' => 'fa-store', 'bg' => 'var(--tint-teal)', 'fg' => 'var(--color-palette-0f766e)'],
            FeatureAccessRule::PANEL_AGENT    => ['label' => __('Agent'), 'icon' => 'fa-user-tie', 'bg' => 'var(--color-palette-fff7ed)', 'fg' => 'var(--color-warning-deep)'],
        ];

        $catTint = $categoryMeta[$feature->category] ?? ['icon' => 'fa-sliders', 'bg' => 'var(--color-surface-soft)', 'fg' => 'var(--color-text-subtle)'];

        /*
         * Recipient lookup exists only where the catalog declares it — the
         * same condition FeatureManagementController@update gates on, so the
         * form never posts a block the controller would ignore.
         */
        $catalogRules   = (array) config("feature_catalog.features.{$feature->key}.rules", []);
        $hasIdentifiers = array_key_exists('identifier_methods', $catalogRules);

        /**
         * The four rules every panel carries, in the order they are read: what
         * is shown, what may be reached, and the two gates in front of it.
         * The matrix names the last two as gates, because in a column headed
         * by a panel "KYC required" reads as a statement about the panel.
         */
        $ruleDefs = [
            'is_visible'     => ['label' => __('Visible'), 'short' => __('Visible'), 'hint' => __('Show in menus and dashboards.'), 'icon' => 'fa-eye', 'fg' => 'var(--color-primary)'],
            'is_accessible'  => ['label' => __('Accessible'), 'short' => __('Accessible'), 'hint' => __('Allow routes, actions and APIs.'), 'icon' => 'fa-unlock', 'fg' => 'var(--color-palette-0f766e)'],
            'requires_kyc'   => ['label' => __('KYC required'), 'short' => __('KYC gate'), 'hint' => __('User must complete KYC first.'), 'icon' => 'fa-id-card', 'fg' => 'var(--color-warning-deep)'],
            'requires_phone' => ['label' => __('Phone required'), 'short' => __('Phone gate'), 'hint' => __('A verified phone number is needed.'), 'icon' => 'fa-comment-sms', 'fg' => 'var(--color-rose)'],
        ];

        $identifierDefs = [
            'wallet_id' => ['label' => __('Wallet ID'), 'hint' => __('Accept a wallet ID like DK-USD-XXXX-XXXX.'), 'icon' => 'fa-fingerprint', 'fg' => 'var(--color-primary)', 'verify' => null],
            'email'     => ['label' => __('Email'), 'hint' => __("Accept the recipient's registered email."), 'icon' => 'fa-at', 'fg' => 'var(--color-palette-0f766e)', 'verify' => 'require_verified_email'],
            'mobile'    => ['label' => __('Mobile number'), 'hint' => __("Accept the recipient's registered mobile."), 'icon' => 'fa-mobile-screen-button', 'fg' => 'var(--color-warning-deep)', 'verify' => 'require_verified_mobile'],
        ];

        $firstPanel = (string) array_key_first($panelOptions);
    @endphp

    <div class="feature-mgmt-page--manage" data-feature-manage>
        {{--
            The status chip is the header's state slot rather than its status
            pill: feature-management.js rewrites it live as the master switch
            moves, so it has to keep its own classes and data hooks.
        --}}
        <x-page-header
            :title="__($feature->label)"
            :subtitle="__($feature->description)"
            :icon="$catTint['icon']"
            :badge="$feature->key"
            :back="route('admin.features.index')"
            :back-label="__('Feature Management')"
            :status="$feature->is_core ? ['label' => __('Core')] : null"
        >
            <x-slot:state>
                <span class="feature-mgmt-status {{ $feature->is_enabled ? '' : 'is-off' }}" data-feature-status
                      data-label-on="{{ __('Enabled') }}"
                      data-label-off="{{ __('Disabled') }}">
                    <span class="feature-mgmt-status__dot" aria-hidden="true"></span>
                    <span data-feature-status-text>{{ $feature->is_enabled ? __('Enabled') : __('Disabled') }}</span>
                </span>
            </x-slot:state>
        </x-page-header>

        <form action="{{ route('admin.features.update', $feature) }}" method="POST" class="feature-mgmt-form" data-feature-form>
            @csrf
            @method('PUT')

            <header class="feature-mgmt-head">
                {{-- The master switch: off hides this feature everywhere,
                     whatever the panel rules below say. --}}
                <div class="feature-mgmt-master {{ $feature->is_enabled ? '' : 'is-off' }}" data-feature-master>
                    <div class="feature-mgmt-master__copy">
                        <span class="feature-mgmt-master__label">{{ __('Enabled platform-wide') }}</span>
                        <span class="feature-mgmt-master__hint {{ $feature->is_core ? 'is-core' : '' }}">
                            {{ $feature->is_core
                                ? __('Core feature — turning off may break dependent flows.')
                                : __('Off hides this feature everywhere, regardless of panel rules.') }}
                        </span>
                    </div>

                    <input type="hidden" name="is_enabled" value="0">
                    <label class="feature-mgmt-sw feature-mgmt-sw--lg"
                           aria-label="{{ __('Toggle :feature', ['feature' => __($feature->label)]) }}">
                        <input class="feature-mgmt-sw__input"
                               type="checkbox"
                               role="switch"
                               name="is_enabled"
                               value="1"
                               data-feature-master-input
                               {{ $feature->is_enabled ? 'checked' : '' }}>
                        <span class="feature-mgmt-sw__track" aria-hidden="true"></span>
                    </label>
                </div>
            </header>

            <div class="feature-mgmt-manage-grid">
                <section class="feature-mgmt-rules-card">
                    <div class="feature-mgmt-rules-card__head">
                        <h2 class="feature-mgmt-rules-card__title">{{ __('Panel rules') }}</h2>
                        <span class="feature-mgmt-rules-card__subtitle">{{ __('Visibility controls menus and entry points. Access controls routes, actions and APIs.') }}</span>
                    </div>

                    {{-- Tabs only show and hide. Every panel's fields stay in
                         the one form, so a panel the admin never opened still
                         posts the values it came with. --}}
                    <div class="feature-mgmt-tabs" role="tablist">
                        @foreach($panelOptions as $panel => $panelLabel)
                            @php
                                $pm = $panelMeta[$panel] ?? ['label' => $panelLabel, 'icon' => 'fa-user', 'fg' => 'var(--color-text-faint)'];
                            @endphp
                            <button type="button"
                                    class="feature-mgmt-tab {{ $panel === $firstPanel ? 'is-active' : '' }}"
                                    role="tab"
                                    data-feature-tab="{{ $panel }}"
                                    aria-selected="{{ $panel === $firstPanel ? 'true' : 'false' }}"
                                    style="--fm-panel-fg: {{ $pm['fg'] }};">
                                <i class="fa-solid {{ $pm['icon'] }} feature-mgmt-tab__icon" aria-hidden="true"></i>{{ $pm['label'] }}
                                <span class="feature-mgmt-tab__pill" data-feature-tab-badge="{{ $panel }}"
                                      data-label-open="{{ __('Open') }}"
                                      data-label-blocked="{{ __('Blocked') }}"
                                      data-label-hidden="{{ __('Hidden') }}"></span>
                            </button>
                        @endforeach
                    </div>

                    @foreach($panelOptions as $panel => $panelLabel)
                        @php
                            $rule        = $rulesByPanel[$panel];
                            $conditions  = (array) ($rule->conditions ?? []);
                            $identifiers = (array) ($conditions['identifier_methods'] ?? []);
                            $countries   = (array) ($conditions['countries_allowed'] ?? []);
                        @endphp
                        <div class="feature-mgmt-pane"
                             data-feature-pane="{{ $panel }}"
                             @unless($panel === $firstPanel) hidden @endunless>

                            <div class="feature-mgmt-rules">
                                @foreach($ruleDefs as $field => $def)
                                    @php
                                        $checked = in_array($field, ['is_visible', 'is_accessible'], true)
                                            ? (bool) $rule->{$field}
                                            : (bool) ($conditions[$field] ?? false);
                                    @endphp
                                    <label class="feature-mgmt-rule {{ $checked ? 'is-on' : '' }}" data-feature-rule>
                                        <span class="feature-mgmt-rule__identity">
                                            <span class="feature-mgmt-rule__icon" aria-hidden="true" style="--fm-rule-fg: {{ $def['fg'] }};">
                                                <i class="fa-solid {{ $def['icon'] }}"></i>
                                            </span>
                                            <span class="feature-mgmt-rule__copy">
                                                <span class="feature-mgmt-rule__label">{{ $def['label'] }}</span>
                                                <span class="feature-mgmt-rule__hint">{{ $def['hint'] }}</span>
                                            </span>
                                        </span>
                                        <input type="hidden" name="panels[{{ $panel }}][{{ $field }}]" value="0">
                                        <span class="feature-mgmt-sw">
                                            <input class="feature-mgmt-sw__input"
                                                   type="checkbox"
                                                   role="switch"
                                                   name="panels[{{ $panel }}][{{ $field }}]"
                                                   value="1"
                                                   data-feature-rule-input="{{ $field }}"
                                                   data-feature-panel="{{ $panel }}"
                                                   {{ $checked ? 'checked' : '' }}>
                                            <span class="feature-mgmt-sw__track" aria-hidden="true"></span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>

                            @if($hasIdentifiers)
                                <div class="feature-mgmt-block">
                                    <div class="feature-mgmt-block__head">
                                        <div class="feature-mgmt-block__copy">
                                            <span class="feature-mgmt-block__title">{{ __('Recipient lookup') }}</span>
                                            <span class="feature-mgmt-block__hint">{{ __('How a recipient can be found on this panel.') }}</span>
                                        </div>
                                        <span class="feature-mgmt-block__meta"
                                              data-feature-id-summary="{{ $panel }}"
                                              data-total="{{ count($identifierDefs) }}"
                                              data-template="{{ __(':count of :total methods allowed') }}"></span>
                                    </div>

                                    <div class="feature-mgmt-ids">
                                        @foreach($identifierDefs as $method => $def)
                                            @php
                                                $on       = (bool) ($identifiers[$method] ?? false);
                                                $verified = $def['verify'] !== null && (bool) ($conditions[$def['verify']] ?? false);
                                            @endphp
                                            <div class="feature-mgmt-id {{ $on ? 'is-on' : '' }}" data-feature-id>
                                                <div class="feature-mgmt-id__head">
                                                    <span class="feature-mgmt-id__label">
                                                        <i class="fa-solid {{ $def['icon'] }}" aria-hidden="true" style="color: {{ $def['fg'] }};"></i>{{ $def['label'] }}
                                                    </span>
                                                    <input type="hidden" name="panels[{{ $panel }}][identifier_methods][{{ $method }}]" value="0">
                                                    <span class="feature-mgmt-sw feature-mgmt-sw--sm">
                                                        <input class="feature-mgmt-sw__input"
                                                               type="checkbox"
                                                               role="switch"
                                                               name="panels[{{ $panel }}][identifier_methods][{{ $method }}]"
                                                               value="1"
                                                               data-feature-id-input
                                                               data-feature-panel="{{ $panel }}"
                                                               {{ $on ? 'checked' : '' }}>
                                                        <span class="feature-mgmt-sw__track" aria-hidden="true"></span>
                                                    </span>
                                                </div>

                                                <span class="feature-mgmt-id__hint">{{ $def['hint'] }}</span>

                                                @if($def['verify'] !== null)
                                                    {{-- Only meaningful while the method is on. Hidden
                                                         rather than removed, so the stored value still
                                                         posts and is not silently cleared. --}}
                                                    <label class="feature-mgmt-id__verify" data-feature-verify @unless($on) hidden @endunless>
                                                        <input type="hidden" name="panels[{{ $panel }}][{{ $def['verify'] }}]" value="0">
                                                        <input type="checkbox"
                                                               class="admin-check"
                                                               name="panels[{{ $panel }}][{{ $def['verify'] }}]"
                                                               value="1"
                                                               {{ $verified ? 'checked' : '' }}>
                                                        <span class="feature-mgmt-id__verify-label">{{ __('Must be verified') }}</span>
                                                    </label>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <div class="feature-mgmt-block">
                                <div class="feature-mgmt-block__head">
                                    <div class="feature-mgmt-block__copy">
                                        <span class="feature-mgmt-block__title">{{ __('Allowed countries') }}</span>
                                        <span class="feature-mgmt-block__hint">{{ __('Leave empty to allow every country.') }}</span>
                                    </div>
                                    <span class="feature-mgmt-block__pill"
                                          data-feature-country-pill="{{ $panel }}"
                                          data-label-all="{{ __('All countries') }}"
                                          data-label-one="{{ __('1 country') }}"
                                          data-label-some="{{ __(':count countries') }}">
                                        <i class="fa-solid fa-globe" aria-hidden="true" data-feature-country-glyph></i><span data-feature-country-text></span>
                                    </span>
                                </div>

                                {{-- The chips are presentation. What posts is the
                                     comma-separated hidden field the controller
                                     already normalises, unchanged. --}}
                                <div class="feature-mgmt-chips" data-feature-chips="{{ $panel }}">
                                    <span class="feature-mgmt-chips__list"
                                          data-feature-chip-list
                                          data-label-remove="{{ __('Remove :code') }}"></span>
                                    <input type="text"
                                           class="feature-mgmt-chips__input"
                                           data-feature-chip-input
                                           data-placeholder-empty="{{ __('Type an ISO code and press Enter, e.g. BD') }}"
                                           data-placeholder-some="{{ __('Add ISO code…') }}"
                                           aria-label="{{ __('Add an allowed country') }}"
                                           autocomplete="off"
                                           maxlength="2">
                                    <input type="hidden"
                                           name="panels[{{ $panel }}][countries_allowed]"
                                           value="{{ implode(',', $countries) }}"
                                           data-feature-country-field>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </section>

                <aside class="feature-mgmt-aside">
                    <div class="feature-mgmt-matrix">
                        <span class="feature-mgmt-matrix__eyebrow">{{ __('Access matrix') }}</span>
                        <div class="feature-mgmt-matrix__grid" style="--fm-matrix-cols: {{ count($panelOptions) }};">
                            <span></span>
                            @foreach($panelOptions as $panel => $panelLabel)
                                @php
                                    $pm = $panelMeta[$panel] ?? ['label' => $panelLabel, 'icon' => 'fa-user', 'bg' => 'var(--color-surface-soft)', 'fg' => 'var(--color-text-faint)'];
                                @endphp
                                <span class="feature-mgmt-matrix__head" title="{{ $pm['label'] }}"
                                      style="--fm-panel-bg: {{ $pm['bg'] }}; --fm-panel-fg: {{ $pm['fg'] }};">
                                    <i class="fa-solid {{ $pm['icon'] }}" aria-hidden="true"></i>
                                </span>
                            @endforeach

                            @foreach($ruleDefs as $field => $def)
                                <span class="feature-mgmt-matrix__label">{{ $def['short'] }}</span>
                                @foreach($panelOptions as $panel => $panelLabel)
                                    <span class="feature-mgmt-matrix__cell"
                                          data-feature-matrix-cell="{{ $panel }}"
                                          data-feature-matrix-field="{{ $field }}"></span>
                                @endforeach
                            @endforeach
                        </div>
                        <span class="feature-mgmt-matrix__foot">{{ __('Agents inherit the User panel when no agent rule exists.') }}</span>
                    </div>

                    <div class="feature-mgmt-save" data-feature-save
                         data-title-clean="{{ __('All changes saved') }}"
                         data-title-dirty="{{ __('Unsaved changes') }}"
                         data-tag-clean="{{ __('synced') }}"
                         data-tag-dirty="{{ __('modified') }}">
                        <div class="feature-mgmt-save__head">
                            <span class="feature-mgmt-save__title" data-feature-save-title>{{ __('All changes saved') }}</span>
                            <span class="feature-mgmt-save__tag" data-feature-save-tag>{{ __('synced') }}</span>
                        </div>
                        <span class="feature-mgmt-save__hint">{{ __('Changes apply immediately to menus, widgets and guarded routes.') }}</span>
                        <div class="feature-mgmt-save__actions">
                            <button type="button" class="feature-mgmt-save__discard" data-feature-discard>{{ __('Discard') }}</button>
                            <button type="submit" class="feature-mgmt-save__submit">
                                <i class="fa-solid fa-check" aria-hidden="true"></i>{{ __('Save changes') }}
                            </button>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('backend/js/feature-management.js') }}"></script>
@endpush
