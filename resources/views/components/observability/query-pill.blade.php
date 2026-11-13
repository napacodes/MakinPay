@props(['token'])

@php
    /*
     * A token the parser could not place still renders. Hiding it would leave
     * the reader looking at a filtered list with no sign that one of their
     * conditions was ignored — an empty result that reads as good news.
     */
    $valid = (bool) ($token['valid'] ?? false);
@endphp

<span class="obs-pill @if(! $valid) obs-pill--invalid @endif"
      @if(! $valid) title="{{ $token['reason'] }}" @endif>
    <span>{{ $token['raw'] }}</span>
    @if(! $valid)
        <span class="visually-hidden">{{ $token['reason'] }}</span>
    @endif
    <button type="button"
            class="obs-pill__remove"
            data-obs-remove-token="{{ $token['raw'] }}"
            aria-label="{{ __('Remove filter :filter', ['filter' => $token['raw']]) }}">&times;</button>
</span>
