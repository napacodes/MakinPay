@php
    use App\Services\Dashboard\SparklinePath;

    /**
     * Five counters, each with the last seven days drawn beside it. A card
     * whose series is too short to be a trend renders without one — see
     * SparklinePath::build().
     */
    $dkCounterIcon = [
        'customers'    => 'fa-users',
        'kyc'          => 'fa-id-card',
        'merchants'    => 'fa-store',
        'transactions' => 'fa-receipt',
        'agents'       => 'fa-user-tie',
    ];

    $dkArrow = [
        'up'   => 'fa-arrow-up',
        'down' => 'fa-arrow-down',
        // A flat reading is a rule through the middle, not an arrow that
        // implies it went somewhere.
        'flat' => 'fa-minus',
    ];
@endphp

<section class="dk-counters" aria-label="{{ __('Platform at a glance') }}">
    @foreach($dashboard['counters'] as $dkCounter)
        @php($dkLine = SparklinePath::build($dkCounter['spark']))
        <a href="{{ $dkCounter['link'] }}"
           class="dk-counter dk-counter--{{ $dkCounter['tone'] }}"
           title="{{ $dkCounter['tip'] }}">
            <span class="dk-counter__glow" aria-hidden="true"></span>

            <span class="dk-counter__top">
                <span class="dk-counter__labels">
                    <span class="dk-counter__eyebrow">{{ $dkCounter['label'] }}</span>
                    <span class="dk-counter__sub">{{ $dkCounter['sub'] }}</span>
                </span>
                <span class="dk-counter__tile" aria-hidden="true">
                    <i class="fa-solid {{ $dkCounterIcon[$dkCounter['key']] ?? 'fa-circle' }}"></i>
                </span>
            </span>

            <span class="dk-counter__figure">
                <span class="dk-counter__value" dir="ltr">{{ $dkCounter['value'] }}</span>
                @if($dkCounter['delta'] !== null)
                    <span class="dk-counter__delta dk-counter__delta--{{ $dkCounter['delta']['direction'] }}" dir="ltr">
                        <i class="fa-solid {{ $dkArrow[$dkCounter['delta']['direction']] ?? 'fa-minus' }}" aria-hidden="true"></i>{{ $dkCounter['delta']['text'] }}
                    </span>
                @endif
            </span>

            <span class="dk-counter__foot">
                <span class="dk-counter__note">{{ $dkCounter['note'] }}</span>
                @if($dkLine !== null)
                    <svg class="dk-spark" viewBox="0 0 80 28" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                        <path class="dk-spark__area" d="{{ $dkLine['area'] }}"></path>
                        <polyline class="dk-spark__line" points="{{ $dkLine['points'] }}" vector-effect="non-scaling-stroke"></polyline>
                        <circle class="dk-spark__tip" cx="80" cy="{{ $dkLine['last'] }}" r="2.6"></circle>
                    </svg>
                @endif
            </span>
        </a>
    @endforeach
</section>
