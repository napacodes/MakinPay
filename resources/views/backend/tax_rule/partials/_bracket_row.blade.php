{{--
    One amount bracket on the tax rule form. `$index` is a number, or
    __INDEX__ in the template tax-rules.js copies for a new row. The "From"
    cell is filled in by the script from the limit above it; the server reads
    only the limit, the percent and the fixed part.
--}}
<tr data-tr-bracket-row>
    <td class="text-body-secondary text-nowrap" dir="ltr" data-tr-bracket-from>&nbsp;</td>
    <td>
        <input type="text" name="brackets[{{ $index }}][up_to]" inputmode="decimal" dir="ltr"
               value="{{ $row['up_to'] ?? '' }}" placeholder="{{ __('and above') }}"
               aria-label="{{ __('Up to') }}" data-tr-bracket-up-to
               class="form-control form-control-sm @error("brackets.$index.up_to") is-invalid @enderror">
        @error("brackets.$index.up_to")<div class="invalid-feedback">{{ $message }}</div>@enderror
    </td>
    <td>
        <div class="input-group input-group-sm">
            <input type="text" name="brackets[{{ $index }}][percent]" inputmode="decimal" dir="ltr"
                   value="{{ $row['percent'] ?? '' }}" placeholder="0" aria-label="{{ __('Percent') }}"
                   class="form-control @error("brackets.$index.percent") is-invalid @enderror">
            <span class="input-group-text">%</span>
            @error("brackets.$index.percent")<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </td>
    <td>
        <input type="text" name="brackets[{{ $index }}][fixed]" inputmode="decimal" dir="ltr"
               value="{{ $row['fixed'] ?? '' }}" placeholder="0" aria-label="{{ __('Fixed amount') }}"
               class="form-control form-control-sm @error("brackets.$index.fixed") is-invalid @enderror">
        @error("brackets.$index.fixed")<div class="invalid-feedback">{{ $message }}</div>@enderror
    </td>
    <td class="text-end">
        <button type="button" class="btn btn-outline-danger btn-sm" data-tr-bracket-remove aria-label="{{ __('Remove bracket') }}">
            <x-icon name="trash" width="14" height="14"/>
        </button>
    </td>
</tr>
