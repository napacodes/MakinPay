@extends('backend.layouts.app')

@section('title', $rule->exists ? __('Edit tax rule') : __('New tax rule'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/feature-management.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/feature-management.css'))) }}">
@endpush

@push('scripts')
    <script src="{{ asset('backend/js/tax-rules.js?v=' . config('app.version') . '-' . filemtime(public_path('backend/js/tax-rules.js'))) }}"></script>
@endpush

{{--
    One form for creating and editing a tax rule. The tax is charged on top of
    what the customer pays; the percent is taken of the amount or of the fee,
    the fixed part is in the site currency and converted at the transaction's
    rate.
--}}
@section('content')
    <x-page-header
        :title="$rule->exists ? __('Edit :name', ['name' => $rule->name]) : __('New tax rule')"
        :subtitle="__('Applies from the next transaction. Transactions already made keep the tax they were charged.')"
        icon="receipt"
        :breadcrumbs="[
            ['label' => __('Platform')],
            ['label' => __('Taxes & levies'), 'url' => route('admin.tax-rule.index')],
            ['label' => $rule->exists ? $rule->name : __('New rule')],
        ]"
        :back="route('admin.tax-rule.index')"
        :back-label="__('Back to taxes & levies')"
    />

    @php
        $selectedCountries = (array) old('countries', $rule->countryCodes());
        $active = old('_tr_form') === null ? (bool) $rule->is_active : (bool) old('is_active');
        $plain = fn (?string $value): string => $value === null || ! is_numeric($value) || \App\Support\DecimalMoney::compare($value, '0') === 0
            ? ''
            : (str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value);
        $chargeBy = old('charge_by', $rule->hasBrackets() ? \App\Http\Requests\Backend\Tax\TaxRuleRequest::CHARGE_BRACKETS : \App\Http\Requests\Backend\Tax\TaxRuleRequest::CHARGE_FLAT);
        $bracketRows = old('_tr_form') !== null
            ? array_values((array) old('brackets', []))
            : array_map(fn (array $bracket): array => [
                'up_to'   => $plain($bracket['up_to']),
                'percent' => $plain($bracket['percent']),
                'fixed'   => $plain($bracket['fixed']),
            ], $rule->brackets());
        if ($bracketRows === []) {
            $bracketRows = [['up_to' => '', 'percent' => '', 'fixed' => ''], ['up_to' => '', 'percent' => '', 'fixed' => '']];
        }
    @endphp

    <div class="card border-0 mb-4">
        <div class="card-body">
            <form method="POST" action="{{ $rule->exists ? route('admin.tax-rule.update', $rule) : route('admin.tax-rule.store') }}" data-tr-form>
                @csrf
                @if($rule->exists)
                    @method('PUT')
                @endif
                <input type="hidden" name="_tr_form" value="1">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="tr-name">{{ __('Name') }} <span class="text-danger">*</span></label>
                        <input type="text" id="tr-name" name="name" required maxlength="{{ \App\Http\Requests\Backend\Tax\TaxRuleRequest::NAME_MAX_LENGTH }}"
                               value="{{ old('name', $rule->name) }}" placeholder="{{ __('VAT') }}"
                               class="form-control @error('name') is-invalid @enderror">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="text-body-secondary">{{ __('Shown to customers as its own line, so use the name they know it by.') }}</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="tr-type">{{ __('Transaction type') }} <span class="text-danger">*</span></label>
                        <select id="tr-type" name="transaction_type" class="form-select @error('transaction_type') is-invalid @enderror" required>
                            @foreach($types as $type)
                                <option value="{{ $type->value }}" @selected(old('transaction_type', $rule->transaction_type) === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        @error('transaction_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="tr-scope">{{ __('Applies to') }} <span class="text-danger">*</span></label>
                        <select id="tr-scope" name="scope" class="form-select @error('scope') is-invalid @enderror" required data-tr-scope>
                            @foreach($scopes as $scope)
                                <option value="{{ $scope->value }}" @selected(old('scope', $rule->scope) === $scope->value)>{{ $scope->label() }}</option>
                            @endforeach
                        </select>
                        @error('scope')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="text-body-secondary">
                            @if($homeCountry !== null)
                                {{ __('Local means customers registered in :country; international means every other country.', ['country' => getCountryByCode($homeCountry)['name'] ?? $homeCountry]) }}
                            @else
                                {{ __('No home country is set, so local and international rules apply to nobody until it is.') }}
                            @endif
                        </small>
                    </div>

                    <div class="col-md-6" data-tr-countries>
                        <label class="form-label" for="tr-countries">{{ __('Countries') }}</label>
                        <select id="tr-countries" name="countries[]" multiple size="6" class="form-select @error('countries') is-invalid @enderror @error('countries.*') is-invalid @enderror">
                            @foreach($countries as $code => $name)
                                <option value="{{ $code }}" @selected(in_array($code, $selectedCountries, true))>{{ $name }} ({{ $code }})</option>
                            @endforeach
                        </select>
                        @error('countries')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @error('countries.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="text-body-secondary">{{ __('The customer\'s registered country decides.') }}</small>
                    </div>

                    <div class="col-md-6">
                        <span class="form-label d-block" id="tr-charge-by-label">{{ __('How it is charged') }}</span>
                        <div class="btn-group w-100" role="radiogroup" aria-labelledby="tr-charge-by-label">
                            <input type="radio" class="btn-check" name="charge_by" id="tr-charge-flat" autocomplete="off" data-tr-charge-by
                                   value="{{ \App\Http\Requests\Backend\Tax\TaxRuleRequest::CHARGE_FLAT }}" @checked($chargeBy !== \App\Http\Requests\Backend\Tax\TaxRuleRequest::CHARGE_BRACKETS)>
                            <label class="btn btn-outline-primary" for="tr-charge-flat">{{ __('One rate for every amount') }}</label>
                            <input type="radio" class="btn-check" name="charge_by" id="tr-charge-brackets" autocomplete="off" data-tr-charge-by
                                   value="{{ \App\Http\Requests\Backend\Tax\TaxRuleRequest::CHARGE_BRACKETS }}" @checked($chargeBy === \App\Http\Requests\Backend\Tax\TaxRuleRequest::CHARGE_BRACKETS)>
                            <label class="btn btn-outline-primary" for="tr-charge-brackets">{{ __('By amount brackets') }}</label>
                        </div>
                        <small class="text-body-secondary">{{ __('Brackets charge a different rate for different amounts, e.g. up to 10 pays 0.20, up to 50 pays 0.50.') }}</small>
                    </div>

                    <div class="col-12" data-tr-brackets>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-2">
                                <caption class="visually-hidden">{{ __('Amount brackets') }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('From') }}</th>
                                        <th scope="col">{{ __('Up to (:currency)', ['currency' => $currency]) }}</th>
                                        <th scope="col">{{ __('Percent') }}</th>
                                        <th scope="col">{{ __('Fixed (:currency)', ['currency' => $currency]) }}</th>
                                        <th scope="col"><span class="visually-hidden">{{ __('Remove') }}</span></th>
                                    </tr>
                                </thead>
                                <tbody data-tr-bracket-rows>
                                    @foreach($bracketRows as $index => $row)
                                        @include('backend.tax_rule.partials._bracket_row', ['index' => $index, 'row' => $row])
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <template data-tr-bracket-template>
                            @include('backend.tax_rule.partials._bracket_row', ['index' => '__INDEX__', 'row' => ['up_to' => '', 'percent' => '', 'fixed' => '']])
                        </template>
                        @error('brackets')<div class="text-danger small mb-2" data-tr-brackets-error>{{ $message }}</div>@enderror
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-tr-bracket-add>
                            <x-icon name="plus" width="14" height="14"/> {{ __('Add bracket') }}
                        </button>
                        <small class="d-block text-body-secondary mt-2">
                            {{ __('The amount of the transaction picks the bracket. Leave the last limit empty to cover every amount above it; an amount above a last limit you set is not taxed by this rule. Limits and fixed amounts are in :currency and converted at the transaction\'s rate.', ['currency' => $currency]) }}
                        </small>
                    </div>

                    <div class="col-md-4" data-tr-flat>
                        <label class="form-label" for="tr-percent">{{ __('Percent') }}</label>
                        <div class="input-group">
                            <input type="text" id="tr-percent" name="percent" inputmode="decimal" dir="ltr"
                                   value="{{ old('percent', $rule->exists ? rtrim(rtrim((string) $rule->percent, '0'), '.') : '') }}"
                                   class="form-control @error('percent') is-invalid @enderror" placeholder="5">
                            <span class="input-group-text">%</span>
                            @error('percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="tr-base">{{ __('Percent of') }}</label>
                        <select id="tr-base" name="base" class="form-select @error('base') is-invalid @enderror" required>
                            @foreach($bases as $base)
                                <option value="{{ $base->value }}" @selected(old('base', $rule->base) === $base->value)>{{ $base->label() }}</option>
                            @endforeach
                        </select>
                        @error('base')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-4" data-tr-flat>
                        <label class="form-label" for="tr-fixed">{{ __('Fixed amount') }}</label>
                        <div class="input-group">
                            <input type="text" id="tr-fixed" name="fixed_amount" inputmode="decimal" dir="ltr"
                                   value="{{ old('fixed_amount', $rule->exists && \App\Support\DecimalMoney::compare((string) $rule->fixed_amount, '0') > 0 ? rtrim(rtrim((string) $rule->fixed_amount, '0'), '.') : '') }}"
                                   class="form-control @error('fixed_amount') is-invalid @enderror" placeholder="0">
                            <span class="input-group-text">{{ $currency }}</span>
                            @error('fixed_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <small class="text-body-secondary">{{ __('Converted to the customer\'s currency at the transaction\'s rate.') }}</small>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="tr-sort">{{ __('Sort order') }}</label>
                        <input type="number" id="tr-sort" name="sort_order" min="0" step="1"
                               value="{{ old('sort_order', $rule->sort_order ?? 0) }}"
                               class="form-control @error('sort_order') is-invalid @enderror">
                        @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="text-body-secondary">{{ __('The order the lines are listed in.') }}</small>
                    </div>

                    <div class="col-md-4">
                        <span class="form-label d-block">{{ __('Status') }}</span>
                        <input type="hidden" name="is_active" value="0">
                        <label class="form-check form-switch feature-mgmt-switch feature-mgmt-switch--lg">
                            <input class="form-check-input" type="checkbox" role="switch" id="tr-active" name="is_active" value="1"
                                   aria-label="{{ __('Rule is on') }}" @checked($active)>
                        </label>
                    </div>
                </div>

                <div class="d-flex gap-2 justify-content-end mt-4">
                    <a href="{{ route('admin.tax-rule.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                    <button type="submit" class="btn btn-primary" data-tr-save>{{ __('Save rule') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection
