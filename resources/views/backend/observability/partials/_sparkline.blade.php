@php
    /*
     * A 24-hour shape, drawn from error_stat_buckets. It is decoration for the
     * count beside it, so it is hidden from assistive technology rather than
     * given a label nobody can act on — the number is the accessible value.
     */
    $values = collect($points ?? [])->values();
    $peak   = max(1, (int) $values->max());
    $step   = $values->count() > 1 ? 62 / ($values->count() - 1) : 62;

    $coordinates = $values
        ->map(fn ($value, $index) => round($index * $step, 2).','.round(20 - (((int) $value / $peak) * 18), 2))
        ->implode(' ');
@endphp

@if($values->count() > 1)
    <svg class="obs-sparkline" viewBox="0 0 62 20" aria-hidden="true" focusable="false">
        <polyline points="{{ $coordinates }}" stroke="var(--err-{{ $level->badge() }})"/>
    </svg>
@endif
