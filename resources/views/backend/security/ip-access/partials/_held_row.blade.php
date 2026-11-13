@php
    /*
     * An address the guard refused to block.
     *
     * It passed the threshold, and several accounts sign in from it, so
     * blocking would have taken the others down with the one. There is no
     * `ip_blocks` row behind this — it is the block that was not written —
     * which is exactly why it has to be on the screen: a safeguard nobody
     * can see is indistinguishable from one that is not working.
     */
    $strikes  = (int) $watch['total'];
    $accounts = (int) $watch['accounts'];

    $segments = 8;
    $filled   = min($segments, (int) ceil($segments * min(1, $strikes / max(1, $threshold))));
@endphp

<div class="ipa-row ipa-row--held">
    <div class="ipa-ip">
        <span class="ipa-ip__addr" dir="ltr">{{ $watch['ip'] }}</span>
        <span class="ipa-chip">
            <x-icon name="user-group" width="10" height="10"/>{{ __('Shared') }}
        </span>
    </div>

    <div class="ipa-why">
        <a href="{{ route('admin.ip-access.panel', ['ip' => $watch['ip']]) }}"
           class="ipa-why__title"
           data-ipa-open="{{ $watch['ip'] }}">{{ __('Past the threshold, deliberately not blocked') }}</a>
        <span class="ipa-why__detail">
            {{ trans_choice('{1} :count account signed in from here in 24h|[2,*] :count different accounts signed in from here in 24h', $accounts, ['count' => $accounts]) }}
            — {{ __('the guard leaves this one to you') }}
        </span>
    </div>

    <div>
        <span class="ipa-meter ipa-meter--held">
            <span class="ipa-meter__bars" aria-hidden="true">
                @for($i = 0; $i < $segments; $i++)
                    <span class="ipa-meter__seg {{ $i < $filled ? 'is-on' : '' }}"></span>
                @endfor
            </span>
            <span class="ipa-meter__count" dir="ltr">{{ $strikes }}/{{ $threshold }}</span>
        </span>
    </div>

    <div class="ipa-when">
        <span class="ipa-when__at">{{ __('Not blocked') }}</span>
        <span class="ipa-when__rel">{{ __('Still counting') }}</span>
    </div>

    <div>
        <button type="button"
                class="ipa-btn"
                data-ipa-open="{{ $watch['ip'] }}">{{ __('Review and decide') }}</button>
    </div>

    <div>
        <button type="button"
                class="ipa-act"
                data-ipa-open="{{ $watch['ip'] }}"
                aria-label="{{ __('Open :ip', ['ip' => $watch['ip']]) }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.7"></circle><circle cx="12" cy="12" r="1.7"></circle><circle cx="19" cy="12" r="1.7"></circle></svg>
        </button>
    </div>
</div>
