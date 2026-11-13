@php
    /**
     * The page's own header. Search, notifications, language and the avatar
     * deliberately stay in the admin top bar — this page owns only its content.
     *
     * The clock is rendered in the site timezone, and "Refreshed" is rewritten
     * by the live poll rather than by a reload.
     */
    $dkNow = now()->timezone($dashboard['timezone']);
@endphp

<div class="dk-head">
    <div class="dk-head__identity">
        <div class="dk-head__line">
            <h1 class="dk-head__title">{{ __('Overview') }}</h1>
            <span class="dk-live-pill">
                <span class="dk-live-pill__dot" aria-hidden="true"></span>{{ __('Live · refreshes every 60s') }}
            </span>
        </div>
        <div class="dk-head__meta">
            <span>{{ $dkNow->isoFormat('ddd, D MMM YYYY') }}</span>
            <span class="dk-head__sep" aria-hidden="true">·</span>
            <span dir="ltr">{{ $dkNow->format('H:i') }} <span class="dk-head__tz">{{ $dashboard['timezone'] }}</span></span>
            <span class="dk-head__sep" aria-hidden="true">·</span>
            <span data-dk-refreshed
                  data-refreshed-at="{{ $dashboard['refreshed_at'] }}"
                  data-just="{{ __('Refreshed just now') }}"
                  data-ago="{{ __('Refreshed :elapsed ago') }}">{{ __('Refreshed just now') }}</span>
        </div>
    </div>

    <button type="button" class="dk-btn dk-btn--outline" data-dk-refresh>
        <i class="fa-solid fa-rotate" aria-hidden="true"></i>{{ __('Refresh now') }}
    </button>
</div>
