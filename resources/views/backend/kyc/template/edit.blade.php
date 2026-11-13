{{--
    Template builder — the edit form, fetched over AJAX into #edit-template-form.

    Mirrors the create modal's two panes. Was one minified line, and its status
    switch read `value="1"{{ ... }}` with no separator, so the checked state
    depended on the browser's tolerance for a missing space.

    The identity the modal header shows travels on the form root, because the
    header is already on the page when this body arrives.
--}}
@php
    use App\Enums\UserRole;

    $selectedRoles = old('applicable_to', $template->applicable_to ?? []);
    $routing       = old('routing', $template->routing ?? 'auto');

    /*
     * Who last saved it, when a name is known. `updated_by` is null on every
     * row written before the column existed, so those show the date alone
     * rather than an invented author.
     */
    $savedWhen  = $template->updated_at?->format('d M, H:i');
    $savedLabel = $savedWhen === null
        ? ''
        : ($template->updatedBy?->name
            ? __('Last saved :when by :who', ['when' => $savedWhen, 'who' => $template->updatedBy->name])
            : __('Last saved :when', ['when' => $savedWhen]));
@endphp

<form action="{{ route('admin.kyc.template.update', $template->id) }}" method="POST" id="edit-template-form-inner"
      data-kt-form
      data-kt-title="{{ $template->title }}"
      data-kt-saved="{{ $savedLabel }}">
    @csrf
    @method('PUT')

    <div class="modal-body">
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-8">
                <label for="title_edit" class="form-label">{{ __('Template name') }}</label>
                <input type="text" name="title" id="title_edit" class="form-control"
                       value="{{ old('title', $template->title) }}" required>
            </div>
            <div class="col-12 col-md-4">
                <label for="routing_edit" class="form-label">{{ __('Routing') }}</label>
                <select name="routing" id="routing_edit" class="form-select">
                    <option value="auto" @selected($routing !== 'manual')>{{ __('Use the active provider') }}</option>
                    <option value="manual" @selected($routing === 'manual')>{{ __('Always review manually') }}</option>
                </select>
            </div>
            <div class="col-12">
                <label for="desc_edit" class="form-label">
                    {{ __('Description') }}
                    <span class="text-body-secondary small">{{ __('shown to the applicant above the form') }}</span>
                </label>
                <input type="text" name="description" id="desc_edit" class="form-control"
                       value="{{ old('description', $template->description) }}">
            </div>
            <div class="col-12">
                <label class="form-label d-block">{{ __('Applies to') }}</label>
                <div class="kt-roles">
                    @foreach(UserRole::cases() as $role)
                        <div class="form-check">
                            <input class="form-check-input"
                                   type="checkbox"
                                   name="applicable_to[]"
                                   value="{{ $role->value }}"
                                   id="role_edit_{{ $role->value }}"
                                   @checked(in_array($role->value, $selectedRoles, true))>
                            <label class="form-check-label" for="role_edit_{{ $role->value }}">{{ $role->title() }}</label>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="kt-builder">
            <div class="kt-panel">
                <div class="kt-panel__head">
                    <h6 class="kt-panel__title">{{ __('Fields') }}</h6>
                    <span class="kt-panel__count" data-field-count>{{ count($template->fields ?? []) }}</span>
                    <span class="kt-panel__hint">{{ __('Drag to reorder — the order here is the order on the review screen') }}</span>
                </div>
                <div class="kt-panel__body fields-container" id="edit-fields-container">
                    @foreach($template->fields ?? [] as $index => $field)
                        @include('backend.kyc.template.partials._fields', ['key' => $index, 'field' => $field])
                    @endforeach
                </div>
                <div class="kt-addbar">
                    <span class="kt-addbar__label">{{ __('Add') }}</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary add-new-field" data-field-type="text">
                        <i class="fa-solid fa-font" aria-hidden="true"></i> {{ __('Text') }}
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary add-new-field" data-field-type="number">
                        <i class="fa-solid fa-hashtag" aria-hidden="true"></i> {{ __('Number') }}
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary add-new-field" data-field-type="date">
                        <i class="fa-solid fa-calendar-days" aria-hidden="true"></i> {{ __('Date') }}
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary add-new-field" data-field-type="file">
                        <i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> {{ __('File upload') }}
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary add-new-field" data-field-type="country">
                        <i class="fa-solid fa-globe" aria-hidden="true"></i> {{ __('Country') }}
                    </button>
                </div>
            </div>

            <div class="kt-panel">
                <div class="kt-panel__head">
                    <h6 class="kt-panel__title">{{ __('What the applicant sees') }}</h6>
                    <span class="kt-panel__hint kt-panel__hint--live">{{ __('Live') }}</span>
                </div>
                <div class="kt-preview">
                    {{--
                        The consent line and the submit button sit outside
                        [data-kt-preview]: the script replaces that element's
                        whole innerHTML on every keystroke, and both of these
                        carry administrator settings rather than anything the
                        builder typed.
                    --}}
                    <div class="kt-preview__card is-empty">
                        <div class="kt-preview__body" data-kt-preview>
                            <div class="kt-preview__empty">{{ __('Name a field to see the form take shape.') }}</div>
                        </div>
                        @if($requiresConsent)
                            <label class="kt-preview__consent">
                                <input type="checkbox" class="admin-check" disabled tabindex="-1">
                                <span>{{ $consentText }}</span>
                            </label>
                        @endif
                        <div class="kt-preview__submit" aria-hidden="true">{{ __('Submit for verification') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-footer">
        <div class="form-check form-switch me-auto">
            <input type="hidden" name="status" value="0">
            <input class="form-check-input coevs-switch"
                   type="checkbox"
                   role="switch"
                   name="status"
                   value="1"
                   id="edit-status-{{ $template->id }}"
                   @checked((bool) $template->status)>
            <label class="form-check-label" for="edit-status-{{ $template->id }}">
                {{ __('Live — applicants in the selected roles can use this template') }}
            </label>
        </div>
        @if(($template->submissions_count ?? 0) > 0)
            {{--
                What is already riding on this form. Renaming a field does not
                rename it on submissions already made — each one stores the
                field names it was filled in with.
            --}}
            <span class="small text-body-secondary">
                {{ trans_choice(':count submission already uses this template|:count submissions already use this template', $template->submissions_count, ['count' => number_format($template->submissions_count)]) }}
            </span>
        @endif
        <button type="button" class="btn btn-secondary" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
        <button type="submit" class="btn btn-primary">
            <x-icon name="check" height="20" width="20"/> {{ __('Update template') }}
        </button>
    </div>
</form>
