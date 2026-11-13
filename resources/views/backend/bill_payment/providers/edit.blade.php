@extends('backend.bill_payment.layout')

@section('title', __('Edit provider'))

@section('sub_header')
    <x-page-header
        :title="$provider->name"
        :subtitle="__('Fee changes apply to new payments. Payments already made keep the fee they were charged.')"
        icon="receipt"
        :back="route('admin.bill-payment.providers.index')"
        :badge="$provider->code"
        :status="match (true) {
            ! $provider->status   => ['tone' => 'neutral', 'label' => __('Disabled')],
            $provider->isPaused() => ['tone' => 'warning', 'label' => __('Paused')],
            default               => ['tone' => 'success', 'label' => __('Active')],
        }"
    />
@endsection

@php
    $setupPlugin     = $setup['plugin'];
    $canEditPlugin   = $setupPlugin !== null && auth('admin')->user()?->can('plugins-manage');
    $hasSetupPanels  = $setup['api'] || $setup['diagnostics'] || $setup['webhooks'] || $setup['live_checks'] !== [] || $setup['sync'];
    $syncErrors      = $errors->getBag('sync');
    $oldSyncCountries = old('countries', $setup['sync_countries']);
    $oldSyncCountries = is_array($oldSyncCountries) ? implode(', ', array_filter($oldSyncCountries, 'is_string')) : (string) $oldSyncCountries;
    $oldSyncCategories = array_filter((array) old('categories', []), 'is_string');
    $oldSyncDryRun   = (string) old('dry_run', '1') === '1';
    $skipLabels      = \App\Http\Controllers\Backend\BillPayment\BillPaymentProviderController::SKIP_REASON_LABELS;
    $conflictLabels  = [
        \App\Services\BillPayment\Sync\BillerSyncReport::CONFLICT_LIMITS           => __('limits no longer fit the provider\'s range'),
        \App\Services\BillPayment\Sync\BillerSyncReport::CONFLICT_CURRENCY_CHANGED => __('the provider changed the currency'),
    ];
    $pluginStates    = [
        \App\Models\Plugin::STATE_ACTIVE => ['tone' => 'success', 'label' => __('Ready')],
        \App\Models\Plugin::STATE_SETUP  => ['tone' => 'warning', 'label' => __('Keys missing')],
        \App\Models\Plugin::STATE_OFF    => ['tone' => 'secondary', 'label' => __('Switched off')],
    ];
@endphp

@section('sub_content')
    @if($provider->isPaused())
        {{-- BP1: the breaker's own notice, above everything it affects. --}}
        <div class="alert alert-warning d-flex gap-2 align-items-start" role="alert" data-bpa-provider-paused>
            <i class="fa-solid fa-circle-pause mt-1" aria-hidden="true"></i>
            <div>
                <strong>{{ __('Paused :when: :reason.', ['when' => $provider->paused_at?->diffForHumans(), 'reason' => $provider->pause_reason?->label() ?? __('Provider fault')]) }}</strong>
                @if(filled($provider->pause_message))
                    <div class="small">{{ __('The provider said: :message', ['message' => $provider->pause_message]) }}</div>
                @endif
                <div class="small">{{ __('Customers see this provider\'s billers as temporarily unavailable. Payments already sent to it are not affected. Fix the account, then run the connection test below: a passing test resumes it.') }}</div>
            </div>
        </div>
    @endif

    @include('backend.bill_payment.providers._form', [
        'action'      => route('admin.bill-payment.providers.update', $provider),
        'method'      => 'PUT',
        'submitLabel' => __('Save changes'),
    ])

    @if($hasSetupPanels)
        {{--
            Outside the form on purpose: nothing here is saved by "Save
            changes". Credentials are edited in the Integration Center's own
            dialog, and the test and the webhook address are read-only.
        --}}
        <div class="bpa-setup" data-bpa-provider-setup>
            @if($setup['api'])
                <section class="card border-0 bpa-card bpa-setup__panel" aria-labelledby="bpa-setup-credentials">
                    <div class="card-body">
                        <h2 class="bpa-form-section__title" id="bpa-setup-credentials">{{ __('Credentials') }}</h2>
                        <p class="bpa-form-section__hint">{{ __('The API keys live in the Integration Center, which never shows a saved secret again.') }}</p>

                        @if($setupPlugin !== null)
                            @php
                                $pluginState = $pluginStates[$setupPlugin->state] ?? $pluginStates[\App\Models\Plugin::STATE_SETUP];
                                $missingKeys = $setupPlugin->missingCredentialKeys();
                            @endphp
                            <dl class="bpa-setup__facts">
                                <div>
                                    <dt>{{ __('Status') }}</dt>
                                    <dd><span class="bpa-pill bpa-pill--{{ $pluginState['tone'] }}" data-bpa-plugin-state="{{ $setupPlugin->state }}">{{ $pluginState['label'] }}</span></dd>
                                </div>
                                @if($missingKeys !== [])
                                    <div>
                                        <dt>{{ __('Still needed') }}</dt>
                                        <dd>{{ collect($missingKeys)->map(fn (string $key): string => ucwords(str_replace('_', ' ', $key)))->implode(', ') }}</dd>
                                    </div>
                                @endif
                                <div>
                                    <dt>{{ __('Last test') }}</dt>
                                    <dd>{{ $setupPlugin->last_tested_at ? __('Passed :when', ['when' => $setupPlugin->last_tested_at->diffForHumans()]) : __('Not tested') }}</dd>
                                </div>
                            </dl>

                            @if($canEditPlugin)
                                <button type="button" class="btn btn-sm btn-outline-primary edit-modal"
                                        data-edit-url="{{ route('admin.settings.plugin.edit', $setupPlugin->id) }}">
                                    {{ __('Edit credentials') }}
                                </button>
                            @else
                                <p class="bpa-muted mb-0">{{ __('An administrator with Integration Center access sets these credentials.') }}</p>
                            @endif
                        @else
                            <p class="bpa-muted mb-0">{{ __('No credentials record is linked yet. Save this provider once to create it.') }}</p>
                        @endif
                    </div>
                </section>
            @endif

            @if($setup['diagnostics'])
                <section class="card border-0 bpa-card bpa-setup__panel" aria-labelledby="bpa-setup-test">
                    <div class="card-body">
                        <h2 class="bpa-form-section__title" id="bpa-setup-test">{{ __('Connection test') }}</h2>
                        <p class="bpa-form-section__hint">{{ __('Asks the provider whether the saved credentials work. Nothing is paid.') }}</p>

                        @if($provider->balance_checked_at !== null && $provider->balance_amount !== null)
                            <dl class="bpa-setup__facts" data-bpa-provider-balance>
                                <div>
                                    <dt>{{ __('Provider balance') }}</dt>
                                    <dd dir="ltr">{{ \App\Support\DecimalMoney::normalize((string) $provider->balance_amount, 2) }} {{ $provider->balance_currency }}</dd>
                                </div>
                                <div>
                                    <dt>{{ __('Read') }}</dt>
                                    <dd>{{ $provider->balance_checked_at->diffForHumans() }}</dd>
                                </div>
                            </dl>
                        @endif

                        <button type="button" class="btn btn-sm btn-outline-primary"
                                data-bpa-provider-test
                                data-test-url="{{ route('admin.bill-payment.providers.test-connection', $provider) }}"
                                data-result-target="#bpa-provider-test-result"
                                data-testing-label="{{ __('Testing…') }}"
                                data-testing-message="{{ __('Contacting the provider…') }}"
                                data-fallback-message="{{ __('The connection test could not be completed.') }}"
                                data-resumed-message="{{ __('The provider was paused and has been resumed: new payments are taken again.') }}">
                            {{ __('Test connection') }}
                        </button>

                        <div id="bpa-provider-test-result" class="bpa-setup__result" role="status" aria-live="polite" hidden></div>
                    </div>
                </section>
            @endif

            @if($setup['webhooks'] && $setup['webhook_url'] !== null)
                <section class="card border-0 bpa-card bpa-setup__panel" aria-labelledby="bpa-setup-webhook">
                    <div class="card-body">
                        <h2 class="bpa-form-section__title" id="bpa-setup-webhook">{{ __('Webhook') }}</h2>
                        <p class="bpa-form-section__hint">{{ __('Payments are confirmed by checking with :provider; the webhook only makes that faster.', ['provider' => $provider->name]) }}</p>

                        @if($setup['deposit_webhook_url'] !== null)
                            <p class="bpa-form-section__hint" data-bpa-webhook-deposit>{{ __('This site also takes Flutterwave deposits, and a Flutterwave account has one dashboard webhook URL. Keep it on the deposit address below: bill payment events are handed over from there.') }}</p>

                            <span class="bpa-copyable bpa-setup__url">
                                <span class="bpa-mono" dir="ltr" data-bpa-webhook-url>{{ $setup['deposit_webhook_url'] }}</span>
                                <button type="button" class="bpa-copy" data-bpa-copy="{{ $setup['deposit_webhook_url'] }}" aria-label="{{ __('Copy the webhook URL') }}" title="{{ __('Copy') }}">
                                    <x-icon name="clipboard" width="14" height="14"/>
                                </button>
                            </span>

                            <ol class="bpa-setup__steps">
                                <li>{{ __('In your :provider dashboard, leave Settings → Webhooks on the deposit address. Replacing it with a bill payment address stops deposits being confirmed by webhook.', ['provider' => $provider->name]) }}</li>
                                <li>{{ __('Set Webhook secret in these credentials to the same value as the Secret hash of the Flutterwave deposit gateway, or leave it blank.') }}</li>
                                <li>{{ __('Whitelist your server\'s IP address with :provider.', ['provider' => $provider->name]) }}</li>
                            </ol>
                        @else
                            <span class="bpa-copyable bpa-setup__url">
                                <span class="bpa-mono" dir="ltr" data-bpa-webhook-url>{{ $setup['webhook_url'] }}</span>
                                <button type="button" class="bpa-copy" data-bpa-copy="{{ $setup['webhook_url'] }}" aria-label="{{ __('Copy the webhook URL') }}" title="{{ __('Copy') }}">
                                    <x-icon name="clipboard" width="14" height="14"/>
                                </button>
                            </span>

                            <ol class="bpa-setup__steps">
                                <li>{{ __('In your :provider dashboard, paste it under Settings → Webhooks. Only for an account that takes no deposits on this site: a Flutterwave account has one dashboard webhook URL.', ['provider' => $provider->name]) }}</li>
                                <li>{{ __('Set the Secret hash there to the same value as Webhook secret in these credentials.') }}</li>
                                <li>{{ __('Whitelist your server\'s IP address with :provider.', ['provider' => $provider->name]) }}</li>
                            </ol>
                        @endif
                    </div>
                </section>
            @endif

            @if($setup['sync'])
                <section class="card border-0 bpa-card bpa-setup__panel bpa-setup__panel--wide" aria-labelledby="bpa-setup-sync" data-bpa-sync>
                    <div class="card-body">
                        <h2 class="bpa-form-section__title" id="bpa-setup-sync">{{ __('Catalogue sync') }}</h2>
                        <p class="bpa-form-section__hint">{{ __('Imports the provider\'s billers for each country. New billers arrive switched off, nothing is ever deleted, and the names, fees, labels and limits you edit are kept.') }}</p>

                        <form method="POST" action="{{ route('admin.bill-payment.providers.sync', $provider) }}" novalidate>
                            @csrf

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="bpa-sync-countries">{{ __('Countries') }}</label>
                                    <input type="text" id="bpa-sync-countries" name="countries" dir="ltr" maxlength="300"
                                           value="{{ $oldSyncCountries }}"
                                           class="form-control @if($syncErrors->has('countries') || $syncErrors->has('countries.*')) is-invalid @endif"
                                           placeholder="NG, KE">
                                    @if($syncErrors->has('countries') || $syncErrors->has('countries.*'))
                                        <div class="invalid-feedback">{{ $syncErrors->first('countries') ?: $syncErrors->first('countries.*') }}</div>
                                    @endif
                                    <div class="form-text">{{ __('Two-letter codes, separated by commas. Left blank, the sync countries saved above are used.') }}</div>
                                </div>

                                <div class="col-md-6">
                                    <span class="form-label d-block">{{ __('Categories') }}</span>
                                    @forelse($setup['sync_categories'] as $categoryCode => $categoryName)
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="categories[]" value="{{ $categoryCode }}"
                                                   id="bpa-sync-category-{{ $categoryCode }}"
                                                   @checked(in_array($categoryCode, $oldSyncCategories, true))>
                                            <label class="form-check-label" for="bpa-sync-category-{{ $categoryCode }}">{{ $categoryName }}</label>
                                        </div>
                                    @empty
                                        <p class="bpa-muted mb-0">{{ __('None of the categories this provider maps exists here yet.') }}</p>
                                    @endforelse
                                    @if($syncErrors->has('categories') || $syncErrors->has('categories.*'))
                                        <div class="text-danger small mt-1">{{ $syncErrors->first('categories') ?: $syncErrors->first('categories.*') }}</div>
                                    @endif
                                    <div class="form-text">{{ __('Leave every box clear to sync all of them.') }}</div>
                                </div>
                            </div>

                            <div class="bpa-sync__options">
                                <div class="form-check form-switch mb-0">
                                    <input type="hidden" name="dry_run" value="0">
                                    <input class="form-check-input" type="checkbox" role="switch" name="dry_run" value="1"
                                           id="bpa-sync-dry-run" @checked($oldSyncDryRun)>
                                    <label class="form-check-label" for="bpa-sync-dry-run">{{ __('Preview only: count what would change and write no billers') }}</label>
                                </div>

                                <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('Queue sync') }}</button>
                            </div>
                        </form>

                        @if($setup['sync_state'] !== [])
                            <div class="bpa-sync__results" data-bpa-sync-state>
                                @foreach($setup['sync_state'] as $syncCountry => $syncRun)
                                    @php
                                        $syncReport = is_array($syncRun['report'] ?? null) ? $syncRun['report'] : [];
                                        try {
                                            $syncAt = isset($syncRun['at']) ? \Illuminate\Support\Carbon::parse((string) $syncRun['at']) : null;
                                        } catch (\Throwable) {
                                            $syncAt = null;
                                        }
                                        $syncSkipped   = is_array($syncReport['skipped'] ?? null) ? $syncReport['skipped'] : [];
                                        $syncConflicts = is_array($syncReport['conflicts'] ?? null) ? $syncReport['conflicts'] : [];
                                    @endphp
                                    <article class="bpa-sync__run" data-bpa-sync-country="{{ $syncCountry }}">
                                        <h3 class="bpa-sync__run-head h6 mb-2">
                                            <span class="bpa-mono">{{ $syncCountry }}</span>
                                            @if(! empty($syncRun['dry_run']))
                                                <span class="bpa-pill bpa-pill--info">{{ __('Preview') }}</span>
                                            @endif
                                            @if(! empty($syncReport['error']))
                                                <span class="bpa-pill bpa-pill--danger">{{ __('Failed') }}</span>
                                            @endif
                                            @if($syncAt !== null)
                                                <span class="bpa-muted small">{{ $syncAt->diffForHumans() }}</span>
                                            @endif
                                        </h3>

                                        @if(! empty($syncReport['error']))
                                            <p class="text-danger small mb-0">{{ $syncReport['error'] }}</p>
                                        @else
                                            <dl class="bpa-setup__facts">
                                                <div><dt>{{ __('Billers') }}</dt><dd>{{ __(':created new, :updated updated, :unchanged unchanged', ['created' => (int) ($syncReport['created'] ?? 0), 'updated' => (int) ($syncReport['updated'] ?? 0), 'unchanged' => (int) ($syncReport['unchanged'] ?? 0)]) }}</dd></div>
                                                <div><dt>{{ __('Gone missing') }}</dt><dd>{{ __(':count switched off', ['count' => (int) ($syncReport['disabled_missing'] ?? 0)]) }}</dd></div>
                                                <div><dt>{{ __('Packages') }}</dt><dd>{{ __(':created new, :updated updated, :disabled switched off', ['created' => (int) ($syncReport['items_created'] ?? 0), 'updated' => (int) ($syncReport['items_updated'] ?? 0), 'disabled' => (int) ($syncReport['items_disabled'] ?? 0)]) }}</dd></div>
                                            </dl>

                                            @if($syncSkipped !== [])
                                                <p class="bpa-muted small mb-1">{{ __('Not imported') }}</p>
                                                <ul class="bpa-sync__skips">
                                                    @foreach($syncSkipped as $skipReason => $skipCount)
                                                        <li>{{ __($skipLabels[$skipReason] ?? (string) $skipReason) }}: {{ (int) $skipCount }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif

                                            @if($syncConflicts !== [])
                                                <p class="bpa-muted small mb-1 mt-2">{{ __('Switched off for a conflict') }}</p>
                                                <ul class="bpa-sync__skips">
                                                    @foreach(array_slice($syncConflicts, 0, 10) as $syncConflict)
                                                        <li><span class="bpa-mono">{{ $syncConflict['external_code'] ?? '' }}</span>: {{ $conflictLabels[$syncConflict['kind'] ?? ''] ?? ($syncConflict['kind'] ?? '') }}</li>
                                                    @endforeach
                                                    @if(count($syncConflicts) > 10)
                                                        <li>{{ __('and :count more', ['count' => count($syncConflicts) - 10]) }}</li>
                                                    @endif
                                                </ul>
                                            @endif
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        @else
                            <p class="bpa-muted small mt-3 mb-0">{{ __('No sync has run for this provider yet.') }}</p>
                        @endif

                        <p class="bpa-sync__footer mb-0">
                            <a href="{{ route('admin.bill-payment.billers.index', ['provider' => $provider->id]) }}">{{ __('View this provider\'s billers') }}</a>
                        </p>
                    </div>
                </section>
            @endif

            @if($setup['live_checks'] !== [])
                <section class="card border-0 bpa-card bpa-setup__panel bpa-setup__panel--wide" aria-labelledby="bpa-setup-live">
                    <div class="card-body">
                        <h2 class="bpa-form-section__title" id="bpa-setup-live">{{ __('Live check pending') }}</h2>
                        <p class="bpa-form-section__hint">{{ __('This driver was built from the provider\'s documentation without live credentials. Confirm these in sandbox before switching the provider on.') }}</p>

                        <ul class="bpa-setup__checks" data-bpa-live-checks>
                            @foreach($setup['live_checks'] as $check)
                                <li>{{ $check }}</li>
                            @endforeach
                        </ul>
                    </div>
                </section>
            @endif
        </div>
    @endif

    @if($canEditPlugin)
        @include('backend.settings.plugin.partials._manage')
    @endif
@endsection

@push('scripts')
    @if($setup['diagnostics'])
        <script src="{{ asset('backend/js/bill-payment-provider.js?v=' . config('app.version') . '-' . filemtime(public_path('backend/js/bill-payment-provider.js'))) }}"></script>
    @endif
    @if($canEditPlugin)
        @include('backend.settings.plugin.partials._scripts')
    @endif
@endpush
