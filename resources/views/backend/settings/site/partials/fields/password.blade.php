@php
    /**
     * A secret never travels back to the browser. The input starts empty, the
     * tag says whether a value is saved, and an empty submission keeps it
     * (see UpdateSettingRequest::prepareForValidation). The reveal button
     * only shows what the administrator is typing right now.
     */
    $secretSaved = \App\Models\Setting::hasStoredSecret($field['key']);
@endphp
<div class="settings-site-secret-field">
    <input type="password"
           name="{{ $field['key'] }}"
           class="form-control settings-site-secret-field__input"
           id="{{ $field['key'] }}"
           value=""
           autocomplete="new-password"
           spellcheck="false"
           placeholder="{{ $secretSaved ? __('Saved — leave blank to keep it') : title($field['label']) }}">
    <button type="button"
            class="settings-site-secret-field__button"
            data-settings-secret-reveal
            aria-label="{{ __('Show or hide value') }}"
            title="{{ __('Show / hide') }}">
        <i class="fa-solid fa-eye"></i>
    </button>
</div>
<span class="settings-site-secret-field__tag {{ $secretSaved ? 'is-saved' : '' }}">
    <i class="fa-solid {{ $secretSaved ? 'fa-lock' : 'fa-lock-open' }}" aria-hidden="true"></i>
    {{ $secretSaved ? __('Secret saved') : __('Not set') }}
</span>
@include('backend.settings.site.partials.fields._error')
