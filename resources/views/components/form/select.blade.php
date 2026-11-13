@if(isset($label) && $isLabel)
    <label for="{{ $name }}" class="form-label small">{{ title($label) }}</label>
@endif
<select name="{{ $name }}" id="{{ $name }}" class="form-select  pe-5 {{ $class ?? '' }}" {{ $attributes }}>
    <option value selected> {{ $label ??  __('All') }} </option>
    @foreach($options as $key => $value)
        <option value="{{ $key }}" {{ isset($selected) && $selected == $key ? 'selected' : '' }}>
            {{-- A Closure only: is_callable() is also true for any string naming a PHP function, so a label of "POS" or "Date" was called instead of printed. --}}
            {{ $value instanceof \Closure ? title($value($key)) : title($value) }}
        </option>
    @endforeach
</select>
