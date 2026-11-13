@php
    /**
     * Money in and out over the chosen range.
     *
     * The bars are laid out with CSS grid and sized as percentages of the
     * tallest column, and the fee line is one polyline in a 100x100 viewBox
     * stretched over them. No charting library: this is four numbers and up to
     * thirty columns, and a runtime that redraws them in canvas would cost more
     * than the panel it draws.
     */
    $dkRange = $dashboard['range'];
    $dkMoney = $dashboard['money'];
    $dkChart = $dashboard['chart'];
@endphp

<section class="dk-card dk-money" aria-label="{{ __('Money in and out') }}" data-dk-money>
    <div class="dk-card__head dk-card__head--tight">
        <div class="dk-card__titles">
            <h2 class="dk-card__title">{{ __('Money in & out') }}</h2>
            <span class="dk-card__sub" data-dk-range-label>{{ $dkRange->label() }} · {{ __('completed transactions') }} · {{ __(':code equivalent', ['code' => $dashboard['base']]) }}</span>
        </div>

        <div class="dk-seg" role="group" aria-label="{{ __('Date range') }}" data-dk-range>
            @foreach($dkRange->presets() as $dkPreset)
                <button type="button"
                        class="dk-seg__btn @if($dkPreset['active']) is-active @endif"
                        data-range-key="{{ $dkPreset['key'] }}"
                        aria-pressed="{{ $dkPreset['active'] ? 'true' : 'false' }}">@if($dkPreset['key'] === 'custom')<i class="fa-solid fa-calendar-days" aria-hidden="true"></i>@endif{{ $dkPreset['label'] }}</button>
            @endforeach
            <input type="hidden" data-dk-range-start value="{{ $dkRange->start->toDateString() }}">
            <input type="hidden" data-dk-range-end value="{{ $dkRange->end->toDateString() }}">
        </div>
    </div>

    @if($dkMoney !== null)
        <div class="dk-kpis" data-dk-kpis>
            @foreach($dkMoney['rows'] as $dkKpi)
                <div class="dk-kpi dk-kpi--{{ $dkKpi['key'] }}" title="{{ $dkKpi['tip'] }}">
                    <span class="dk-kpi__label"><span class="dk-kpi__swatch" aria-hidden="true"></span>{{ $dkKpi['label'] }}</span>
                    <span class="dk-kpi__value" dir="ltr" title="{{ $dkKpi['full'] }}">{{ $dkKpi['display'] }}</span>
                    <span class="dk-kpi__delta">
                        @if($dkKpi['delta'] !== null)
                            <span class="dk-kpi__change dk-kpi__change--{{ $dkKpi['delta']['direction'] }}" dir="ltr">{{ $dkKpi['delta']['text'] }}</span>
                            {{ __('vs previous :span', ['span' => $dkRange->shortLabel()]) }}
                        @else
                            {{ __('no earlier :span to compare', ['span' => $dkRange->shortLabel()]) }}
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    @endif

    @if($dkChart !== null)
        @if(! $dkChart['has_data'])
            <div class="dk-empty dk-empty--chart">
                <span class="dk-empty__icon" aria-hidden="true"><i class="fa-solid fa-chart-line"></i></span>
                <div class="dk-empty__title">{{ __('No completed transactions in this range') }}</div>
                <div class="dk-empty__body">{{ __('Deposits, withdrawals and fee revenue chart here as soon as money moves.') }}</div>
            </div>
        @else
            <div class="dk-plot" data-dk-plot style="--dk-cols: {{ count($dkChart['buckets']) }}">
                <div class="dk-plot__frame">
                    <div class="dk-plot__axis" aria-hidden="true">
                        @foreach($dkChart['y_ticks'] as $dkTick)
                            <span style="top: {{ $dkTick['top'] }}">{{ $dkTick['label'] }}</span>
                        @endforeach
                    </div>

                    <div class="dk-plot__bars">
                        @foreach($dkChart['y_ticks'] as $dkTick)
                            <span class="dk-plot__grid" aria-hidden="true" style="top: {{ $dkTick['top'] }}"></span>
                        @endforeach

                        @foreach($dkChart['buckets'] as $dkBucket)
                            <div class="dk-plot__col" title="{{ $dkBucket['long'] }} · {{ $dkBucket['tip'] }}">
                                <span class="dk-plot__bar dk-plot__bar--in" style="height: {{ $dkBucket['in_pct'] }}%"></span>
                                <span class="dk-plot__bar dk-plot__bar--out" style="height: {{ $dkBucket['out_pct'] }}%"></span>
                            </div>
                        @endforeach

                        <svg class="dk-plot__fees" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                            <polyline points="{{ $dkChart['fee_points'] }}" vector-effect="non-scaling-stroke"></polyline>
                        </svg>
                    </div>
                </div>

                <div class="dk-plot__labels" aria-hidden="true">
                    @foreach($dkChart['buckets'] as $dkBucket)
                        <span>{{ $dkBucket['label'] }}</span>
                    @endforeach
                </div>
            </div>
        @endif
    @endif

    <div class="dk-card__foot dk-card__foot--legend">
        <span class="dk-legend">
            <span class="dk-legend__item"><span class="dk-legend__swatch dk-legend__swatch--in" aria-hidden="true"></span>{{ __('Money in') }}</span>
            <span class="dk-legend__item"><span class="dk-legend__swatch dk-legend__swatch--out" aria-hidden="true"></span>{{ __('Money out') }}</span>
            <span class="dk-legend__item"><span class="dk-legend__swatch dk-legend__swatch--fee" aria-hidden="true"></span>{{ __('Fee revenue') }}</span>
        </span>

        <span class="dk-money__totals">
            @if($dkMoney !== null)
                {{ __('Net flow') }} <strong class="dk-money__net @if(! $dkMoney['net']['positive']) is-negative @endif" dir="ltr">{{ $dkMoney['net']['display'] }}</strong>
            @endif
            @if($dkChart !== null && $dkChart['busiest'] !== null)
                · {{ __('Busiest day') }} <strong class="dk-money__peak">{{ $dkChart['busiest'] }}</strong>
            @endif
        </span>
    </div>

    <div class="dk-veil" data-dk-veil hidden>
        <span class="dk-veil__pill"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>{{ __('Loading…') }}</span>
    </div>
</section>
