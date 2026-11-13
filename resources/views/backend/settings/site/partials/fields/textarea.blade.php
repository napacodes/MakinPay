{{--
	Control only. Three rows is enough for the comma-separated lists this
	field mostly holds; the consent statement is a paragraph an administrator
	edits as prose, so it gets five.
--}}
<textarea class="form-control"
          name="{{ $field['key'] }}"
          id="{{ $field['key'] }}"
          rows="{{ $field['rows'] ?? ($field['key'] === 'kyc_consent_text' ? 5 : 3) }}">{{ setting($field['key'],$field['value']) }}</textarea>
@include('backend.settings.site.partials.fields._error')
