{{--
    Template builder — create.

    Two panes: the field list on the left, and what the applicant will see on
    the right. The preview is the part the old modal had no answer for — an
    admin composing a verification form could not see the form.
--}}
@php
    use App\Enums\UserRole;
@endphp

<div class="modal fade" id="createTemplateModal" tabindex="-1" aria-labelledby="createTemplateLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content kyc-template-content">
            <div class="modal-header">
                <h5 id="createTemplateLabel" class="modal-title">{{ __('New KYC template') }}</h5>
                <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>

            <form action="{{ route('admin.kyc.template.store') }}" method="POST" id="create-template-form" data-kt-form>
                @csrf

                <div class="modal-body">
                    {{--
                        Name and routing first, because routing decides where
                        the finished submission lands; who the form is for is
                        a wider choice and gets a row of its own below.
                    --}}
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-8">
                            <label for="title" class="form-label">{{ __('Template name') }}</label>
                            <input type="text" name="title" id="title" class="form-control" value="{{ old('title') }}" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="routing" class="form-label">{{ __('Routing') }}</label>
                            <select name="routing" id="routing" class="form-select">
                                <option value="auto" @selected(old('routing', 'auto') === 'auto')>{{ __('Use the active provider') }}</option>
                                <option value="manual" @selected(old('routing') === 'manual')>{{ __('Always review manually') }}</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="description" class="form-label">
                                {{ __('Description') }}
                                <span class="text-body-secondary small">{{ __('shown to the applicant above the form') }}</span>
                            </label>
                            <input type="text" name="description" id="description" class="form-control" value="{{ old('description') }}">
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
                                               id="role_{{ $role->value }}"
                                               @checked(in_array($role->value, old('applicable_to', []), true))>
                                        <label class="form-check-label" for="role_{{ $role->value }}">{{ $role->title() }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="kt-builder">
                        <div class="kt-panel">
                            <div class="kt-panel__head">
                                <h6 class="kt-panel__title">{{ __('Fields') }}</h6>
                                <span class="kt-panel__count" data-field-count>1</span>
                                <span class="kt-panel__hint">{{ __('Drag to reorder — the order here is the order on the review screen') }}</span>
                            </div>
                            <div class="kt-panel__body fields-container" id="fields-container">
                                @include('backend.kyc.template.partials._fields', ['key' => 0, 'field' => []])
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
                                    The consent line and the submit button sit
                                    outside [data-kt-preview]: the script
                                    replaces that element's whole innerHTML on
                                    every keystroke, and both of these carry
                                    administrator settings rather than
                                    anything the builder typed.
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
                        <input class="form-check-input coevs-switch" type="checkbox" role="switch" name="status" value="1" id="create-status" checked>
                        <label class="form-check-label" for="create-status">
                            {{ __('Live — applicants in the selected roles can use this template') }}
                        </label>
                    </div>
                    <button type="button" class="btn btn-secondary" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-primary">
                        <x-icon name="check" height="20" width="20"/> {{ __('Create template') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
