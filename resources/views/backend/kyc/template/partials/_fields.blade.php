{{--
    One field in the template builder.

    Was four bare inputs in a Bootstrap row, on a single minified line, with no
    sense of order or type. The order here is the order a reviewer reads on the
    review screen, so the row shows a handle and the type is visible at a
    glance rather than hidden inside a select.
--}}
@php
    $fieldType     = $field['type'] ?? 'text';
    $fieldRequired = $field['required'] ?? 'true';
    $typeIcon      = match ($fieldType) {
        'file'    => 'fa-arrow-up-from-bracket',
        'number'  => 'fa-hashtag',
        'date'    => 'fa-calendar-days',
        'country' => 'fa-globe',
        default   => 'fa-font',
    };
@endphp

<div class="kt-field field-item">
    {{--
        Focusable, because the list is reorderable and a keyboard has to be
        able to reach the thing that reorders it.
    --}}
    <button type="button"
            class="kt-field__handle"
            aria-label="{{ __('Reorder this field') }}">
        <i class="fa-solid fa-grip-vertical" aria-hidden="true"></i>
    </button>

    <span class="kt-type kt-type--{{ $fieldType }} field-type-badge" aria-hidden="true">
        <i class="fa-solid {{ $typeIcon }}"></i>
    </span>

    <label class="kt-field__label">
        <span class="visually-hidden">{{ __('Field name') }}</span>
        <input type="text"
               name="fields[{{ $key }}][label]"
               class="form-control form-control-sm field-label-input"
               value="{{ $field['label'] ?? '' }}"
               placeholder="{{ __('Field name, as the applicant sees it') }}"
               required>
    </label>

    <label class="kt-field__type">
        <span class="visually-hidden">{{ __('Field type') }}</span>
        <select name="fields[{{ $key }}][type]" class="form-select form-select-sm field-type-input" required>
            <option value="text" @selected($fieldType === 'text')>{{ __('Short text') }}</option>
            <option value="number" @selected($fieldType === 'number')>{{ __('Number') }}</option>
            <option value="date" @selected($fieldType === 'date')>{{ __('Date') }}</option>
            <option value="file" @selected($fieldType === 'file')>{{ __('File upload') }}</option>
            <option value="country" @selected($fieldType === 'country')>{{ __('Country') }}</option>
        </select>
    </label>

    {{--
        Required is one bit, so it is a checkbox and not a two-option select.
        The hidden input before it is what makes an unchecked box post at all:
        a browser sends nothing for one, and the stored field would lose its
        `required` key rather than gain a false.
    --}}
    <input type="hidden" name="fields[{{ $key }}][required]" value="false">
    <label class="kt-field__required">
        <input type="checkbox"
               class="admin-check field-required-input"
               name="fields[{{ $key }}][required]"
               value="true"
               @checked($fieldRequired === 'true' || $fieldRequired === true)>
        {{ __('Required') }}
    </label>

    <button type="button"
            class="kt-iconbtn kt-iconbtn--danger remove-field"
            aria-label="{{ __('Remove this field') }}">
        <i class="fa-solid fa-trash" aria-hidden="true"></i>
    </button>
</div>
