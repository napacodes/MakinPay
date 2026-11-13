{{-- Control only; the row wrapper draws the label and hint. --}}
<input type="number" name="{{ $field['key'] }}" class="form-control ss-control--number" id="{{ $field['key'] }}"
       value="{{ setting($field['key'], $field['value']) }}" placeholder="{{ title($field['label']) }}"
       step="{{ $field['step'] ?? '1' }}"
       inputmode="numeric"
       @if(isset($field['min'])) min="{{ $field['min'] }}" @endif
       @if(isset($field['max'])) max="{{ $field['max'] }}" @endif>
@include('backend.settings.site.partials.fields._error')
