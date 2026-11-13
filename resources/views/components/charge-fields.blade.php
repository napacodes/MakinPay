@props([
    'prefix'   => '',
    'method'   => null,
    'currency' => null,
])
@php
    // '' for the base charge, 'user_' / 'merchant_' / 'agent_' for role overrides.
    $fixedName   = $prefix.'charge_fixed';
    $percentName = $prefix.'charge_percent';
    $minName     = $prefix.'charge_min';
    $maxName     = $prefix.'charge_max';

    $unit = $currency ?: ($method->currency ?? siteCurrency());

    /**
     * These columns are decimal(24,8), so an unset component renders as
     * "0.00000000" — and the input is too narrow to show the leading "0.".
     * An admin clicking in and typing 0.0001 therefore produced
     * "0.000000000.0001", which validateDouble() truncates at the second
     * decimal point back down to zero. The fee silently would not save.
     *
     * An unset component renders empty so the placeholder shows and typing
     * starts from a clean field; a configured one drops its storage padding.
     */
    $value = function (string $name) use ($method): string {
        $raw = old($name, $method?->getAttributes()[$name] ?? null);

        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return '';
        }

        return \App\Support\DecimalMoney::compare($raw, 0) === 0
            ? ''
            : \App\Support\Money::trimFee($raw);
    };
@endphp

{{-- Total charge = Fixed + (Amount x Percentage), then clamped to any
     min/max. Leave a component at 0 for a purely fixed or purely
     percentage charge. --}}
{{-- The fixed component is charged in the method's own currency, the way
     processors publish fee schedules. Its base-currency equivalent is shown
     underneath so the admin can compare methods without converting by hand. --}}
<div class="input-group mb-1">
    <span class="input-group-text">{{ __('Fixed') }}</span>
    <input class="form-control" type="text" oninput="this.value = validateDouble(this.value)"
           name="{{ $fixedName }}" value="{{ $value($fixedName) }}" placeholder="{{ __('e.g. 0.50') }}"
           data-equivalent="to-base">
    <span class="input-group-text">{{ $unit }}</span>
</div>
<small class="text-muted d-block mb-2" data-equivalent-for="{{ $fixedName }}"></small>

<div class="input-group">
    <span class="input-group-text">{{ __('Percent') }}</span>
    <input class="form-control" type="text" oninput="this.value = validateDouble(this.value)"
           name="{{ $percentName }}" value="{{ $value($percentName) }}" placeholder="{{ __('e.g. 2.5') }}">
    <span class="input-group-text">%</span>
</div>

{{-- The floor and ceiling clamp the *charge*, and they are read in the same
     currency the fixed component is written in — FeeCalculator does the whole
     calculation, clamp included, in the method's own currency. Without the
     badge the admin had no way to tell whether "25" meant 25 USD or 25 BTC,
     and the percentage field sitting directly above it invited reading them
     as percentages. --}}
<details class="charge-config__advanced mt-2">
    <summary class="small text-muted">{{ __('Minimum / maximum charge') }}</summary>
    <small class="text-muted d-block mt-1">
        {{ __('A flat floor and ceiling on the total charge, in :currency — not a percentage.', ['currency' => $unit]) }}
    </small>
    <div class="input-group input-group-sm mt-2">
        <span class="input-group-text">{{ __('Min') }}</span>
        <input class="form-control" type="text" oninput="this.value = validateDouble(this.value)"
               name="{{ $minName }}" value="{{ $value($minName) }}" placeholder="{{ __('No minimum') }}">
        <span class="input-group-text">{{ $unit }}</span>
    </div>
    <div class="input-group input-group-sm mt-1">
        <span class="input-group-text">{{ __('Max') }}</span>
        <input class="form-control" type="text" oninput="this.value = validateDouble(this.value)"
               name="{{ $maxName }}" value="{{ $value($maxName) }}" placeholder="{{ __('No maximum') }}">
        <span class="input-group-text">{{ $unit }}</span>
    </div>
</details>
