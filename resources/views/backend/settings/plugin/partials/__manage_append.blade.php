@php
    use App\Models\Plugin;
    use App\Support\PluginSecretFields;

    /**
     * Secret credentials are never printed into the form: the input starts
     * empty, a tag says whether a value is saved, and leaving it blank keeps
     * that value. Public identifiers (site keys, page ids) stay visible.
     */
    $savedSecrets = $savedSecrets ?? [];

    $pluginGroupLabels = [
        'general' => __('Core services'),
        'notification' => __('Notifications'),
        'exchange_rate' => __('Exchange rates'),
        'mobile_recharge' => __('Mobile recharge'),
    ];

    $stateLabels = [
        Plugin::STATE_ACTIVE => __('Active'),
        Plugin::STATE_SETUP => __('Needs setup'),
        Plugin::STATE_OFF => __('Off'),
    ];

    /*
     * PluginController@edit blanks every stored secret on the model it hands
     * over — deliberately, so no key reaches the page source. Reading `state`
     * off that copy would therefore report "needs setup" for every provider
     * that has one. The untouched row is what knows.
     */
    $pluginState = Plugin::find($plugin->id)?->state ?? Plugin::STATE_SETUP;
@endphp

<div class="settings-plugin-modal__header modal-header" data-state="{{ $pluginState }}">
    <div class="settings-plugin-modal__title">
        <span class="settings-plugin-modal__icon">
            <img src="{{ asset($plugin->logo) }}" alt="{{ $plugin->name }}" loading="lazy">
        </span>
        <div class="settings-plugin-modal__copy">
            <span class="settings-plugin-modal__eyebrow">{{ $pluginGroupLabels[$plugin->type] ?? str($plugin->type)->replace('_', ' ')->title() }}</span>
            <h5 class="modal-title" id="pluginManageModalLabel">{{ $plugin->name }}</h5>
            <span class="settings-plugin-status">
                <span class="settings-plugin-status__dot" aria-hidden="true"></span>{{ $stateLabels[$pluginState] }}
            </span>
        </div>
    </div>
</div>

