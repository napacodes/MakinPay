{{--
    The biller editor, shared by create and edit.

    Every amount here is in the biller's own currency. The browser only helps:
    the pattern tester, the inherited-fee line and the section toggles are
    display aids, and StoreBillerRequest decides what is saved.
--}}
@php
    /* Stored decimals arrive padded to eight places; show them as typed. */
    $plain = static function ($value): string {
        if ($value === null || $value === '') {
            return '';
        }

        $value = (string) $value;

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    };

    /* A synced biller's packages and amount type belong to the catalogue sync. */
    $isSynced   = $biller->exists && $biller->isSyncManaged();
    $amountType = $isSynced
        ? (string) ($biller->amount_type?->value ?? 'open')
        : (string) old('amount_type', $biller->amount_type?->value ?? 'open');

    $items = old('items');

    if (! is_array($items)) {
        $items = $biller->exists
            ? $biller->items->map(fn ($item) => [
                'id'            => $item->id,
                'name'          => $item->name,
                'amount'        => $plain($item->amount),
                'external_code' => $item->external_code,
                'status'        => $item->status ? '1' : '0',
            ])->all()
            : [];
    }

    if ($items === []) {
        $items = [['id' => '', 'name' => '', 'amount' => '', 'external_code' => '', 'status' => '1']];
    }

    $pattern       = (string) old('customer_number_pattern', $biller->customer_number_pattern);
    $presetKey     = collect($patternPresets)->search(fn ($preset) => $preset['pattern'] === $pattern) ?: 'custom';
    /*
     * A switched-off override disables its input, so a failed save posts no
     * key for it; with old input present, that absence means "no override".
     */
    $hasOldInput   = session()->hasOldInput();
    $feePercent    = $hasOldInput ? old('fee_percent') : $plain($biller->fee_percent);
    $feeFixed      = $hasOldInput ? old('fee_fixed') : $plain($biller->fee_fixed);
    $nextItemIndex = max(array_map('intval', array_keys($items))) + 1;
    $overridesPct  = $feePercent !== null && $feePercent !== '';
    $overridesFix  = $feeFixed !== null && $feeFixed !== '';
    $providerId    = (int) old('bill_payment_provider_id', $biller->bill_payment_provider_id);
    $currencyCode  = strtoupper((string) old('currency', $biller->currency));
    $status        = (bool) old('status', $biller->status);
@endphp

