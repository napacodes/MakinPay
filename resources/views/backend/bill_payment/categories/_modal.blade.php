{{--
    The one create/edit dialog for categories. bill-payment-admin.js fills
    it from the row's data-* attributes and points it at the right action;
    after a failed save it reopens with what the admin typed.
--}}
@php
    $reopen    = $errors->any() && old('_bpa_form') === 'category';
    $editingId = $reopen ? (int) old('_bpa_category_id') : 0;
    $editing   = $editingId > 0 ? $categories->firstWhere('id', $editingId) : null;
    $action    = $editing
        ? route('admin.bill-payment.categories.update', $editing)
        : route('admin.bill-payment.categories.store');
    $icon      = (string) old('icon', '');
    $status    = $reopen ? (bool) old('status') : true;
@endphp

<div class="modal fade bpa-modal" id="bpa-category-modal" tabindex="-1" aria-labelledby="bpa-category-modal-title" aria-hidden="true"
     data-bpa-category-modal
     data-store-action="{{ route('admin.bill-payment.categories.store') }}"
     data-title-create="{{ __('New category') }}"
     data-title-edit="{{ __('Edit category') }}"
     @if($reopen) data-bpa-reopen @endif>
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form class="modal-content" method="POST" action="{{ $action }}" data-bpa-category-form>
            @csrf
            <input type="hidden" name="_method" value="{{ $editing ? 'PUT' : 'POST' }}" data-bpa-category-method>
            <input type="hidden" name="_bpa_form" value="category">
            <input type="hidden" name="_bpa_category_id" value="{{ $editing?->id }}" data-bpa-category-id>

            <div class="modal-header">
                <h2 class="modal-title bpa-modal__title" id="bpa-category-modal-title" data-bpa-category-title>
                    {{ $editing ? __('Edit category') : __('New category') }}
                </h2>
                <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>

            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="bpa-category-name">{{ __('Name') }} <span class="text-danger">*</span></label>
                        <input type="text" id="bpa-category-name" name="name" maxlength="120" required
                               value="{{ $reopen ? old('name') : '' }}"
                               class="form-control @error('name') is-invalid @enderror">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="bpa-category-code">{{ __('Code') }} <span class="text-danger">*</span></label>
                        <input type="text" id="bpa-category-code" name="code" maxlength="64" required dir="ltr"
                               value="{{ $reopen ? old('code') : '' }}"
                               class="form-control bpa-mono @error('code') is-invalid @enderror"
                               placeholder="electricity"
                               @if($editing?->is_system) readonly @endif>
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="bpa-muted" data-bpa-category-code-hint
                               data-text-default="{{ __('Lowercase letters, digits and dashes. It appears in the customer page address.') }}"
                               data-text-system="{{ __('A system category keeps its code.') }}">{{ $editing?->is_system ? __('A system category keeps its code.') : __('Lowercase letters, digits and dashes. It appears in the customer page address.') }}</small>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="bpa-category-icon">{{ __('Icon') }}</label>
                        <div class="bpa-icon-picker">
                            <span class="bpa-icon-picker__preview" aria-hidden="true">
                                @foreach($icons as $iconKey)
                                    <span data-bpa-icon-preview="{{ $iconKey }}" @if($iconKey !== ($icon !== '' ? $icon : 'bill-other')) hidden @endif>
                                        <x-icon :name="$iconKey" width="22" height="22"/>
                                    </span>
                                @endforeach
                            </span>
                            <select id="bpa-category-icon" name="icon" class="form-select @error('icon') is-invalid @enderror" data-bpa-icon-select>
                                <option value="">{{ __('Default') }}</option>
                                @foreach($icons as $iconKey)
                                    <option value="{{ $iconKey }}" @selected($icon === $iconKey)>{{ ucfirst(str_replace(['bill-', '-'], ['', ' '], $iconKey)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        @error('icon')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="bpa-category-description">{{ __('Description') }} <span class="bpa-muted">({{ __('optional') }})</span></label>
                        <input type="text" id="bpa-category-description" name="description" maxlength="255"
                               value="{{ $reopen ? old('description') : '' }}"
                               class="form-control @error('description') is-invalid @enderror">
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-6">
                        <label class="form-label" for="bpa-category-sort">{{ __('Sort order') }}</label>
                        <input type="number" id="bpa-category-sort" name="sort_order" min="0" step="1"
                               value="{{ $reopen ? old('sort_order') : '' }}"
                               class="form-control @error('sort_order') is-invalid @enderror">
                        @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-6">
                        <span class="form-label d-block">{{ __('Status') }}</span>
                        <input type="hidden" name="status" value="0">
                        <label class="form-check form-switch feature-mgmt-switch feature-mgmt-switch--lg">
                            <input class="form-check-input" type="checkbox" role="switch" id="bpa-category-status"
                                   name="status" value="1" aria-label="{{ __('Category is on') }}" @checked($status)>
                        </label>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Save category') }}</button>
            </div>
        </form>
    </div>
</div>
