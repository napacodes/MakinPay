{{--
    One package row of a fixed-amount biller. Rendered for each saved or
    re-submitted package, and once inside a <template> with the index
    "__INDEX__", which bill-payment-admin.js replaces when it adds a row.
--}}
@php
    $rowName   = 'items['.$index.']';
    $rowKey    = 'items.'.$index;
    $rowStatus = (string) ($item['status'] ?? '1') !== '0';
@endphp

<div class="bpa-item" data-bpa-item>
    <input type="hidden" name="{{ $rowName }}[id]" value="{{ $item['id'] ?? '' }}">

    <div class="bpa-item__field bpa-item__field--name">
        <label class="form-label" for="bpa-item-{{ $index }}-name">{{ __('Package name') }}</label>
        <input type="text" id="bpa-item-{{ $index }}-name" name="{{ $rowName }}[name]" maxlength="120"
               value="{{ $item['name'] ?? '' }}"
               class="form-control @if($errors->has($rowKey.'.name')) is-invalid @endif"
               placeholder="{{ __('e.g. Access bouquet, 1 month') }}">
        @if($errors->has($rowKey.'.name'))
            <div class="invalid-feedback">{{ $errors->first($rowKey.'.name') }}</div>
        @endif
    </div>

    <div class="bpa-item__field">
        <label class="form-label" for="bpa-item-{{ $index }}-amount">{{ __('Price') }}</label>
        <input type="text" id="bpa-item-{{ $index }}-amount" name="{{ $rowName }}[amount]" inputmode="decimal" dir="ltr"
               value="{{ $item['amount'] ?? '' }}"
               class="form-control @if($errors->has($rowKey.'.amount')) is-invalid @endif"
               placeholder="0">
        @if($errors->has($rowKey.'.amount'))
            <div class="invalid-feedback">{{ $errors->first($rowKey.'.amount') }}</div>
        @endif
    </div>

    <div class="bpa-item__field">
        <label class="form-label" for="bpa-item-{{ $index }}-code">{{ __('Code') }} <span class="bpa-muted">({{ __('optional') }})</span></label>
        <input type="text" id="bpa-item-{{ $index }}-code" name="{{ $rowName }}[external_code]" maxlength="128" dir="ltr"
               value="{{ $item['external_code'] ?? '' }}"
               class="form-control @if($errors->has($rowKey.'.external_code')) is-invalid @endif">
        @if($errors->has($rowKey.'.external_code'))
            <div class="invalid-feedback">{{ $errors->first($rowKey.'.external_code') }}</div>
        @endif
    </div>

    <div class="bpa-item__field bpa-item__field--switch">
        <span class="form-label">{{ __('On sale') }}</span>
        <input type="hidden" name="{{ $rowName }}[status]" value="0">
        <label class="form-check form-switch feature-mgmt-switch">
            <input class="form-check-input" type="checkbox" role="switch"
                   name="{{ $rowName }}[status]" value="1"
                   aria-label="{{ __('Package on sale') }}"
                   @checked($rowStatus)>
        </label>
    </div>

    <div class="bpa-item__field bpa-item__field--remove">
        <button type="button" class="btn btn-outline-danger btn-sm" data-bpa-item-remove
                aria-label="{{ __('Remove this package') }}" title="{{ __('Remove this package') }}">
            <x-icon name="trash" width="14" height="14"/>
        </button>
    </div>
</div>
