@props(['level'])

@php
    /*
     * Eight PSR-3 levels collapse into four badges here, at render time —
     * the stored value keeps the distinction the badge hides.
     *
     * The glyph is a shape, not a colour: an octagon, a triangle, a diamond
     * and a square. With the uppercase label beside it, severity survives
     * grayscale, colour blindness and a screen reader, so no reader depends on
     * hue alone. There is deliberately no icon-only variant.
     */
    $badge = $level->badge();

    $glyphs = [
        'fatal'   => 'M8.6 2h6.8L22 8.6v6.8L15.4 22H8.6L2 15.4V8.6z',
        'error'   => 'M12 2 22.5 21H1.5z',
        'warning' => 'M12 1.5 22.5 12 12 22.5 1.5 12z',
        'info'    => 'M3.5 3.5h17v17h-17z',
    ];
@endphp

<span {{ $attributes->class(['obs-badge', 'obs-badge--'.$badge]) }}
      title="{{ $level->label() }}">
    <svg class="obs-badge__glyph" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="{{ $glyphs[$badge] }}"/>
    </svg>
    <span>{{ $level->badgeLabel() }}</span>
    <span class="visually-hidden">{{ __('Severity: :level', ['level' => $level->label()]) }}</span>
</span>
