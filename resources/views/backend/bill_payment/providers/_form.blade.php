{{--
    The provider editor, shared by create and edit. The code and the driver
    are chosen once: on edit they are shown, not posted.
--}}
@php
    $plain = static function ($value): string {
        if ($value === null || $value === '') {
            return '';
        }

        $value = (string) $value;

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    };

    $isNew       = ! $provider->exists;
    $feeCurrency = strtoupper((string) old('fee_currency', $provider->fee_currency));
    $status      = (bool) old('status', $provider->status);

    $isApi          = (bool) ($setup['api'] ?? false);
    $providerConfig = is_array($provider->config) ? $provider->config : [];
    $syncCountries  = old('config.sync_countries', $providerConfig['sync_countries'] ?? []);
    $syncCountries  = is_array($syncCountries) ? implode(', ', array_filter($syncCountries, 'is_string')) : (string) $syncCountries;
    $customerLookup = (string) old('config.customer_lookup', $providerConfig['customer_lookup'] ?? \App\Http\Requests\Backend\BillPayment\StoreBillPaymentProviderRequest::CUSTOMER_LOOKUP_DEFAULT);
    $autoSync       = filter_var(old('config.auto_sync', $providerConfig['auto_sync'] ?? false), FILTER_VALIDATE_BOOLEAN);
    $canSync        = (bool) ($setup['sync'] ?? false);
    $lookupLabels   = [
        'advisory' => __('Advisory: block only an account the provider says does not exist'),
        'required' => __('Required: also block while the provider cannot be asked'),
        'off'      => __('Off: never ask the provider'),
    ];
@endphp

