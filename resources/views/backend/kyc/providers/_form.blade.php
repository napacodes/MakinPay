@php
    $catalog = $catalog ?? (array) config('kyc.catalog', []);
    $modalMode = $modal ?? false;
    $useOldInput = ! $modalMode || old('driver') === ($provider->driver ?? '');
    $selectedDriver = $useOldInput ? old('driver', $provider->driver ?? '') : ($provider->driver ?? '');
    $entry = $catalog[$selectedDriver] ?? null;
    $displayName = $useOldInput ? old('name', $provider->name ?: ($entry['name'] ?? '')) : ($provider->name ?: ($entry['name'] ?? ''));
    $codeValue = $useOldInput ? old('code', $provider->code ?: str_replace('_', '-', (string) $selectedDriver)) : ($provider->code ?: str_replace('_', '-', (string) $selectedDriver));
    $instanceId = 'kyc-'.str_replace('_', '-', $selectedDriver).($modalMode ? '-modal' : '-page');

    $selectedCountries = collect($useOldInput ? old('countries', $provider->countries ?? []) : ($provider->countries ?? []))
        ->map(fn ($country) => strtoupper((string) $country))
        ->all();
    $coversAll = $selectedCountries === [] || in_array('*', $selectedCountries, true);
    $hasSavedKeys = filled($provider->credentials);

    $credentialFields = [
        'smile_id' => ['partner_id' => __('Partner ID'), 'api_key' => __('API Key')],
        'shufti'   => ['client_id' => __('Client ID'), 'secret_key' => __('Secret Key')],
        'sumsub'   => ['app_token' => __('App Token'), 'secret_key' => __('Secret Key'), 'level_name' => __('Level Name'), 'webhook_secret' => __('Webhook Secret')],
    ];
    $fields = $credentialFields[$selectedDriver] ?? [];
    $selectedMode = $useOldInput ? old('mode', $provider->mode ?? 'sandbox') : ($provider->mode ?? 'sandbox');
    $selectedStatus = $useOldInput ? old('status', $provider->status ?? false) : ($provider->status ?? false);
    $webhookUrl = route('webhooks.kyc', ['provider' => $codeValue ?: 'provider-code']);
@endphp

