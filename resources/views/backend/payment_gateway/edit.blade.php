@use('App\Support\GatewayCredentialLabel')

@php
    // Classified by key name (GatewayCredentialLabel::isToggle), not by the
    // stored value's PHP type — a toggle saved through this form round-trips
    // through HTTP as the string "0"/"1", so a type check stops recognising
    // it as a toggle after the first save.
    $credentialEntries       = collect($paymentGateway->credentials);
    $credentialTextEntries   = $credentialEntries->reject(fn ($value, $key) => GatewayCredentialLabel::isToggle($key));
    $credentialToggleEntries = $credentialEntries->filter(fn ($value, $key) => GatewayCredentialLabel::isToggle($key));
@endphp

<form method="POST"
      action="{{ route('admin.payment.gateway.update', ['gateway' => $paymentGateway->id]) }}"
      enctype="multipart/form-data"
      class="pgm-form pgm-form--compact pgm-form--access">
    @method('PUT')
    @csrf

    <div class="pgm-provider">
        <div class="pgm-provider__logo">
            <img src="{{ asset($paymentGateway->logo) }}" alt="{{ $paymentGateway->name }}" loading="lazy">
        </div>
        <div class="pgm-provider__meta">
            <span>{{ __('Connected provider') }}</span>
            <strong>{{ $paymentGateway->name }}</strong>
            <small>{{ Str::upper($paymentGateway->code) }}</small>
        </div>
        <span
            class="pgm-provider__badge {{ $paymentGateway->status ? 'pgm-provider__badge--active' : 'pgm-provider__badge--inactive' }}">
			<i class="fa-solid {{ $paymentGateway->status ? 'fa-circle-check' : 'fa-circle-pause' }}"
               aria-hidden="true"></i>
            {{ $paymentGateway->status ? __('API enabled') : __('API disabled') }}
        </span>
    </div>

    <input name="name" value="{{ $paymentGateway->name }}" type="hidden">

    <div class="pgm-panel pgm-credential-vault">
        <div class="pgm-panel__head">
            <div class="pgm-panel__title">
                <span class="pgm-panel__icon"><i class="fa-solid fa-key" aria-hidden="true"></i></span>
                <div>
                    <h3>{{ __('Credential vault') }}</h3>
                    <p>{{ __('Paste the credentials issued by the provider') }}</p>
                </div>
            </div>
        </div>

        @if($credentialTextEntries->isEmpty())
            <x-admin-not-found
                :title="__('No credentials to configure')"
                :message="__('This provider does not expose any API credentials, so there is nothing to store here.')"
                icon="fa-key"
            />
        @else
            <div class="pgm-fields pgm-credential-list">
                @foreach($credentialTextEntries as $key => $value)
                    @php
                        $label      = GatewayCredentialLabel::label($key);
                        $fieldId    = 'credential_'.$paymentGateway->id.'_'.$loop->index;
                        $isOptional = \App\Models\PaymentGateway::isOptionalCredential($paymentGateway->code, $key);
                    @endphp

                    <div class="pgm-credential-row">
                        <div class="pgm-credential-row__label">
                            <label class="form-label" for="{{ $fieldId }}">{{ __($label) }}</label>
                        </div>
                        <div class="pgm-secret-field pgm-access-input">
                            <input
                                class="form-control pgm-input"
                                type="text"
                                name="credentials[{{ $key }}]"
                                id="{{ $fieldId }}"
                                value="{{ $value }}"
                                placeholder="{{ __('Paste :label', ['label' => __($label)]) }}"
                                autocomplete="off"
                                spellcheck="false"
                                data-lpignore="true"
                                data-1p-ignore="true"
                                data-bwignore="true"
                                data-form-type="other"
                                @if($isOptional)aria-describedby="{{ $fieldId }}_hint" @else required @endif>
                            @if($isOptional)
                                <small class="pgm-help" id="{{ $fieldId }}_hint">{{ __('Optional. Leave blank if the provider issued only one key.') }}</small>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="pgm-access-grid">
        @if($paymentGateway->ipn)
            <div class="pgm-panel pgm-panel--webhook pgm-access-panel">
                <div class="pgm-panel__head">
                    <div class="pgm-panel__title">
                        <span class="pgm-panel__icon"><i class="fa-solid fa-link" aria-hidden="true"></i></span>
                        <div>
                            <h3>{{ __('Webhook endpoint') }}</h3>
                            <p>{{ __('Receive provider events in DigiKash') }}</p>
                        </div>
                    </div>
                </div>

                <div class="pgm-webhook-flow"
                     aria-label="{{ __('Provider events are delivered to DigiKash') }}">
                    <span class="pgm-webhook-flow__node">{{ __('Provider events') }}</span>
                    <span class="pgm-webhook-flow__connector" aria-hidden="true"></span>
                    <span class="pgm-webhook-flow__node pgm-webhook-flow__node--target">{{ __('DigiKash') }}</span>
                </div>

                <div class="pgm-field mb-0">
                    <div class="input-group pgm-copy-group">
                        <code class="pgm-webhook-value" id="ipn_url">{{ url('/ipn/'.$paymentGateway->code) }}</code>
                        <button class="pgm-copy-action copyNow"
                                type="button"
                                data-clipboard-target="#ipn_url"
                                data-coreui-placement="top"
                                data-coreui-toggle="tooltip"
                                title="{{ __('Copy Webhook URL') }}"
                                aria-label="{{ __('Copy Webhook URL') }}">
                            <i class="fa-regular fa-copy" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>
        @endif

        <div class="pgm-panel pgm-connection-check pgm-access-panel">
            <div class="pgm-panel__head">
                <div class="pgm-panel__title">
                    <span class="pgm-panel__icon"><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i></span>
                    <div>
                        <h3>{{ __('Connection diagnostics') }}</h3>
                        <p>{{ __('Validate credentials with the provider') }}</p>
                    </div>
                </div>
            </div>

            <div class="pgm-check-control">
                <div class="pgm-mode-group" role="group" aria-label="{{ __('Credential test environment') }}">
                    <input class="btn-check" type="radio" name="test_mode" id="test_mode_current" value="current"
                           checked>
                    <label class="pgm-mode-option" for="test_mode_current">
                        <span>{{ __('Current') }}</span>
                    </label>

                    <input class="btn-check" type="radio" name="test_mode" id="test_mode_live" value="live">
                    <label class="pgm-mode-option" for="test_mode_live">
                        <span>{{ __('Live') }}</span>
                    </label>

                    <input class="btn-check" type="radio" name="test_mode" id="test_mode_sandbox" value="sandbox">
                    <label class="pgm-mode-option" for="test_mode_sandbox">
                        <span>{{ __('Sandbox') }}</span>
                    </label>
                </div>

                <button class="btn btn-outline-primary pgm-test-btn"
                        type="button"
                        data-gateway-test
                        data-test-url="{{ route('admin.payment.gateway.test', ['gateway' => $paymentGateway->id]) }}"
                        data-testing-label="{{ __('Checking...') }}"
                        data-testing-message="{{ __('Validating gateway credentials...') }}"
                        data-fallback-message="{{ __('Unable to validate this gateway right now.') }}">
					<span class="pgm-test-btn__icon" aria-hidden="true">
						<i class="fa-solid fa-plug"></i>
					</span>
                    <span class="pgm-test-btn__content">
						<strong data-gateway-test-label>{{ __('Run diagnostics') }}</strong>
					</span>
                </button>
            </div>

            <div class="pgm-test-result d-none" data-gateway-test-result></div>
        </div>
    </div>

    <div class="pgm-panel pgm-access-status">
        <div class="pgm-panel__head">
            <div class="pgm-panel__title">
                {{--				<span class="pgm-panel__icon"><i class="fa-solid fa-toggle-on" aria-hidden="true"></i></span>--}}
                <div>
                    <h3>{{ __('Runtime settings') }}</h3>
                    <p>{{ __('Control how this integration behaves in production') }}</p>
                </div>
            </div>
        </div>

        <div class="pgm-switch-grid">
            @foreach($credentialToggleEntries as $key => $value)
                @php
                    $checked     = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    $toggleTitle = match ($key) {
                        'sandbox'            => __('Sandbox Mode'),
                        'remittance_enabled' => __('Remittance Payouts'),
                        default              => __(GatewayCredentialLabel::label($key)),
                    };
                    $toggleHint = match ($key) {
                        'sandbox'            => __('Provider test environment'),
                        'remittance_enabled' => __('Outbound payouts to recipients'),
                        default              => __('Provider option'),
                    };
                    $toggleIcon = match ($key) {
                        'sandbox'            => 'fa-vial',
                        'remittance_enabled' => 'fa-paper-plane',
                        default              => 'fa-toggle-on',
                    };
                    $enabledLabel  = $key === 'sandbox' ? __('Sandbox') : __('Enabled');
                    $disabledLabel = $key === 'sandbox' ? __('Live') : __('Disabled');
                @endphp

                <input type="hidden" name="credentials[{{ $key }}]" value="0">
                <label class="pgm-toggle-row pgm-toggle-row--credential" for="{{ $key }}">
                    <span class="pgm-switch-row__icon"><i class="fa-solid {{ $toggleIcon }}"
                                                          aria-hidden="true"></i></span>
                    <span class="pgm-toggle-row__copy">
						<span>{{ $toggleTitle }}</span>
						<small>{{ $toggleHint }}</small>
					</span>
                    <span class="pgm-toggle-card">
						<input
                            class="pgm-toggle-card__input"
                            type="checkbox"
                            role="switch"
                            name="credentials[{{ $key }}]"
                            id="{{ $key }}"
                            value="1"
							@checked($checked)>
						<span class="pgm-toggle-card__track" aria-hidden="true"></span>
						<span class="pgm-toggle-card__meta">
							<span
                                class="pgm-toggle-card__state pgm-toggle-card__state--enabled">{{ $enabledLabel }}</span>
							<span
                                class="pgm-toggle-card__state pgm-toggle-card__state--disabled">{{ $disabledLabel }}</span>
						</span>
					</span>
                </label>
            @endforeach

            <label class="pgm-toggle-row pgm-toggle-row--status" for="status">
                <span class="pgm-switch-row__icon pgm-switch-row__icon--power"><i class="fa-solid fa-power-off"
                                                                                  aria-hidden="true"></i></span>
                <span class="pgm-toggle-row__copy">
					<span>{{ __('API availability') }}</span>
					<small>{{ __('Allow live production requests') }}</small>
				</span>
                <span class="pgm-toggle-card">
					<input class="pgm-toggle-card__input" type="checkbox" role="switch" name="status" id="status"
                           value="1" @checked($paymentGateway->status)>
					<span class="pgm-toggle-card__track" aria-hidden="true"></span>
					<span class="pgm-toggle-card__meta">
						<span class="pgm-toggle-card__state pgm-toggle-card__state--enabled">{{ __('Active') }}</span>
						<span
                            class="pgm-toggle-card__state pgm-toggle-card__state--disabled">{{ __('Inactive') }}</span>
					</span>
				</span>
            </label>
        </div>
    </div>

    <div class="pgm-form__footer">
        <button class="btn btn-outline-secondary" type="button" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
        <button class="btn btn-primary pgm-submit" type="submit">
            <x-icon name="check" height="18" width="18"/>
            {{ __('Save changes') }}
        </button>
    </div>
</form>
