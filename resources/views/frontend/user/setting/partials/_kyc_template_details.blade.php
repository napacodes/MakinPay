{{--
    KYC template field group partial.
    Rendered inside the parent verification form; must NOT contain a nested <form>.
--}}
<div class="kyc-template-fields">
    <header class="kyc-template-fields__header">
        <h6 class="kyc-template-fields__title">{{ $template->title }}</h6>
        @if(! empty($template->description))
            <p class="kyc-template-fields__description">{{ $template->description }}</p>
        @endif
    </header>

    <div class="row g-3 kyc-template-fields__grid">
        @foreach($template->fields as $field)
            @php
                $fieldLabel    = ucfirst(str_replace('_', ' ', $field['label']));
                $fieldKey      = $field['label'];
                $fieldType     = $field['type'] ?? 'text';
                /*
                 * The same test the validator uses. This read
                 * `! empty($field['validation'])`, a key the builder has never
                 * written, so the asterisk was absent on every required field
                 * and present on none — while the server rejected the form
                 * anyway.
                 */
                $isRequired    = isset($field['required']) && ($field['required'] === 'true' || $field['required'] === true);
                $fieldId       = 'kyc-credential-' . \Illuminate\Support\Str::slug($fieldKey);
                $fieldName     = "credentials[{$fieldKey}]";
            @endphp

            <div class="col-md-6 kyc-template-fields__item">
                <label for="{{ $fieldId }}" class="form-label">
                    {{ $fieldLabel }}
                    @if($isRequired)
                        <span class="text-danger" aria-hidden="true">*</span>
                    @endif
                </label>

                @if($fieldType === 'file')
                    <input type="file"
                           id="{{ $fieldId }}"
                           name="{{ $fieldName }}"
                           class="form-control"
                           @if($isRequired) required @endif>
                @elseif($fieldType === 'country')
                    {{-- A country is a choice, not a text box: the validator
                         checks the value against the ISO list, so the form has
                         to offer it. --}}
                    <select id="{{ $fieldId }}"
                            name="{{ $fieldName }}"
                            class="form-select"
                            @if($isRequired) required @endif>
                        <option value="">{{ __('Select a country') }}</option>
                        @foreach(getCountries() as $country)
                            <option value="{{ $country['code'] }}"
                                    @selected(old("credentials.{$fieldKey}") === $country['code'])>{{ $country['name'] }}</option>
                        @endforeach
                    </select>
                @elseif($fieldType === 'date')
                    <input type="date"
                           id="{{ $fieldId }}"
                           name="{{ $fieldName }}"
                           class="form-control"
                           value="{{ old("credentials.{$fieldKey}") }}"
                           @if($isRequired) required @endif>
                @elseif($fieldType === 'number')
                    <input type="number"
                           id="{{ $fieldId }}"
                           name="{{ $fieldName }}"
                           class="form-control"
                           value="{{ old("credentials.{$fieldKey}") }}"
                           placeholder="{{ $fieldLabel }}"
                           @if($isRequired) required @endif>
                @elseif($fieldType === 'textarea')
                    <textarea id="{{ $fieldId }}"
                              name="{{ $fieldName }}"
                              class="form-control rounded"
                              rows="3"
                              placeholder="{{ $fieldLabel }}"
                              @if($isRequired) required @endif>{{ old("credentials.{$fieldKey}") }}</textarea>
                @else
                    {{-- Anything the builder can save that is not one of the
                         above is short text, which is also what the validator
                         falls back to. An unknown type used to reach the
                         browser as type="{{ '{' }}whatever{{ '}' }}". --}}
                    <input type="text"
                           id="{{ $fieldId }}"
                           name="{{ $fieldName }}"
                           class="form-control"
                           value="{{ old("credentials.{$fieldKey}") }}"
                           placeholder="{{ $fieldLabel }}"
                           @if($isRequired) required @endif>
                @endif
            </div>
        @endforeach
    </div>
</div>
