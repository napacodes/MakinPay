{{--
    One add-on's detail drawer, rendered hidden beside its card and opened by
    feature-management.js. Sections follow docs/ui/addons/README.md §E, minus
    what the backend cannot vouch for (screenshots, scheduled jobs, changelog,
    update check — GAP.md §2.1).
--}}
@php
    $detail   = $row['detail'];
    $license  = $detail['license'];
    $drawerId = 'addon-drawer-'.$row['slug'];
    $failing  = collect($detail['requirements'])->where('passed', false)->count();

    $licensePill = match ($license['state']) {
        \App\Services\Addons\AddonLicensing::VALID   => ['tone' => 'success', 'icon' => 'fa-key', 'text' => __('Active')],
        \App\Services\Addons\AddonLicensing::INVALID => ['tone' => 'danger', 'icon' => 'fa-key', 'text' => __('Rejected')],
        \App\Services\Addons\AddonLicensing::MISSING => ['tone' => 'warning', 'icon' => 'fa-key', 'text' => __('Missing')],
        default                                       => ['tone' => 'neutral', 'icon' => 'fa-key', 'text' => __('Not checked here')],
    };
@endphp

<div class="feature-mgmt-drawer"
     id="{{ $drawerId }}"
     data-addon-drawer="{{ $row['slug'] }}"
     role="dialog"
     aria-modal="true"
     aria-labelledby="{{ $drawerId }}-title"
     hidden>
    <div class="feature-mgmt-drawer__body">
        <section class="feature-mgmt-drawer__head">
            <span class="feature-mgmt-drawer__tile" aria-hidden="true"><i class="fa-solid fa-puzzle-piece"></i></span>
            <div class="feature-mgmt-drawer__identity">
                <h2 class="feature-mgmt-drawer__title" id="{{ $drawerId }}-title">{{ $row['name'] }}</h2>
                <span class="feature-mgmt-badge {{ $row['verified'] ? 'feature-mgmt-badge--brand' : 'feature-mgmt-badge--neutral' }}">
                    <i class="fa-solid {{ $row['verified'] ? 'fa-circle-check' : 'fa-building' }}" aria-hidden="true"></i>{{ $row['publisher'] }}
                </span>
                <span class="feature-mgmt-drawer__meta" dir="ltr">
                    {{ $row['version'] !== '' ? 'v'.$row['version'].' · ' : '' }}{{ $row['slug'] }}@if($row['installed_at']) · {{ __('Installed :date', ['date' => $row['installed_at']->translatedFormat('j M Y')]) }}@endif
                </span>
                @if($row['pills'] !== [])
                    <div class="feature-mgmt-states">
                        @foreach($row['pills'] as $pill)
                            <span class="feature-mgmt-state feature-mgmt-state--{{ $pill['tone'] }}"><i class="fa-solid {{ $pill['icon'] }}" aria-hidden="true"></i>{{ $pill['text'] }}</span>
                        @endforeach
                    </div>
                @endif
            </div>
            <button type="button" class="feature-mgmt-modal__close feature-mgmt-drawer__close" data-addon-drawer-close aria-label="{{ __('Close') }}">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </section>

        @if($row['switch']['shown'] || $detail['settings_url'])
            <section class="feature-mgmt-drawer__section feature-mgmt-drawer__enable">
                @if($row['switch']['shown'])
                    <form action="{{ route('admin.features.addons.status', $row['slug']) }}"
                          method="POST"
                          class="feature-mgmt-toggle-form feature-mgmt-drawer__switch"
                          data-feature-label="{{ $row['name'] }}"
                          data-text-on="{{ __('The add-on starts running. Its features stay off until you turn them on in the Features tab.') }}"
                          data-text-off="{{ trans_choice('Its :count feature is hidden in every panel and its admin screens close. Settings and data are kept.|Its :count features are hidden in every panel and its admin screens close. Settings and data are kept.', count($detail['features']), ['count' => count($detail['features'])]) }}"
                          data-impact-on="{{ __('Its routes, menus and scheduled work load on the next request.') }}"
                          data-impact-off="{{ __('Its routes, menus and scheduled work stop on the next request.') }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="is_enabled" value="0">
                        <label class="feature-mgmt-sw {{ $row['switch']['disabled'] ? 'is-disabled' : '' }}"
                               @if($row['switch']['reason']) title="{{ $row['switch']['reason'] }}" @endif>
                            <input class="feature-mgmt-sw__input feature-mgmt-switch__input" type="checkbox" role="switch" name="is_enabled" value="1"
                                   @checked($row['switch']['checked']) @disabled($row['switch']['disabled'])
                                   aria-labelledby="{{ $drawerId }}-enable">
                            <span class="feature-mgmt-sw__track" aria-hidden="true"></span>
                        </label>
                        <span class="feature-mgmt-drawer__enable-copy" id="{{ $drawerId }}-enable">
                            <strong>{{ $row['switch']['checked'] ? __('On for this install') : __('Off for this install') }}</strong>
                            <span>{{ $row['switch']['reason'] ?? __('Turning it off hides its features in every panel. Settings and data are kept.') }}</span>
                        </span>
                    </form>
                @endif
                @if($detail['settings_url'])
                    <a href="{{ $detail['settings_url'] }}" class="feature-mgmt-btn feature-mgmt-btn--primary">{{ __('Open settings') }}</a>
                @endif
            </section>
        @endif

        @if($row['description'])
            <section class="feature-mgmt-drawer__section">
                <h3 class="feature-mgmt-drawer__eyebrow">{{ __('Overview') }}</h3>
                <p class="feature-mgmt-drawer__text">{{ $row['description'] }}</p>
            </section>
        @endif

        <section class="feature-mgmt-drawer__section">
            <h3 class="feature-mgmt-drawer__eyebrow">{{ __('What this add-on adds') }}</h3>

            @forelse($detail['features'] as $feature)
                <div class="feature-mgmt-drawer__row">
                    <span class="feature-mgmt-drawer__row-copy">
                        <span>{{ $feature['label'] }}</span>
                        <span class="feature-mgmt-drawer__mono" dir="ltr">{{ $feature['key'] }}</span>
                    </span>
                    <span class="feature-mgmt-state feature-mgmt-state--{{ $feature['on'] ? 'success' : 'neutral' }}">{{ $feature['on'] ? __('On') : __('Off') }}</span>
                    <a class="feature-mgmt-drawer__link" href="{{ route('admin.features.index', ['tab' => 'features', 'category' => $feature['category']]) }}#card-{{ $feature['key'] }}">
                        {{ __('Open in Features') }}<i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                    </a>
                </div>
            @empty
                <p class="feature-mgmt-drawer__text">{{ __('No features.') }}</p>
            @endforelse

            @if($detail['menus'] !== [])
                <div class="feature-mgmt-drawer__chips">
                    <span class="feature-mgmt-drawer__label">{{ __('Admin menu entries') }}</span>
                    @foreach($detail['menus'] as $menu)
                        <span class="feature-mgmt-state feature-mgmt-state--neutral">{{ $menu }}</span>
                    @endforeach
                </div>
            @endif

            @if($detail['permissions'] !== [])
                <div class="feature-mgmt-drawer__chips">
                    <span class="feature-mgmt-drawer__label">{{ __('Permissions') }}</span>
                    @foreach($detail['permissions'] as $permission)
                        <span class="feature-mgmt-drawer__mono" dir="ltr">{{ $permission }}</span>
                    @endforeach
                    @if(Route::has('admin.role.index'))
                        <a class="feature-mgmt-drawer__link" href="{{ route('admin.role.index') }}">{{ __('Assign in Roles & permissions') }}<i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a>
                    @endif
                </div>
            @endif
        </section>

        <section class="feature-mgmt-drawer__section" data-addon-drawer-requirements>
            <div class="feature-mgmt-drawer__section-head">
                <h3 class="feature-mgmt-drawer__eyebrow">{{ __('Requirements') }}</h3>
                <a class="feature-mgmt-drawer__link" href="{{ route('admin.features.index', ['tab' => 'addons', 'addon' => $row['slug']]) }}">
                    <i class="fa-solid fa-rotate" aria-hidden="true"></i>{{ __('Re-check') }}
                </a>
            </div>
            @foreach($detail['requirements'] as $check)
                <div class="feature-mgmt-drawer__check {{ $check['passed'] ? 'is-passing' : 'is-failing' }}">
                    <span class="feature-mgmt-drawer__check-mark" aria-hidden="true"><i class="fa-solid {{ $check['passed'] ? 'fa-check' : 'fa-xmark' }}"></i></span>
                    <span class="feature-mgmt-drawer__check-copy">
                        <span>{{ $check['label'] }}</span>
                        <span class="visually-hidden">{{ $check['passed'] ? __('met') : __('not met') }}</span>
                        @unless($check['passed'])
                            <span class="feature-mgmt-drawer__fix"><strong>{{ __('How to fix:') }}</strong> {{ $check['fix'] }}</span>
                        @endunless
                    </span>
                </div>
            @endforeach
            @if($failing === 0)
                <p class="feature-mgmt-drawer__text">{{ __('Everything this add-on needs is in place.') }}</p>
            @endif
        </section>

        @if($license['required'])
            <section class="feature-mgmt-drawer__section">
                <div class="feature-mgmt-drawer__section-head">
                    <h3 class="feature-mgmt-drawer__eyebrow">{{ __('License') }}</h3>
                    <span class="feature-mgmt-state feature-mgmt-state--{{ $licensePill['tone'] }}"><i class="fa-solid {{ $licensePill['icon'] }}" aria-hidden="true"></i>{{ $licensePill['text'] }}</span>
                </div>

                <dl class="feature-mgmt-drawer__facts">
                    <div><dt>{{ __('Domain') }}</dt><dd dir="ltr">{{ $license['domain'] ?? request()->getHost() }}</dd></div>
                    <div><dt>{{ __('Support until') }}</dt><dd>{{ $license['support_until']?->translatedFormat('j M Y') ?? '—' }}</dd></div>
                    <div><dt>{{ __('Last check') }}</dt><dd>{{ $license['last_checked_at']?->diffForHumans() ?? '—' }}</dd></div>
                    @if($license['masked_code'])
                        <div><dt>{{ __('Purchase code') }}</dt><dd dir="ltr" class="feature-mgmt-drawer__mono">{{ $license['masked_code'] }}</dd></div>
                    @endif
                </dl>

                <form action="{{ route('admin.features.addons.license', $row['slug']) }}" method="POST" class="feature-mgmt-drawer__license">
                    @csrf
                    <label class="visually-hidden" for="{{ $drawerId }}-code">{{ __('Purchase code') }}</label>
                    <input type="text" id="{{ $drawerId }}-code" name="purchase_code" class="form-control feature-mgmt-drawer__code" dir="ltr"
                           autocomplete="off" spellcheck="false" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" required>
                    <button type="submit" class="feature-mgmt-btn feature-mgmt-btn--ghost">{{ $license['masked_code'] ? __('Change code') : __('Activate') }}</button>
                </form>

                @if($license['masked_code'])
                    <form action="{{ route('admin.features.addons.license.refresh', $row['slug']) }}" method="POST">
                        @csrf
                        <button type="submit" class="feature-mgmt-drawer__link feature-mgmt-drawer__link--button"><i class="fa-solid fa-rotate" aria-hidden="true"></i>{{ __('Re-check with the license server') }}</button>
                    </form>
                @endif

                <p class="feature-mgmt-drawer__note">{{ __('Licensed per domain. Moving this install to another domain needs a new activation.') }}</p>
            </section>
        @endif

        <section class="feature-mgmt-drawer__section">
            <h3 class="feature-mgmt-drawer__eyebrow">{{ __('Version') }}</h3>
            <p class="feature-mgmt-drawer__text">
                <span class="feature-mgmt-drawer__mono" dir="ltr">v{{ $row['version'] }}</span>
                @if($row['requires_core']) · {{ __('Needs DigiKash :version or later', ['version' => $row['requires_core']]) }}@endif
            </p>
        </section>
    </div>
</div>