<form action="{{ $action }}" method="POST" enctype="multipart/form-data" class="bpa-editor" data-bpa-biller-form novalidate>
    @csrf
    @if($method !== 'POST')
        @method($method)
    @endif

    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            {{ __('Some fields need attention. Check the highlighted fields below.') }}
        </div>
    @endif

    {{-- 1. Identity --}}
    <section class="card border-0 bpa-card bpa-form-section">
        <div class="card-body">
            <header class="bpa-form-section__head">
                <span class="bpa-form-section__step" aria-hidden="true">1</span>
                <div>
                    <h2 class="bpa-form-section__title">{{ __('Identity') }}</h2>
                    <p class="bpa-form-section__hint">{{ __('Who the customer is paying, where, and in which currency.') }}</p>
                </div>
            </header>

            <div class="bpa-identity">
                <div class="bpa-identity__logo">
                    <span class="form-label">{{ __('Logo') }}</span>
                    <x-img name="logo" :old="$biller->logo" ref="-bpa-biller"/>
                    @error('logo')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="bpa-name">{{ __('Name') }} <span class="text-danger">*</span></label>
                        <input type="text" id="bpa-name" name="name" maxlength="120" required
                               value="{{ old('name', $biller->name) }}"
                               class="form-control @error('name') is-invalid @enderror">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="bpa-category">{{ __('Category') }} <span class="text-danger">*</span></label>
                        <select id="bpa-category" name="bill_category_id" required class="form-select @error('bill_category_id') is-invalid @enderror">
                            <option value="">{{ __('Choose a category') }}</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected((int) old('bill_category_id', $biller->bill_category_id) === $category->id)>
                                    {{ __($category->name) }}{{ $category->status ? '' : ' ('.__('off').')' }}
                                </option>
                            @endforeach
                        </select>
                        @error('bill_category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="bpa-provider">{{ __('Provider') }} <span class="text-danger">*</span></label>
                        <select id="bpa-provider" name="bill_payment_provider_id" required data-bpa-provider
                                class="form-select @error('bill_payment_provider_id') is-invalid @enderror">
                            <option value="">{{ __('Choose a provider') }}</option>
                            @foreach($providers as $provider)
                                <option value="{{ $provider->id }}"
                                        data-fee-percent="{{ $plain($provider->fee_percent) }}"
                                        data-fee-fixed="{{ $plain($provider->fee_fixed) }}"
                                        data-fee-currency="{{ strtoupper((string) $provider->fee_currency) }}"
                                        @selected($providerId === $provider->id)>
                                    {{ $provider->name }}{{ $provider->status ? '' : ' ('.__('off').')' }}
                                </option>
                            @endforeach
                        </select>
                        @error('bill_payment_provider_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="bpa-country">{{ __('Country') }} <span class="text-danger">*</span></label>
                        <select id="bpa-country" name="country" required class="form-select @error('country') is-invalid @enderror">
                            <option value="">{{ __('Choose') }}</option>
                            @foreach($countries as $code => $label)
                                <option value="{{ $code }}" @selected(strtoupper((string) old('country', $biller->country)) === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('country')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="bpa-currency">{{ __('Currency') }} <span class="text-danger">*</span></label>
                        <select id="bpa-currency" name="currency" required data-bpa-currency
                                class="form-select @error('currency') is-invalid @enderror">
                            <option value="">{{ __('Choose') }}</option>
                            @foreach($currencies as $currency)
                                <option value="{{ $currency->code }}" @selected($currencyCode === strtoupper((string) $currency->code))>{{ $currency->code }} · {{ $currency->name }}</option>
                            @endforeach
                        </select>
                        @error('currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="bpa-muted">{{ __('Customers pay from a wallet in this currency.') }}</small>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="bpa-description">{{ __('Description') }} <span class="bpa-muted">({{ __('optional') }})</span></label>
                        <input type="text" id="bpa-description" name="description" maxlength="500"
                               value="{{ old('description', $biller->description) }}"
                               class="form-control @error('description') is-invalid @enderror">
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- 2. Customer number --}}
    <section class="card border-0 bpa-card bpa-form-section">
        <div class="card-body">
            <header class="bpa-form-section__head">
                <span class="bpa-form-section__step" aria-hidden="true">2</span>
                <div>
                    <h2 class="bpa-form-section__title">{{ __('Customer number') }}</h2>
                    <p class="bpa-form-section__hint">{{ __('The number that identifies the customer at the biller: a meter, a smartcard, an account.') }}</p>
                </div>
            </header>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="bpa-number-label">{{ __('Label') }} <span class="text-danger">*</span></label>
                    <input type="text" id="bpa-number-label" name="customer_number_label" maxlength="80" required
                           value="{{ old('customer_number_label', $biller->customer_number_label) }}"
                           class="form-control @error('customer_number_label') is-invalid @enderror"
                           placeholder="{{ __('e.g. Meter number') }}">
                    @error('customer_number_label')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="bpa-number-preset">{{ __('Format') }}</label>
                    <select id="bpa-number-preset" class="form-select" data-bpa-pattern-preset>
                        <option value="custom" data-pattern="" @selected($presetKey === 'custom')>{{ __('Custom pattern (or none)') }}</option>
                        @foreach($patternPresets as $key => $preset)
                            <option value="{{ $key }}" data-pattern="{{ $preset['pattern'] }}" @selected($presetKey === $key)>{{ __($preset['label']) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="bpa-number-pattern">{{ __('Pattern') }} <span class="bpa-muted">({{ __('optional') }})</span></label>
                    <input type="text" id="bpa-number-pattern" name="customer_number_pattern" maxlength="255" dir="ltr"
                           value="{{ $pattern }}" data-bpa-pattern spellcheck="false" autocomplete="off"
                           class="form-control bpa-mono @error('customer_number_pattern') is-invalid @enderror"
                           placeholder="[0-9]{11}">
                    @error('customer_number_pattern')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="bpa-muted">{{ __('A regular expression the whole number must match, without slashes or anchors. Leave it empty to accept any number.') }}</small>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="bpa-number-example">{{ __('Example') }}</label>
                    <input type="text" id="bpa-number-example" name="customer_number_example" maxlength="64" dir="ltr"
                           value="{{ old('customer_number_example', $biller->customer_number_example) }}"
                           class="form-control bpa-mono @error('customer_number_example') is-invalid @enderror"
                           placeholder="01234567891">
                    @error('customer_number_example')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="bpa-muted">{{ __('Shown to customers as a hint. Required with a pattern, and must match it.') }}</small>
                </div>

                <div class="col-12">
                    <label class="form-label" for="bpa-number-help">{{ __('Help text') }} <span class="bpa-muted">({{ __('optional') }})</span></label>
                    <input type="text" id="bpa-number-help" name="customer_number_help" maxlength="255"
                           value="{{ old('customer_number_help', $biller->customer_number_help) }}"
                           class="form-control @error('customer_number_help') is-invalid @enderror"
                           placeholder="{{ __('e.g. The 11 digits printed on your meter') }}">
                    @error('customer_number_help')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <div class="bpa-tester"
                         data-bpa-tester
                         data-text-empty="{{ __('Type a number to try it against the pattern.') }}"
                         data-text-none="{{ __('No pattern: any number is accepted.') }}"
                         data-text-match="{{ __('This number matches.') }}"
                         data-text-miss="{{ __('This number does not match.') }}"
                         data-text-unknown="{{ __('The browser cannot check this pattern. The server will when you save.') }}">
                        <label class="form-label" for="bpa-number-try">{{ __('Try a number') }}</label>
                        <input type="text" id="bpa-number-try" class="form-control bpa-mono" dir="ltr" autocomplete="off" data-bpa-tester-input>
                        <p class="bpa-tester__result" data-bpa-tester-result role="status" aria-live="polite">{{ __('Type a number to try it against the pattern.') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- 3. Amount --}}
    <section class="card border-0 bpa-card bpa-form-section">
        <div class="card-body">
            <header class="bpa-form-section__head">
                <span class="bpa-form-section__step" aria-hidden="true">3</span>
                <div>
                    <h2 class="bpa-form-section__title">{{ __('Amount') }}</h2>
                    <p class="bpa-form-section__hint">{{ __('Whether the customer types the amount, or picks one of your packages.') }}</p>
                </div>
            </header>

            <div class="bpa-segment" role="radiogroup" aria-label="{{ __('Amount type') }}">
                <input type="radio" class="btn-check" name="amount_type" id="bpa-amount-open" value="open" data-bpa-amount-type @checked($amountType !== 'fixed') @disabled($isSynced)>
                <label class="btn btn-outline-primary" for="bpa-amount-open">{{ __('Customer enters the amount') }}</label>
                <input type="radio" class="btn-check" name="amount_type" id="bpa-amount-fixed" value="fixed" data-bpa-amount-type @checked($amountType === 'fixed') @disabled($isSynced)>
                <label class="btn btn-outline-primary" for="bpa-amount-fixed">{{ __('Fixed packages') }}</label>
            </div>
            @error('amount_type')<div class="text-danger small mt-2">{{ $message }}</div>@enderror

            <div class="row g-3 mt-1" data-bpa-amount-section="open" @if($amountType === 'fixed') hidden @endif>
                <div class="col-md-6">
                    <label class="form-label" for="bpa-min">{{ __('Minimum amount') }} <span class="bpa-muted">({{ __('optional') }})</span></label>
                    <input type="text" id="bpa-min" name="min_amount" inputmode="decimal" dir="ltr"
                           value="{{ old('min_amount', $plain($biller->min_amount)) }}"
                           class="form-control @error('min_amount') is-invalid @enderror"
                           placeholder="{{ __('No minimum') }}">
                    @error('min_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="bpa-max">{{ __('Maximum amount') }} <span class="bpa-muted">({{ __('optional') }})</span></label>
                    <input type="text" id="bpa-max" name="max_amount" inputmode="decimal" dir="ltr"
                           value="{{ old('max_amount', $plain($biller->max_amount)) }}"
                           class="form-control @error('max_amount') is-invalid @enderror"
                           placeholder="{{ __('No maximum') }}">
                    @error('max_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            @if($isSynced)
                {{-- The sync owns these packages: only their on/off switch is posted, in this order. --}}
                <div class="bpa-items mt-3" data-bpa-amount-section="fixed" data-bpa-synced-items @if($amountType !== 'fixed') hidden @endif>
                    <p class="bpa-form-section__hint mb-2">{{ __('Packages are managed by catalogue sync. Names, prices and codes follow the provider; you can switch a package on or off.') }}</p>
                    @error('items')<div class="alert alert-danger py-2" role="alert">{{ $message }}</div>@enderror
                    @error('items.*.id')<div class="alert alert-danger py-2" role="alert">{{ $message }}</div>@enderror

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('Package') }}</th>
                                    <th scope="col">{{ __('Price') }}</th>
                                    <th scope="col">{{ __('Code') }}</th>
                                    <th scope="col">{{ __('On sale') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($biller->items as $syncedIndex => $syncedItem)
                                    <tr>
                                        <td>{{ $syncedItem->name }}</td>
                                        <td dir="ltr">{{ $plain($syncedItem->amount) }} {{ $biller->currency }}</td>
                                        <td class="bpa-mono" dir="ltr">{{ $syncedItem->external_code }}</td>
                                        <td>
                                            <input type="hidden" name="items[{{ $syncedIndex }}][id]" value="{{ $syncedItem->id }}">
                                            <input type="hidden" name="items[{{ $syncedIndex }}][status]" value="0">
                                            <label class="form-check form-switch feature-mgmt-switch mb-0">
                                                <input class="form-check-input" type="checkbox" role="switch"
                                                       name="items[{{ $syncedIndex }}][status]" value="1"
                                                       aria-label="{{ __('Package on sale') }}"
                                                       @checked($syncedItem->status)>
                                            </label>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="bpa-muted">{{ __('The provider lists no packages for this biller yet.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
            <div class="bpa-items mt-3" data-bpa-amount-section="fixed" data-bpa-items data-next-index="{{ $nextItemIndex }}" @if($amountType !== 'fixed') hidden @endif>
                @error('items')<div class="alert alert-danger py-2" role="alert">{{ $message }}</div>@enderror

                <div class="bpa-items__list" data-bpa-items-list>
                    @foreach($items as $index => $item)
                        @include('backend.bill_payment.billers._item_row', ['index' => $index, 'item' => $item])
                    @endforeach
                </div>

                <template data-bpa-item-template>
                    @include('backend.bill_payment.billers._item_row', ['index' => '__INDEX__', 'item' => []])
                </template>

                <button type="button" class="btn btn-outline-primary btn-sm" data-bpa-item-add>
                    <x-icon name="plus" width="14" height="14"/>
                    <span>{{ __('Add package') }}</span>
                </button>
            </div>
            @endif
        </div>
    </section>

    {{-- 4. Fees --}}
    <section class="card border-0 bpa-card bpa-form-section">
        <div class="card-body"
             data-bpa-fees
             data-text-percent="{{ __('Inherited from the provider: :percent%.') }}"
             data-text-percent-none="{{ __('Inherited from the provider: no percentage fee.') }}"
             data-text-fixed="{{ __('Inherited from the provider: :amount :currency.') }}"
             data-text-fixed-none="{{ __('Inherited from the provider: no fixed fee.') }}"
             data-text-fixed-foreign="{{ __("The provider's fixed fee is in :currency and does not apply to this biller.") }}"
             data-text-no-provider="{{ __('Choose a provider to see the fee this biller inherits.') }}">
            <header class="bpa-form-section__head">
                <span class="bpa-form-section__step" aria-hidden="true">4</span>
                <div>
                    <h2 class="bpa-form-section__title">{{ __('Fees') }}</h2>
                    <p class="bpa-form-section__hint">{{ __('What the customer pays on top of the bill. Each part follows the provider unless you override it here.') }}</p>
                </div>
            </header>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="bpa-fee">
                        <div class="bpa-fee__head">
                            <label class="form-label mb-0" for="bpa-fee-percent">{{ __('Percentage fee') }}</label>
                            <label class="form-check form-switch feature-mgmt-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       data-bpa-fee-override="#bpa-fee-percent"
                                       aria-label="{{ __('Override the percentage fee') }}"
                                       @checked($overridesPct)>
                            </label>
                        </div>
                        <div class="input-group">
                            <input type="text" id="bpa-fee-percent" name="fee_percent" inputmode="decimal" dir="ltr"
                                   value="{{ $feePercent }}"
                                   class="form-control @error('fee_percent') is-invalid @enderror"
                                   placeholder="0" @disabled(! $overridesPct)>
                            <span class="input-group-text">%</span>
                            @error('fee_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <small class="bpa-muted" data-bpa-inherited="percent"></small>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="bpa-fee">
                        <div class="bpa-fee__head">
                            <label class="form-label mb-0" for="bpa-fee-fixed">{{ __('Fixed fee') }}</label>
                            <label class="form-check form-switch feature-mgmt-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       data-bpa-fee-override="#bpa-fee-fixed"
                                       aria-label="{{ __('Override the fixed fee') }}"
                                       @checked($overridesFix)>
                            </label>
                        </div>
                        <div class="input-group">
                            <input type="text" id="bpa-fee-fixed" name="fee_fixed" inputmode="decimal" dir="ltr"
                                   value="{{ $feeFixed }}"
                                   class="form-control @error('fee_fixed') is-invalid @enderror"
                                   placeholder="0" @disabled(! $overridesFix)>
                            <span class="input-group-text" data-bpa-currency-label>{{ $currencyCode !== '' ? $currencyCode : __('Currency') }}</span>
                            @error('fee_fixed')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <small class="bpa-muted" data-bpa-inherited="fixed"></small>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- 5. Delivery and status --}}
    <section class="card border-0 bpa-card bpa-form-section">
        <div class="card-body">
            <header class="bpa-form-section__head">
                <span class="bpa-form-section__step" aria-hidden="true">5</span>
                <div>
                    <h2 class="bpa-form-section__title">{{ __('Delivery and status') }}</h2>
                    <p class="bpa-form-section__hint">{{ __('What the customer receives, what they are told, and whether they can pay this biller now.') }}</p>
                </div>
            </header>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="bpa-token-label">{{ __('Token label') }} <span class="bpa-muted">({{ __('optional') }})</span></label>
                    <input type="text" id="bpa-token-label" name="token_label" maxlength="80"
                           value="{{ old('token_label', $biller->token_label) }}"
                           class="form-control @error('token_label') is-invalid @enderror"
                           placeholder="{{ __('e.g. Electricity token') }}">
                    @error('token_label')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="bpa-muted">{{ __('Set it when the biller issues a code the customer needs, such as a prepaid token. Marking a payment paid will then ask for it.') }}</small>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="bpa-processing-note">{{ __('Processing note') }} <span class="bpa-muted">({{ __('optional') }})</span></label>
                    <input type="text" id="bpa-processing-note" name="processing_note" maxlength="255"
                           value="{{ old('processing_note', $biller->processing_note) }}"
                           class="form-control @error('processing_note') is-invalid @enderror"
                           placeholder="{{ __("Leave empty to use the provider's note") }}">
                    @error('processing_note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label" for="bpa-instructions">{{ __('Instructions') }} <span class="bpa-muted">({{ __('optional') }})</span></label>
                    <textarea id="bpa-instructions" name="instructions" rows="3" maxlength="2000"
                              class="form-control @error('instructions') is-invalid @enderror"
                              placeholder="{{ __('Shown on the pay page and the receipt.') }}">{{ old('instructions', $biller->instructions) }}</textarea>
                    @error('instructions')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="bpa-sort-order">{{ __('Sort order') }}</label>
                    <input type="number" id="bpa-sort-order" name="sort_order" min="0" step="1"
                           value="{{ old('sort_order', $biller->sort_order ?? 0) }}"
                           class="form-control @error('sort_order') is-invalid @enderror">
                    @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <div class="bpa-status-row">
                        <div>
                            <label class="form-label mb-0" for="bpa-status">{{ __('Customers can pay this biller') }}</label>
                            <small class="bpa-muted d-block">{{ __('Its category and provider must be on as well.') }}</small>
                        </div>
                        <input type="hidden" name="status" value="0">
                        <label class="form-check form-switch feature-mgmt-switch feature-mgmt-switch--lg">
                            <input class="form-check-input" type="checkbox" role="switch" id="bpa-status"
                                   name="status" value="1" @checked($status)>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="bpa-editor__actions">
        <a href="{{ route('admin.bill-payment.billers.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
        <button type="submit" class="btn btn-primary">{{ $submitLabel }}</button>
    </div>
</form>