<form action="{{ $action }}" method="POST" class="kyc-setup pgm-form" data-kyc-provider-form>
    @csrf
    @if(($method ?? 'POST') === 'PUT')
        @method('PUT')
    @endif

    <input type="hidden" name="driver" value="{{ $selectedDriver }}">
    <input type="hidden" name="name" value="{{ $displayName }}">
    <input type="hidden" name="code" value="{{ $codeValue }}">
    <input type="hidden" name="priority" value="{{ $useOldInput ? old('priority', $provider->priority ?? 0) : ($provider->priority ?? 0) }}">
    @if($provider->exists)
        <input type="hidden" name="provider_id" value="{{ $provider->getKey() }}">
    @endif

    @error('driver')
        <div class="alert alert-danger mb-0">{{ $message }}</div>
    @enderror

    <div class="pgm-provider kpm-provider">
        <div class="pgm-provider__logo kpm-provider__logo">
            @if(! empty($entry['logo']))
                <img src="{{ asset($entry['logo']) }}" alt="{{ $displayName }}" loading="lazy">
            @else
                <i class="{{ $entry['icon'] ?? 'fa-solid fa-shield-halved' }}" aria-hidden="true"></i>
            @endif
        </div>
        <div class="pgm-provider__meta">
            <span>{{ __('Verification engine') }}</span>
            <strong>{{ $displayName ?: __('KYC Provider') }}</strong>
            <small>{{ $selectedDriver }}</small>
        </div>
        <span class="pgm-provider__badge {{ $provider->status ? 'pgm-provider__badge--active' : 'pgm-provider__badge--inactive' }}">
            {{ $provider->status ? __('Active engine') : ($hasSavedKeys ? __('Ready') : __('Setup required')) }}
        </span>
    </div>

    <div class="kpm-grid">
        <div class="pgm-panel kpm-panel">
            <div class="pgm-panel__head">
                <div>
                    <span class="kpm-step"><i class="fa-solid fa-earth-americas" aria-hidden="true"></i></span>
                    <h3>{{ __('Coverage & routing') }}</h3>
                    <p>{{ __('Choose which customers should be verified by this provider.') }}</p>
                </div>
                <span class="pgm-panel__pill">{{ __('Step 1') }}</span>
            </div>

            <div class="kyc-setup__choice-row" role="radiogroup" aria-label="{{ __('Coverage') }}">
                <label class="kyc-setup__choice {{ $coversAll ? 'is-selected' : '' }}">
                    <input type="radio" name="coverage_scope" value="all" data-kyc-scope @checked($coversAll)>
                    <span class="kyc-setup__choice-icon"><i class="fa-solid fa-globe" aria-hidden="true"></i></span>
                    <span class="kyc-setup__choice-body">
                        <strong>{{ __('All countries') }}</strong>
                        <small>{{ __('Global verification routing') }}</small>
                    </span>
                    <i class="fa-solid fa-circle-check kpm-choice-check" aria-hidden="true"></i>
                </label>
                <label class="kyc-setup__choice {{ $coversAll ? '' : 'is-selected' }}">
                    <input type="radio" name="coverage_scope" value="specific" data-kyc-scope @checked(! $coversAll)>
                    <span class="kyc-setup__choice-icon"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
                    <span class="kyc-setup__choice-body">
                        <strong>{{ __('Selected countries') }}</strong>
                        <small>{{ __('Route only chosen markets') }}</small>
                    </span>
                    <i class="fa-solid fa-circle-check kpm-choice-check" aria-hidden="true"></i>
                </label>
            </div>

            <input type="hidden" name="countries[]" value="*" data-kyc-all-input @disabled(! $coversAll)>

            <div class="kpm-country-picker mt-3 {{ $coversAll ? 'd-none' : '' }}" data-kyc-country-wrap>
                <label class="form-label" for="{{ $instanceId }}-countries">{{ __('Countries') }}</label>
                <select id="{{ $instanceId }}-countries" name="countries[]" class="form-select pgm-input" multiple size="7"
                        data-kyc-country-select @disabled($coversAll)>
                    @foreach($countries as $country)
                        <option value="{{ $country['code'] }}" @selected(in_array($country['code'], $selectedCountries, true))>
                            {{ getCountryFlagEmoji($country['code']) }} {{ $country['name'] }} ({{ $country['code'] }})
                        </option>
                    @endforeach
                </select>
                <small class="pgm-help">{{ __('Use Ctrl / Cmd to select multiple markets.') }}</small>
                @error('countries')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                @error('countries.*')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>

            <div class="kpm-mode mt-3" role="group" aria-label="{{ __('Environment') }}">
                <span class="kpm-mode__label">{{ __('Environment') }}</span>
                <div class="kpm-mode__options">
                    <input type="radio" class="btn-check" name="mode" id="{{ $instanceId }}-sandbox" value="sandbox"
                           @checked($selectedMode !== 'live')>
                    <label class="kpm-mode__option" for="{{ $instanceId }}-sandbox">
                        <i class="fa-solid fa-flask" aria-hidden="true"></i>{{ __('Sandbox') }}
                    </label>
                    <input type="radio" class="btn-check" name="mode" id="{{ $instanceId }}-live" value="live"
                           @checked($selectedMode === 'live')>
                    <label class="kpm-mode__option" for="{{ $instanceId }}-live">
                        <i class="fa-solid fa-tower-broadcast" aria-hidden="true"></i>{{ __('Live') }}
                    </label>
                </div>
            </div>
        </div>

        <div class="pgm-panel kpm-panel">
            <div class="pgm-panel__head">
                <div>
                    <span class="kpm-step"><i class="fa-solid fa-key" aria-hidden="true"></i></span>
                    <h3>{{ __('Credential vault') }}</h3>
                    <p>{{ __('Securely connect the provider API and result webhook.') }}</p>
                </div>
                <span class="pgm-panel__pill">{{ trans_choice(':count key|:count keys', count($fields), ['count' => count($fields)]) }}</span>
            </div>

            @if($hasSavedKeys)
                <div class="kyc-setup__saved-note" role="status">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <span><strong>{{ __('Credentials protected') }}</strong>{{ __('Leave a field blank to keep its saved value.') }}</span>
                </div>
            @endif

            <div class="pgm-fields kpm-fields">
                @foreach($fields as $fieldKey => $fieldLabel)
                    <div class="pgm-field">
                        <label class="form-label" for="{{ $instanceId }}-{{ $fieldKey }}">{{ $fieldLabel }}</label>
                        <div class="pgm-secret-field">
                            <span class="pgm-secret-field__icon"><i class="fa-solid fa-key" aria-hidden="true"></i></span>
                            <input type="password" autocomplete="off" data-lpignore="true" data-1p-ignore
                                   data-form-type="other" id="{{ $instanceId }}-{{ $fieldKey }}"
                                   name="credentials[{{ $fieldKey }}]" class="form-control pgm-input"
                                   value="{{ $useOldInput ? old('credentials.'.$fieldKey) : '' }}"
                                   placeholder="{{ $hasSavedKeys ? __('Saved securely — enter to replace') : __('Enter :field', ['field' => strtolower($fieldLabel)]) }}">
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="kpm-webhook">
                <div class="kpm-webhook__copy">
                    <span class="kpm-webhook__icon"><i class="fa-solid fa-link" aria-hidden="true"></i></span>
                    <div>
                        <strong>{{ __('Result webhook') }}</strong>
                        <small>{{ __('Add this endpoint in the provider dashboard.') }}</small>
                    </div>
                </div>
                <div class="input-group pgm-copy-group">
                    <input type="text" id="{{ $instanceId }}-webhook" class="form-control pgm-input" value="{{ $webhookUrl }}" readonly>
                    <button type="button" class="btn btn-outline-info copyNow" data-clipboard-target="#{{ $instanceId }}-webhook"
                            title="{{ __('Copy webhook URL') }}" aria-label="{{ __('Copy webhook URL') }}">
                        <i class="fa-solid fa-copy" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="pgm-panel pgm-connection-check kpm-panel kpm-health">
        <div class="pgm-panel__head">
            <div>
                <span class="kpm-step"><i class="fa-solid fa-heart-pulse" aria-hidden="true"></i></span>
                <h3>{{ __('Provider connection test') }}</h3>
                <p>{{ __('Validate the credentials against the selected environment before saving.') }}</p>
            </div>
            <span class="pgm-panel__pill pgm-panel__pill--success">
                <i class="fa-solid fa-lock" aria-hidden="true"></i><span>{{ __('Read-only') }}</span>
            </span>
        </div>

        <div class="pgm-check-control kpm-health__control">
            <div class="kpm-health__note">
                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                <span>{{ __('No verification or applicant will be created.') }}</span>
            </div>
            <button class="btn btn-outline-primary pgm-test-btn" type="button"
                    data-kyc-provider-test
                    data-test-url="{{ route('admin.kyc.providers.test') }}"
                    data-testing-label="{{ __('Testing...') }}"
                    data-testing-message="{{ __('Contacting the provider securely...') }}"
                    data-fallback-message="{{ __('Unable to test this provider right now.') }}">
                <span class="pgm-test-btn__icon" aria-hidden="true">
                    <i class="fa-solid fa-circle-check"></i>
                </span>
                <span class="pgm-test-btn__content">
                    <strong data-kyc-provider-test-label>{{ __('Test connection') }}</strong>
                    <small>{{ __('Credential health check') }}</small>
                </span>
            </button>
        </div>

        <div class="pgm-test-result d-none" data-kyc-provider-test-result role="status" aria-live="polite"></div>
    </div>

    <div class="pgm-panel kpm-activation">
        <label class="kpm-activation__label" for="{{ $instanceId }}-status">
            <span class="kpm-activation__icon"><i class="fa-solid fa-bolt" aria-hidden="true"></i></span>
            <span class="kpm-activation__copy">
                <strong>{{ __('Make this the active verification engine') }}</strong>
                <small>{{ __('Only one provider can run at a time. Enabling this one safely pauses the current engine.') }}</small>
            </span>
            <span class="form-check form-switch kpm-switch">
                <input type="hidden" name="status" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="{{ $instanceId }}-status" name="status" value="1"
                       @checked($selectedStatus)>
            </span>
        </label>
    </div>

    <div class="pgm-form__footer kpm-footer">
        @if($modalMode)
            <button type="button" class="btn btn-outline-secondary" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
        @else
            <a href="{{ route('admin.kyc.providers.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
        @endif
        <button type="submit" class="btn btn-primary pgm-submit">
            <i class="fa-solid fa-check" aria-hidden="true"></i>
            {{ $provider->exists ? __('Save changes') : __('Connect provider') }}
        </button>
    </div>
</form>
