@php
    /**
     * One navigation row — a top-level link, a group header, or a sub-item.
     *
     * $row array{label, href, icon, active, open, group, badge, level, reachable}
     */
    $badge   = $row['badge'] ?? null;
    $isSub   = ($row['level'] ?? 'item') === 'sub';
    $isGroup = ($row['group'] ?? null) !== null;

    /* A row inside a shut group is on the page but out of the tab order. */
    $reachable = $row['reachable'] ?? true;
@endphp

{{--
    One @class, not a class attribute beside one.

    @class emits a class attribute of its own, so writing both put two of
    them on the element - and a parser keeps the first and drops the second.
    Every is-active and is-open the sidebar computed was being thrown away by
    the browser, which is why the current page highlighted nothing. The state
    was right the whole time; only aria-current survived to show it.
--}}
<a @class([
       'dk-side__row',
       'dk-side__row--sub' => $isSub,
       'dk-side__row--item' => ! $isSub,
       'is-active' => $row['active'],
       'is-open' => $row['open'] ?? false,
   ])
   href="{{ $row['href'] }}"
   @if($isGroup)
       data-dk-side-group="{{ $row['group'] }}"
       aria-expanded="{{ ($row['open'] ?? false) ? 'true' : 'false' }}"
   @endif
   @if($row['active']) aria-current="page" @endif
   @unless($reachable) tabindex="-1" @endunless
   title="{{ $row['label'] }}">

    @if($row['active'] && ! $isSub)
        <span class="dk-side__marker" aria-hidden="true"></span>
    @endif

    @if($isSub)
        {{--
            The tree's branch and leaf are pseudo-elements of the row. This
            is the anchor bar on the row's end edge, which grows on the
            current child; it needs an element because the row already
            spends both pseudo-elements on the branch and the leaf.
        --}}
        <span class="dk-side__anchor" aria-hidden="true"></span>
    @else
        <span class="dk-side__icon" aria-hidden="true">
            <x-icon name="{{ $row['icon'] }}" width="20" height="20"/>
        </span>
    @endif

    <span class="dk-side__label">{{ $row['label'] }}</span>

    @if($badge)
        {{--
            dir="ltr" so a count never reverses in an RTL panel. The collapsed
            rail repeats the badge as a pip, because at 72px there is no room
            for the number but there is still something waiting.
        --}}
        <span class="dk-side__badge dk-side__badge--{{ $badge['tone'] }}"
              dir="ltr"
              title="{{ $badge['title'] }}">{{ $badge['display'] }}</span>
        @unless($isSub)
            <span class="dk-side__pip dk-side__pip--{{ $badge['tone'] }}" aria-hidden="true"></span>
        @endunless
    @endif

    @if($isGroup)
        <span class="dk-side__chevron" aria-hidden="true">
            <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </span>
    @endif
</a>