<form action="{{ $action }}" method="POST" enctype="multipart/form-data" class="bpa-editor" novalidate>
    @csrf
    @if($method !== 'POST')
        @method($method)
    @endif

    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            {{ __('Some fields need attention. Check the highlighted fields below.') }}
        </div>
    @endif

    <section class="card border-0 bpa-card bpa-form-section">
        <div class="card-body">
            <header class="bpa-form-section__head">
                <span class="bpa-form-section__step" aria-hidden="true">1</span>
                <div>
                    <h2 class="bpa-form-section__title">{{ __('Identity') }}</h2>
                    <p class="bpa-form-section__hint">{{ __('The provider and the driver that hands it payments.') }}</p>
                </div>
            </header>

            <div class="bpa-identity">
                <div class="bpa-identity__logo">
                    <span class="form-label">{{ __('Logo') }}</span>
                    <x-img name="logo" :old="$provider->logo" ref="-bpa-provider"/>
                    @error('logo')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="bpa-provider-name">{{ __('Name') }} <span class="text-danger">*</span></label>
                        <input type="text" id="bpa-provider-name" name="name" maxlength="120" required
                               value="{{ old('name', $provider->name) }}"
                               class="form-control @error('name') is-invalid @enderror">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="bpa-provider-code">{{ __('Code') }} @if($isNew)<span class="text-danger">*</span>@endif</label>
                        @if($isNew)
                            <input type="text" id="bpa-provider-code" name="code" maxlength="64" required dir="ltr"
                                   value="{{ old('code') }}"
                                   class="form-control bpa-mono @error('code') is-invalid @enderror"
                                   placeholder="my_provider">
                            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <small class="bpa-muted">{{ __('Letters, digits, dashes and underscores. It cannot change later.') }}</small>
                        @else
                            <input type="text" id="bpa-provider-code" value="{{ $provider->code }}" class="form-control bpa-mono" dir="ltr" readonly>
                            <small class="bpa-muted">{{ __('Payments record this code, so it cannot change.') }}</small>
                        @endif
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="bpa-provider-driver">{{ __('Driver') }} @if($isNew)<span class="text-danger">*</span>@endif</label>
                        @if($isNew)
                            <select id="bpa-provider-driver" name="driver" required class="form-select @error('driver') is-invalid @enderror">
                                @foreach($driverLabels as $driver => $label)
                                    <option value="{{ $driver }}" @selected(old('driver', 'manual') === $driver)>{{ __($label) }}</option>
                                @endforeach
                            </select>
                            @error('driver')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @else
                            <input type="text" id="bpa-provider-driver" class="form-control" readonly
                                   value="{{ isset($driverLabels[$provider->driver]) ? __($driverLabels[$provider->driver]) : $provider->driver }}">
                            <small class="bpa-muted">{{ __('The driver decides who fulfils a payment, so it cannot change.') }}</small>
                        @endif
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="bpa-provider-sort">{{ __('Sort order') }}</label>
                        <input type="number" id="bpa-provider-sort" name="sort_order" min="0" step="1"
                               value="{{ old('sort_order', $provider->sort_order) }}"
                               class="form-control @error('sort_order') is-invalid @enderror">
                        @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="bpa-provider-description">{{ __('Description') }} <span class="bpa-muted">({{ __('optional') }})</span></label>
                        <input type="text" id="bpa-provider-description" name="description" maxlength="500"
                               value="{{ old('description', $provider->description) }}"
                               class="form-control @error('description') is-invalid @enderror">
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="card border-0 bpa-card bpa-form-section">
        <div class="card-body">
            <header class="bpa-form-section__head">
                <span class="bpa-form-section__step" aria-hidden="true">2</span>
                <div>
                    <h2 class="bpa-form-section__title">{{ __('Fees') }}</h2>
                    <p class="bpa-form-section__hint">{{ __('The default fee for every biller this provider pays. A biller can override either part.') }}</p>
                </div>
            </header>

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="bpa-provider-fee-percent">{{ __('Percentage fee') }}</label>
                    <div class="input-group">
                        <input type="text" id="bpa-provider-fee-percent" name="fee_percent" inputmode="decimal" dir="ltr"
                               value="{{ old('fee_percent', $plain($provider->fee_percent)) }}"
                               class="form-control @error('fee_percent') is-invalid @enderror" placeholder="0">
                        <span class="input-group-text">%</span>
                        @error('fee_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="bpa-provider-fee-fixed">{{ __('Fixed fee') }}</label>
                    <input type="text" id="bpa-provider-fee-fixed" name="fee_fixed" inputmode="decimal" dir="ltr"
                           value="{{ old('fee_fixed', $plain($provider->fee_fixed)) }}"
                           class="form-control @error('fee_fixed') is-invalid @enderror" placeholder="0">
                    @error('fee_fixed')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="bpa-provider-fee-currency">{{ __('Fixed fee currency') }}</label>
                    <select id="bpa-provider-fee-currency" name="fee_currency" class="form-select @error('fee_currency') is-invalid @enderror">
                        <option value="">{{ __('None') }}</option>
                        @foreach($currencies as $currency)
                            <option value="{{ $currency->code }}" @selected($feeCurrency === strtoupper((string) $currency->code))>{{ $currency->code }} · {{ $currency->name }}</option>
                        @endforeach
                    </select>
                    @error('fee_currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <p class="bpa-muted mb-0">{{ __('The fixed fee is never converted: it applies only to billers priced in its currency, and other billers inherit the percentage alone.') }}</p>
                </div>
            </div>
        </div>
    </section>

    <section class="card border-0 bpa-card bpa-form-section">
        <div class="card-body">
            <header class="bpa-form-section__head">
                <span class="bpa-form-section__step" aria-hidden="true">3</span>
                <div>
                    <h2 class="bpa-form-section__title">{{ __('Customers and status') }}</h2>
                    <p class="bpa-form-section__hint">{{ __('What customers are told about timing, and whether this provider takes payments now.') }}</p>
                </div>
            </header>

            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="bpa-provider-note">{{ __('Processing note') }} <span class="bpa-muted">({{ __('optional') }})</span></label>
                    <input type="text" id="bpa-provider-note" name="processing_note" maxlength="255"
                           value="{{ old('processing_note', $provider->processing_note) }}"
                           class="form-control @error('processing_note') is-invalid @enderror"
                           placeholder="{{ __('e.g. Usually paid within 2 hours') }}">
                    @error('processing_note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-4">
                    <div class="bpa-status-row">
                        <div>
                            <label class="form-label mb-0" for="bpa-provider-status">{{ __('Provider is on') }}</label>
                            <small class="bpa-muted d-block">{{ __('Off hides every biller it pays.') }}</small>
                        </div>
                        <input type="hidden" name="status" value="0">
                        <label class="form-check form-switch feature-mgmt-switch feature-mgmt-switch--lg">
                            <input class="form-check-input" type="checkbox" role="switch" id="bpa-provider-status"
                                   name="status" value="1" @checked($status)>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </section>

    @if($isApi)
        <section class="card border-0 bpa-card bpa-form-section">
            <div class="card-body">
                <header class="bpa-form-section__head">
                    <span class="bpa-form-section__step" aria-hidden="true">4</span>
                    <div>
                        <h2 class="bpa-form-section__title">{{ __('Provider API') }}</h2>
                        <p class="bpa-form-section__hint">{{ __('Which countries a catalogue sync covers, and how customer accounts are checked before a payment.') }}</p>
                    </div>
                </header>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="bpa-provider-sync-countries">{{ __('Sync countries') }} <span class="bpa-muted">({{ __('optional') }})</span></label>
                        <input type="text" id="bpa-provider-sync-countries" name="config[sync_countries]" dir="ltr" maxlength="400"
                               value="{{ $syncCountries }}"
                               class="form-control bpa-mono @if($errors->has('config.sync_countries') || $errors->has('config.sync_countries.*')) is-invalid @endif"
                               placeholder="NG, KE">
                        @if($errors->has('config.sync_countries') || $errors->has('config.sync_countries.*'))
                            <div class="invalid-feedback">{{ $errors->first('config.sync_countries') ?: $errors->first('config.sync_countries.*') }}</div>
                        @endif
                        <small class="bpa-muted">{{ __('Two-letter country codes, separated by commas.') }}</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="bpa-provider-customer-lookup">{{ __('Customer account check') }}</label>
                        <select id="bpa-provider-customer-lookup" name="config[customer_lookup]" class="form-select @error('config.customer_lookup') is-invalid @enderror">
                            @foreach($lookupLabels as $policy => $label)
                                <option value="{{ $policy }}" @selected($customerLookup === $policy)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('config.customer_lookup')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="bpa-muted">{{ __('Used only where the provider can look an account up.') }}</small>
                    </div>

                    @if($canSync)
                        {{-- BP4: the daily catalogue sync. Off by default; a sync by hand from the edit screen is unchanged. --}}
                        <div class="col-12">
                            <div class="d-flex align-items-start justify-content-between gap-3" data-bpa-auto-sync>
                                <div>
                                    <label class="form-label mb-1" for="bpa-provider-auto-sync">{{ __('Sync the catalogue every day') }}</label>
                                    <div class="bpa-muted small">{{ __('Every day at 04:10, for the sync countries above and every mapped category. New billers arrive switched off, nothing is deleted, and the bill payment admins are told what changed. Skipped while the provider is off or paused.') }}</div>
                                    @error('config.auto_sync')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                </div>
                                <input type="hidden" name="config[auto_sync]" value="0">
                                <label class="form-check form-switch feature-mgmt-switch feature-mgmt-switch--lg">
                                    <input class="form-check-input" type="checkbox" role="switch" id="bpa-provider-auto-sync"
                                           name="config[auto_sync]" value="1" @checked($autoSync)>
                                </label>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif

    <div class="bpa-editor__actions">
        <a href="{{ route('admin.bill-payment.providers.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
        <button type="submit" class="btn btn-primary">{{ $submitLabel }}</button>
    </div>
</form>
