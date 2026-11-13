{{--
    A biller's logo, or its category's icon on a tinted tile when it has none.
    Expects $logo (?string path), $icon (?string category icon key) and
    optionally $size ('sm' | 'md' | 'lg').
--}}
@php
    $bpLogoIcons = (array) config('bill_payments.category_icons', []);
    $bpLogoIcon = in_array($icon ?? null, $bpLogoIcons, true) ? $icon : 'bill-other';
    $bpLogoSize = in_array($size ?? 'md', ['sm', 'md', 'lg'], true) ? ($size ?? 'md') : 'md';
@endphp
@if(! empty($logo))
    <span class="bp-logo bp-logo--{{ $bpLogoSize }}">
        <img src="{{ asset($logo) }}" alt="" loading="lazy">
    </span>
@else
    <span class="bp-logo bp-logo--{{ $bpLogoSize }} bp-logo--icon" aria-hidden="true">
        <x-icon :name="$bpLogoIcon" class="bp-logo__svg" />
    </span>
@endif
