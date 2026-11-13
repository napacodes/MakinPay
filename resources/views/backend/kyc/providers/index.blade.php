@extends('backend.layouts.app')
@section('title', __('KYC Providers'))

@push('styles')
    <link rel="stylesheet"
          href="{{ asset('backend/css/kyc-providers.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/kyc-providers.css'))) }}">
@endpush

@section('content')
    @php
        /*
            The routing strip needs the live engine itself, not just its name:
            its initials draw the step's tile and its coverage decides whether
            the step reads "every country" or a count.
        */
        $activeEntry      = collect($catalog)->first(fn (array $entry): bool => $entry['provider'] !== null && (bool) $entry['provider']->status);
        $activeRow        = $activeEntry['provider'] ?? null;
        $coversEverywhere = in_array('*', $activeRow?->countries ?? [], true);
        $coveredCountries = (int) ($stats['countries'] ?? 0);
        $pendingCount     = (int) ($stats['pending'] ?? 0);
        $oldestPending    = $stats['oldest_pending'] ?? null;
    @endphp

    <div class="kyc-providers-page">
        <x-page-header
            :title="__('KYC Verification Providers')"
            {{--
                The routing rule itself, because it is the one thing an admin
                has to know before touching any card on this page.
            --}}
            :subtitle="__('One engine verifies at a time. Activating a provider pauses the previous one; when none is active, everything falls back to manual review.')"
            icon="kyc"
            tile="sky"
            {{--
                Truthful, not decorative: this said "One active engine"
                even with no provider selected and every submission going
                to the manual queue.
            --}}
            :status="$stats['active']
                ? ['tone' => 'success', 'label' => __(':name is live', ['name' => $stats['active']])]
                : ['tone' => 'warning', 'label' => __('Manual review')]"
        >
            {{-- Permanent, not conditional: automation lives in settings in
                 every state, and the link used to appear only when it was off. --}}
            <x-slot:actions>
                <a href="{{ route('admin.settings.site.index', ['section' => 'kyc_settings']) }}" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-gear" aria-hidden="true"></i>{{ __('KYC settings') }}
                </a>
            </x-slot:actions>
        </x-page-header>

        {{-- Routing, drawn once, instead of three alternative paragraphs.
             Every step reads its state from $stats; nothing here is fixed. --}}
        <section class="kyc-route" aria-label="{{ __('How a submission is routed') }}">
            <div class="kyc-route__flow">
                <div class="kyc-route__step">
                    <span class="kyc-route__icon" aria-hidden="true"><i class="fa-solid fa-file-lines"></i></span>
                    <span class="kyc-route__body">
                        <span class="kyc-route__label">{{ __('Submission arrives') }}</span>
                        <span class="kyc-route__note">{{ __('applicant completes a template') }}</span>
                    </span>
                </div>

                <span class="kyc-route__arrow" aria-hidden="true"></span>

                <div class="kyc-route__step {{ $stats['auto_on'] ? 'kyc-route__step--on' : 'kyc-route__step--off' }}">
                    <span class="kyc-route__icon" aria-hidden="true"><i class="fa-solid {{ $stats['auto_on'] ? 'fa-check' : 'fa-circle-xmark' }}"></i></span>
                    <span class="kyc-route__body">
                        <span class="kyc-route__label">{{ $stats['auto_on'] ? __('Automation is on') : __('Automation is off') }}</span>
                        <span class="kyc-route__note">{{ __('set in KYC settings, not here') }}</span>
                    </span>
                </div>

                <span class="kyc-route__arrow" aria-hidden="true"></span>

                @if($activeRow)
                    <div class="kyc-route__step kyc-route__step--engine">
                        <span class="kyc-route__icon kyc-route__icon--mark" aria-hidden="true">{{ \App\Support\KycQueueRow::providerInitials($activeRow->driver) }}</span>
                        <span class="kyc-route__body">
                            <span class="kyc-route__label">{{ __(':name verifies', ['name' => $activeEntry['name']]) }}</span>
                            <span class="kyc-route__note">
                                {{ $coversEverywhere
                                    ? __('for every country')
                                    : trans_choice('for :count covered country|for :count covered countries', $coveredCountries, ['count' => $coveredCountries]) }}
                            </span>
                        </span>
                    </div>
                @else
                    <div class="kyc-route__step kyc-route__step--idle">
                        <span class="kyc-route__icon" aria-hidden="true"><i class="fa-solid fa-power-off"></i></span>
                        <span class="kyc-route__body">
                            <span class="kyc-route__label">{{ __('No engine is active') }}</span>
                            <span class="kyc-route__note">{{ __('nothing is verified automatically') }}</span>
                        </span>
                    </div>
                @endif

                <span class="kyc-route__arrow" aria-hidden="true"></span>

                <div class="kyc-route__step kyc-route__step--manual">
                    <span class="kyc-route__icon" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
                    <span class="kyc-route__body">
                        <span class="kyc-route__label">{{ __('Manual queue') }}</span>
                        <span class="kyc-route__note">{{ __('anything not covered · :count waiting', ['count' => $pendingCount]) }}</span>
                    </span>
                </div>
            </div>
        </section>

        <div class="kyc-catalog" role="list">
        @foreach($catalog as $entry)
            @php
                $row          = $entry['provider'];
                $isConfigured = $row !== null;
                $isActive     = $isConfigured && $row->status;
                $hasKeys      = $isConfigured && filled($row->credentials);
                $accent       = $entry['accent'] ?? 'azure';
                $stateClass   = $isActive ? 'is-active' : ($isConfigured ? 'is-configured' : 'is-empty');
                $modalId      = 'manage-kyc-provider-' . str_replace('_', '-', $entry['driver']);
                $verifiedToday = (int) ($entry['verified_today'] ?? 0);

                /*
                    "Last test" invents nothing: until someone runs a test the
                    cell says so, rather than dating a check that never ran.
                */
                $lastTestedAt = $isConfigured ? $row->last_tested_at : null;
                $lastTestAgo  = \App\Support\KycQueueRow::humanDuration($lastTestedAt);
                $lastTestText = __('Never tested');
                $lastTestTone = 'is-muted';

                if ($lastTestedAt) {
                    $lastTestText = match ($row->last_test_status) {
                        'success' => __('Passed :ago ago', ['ago' => $lastTestAgo]),
                        'warning' => __('Warned :ago ago', ['ago' => $lastTestAgo]),
                        'error'   => __('Failed :ago ago', ['ago' => $lastTestAgo]),
                        default   => __('Tested :ago ago', ['ago' => $lastTestAgo]),
                    };
                    $lastTestTone = match ($row->last_test_status) {
                        'success' => 'is-success',
                        'warning' => 'is-warn',
                        'error'   => 'is-danger',
                        default   => '',
                    };
                }
            @endphp

            <article class="kyc-pcard kyc-pcard--{{ $accent }} {{ $stateClass }}" role="listitem" aria-label="{{ $entry['name'] }}">
                <header class="kyc-pcard__head">
                    <span class="kyc-pcard__logo" aria-hidden="true">
                        @if(! empty($entry['logo']))
                            <img class="kyc-pcard__logo-img" src="{{ asset($entry['logo']) }}" alt="{{ $entry['name'] }}" loading="lazy" width="46" height="46">
                        @else
                            <i class="{{ $entry['icon'] }}"></i>
                        @endif
                    </span>
                    <div class="kyc-pcard__ident">
                        <h3 class="kyc-pcard__name">{{ $entry['name'] }}</h3>
                        <span class="kyc-pcard__driver">{{ $entry['driver'] }}</span>
                    </div>
                    <span class="kyc-pcard__state kyc-pcard__state--{{ $isActive ? 'active' : ($isConfigured ? 'ready' : 'empty') }}">
                        {{-- No dot on "Not configured": a status light for a
                             state that has no status read as a fourth colour. --}}
                        @if($isConfigured)
                            <span class="kyc-pcard__state-dot" aria-hidden="true"></span>
                        @endif
                        {{ $isActive ? __('Live engine') : ($isConfigured ? ($hasKeys ? __('Ready') : __('Keys missing')) : __('Not configured')) }}
                    </span>
                </header>

                <p class="kyc-pcard__tagline">{{ $entry['tagline'] }}</p>

                <div class="kyc-pcard__meta">
                    <span class="kyc-pcard__meta-label">{{ $isConfigured ? __('Routed countries') : __('Best for') }}</span>
                    <span class="kyc-pcard__badges">
                        @if($isConfigured)
                            @foreach(collect($row->countries ?? [])->take(5) as $country)
                                <span class="kyc-pcard__badge">{{ $country === '*' ? __('Any country') : $country }}</span>
                            @endforeach
                            @if(count($row->countries ?? []) > 5)
                                <span class="kyc-pcard__badge kyc-pcard__badge--soft">+{{ count($row->countries) - 5 }}</span>
                            @endif
                        @else
                            @foreach(array_slice($entry['regions'], 0, 4) as $region)
                                <span class="kyc-pcard__badge kyc-pcard__badge--soft">{{ $region }}</span>
                            @endforeach
                        @endif
                    </span>
                </div>

                @if($isConfigured)
                    <dl class="kyc-pcard__facts">
                        <div class="kyc-pcard__fact">
                            <dt>{{ __('Environment') }}</dt>
                            <dd class="{{ $row->mode === 'live' ? 'is-success' : 'is-warn' }}">{{ $row->mode === 'live' ? __('Live mode') : __('Sandbox') }}</dd>
                        </div>
                        <div class="kyc-pcard__fact">
                            <dt>{{ __('Credentials') }}</dt>
                            <dd class="{{ $hasKeys ? '' : 'is-warn' }}">{{ $hasKeys ? __('Saved & encrypted') : __('Not saved yet') }}</dd>
                        </div>
                        <div class="kyc-pcard__fact">
                            <dt>{{ __('Last test') }}</dt>
                            <dd class="{{ $lastTestTone }}">{{ $lastTestText }}</dd>
                        </div>
                        <div class="kyc-pcard__fact">
                            <dt>{{ __('Verified today') }}</dt>
                            <dd class="{{ $verifiedToday > 0 ? '' : 'is-muted' }}">{{ $verifiedToday > 0 ? $verifiedToday : __('None') }}</dd>
                        </div>
                    </dl>
                @else
                    <div class="kyc-pcard__locknote">
                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                        <span>{{ __('Add an API key and secret to enable this engine. Credentials are encrypted at rest and never shown again after saving.') }}</span>
                    </div>
                @endif

                <footer class="kyc-pcard__actions">
                    @can('kyc-provider-manage')
                        @if(! $isConfigured)
                            <button type="button" class="btn btn-outline-secondary kyc-pcard__btn"
                                    data-coreui-toggle="modal" data-coreui-target="#{{ $modalId }}"
                                    aria-controls="{{ $modalId }}">
                                <i class="fa-solid fa-sliders" aria-hidden="true"></i>{{ __('Configure') }}
                            </button>
                        @else
                            @if($isActive)
                                <form action="{{ route('admin.kyc.providers.deactivate', $row) }}" method="POST"
                                      onsubmit="return confirm('{{ __('Deactivate :name? Submissions will fall back to manual review.', ['name' => $entry['name']]) }}');">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-warning kyc-pcard__btn">
                                        <i class="fa-solid fa-power-off" aria-hidden="true"></i>{{ __('Deactivate') }}
                                    </button>
                                </form>
                            @else
                                <form action="{{ route('admin.kyc.providers.activate', $row) }}" method="POST"
                                      onsubmit="return confirm('{{ __('Activate :name? Any other active provider will be switched off.', ['name' => $entry['name']]) }}');">
                                    @csrf
                                    <button type="submit" class="btn btn-primary kyc-pcard__btn" @disabled(! $hasKeys)
                                            title="{{ $hasKeys ? __('Make this the active engine') : __('Add credentials first') }}">
                                        <i class="fa-solid fa-play" aria-hidden="true"></i>{{ __('Activate') }}
                                    </button>
                                </form>
                            @endif

                            <button type="button" class="btn btn-outline-secondary kyc-pcard__btn"
                                    data-coreui-toggle="modal" data-coreui-target="#{{ $modalId }}"
                                    aria-controls="{{ $modalId }}">
                                <i class="fa-solid fa-pen" aria-hidden="true"></i>{{ __('Edit') }}
                            </button>

                            {{-- Reaching the engine from the card, without opening
                                 the credential modal to do it. The modal's own
                                 tester serialises its form; this one carries the
                                 saved provider's id and posts on its own. --}}
                            <button type="button" class="btn btn-outline-secondary kyc-pcard__btn"
                                    data-kyc-provider-card-test
                                    data-test-url="{{ route('admin.kyc.providers.test') }}"
                                    data-token="{{ csrf_token() }}"
                                    data-driver="{{ $entry['driver'] }}"
                                    data-mode="{{ $row->mode }}"
                                    data-provider-id="{{ $row->id }}"
                                    data-testing-message="{{ __('Testing provider connection...') }}"
                                    data-fallback-message="{{ __('Unable to test this provider right now.') }}">
                                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>{{ __('Test') }}
                            </button>
                        @endif
                    @endcan

                    <a href="{{ $entry['site'] }}" target="_blank" rel="noopener" class="kyc-pcard__site"
                       title="{{ __('Documentation (opens in a new tab)') }}"
                       aria-label="{{ __('Documentation (opens in a new tab)') }}">{{ __('Docs') }}</a>
                </footer>

                @can('kyc-provider-manage')
                    @if($isConfigured)
                        <p class="kyc-pcard__test-result d-none" role="status" aria-live="polite" data-kyc-provider-card-test-result></p>
                    @endif
                @endcan
            </article>
        @endforeach
        </div>

        {{-- The queue behind every engine. It is not a provider and cannot be
             switched off, so it sits below the grid rather than inside it. --}}
        <div class="kyc-manual">
            <span class="kyc-manual__icon" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
            <span class="kyc-manual__body">
                <span class="kyc-manual__title">{{ __('Manual review — always on, never removable') }}</span>
                <span class="kyc-manual__note">
                    {{ __('Anything a provider does not cover, and anything it sends back as "needs review", lands in the pending queue.') }}
                    @if($pendingCount > 0)
                        {{ __(':count waiting, oldest :age.', ['count' => $pendingCount, 'age' => \App\Support\KycQueueRow::humanDuration($oldestPending)]) }}
                    @else
                        {{ __('Nothing is waiting right now.') }}
                    @endif
                </span>
            </span>
            <a href="{{ route('admin.kyc.pending') }}" class="btn btn-outline-secondary kyc-manual__action">
                {{ __('Open the queue') }}
            </a>
        </div>

        <aside class="kyc-catalog__hint" aria-label="{{ __('Routing safeguard') }}">
            <span class="kyc-catalog__hint-icon"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>
            <span><strong>{{ __('Safe switching.') }}</strong> {{ __('Activating a provider pauses the previous one in the same transaction, so two engines can never verify at once — and switching never touches a submission already in flight.') }}</span>
        </aside>

        @can('kyc-provider-manage')
            @foreach($catalog as $entry)
                @include('backend.kyc.providers._provider_modal', ['entry' => $entry, 'countries' => $countries])
            @endforeach
        @endcan

    </div>
@endsection

@push('scripts')
    @can('kyc-provider-manage')
        <script src="{{ asset('backend/js/kyc-provider.js?v='.config('app.version')) }}"></script>
    @endcan
@endpush

@if($errors->any() && old('driver'))
    @push('scripts')
        <script>
            $(function () {
                var modalElement = document.getElementById(@json('manage-kyc-provider-'.str_replace('_', '-', old('driver'))));

                if (modalElement && window.coreui && window.coreui.Modal) {
                    window.coreui.Modal.getOrCreateInstance(modalElement).show();
                }
            });
        </script>
    @endpush
@endif