<div class="modal-body settings-plugin-modal__body">
    <form method="POST" action="{{ route('admin.settings.plugin.update', $plugin->id) }}" class="settings-plugin-manage-form">
        @method('PUT')
        @csrf

        <section class="settings-plugin-form-section">
            <div class="settings-plugin-form-section__header">
                <span>{{ __('Credentials') }}</span>
            </div>

            {{-- A panel, not a link: it carries the provider's console URL and
                 the exact steps for each key, which is the only place those
                 are written down. --}}
            @include('backend.settings.plugin.partials._credential_guide', ['plugin' => $plugin])

            <div class="plugin-fields">
                @foreach($plugin->credentials as $field_name => $credential)
                    @continue($field_name === 'fields')
                    @php
                        $isSecret    = PluginSecretFields::isSecret($field_name);
                        $secretSaved = $isSecret && ($savedSecrets[$field_name] ?? false);
                        $fieldId     = 'cred-'.$field_name;
                        $fieldType   = $isSecret ? 'password' : 'text';
                        /* fa-lock, not fa-shield-*: both shield glyphs are in
                           the stylesheet but absent from the shipped webfont
                           subset, so the tag rendered an empty tile. */
                        $fieldIcon   = $isSecret ? 'fa-lock' : 'fa-key';
                        $placeholder = $secretSaved
                            ? __('Saved — leave blank to keep it')
                            : __('Enter :field', ['field' => str_replace('_', ' ', $field_name)]);
                    @endphp
                    <div class="plugin-field">
                        <label class="plugin-field__label" for="{{ $fieldId }}">
                            <span>{{ ucwords(str_replace('_', ' ', $field_name)) }}</span>
                            @if($isSecret)
                                <span class="plugin-field__tag {{ $secretSaved ? 'is-saved' : '' }}">
                                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                                    {{-- The handoff draws this as a static
                                         "Secret" type marker. It already says
                                         something better: whether a value is
                                         stored. Losing that would leave an
                                         administrator unable to tell a saved
                                         key from an empty field, since the
                                         input is blank either way. --}}
                                    {{ $secretSaved ? __('Secret saved') : __('Not set') }}
                                </span>
                            @endif
                        </label>
                        <div class="plugin-field__control {{ $isSecret ? 'plugin-field--single-action' : '' }}">
                            <span class="plugin-field__icon" aria-hidden="true"><i class="fa-solid {{ $fieldIcon }}"></i></span>
                            @if($field_name === 'sandbox')
                                {{-- A choice, not a text box: typed "false" or
                                     "live" used to read as on, so live keys
                                     went to the sandbox and every token was
                                     refused. --}}
                                @php
                                    $sandboxOn = \App\Services\MobileRecharge\Drivers\ReloadlyMobileRechargeProvider::isSandbox(['sandbox' => $credential]);
                                @endphp
                                <select name="credentials[sandbox]" id="{{ $fieldId }}" class="form-select plugin-field__input">
                                    <option value="1" @selected($sandboxOn)>{{ __('Sandbox — test keys') }}</option>
                                    <option value="0" @selected(! $sandboxOn)>{{ __('Live — production keys') }}</option>
                                </select>
                            @else
                            <input type="{{ $fieldType }}"
                                   name="credentials[{{ $field_name }}]"
                                   id="{{ $fieldId }}"
                                   value="{{ $isSecret ? '' : $credential }}"
                                   placeholder="{{ $placeholder }}"
                                   spellcheck="false"
                                   autocomplete="{{ $isSecret ? 'new-password' : 'off' }}"
                                   class="form-control plugin-field__input">
                            @endif
                            @if($isSecret)
                                <div class="plugin-field__actions">
                                    <button type="button" class="plugin-field__btn" data-field-reveal
                                            aria-label="{{ __('Show or hide value') }}"
                                            title="{{ __('Show / hide') }}">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            @if($plugin->fields_blade)
                <div class="row plugin-fields__extra">
                    @includeIf('backend.settings.plugin.other_fields.'.$plugin->fields_blade, ['plugin' => $plugin])
                </div>
            @endif
        </section>

        <section class="settings-plugin-test-card">
            <div class="settings-plugin-test-card__content">
                <span class="settings-plugin-test-card__icon" aria-hidden="true">
                    <i class="fa-solid fa-rotate"></i>
                </span>
                <div>
                    <h6>{{ __('Test connection') }}</h6>
                    <p>{{ __('Checks the keys above before you save.') }}</p>
                </div>
            </div>
            <button class="btn settings-plugin-test-card__button"
                    type="button"
                    data-plugin-test
                    data-test-url="{{ route('admin.settings.plugin.test', ['plugin' => $plugin->id]) }}"
                    data-testing-label="{{ __('Testing…') }}"
                    data-testing-message="{{ __('Testing provider connection…') }}"
                    data-fallback-message="{{ __('Unable to test this provider right now.') }}">
                <span class="settings-plugin-test-card__button-icon" aria-hidden="true">
                    <i class="fa-solid fa-rotate"></i>
                </span>
                <span data-plugin-test-label>{{ __('Run test') }}</span>
            </button>
            <div class="settings-plugin-test-card__result d-none" data-plugin-test-result></div>
        </section>

        <div class="settings-plugin-switch-card">
            <span class="settings-plugin-switch-card__copy">
                <label class="form-check-label" for="plugin-status-{{ $plugin->id }}">{{ __('Enabled') }}</label>
                <small>{{ __('When off, the platform ignores this provider.') }}</small>
            </span>
            <input type="hidden" name="status" value="0">
            <label class="form-check form-switch feature-mgmt-switch feature-mgmt-switch--lg settings-plugin-status-switch" aria-label="{{ __('Toggle plugin status') }}">
                <input class="form-check-input feature-mgmt-switch__input" type="checkbox" role="switch"
                       name="status" @checked($plugin->status) value="1" id="plugin-status-{{ $plugin->id }}">
            </label>
        </div>

        <div class="settings-plugin-manage-form__footer">
            <button class="btn settings-plugin-manage-form__cancel" type="button" data-coreui-dismiss="modal">
                {{ __('Cancel') }}
            </button>
            <button class="btn settings-plugin-manage-form__submit" type="submit">
                <i class="fa-solid fa-check" aria-hidden="true"></i>{{ __('Save changes') }}
            </button>
        </div>
    </form>
</div>
