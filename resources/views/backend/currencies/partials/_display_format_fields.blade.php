@php
    use App\Support\Money;

    /*
     * How this currency is written, as opposed to how precisely it is stored.
     * Both default to what Money::format() did before these columns existed —
     * a prefixed symbol and thousands grouping — so an upgraded installation
     * renders exactly as it did until an administrator changes one.
     */
    $dkCurrency  = $currency ?? null;
    $dkIdPrefix  = $idPrefix ?? '';
    $dkPosition  = old('symbol_position', $dkCurrency?->symbol_position ?: Money::POSITION_PREFIX);
    $dkGrouping  = old('number_grouping', $dkCurrency?->number_grouping ?: Money::GROUPING_STANDARD);
    $dkSymbol    = $dkCurrency?->symbol ?: '$';
@endphp

<div class="currency-field-grid mt-3">
    <div class="currency-field">
        <label class="form-label" for="{{ $dkIdPrefix }}symbol_position">{{ __('Symbol Position') }}</label>
        <select name="symbol_position" id="{{ $dkIdPrefix }}symbol_position" class="form-control">
            <option value="{{ Money::POSITION_PREFIX }}" @selected($dkPosition === Money::POSITION_PREFIX)>
                {{ __('Before the amount') }} — {{ $dkSymbol }}1,000.00
            </option>
            <option value="{{ Money::POSITION_SUFFIX }}" @selected($dkPosition === Money::POSITION_SUFFIX)>
                {{ __('After the amount') }} — 1,000.00 {{ $dkSymbol }}
            </option>
        </select>
        <small class="text-body-secondary">
            {{ __('Most tickers read better after the amount (1,000.00 USDT); most currency signs read better before it.') }}
        </small>
    </div>

    <div class="currency-field">
        <label class="form-label" for="{{ $dkIdPrefix }}number_grouping">{{ __('Digit Grouping') }}</label>
        <select name="number_grouping" id="{{ $dkIdPrefix }}number_grouping" class="form-control">
            <option value="{{ Money::GROUPING_STANDARD }}" @selected($dkGrouping === Money::GROUPING_STANDARD)>
                {{ __('Thousands') }} — 1,234,567.00
            </option>
            <option value="{{ Money::GROUPING_INDIAN }}" @selected($dkGrouping === Money::GROUPING_INDIAN)>
                {{ __('Lakh & crore') }} — 12,34,567.00
            </option>
        </select>
        <small class="text-body-secondary">
            {{ __('Lakh and crore grouping is how BDT, INR, PKR, NPR and LKR are written.') }}
        </small>
    </div>
</div>
