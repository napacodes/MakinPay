{{--
	The control is a real checkbox: it carries the field name, it is what the
	form posts, and it is what a screen reader announces. The track and the
	knob are drawn beside it and the input itself is transparent, so nothing
	here depends on JavaScript having run.

	The hidden 0 ahead of it is how an unchecked box still reaches the server.
--}}
<div class="settings-site-switch-control">
    <input type="hidden" name="{{ $field['key'] }}" value="0">
    <label class="settings-site-switch-card" for="{{ $field['key'] }}_switch">
        <input class="form-check-input coevs-switch settings-site-switch-card__input"
               type="checkbox"
               role="switch"
               id="{{ $field['key'] }}_switch"
               name="{{ $field['key'] }}"
               value="1"
               @isset($field['enable_warning'])
                   data-settings-enable-warning="{{ __($field['enable_warning']) }}"
                   data-settings-enable-warning-title="{{ __($field['enable_warning_title'] ?? 'Confirm Status Change') }}"
                   data-settings-enable-warning-confirm="{{ __($field['enable_warning_confirm'] ?? 'Continue') }}"
                   @isset($field['enable_warning_ack'])
                       data-settings-enable-warning-ack="{{ __($field['enable_warning_ack']) }}"
                   @endisset
                   @if(($field['enable_warning_recovery'] ?? false) === true)
                       data-settings-enable-warning-recovery="1"
                   @endif
               @endisset
               @checked(setting($field['key'],$field['value']))>
        <span class="settings-site-switch-card__track" aria-hidden="true"></span>
        <span class="settings-site-switch-card__meta" aria-hidden="true">
            <span class="settings-site-switch-card__state settings-site-switch-card__state--enabled">{{ __('On') }}</span>
            <span class="settings-site-switch-card__state settings-site-switch-card__state--disabled">{{ __('Off') }}</span>
        </span>
    </label>
</div>
@include('backend.settings.site.partials.fields._error')
