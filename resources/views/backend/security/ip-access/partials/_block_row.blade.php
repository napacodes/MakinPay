@php
    /*
     * One deny-list row. Three states share it, and telling them apart is
     * the whole reading: refusing now, lapsed on its own, or permanent
     * because an administrator said so.
     */
    $lapsed    = $block->isExpired();
    $automatic = $block->isAutomatic();
    $strikes   = (int) $block->strikes;
    $window    = $block->windowMinutes();
    $left      = $block->minutesRemaining();

    $segments  = 8;
    $filled    = $strikes > 0 ? min($segments, (int) ceil($segments * min(1, $strikes / max(1, $threshold)))) : 0;
    $meterTone = $lapsed ? 'past' : 'over';

    /*
     * How much of the window has already run. The bar fills as the block
     * ages, so a long block that has just started and a short one about to
     * lapse never look the same.
     */
    $elapsed = $window !== null && $left !== null
        ? max(0, min(100, (int) round((($window - $left) / max(1, $window)) * 100)))
        : 0;

    /*
     * The detail line used to restate the strike count, which the reason
     * already carries — "Automatic: repeated failed sign-ins (15 strikes)"
     * above "15 strikes counted". It names the signal that did most of the
     * damage instead, which is the one fact the row was not already telling.
     */
    $signalNames = [
        'login_lockout'   => __('Mostly the sign-in limiter'),
        'throttled'       => __('Mostly rate limiting'),
        'forbidden'       => __('Mostly pages it may not have'),
        'unauthenticated' => __('Mostly unauthenticated calls'),
        'csrf'            => __('Mostly missing CSRF tokens'),
    ];

    $dominant = collect($block->signals ?? [])
        ->map(fn ($count, $key) => (int) $count * (\App\Services\Security\IpAbuseGuard::SIGNAL_WEIGHTS[$key] ?? 1))
        ->sortDesc()
        ->keys()
        ->first();
@endphp

<div class="ipa-row {{ $lapsed ? 'ipa-row--lapsed' : '' }}">
    <div class="ipa-ip">
        <span class="ipa-ip__addr" dir="ltr">{{ $block->ip_address }}</span>
        @if($automatic)
            <span class="ipa-chip ipa-chip--auto">
                <x-icon name="barrier-block" width="10" height="10"/>{{ __('Automatic') }}
            </span>
        @else
            <span class="ipa-chip ipa-chip--manual">
                <x-icon name="users" width="10" height="10"/>{{ __('By admin') }}
            </span>
        @endif
    </div>

    <div class="ipa-why">
        <a href="{{ route('admin.ip-access.panel', ['ip' => $block->ip_address]) }}"
           class="ipa-why__title"
           data-ipa-open="{{ $block->ip_address }}">{{ $block->reason ?: __('No reason recorded') }}</a>
        <span class="ipa-why__detail">
            @if($automatic && $dominant !== null)
                {{ $signalNames[$dominant] ?? __('Mixed refusals') }}
            @elseif($automatic)
                {{ __('Refusals counted across the window') }}
            @else
                {{ __('Added by hand, never rewritten by the guard') }}
            @endif
        </span>
    </div>

    <div>
        @if($strikes > 0)
            <span class="ipa-meter ipa-meter--{{ $meterTone }}">
                <span class="ipa-meter__bars" aria-hidden="true">
                    @for($i = 0; $i < $segments; $i++)
                        <span class="ipa-meter__seg {{ $i < $filled ? 'is-on' : '' }}"></span>
                    @endfor
                </span>
                <span class="ipa-meter__count" dir="ltr">{{ $strikes }}/{{ $threshold }}</span>
            </span>
        @else
            <span class="ipa-none">—</span>
        @endif
    </div>

    {{--
        <bdi> around the date and the relative time.

        Measured in Arabic: "19 Sep, 17:37" came out as "Sep, 17:37 19" and
        "12 minutes ago" as "minutes ago 12". A date is one run whichever way
        the page reads, and the bidi algorithm only knows that if it is told.
    --}}
    <div class="ipa-when">
        <span class="ipa-when__at"><bdi>{{ $block->blocked_at?->format('d M, H:i') }}</bdi></span>
        <span class="ipa-when__rel"><bdi>{{ $block->blocked_at?->diffForHumans() }}</bdi></span>
    </div>

    <div>
        @if($block->expires_at === null)
            <span class="ipa-pill">
                <x-icon name="lock" width="12" height="12"/>{{ __('Until you remove it') }}
            </span>
        @elseif($lapsed)
            <span class="ipa-pill ipa-pill--lapsed">
                <x-icon name="check" width="12" height="12"/>{{ __('Lapsed') }} <bdi>{{ $block->expires_at->format('H:i') }}</bdi>
            </span>
        @else
            {{--
                One run, one unit. "47 minutes left of 60 minutes" says
                "minutes" twice and wrapped mid-phrase in Arabic; the ratio
                carries the same fact in a quarter of the width, and inside
                a single <bdi> it cannot be reordered.
            --}}
            <span class="ipa-expiry">
                <span class="ipa-expiry__line">
                    <span class="ipa-expiry__left"><bdi>{{ $left }} / {{ $window }}</bdi></span>
                    {{ trans_choice('{1} minute left|[2,*] minutes left', $window) }}
                </span>
                <span class="ipa-track" aria-hidden="true">
                    <span class="ipa-track__fill" style="inline-size: {{ $elapsed }}%"></span>
                </span>
            </span>
        @endif
    </div>

    <div>
        <button type="button"
                class="ipa-act"
                data-ipa-open="{{ $block->ip_address }}"
                aria-label="{{ __('Open :ip', ['ip' => $block->ip_address]) }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.7"></circle><circle cx="12" cy="12" r="1.7"></circle><circle cx="19" cy="12" r="1.7"></circle></svg>
        </button>
    </div>
</div>
