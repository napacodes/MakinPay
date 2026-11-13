@php
    $currentColorValue = old($field['key'], setting($field['key'], $field['value']));
    $pickerColorValue = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $currentColorValue) === 1
        ? $currentColorValue
        : $field['value'];

    /*
     * Five starting points rather than a palette: brand indigo, the two
     * status hues the product already uses, and a warm and a hot accent for
     * a role that wants to read as different rather than as broken.
     */
    $colorPresets = ['#4F46E5', '#16A34A', '#0891B2', '#F59E0B', '#BE123C'];
@endphp

<div class="ss-color">
    <div class="input-group settings-site-color-field">
        <span class="input-group-text settings-site-color-field__swatch-wrap">
            <input type="color"
                   class="form-control form-control-color settings-site-color-field__picker"
                   value="{{ $pickerColorValue }}"
                   data-settings-color-target="{{ $field['key'] }}"
                   aria-label="{{ title($field['label']) }} {{ __('Picker') }}">
        </span>
        <input type="text"
               name="{{ $field['key'] }}"
               id="{{ $field['key'] }}"
               value="{{ $currentColorValue }}"
               class="form-control settings-site-color-field__hex @error($field['key']) is-invalid @enderror"
               maxlength="7"
               dir="ltr"
               pattern="^#[0-9A-Fa-f]{6}$"
               data-settings-color-input="{{ $field['key'] }}"
               placeholder="{{ title($field['label']) }}">
    </div>
    <div class="ss-color__presets" role="group" aria-label="{{ __('Preset colors') }}">
        @foreach($colorPresets as $preset)
            <button type="button"
                    class="ss-color__preset {{ strtoupper((string) $currentColorValue) === $preset ? 'is-selected' : '' }}"
                    style="--ss-preset: {{ $preset }}"
                    data-ss-color-preset="{{ $preset }}"
                    data-ss-color-for="{{ $field['key'] }}"
                    title="{{ $preset }}"
                    aria-label="{{ __('Use color :hex', ['hex' => $preset]) }}"></button>
        @endforeach
    </div>
</div>
@include('backend.settings.site.partials.fields._error')
