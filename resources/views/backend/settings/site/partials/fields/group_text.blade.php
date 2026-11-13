{{--
	A value with a leading on/off segment, a trailing unit, or both. The three
	parts share one rounded outline, so each end only rounds the corners it
	actually owns — that is what `ss-group-text--*` decides.

	`related_default` is the switch's state before its row exists. Without it
	an upgraded site renders a switch that defaults on as off, and the next
	save of that section posts the 0 and turns it off for real.
--}}
@php
	$hasToggle = isset($field['related_field']);
	$hasUnit   = isset($field['unit']);
	$isNumeric = ($field['data'] ?? null) === 'integer';
@endphp

<div class="input-group settings-site-toggle-input {{ $hasToggle ? 'ss-group-text--toggled' : '' }} {{ $hasUnit ? 'ss-group-text--united' : '' }}">
    @if($hasToggle)
        @php
            $relatedFieldId = 'switch-' . str_replace(['.', '_'], '-', $field['related_field']);
        @endphp
        <span class="input-group-text settings-site-toggle-input__toggle">
            <span class="form-check form-switch settings-site-toggle-input__check m-0">
                <input type="hidden" name="{{$field['related_field']}}" value="0">
                <input type="checkbox"
                       class="form-check-input coevs-switch"
                       id="{{ $relatedFieldId }}"
                       name="{{$field['related_field']}}"
                       value="1"
                       @checked(setting($field['related_field'], $field['related_default'] ?? false))>
                <label class="form-check-label" for="{{ $relatedFieldId }}">
                    <span class="settings-site-toggle-input__state settings-site-toggle-input__state--enabled">{{ __('On') }}</span>
                    <span class="settings-site-toggle-input__state settings-site-toggle-input__state--disabled">{{ __('Off') }}</span>
                </label>
            </span>
        </span>
    @endif

    <input type="text"
           name="{{ $field['key'] }}"
           id="{{ $field['key'] }}"
           value="{{ setting($field['key'], $field['value']) }}"
           class="form-control {{ $isNumeric ? 'ss-control--number' : '' }}"
           @if($isNumeric) inputmode="decimal" oninput="this.value = validateNumber(this.value)" @endif
           placeholder="{{ title($field['label']) }}">

    @if($hasUnit)
        <span class="input-group-text settings-site-toggle-input__unit">{{ __($field['unit']) }}</span>
    @endif
</div>
@include('backend.settings.site.partials.fields._error')
