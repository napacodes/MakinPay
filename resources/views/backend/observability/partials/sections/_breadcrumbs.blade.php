@php
    /* Capped in the controller, so one budget covers every list on this tab. */
    $crumbs = $breadcrumbs;
@endphp

@if($crumbs === [])
    <x-admin-not-found
        :title="__('No breadcrumbs were recorded.')"
        :message="__('Breadcrumbs are collected during the request that failed, so a background failure often has none.')"
        icon="fa-shoe-prints"
    />
@else
    <ol class="obs-timeline">
        @foreach($crumbs as $crumb)
            <li class="obs-timeline__item">
                <span class="obs-timeline__time">{{ \Illuminate\Support\Carbon::parse($crumb['at'])->format('H:i:s') }}</span>
                <span class="obs-timeline__dot" aria-hidden="true"></span>
                <span>
                    <span class="obs-code-chip">{{ $crumb['kind'] }}</span>
                    <span class="obs-error-class d-inline">{{ $crumb['label'] }}</span>
                    @if(($crumb['kind'] ?? '') === 'log' && ! empty($crumb['data']))
                        <span class="obs-timeline__data">
                            @foreach($crumb['data'] as $key => $value)
                                <span>{{ $key }}: {{ is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : $value) : json_encode($value) }}</span>
                            @endforeach
                        </span>
                    @endif
                </span>
            </li>
        @endforeach
    </ol>
@endif
