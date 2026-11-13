@php
    use Illuminate\Support\Str;

    $dkHealth    = $dashboard['health'];
    $dkAttention = $dkHealth['attention'];
@endphp

<section class="dk-card dk-health" aria-label="{{ __('Platform health') }}" data-dk-health>
    <div class="dk-card__head">
        <div class="dk-card__titles">
            <h2 class="dk-card__title">{{ __('Platform health') }}</h2>
            <span class="dk-card__sub" data-dk-health-summary>{{ $dkAttention === 0
                ? __('Everything is running normally')
                : __(':count of :total checks need a look', ['count' => $dkAttention, 'total' => $dkHealth['total']]) }}</span>
        </div>
        <span class="dk-status-pill @if($dkAttention > 0) dk-status-pill--warn @endif" data-dk-health-pill>{{ $dkAttention === 0 ? __('All good') : __('Attention') }}</span>
    </div>

    @if($dkHealth['rows'] === [])
        <div class="dk-empty">
            <span class="dk-empty__icon" aria-hidden="true"><i class="fa-solid fa-server"></i></span>
            <div class="dk-empty__title">{{ __('No checks reporting yet') }}</div>
            <div class="dk-empty__body">{{ __('The scheduler, the queue worker and each configured gateway report in here once they run.') }}</div>
        </div>
    @else
        <div class="dk-health__rows" data-dk-health-rows>
            @foreach($dkHealth['rows'] as $dkRow)
                @php
                    $dkFull = $dkRow['name'].' · '.$dkRow['note'].' · '.$dkRow['value'];

                    /*
                     * A row is only a link for an administrator the screen it
                     * points at would let in. Without an href the anchor is
                     * not focusable and not clickable, so the status stays
                     * readable without offering a click that would 403.
                     */
                    $dkHref = auth('admin')->user()?->can($dkRow['can']) ? $dkRow['href'] : null;
                @endphp
                <a @if($dkHref !== null) href="{{ $dkHref }}" @endif
                   class="dk-health__row dk-health__row--{{ $dkRow['state'] }}"
                   data-health="{{ $dkRow['key'] }}"
                   title="{{ $dkFull }}">
                    <span class="dk-health__dot" aria-hidden="true"></span>
                    <span class="dk-health__body">
                        <span class="dk-health__name">{{ $dkRow['name'] }}</span>
                        <span class="dk-health__note">{{ $dkRow['note'] }}</span>
                    </span>
                    {{--
                        The chip is one line and 132px wide; a longer value is
                        clipped here rather than allowed to reflow the row, and
                        the whole string stays available on the row's title.

                        `auto`, not `ltr`. The chip was pinned LTR for the money
                        values, which must never be reordered — "$12,480.55 ·
                        24h" reads one way in every language. But the same pin
                        applied to a translated value put the number on the
                        wrong end of it: "4 في الانتظار" laid out with the 4
                        leftmost, so an Arabic reader met it last and read it as
                        "في الانتظار 4". `auto` takes its direction from the
                        first strong character, which leaves every Latin and
                        numeric value byte-identical — measured per glyph
                        against `ltr` for the money, percent, "set up cron" and
                        "Ran :ago" chips — and gives an Arabic value the
                        direction it is written in.
                    --}}
                    <span class="dk-health__value @if($dkRow['mono']) is-mono @endif" dir="auto">{{ Str::limit($dkRow['value'], 17, '…') }}</span>
                </a>
            @endforeach
        </div>
    @endif

    <div class="dk-card__foot">
        <span class="dk-card__status">
            <span class="dk-pulse" aria-hidden="true"></span><span data-dk-health-age
                  data-just="{{ __('Auto-checks every 60s · just now') }}"
                  data-ago="{{ __('Auto-checks every 60s · last :elapsed ago') }}">{{ __('Auto-checks every 60s · just now') }}</span>
        </span>
        <span class="dk-card__actions">
            @can('background-task-list')
                <a href="{{ route('admin.background-tasks.index') }}" class="dk-btn dk-btn--outline"><i class="fa-solid fa-gear" aria-hidden="true"></i>{{ __('Background jobs') }}</a>
            @endcan
            @can('payment-gateway-list')
                <a href="{{ route('admin.payment.gateway.index') }}" class="dk-btn dk-btn--outline"><i class="fa-solid fa-credit-card" aria-hidden="true"></i>{{ __('Gateways') }}</a>
            @endcan
        </span>
    </div>
</section>
