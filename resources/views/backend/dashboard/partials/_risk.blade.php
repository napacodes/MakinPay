@php
    use App\Services\Dashboard\RiskSignalService;

    $dkRisk   = $dashboard['risk'];
    $dkCounts = $dkRisk['counts'];

    $dkLevels = [
        RiskSignalService::LEVEL_HIGH   => __('High'),
        RiskSignalService::LEVEL_MEDIUM => __('Medium'),
        RiskSignalService::LEVEL_LOW    => __('Low'),
    ];

    $dkSignalIcon = [
        'pass_through_payout' => 'fa-circle-up',
        'failed_logins'       => 'fa-lock',
        'implausible_travel'  => 'fa-triangle-exclamation',
        'duplicate_document'  => 'fa-id-card',
        'rate_divergence'     => 'fa-rotate',
        'refund_spike'        => 'fa-store',
    ];
@endphp

<section class="dk-card dk-risk" aria-label="{{ __('Risk and fraud watch') }}">
    <div class="dk-card__head">
        <div class="dk-card__titles">
            <h2 class="dk-card__title">
                <span class="dk-card__tile dk-card__tile--danger" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>{{ __('Risk & fraud watch') }}
            </h2>
            <span class="dk-card__sub">{{ __('Last 24 hours · signals that need a human look') }}</span>
        </div>
        <span class="dk-status-pill dk-status-pill--danger" data-dk-risk-pill>
            <span class="dk-status-pill__dot" aria-hidden="true"></span><span data-dk-risk-count>{{ $dkCounts['total'] }}</span> {{ __('flagged') }}
        </span>
    </div>

    <div class="dk-risk__levels">
        @foreach($dkLevels as $dkLevelKey => $dkLevelLabel)
            <div class="dk-risk__level dk-risk__level--{{ $dkLevelKey }}">
                <span class="dk-risk__count" dir="ltr">{{ $dkCounts[$dkLevelKey] }}</span>
                <span class="dk-risk__level-label">{{ $dkLevelLabel }}</span>
            </div>
        @endforeach
    </div>

    @if($dkRisk['signals'] === [])
        <div class="dk-empty">
            <span class="dk-empty__icon" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
            <div class="dk-empty__title">{{ __('Nothing flagged today') }}</div>
            <div class="dk-empty__body">{{ __('Fast pass-through payouts, accounts being guessed at, sign-ins from several countries, one ID document across accounts, blocked rates and refund spikes are listed here as they happen.') }}</div>
        </div>
    @else
        <div class="dk-risk__rows">
            @foreach($dkRisk['signals'] as $dkSignal)
                <a href="{{ $dkSignal['link'] }}" class="dk-risk__row dk-risk__row--{{ $dkSignal['level'] }}">
                    <span class="dk-risk__tile" aria-hidden="true"><i class="fa-solid {{ $dkSignalIcon[$dkSignal['key']] ?? 'fa-triangle-exclamation' }}"></i></span>
                    <span class="dk-risk__body">
                        <span class="dk-risk__head">
                            <span class="dk-risk__title">{{ $dkSignal['title'] }}</span>
                            <span class="dk-risk__tag">{{ $dkLevels[$dkSignal['level']] ?? $dkSignal['level'] }}</span>
                        </span>
                        <span class="dk-risk__detail">{{ $dkSignal['detail'] }}</span>
                        {{--
                            Guidance, not data: it says why a correct signal is
                            firing on a site whose normal state produces it. Set
                            apart from the detail line so it does not read as
                            more evidence for the flag.
                        --}}
                        @if(($dkSignal['note'] ?? null) !== null)
                            <span class="dk-risk__note">{{ $dkSignal['note'] }}</span>
                        @endif
                        <span class="dk-risk__meta">
                            <span class="dk-risk__who">
                                <code class="dk-risk__subject" dir="ltr">{{ $dkSignal['subject'] }}</code>
                                <span class="dk-risk__when">{{ $dkSignal['at']->diffForHumans() }}</span>
                            </span>
                            <span class="dk-risk__action">{{ $dkSignal['action'] }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                        </span>
                    </span>
                </a>
            @endforeach
        </div>
    @endif

    <div class="dk-card__foot">
        <span>{{ __('Auto-blocked today') }} · <strong>{{ trans_choice('{0}no IPs|{1}:count IP|[2,*]:count IPs', $dkRisk['blocked']['ips'], ['count' => $dkRisk['blocked']['ips']]) }}</strong>
            · <strong>{{ trans_choice('{0}no accounts|{1}:count account|[2,*]:count accounts', $dkRisk['blocked']['accounts'], ['count' => $dkRisk['blocked']['accounts']]) }}</strong></span>

        @if($dkCounts['total'] > count($dkRisk['signals']))
            <span class="dk-card__actions">
                <a href="{{ route('admin.activity-log') }}" class="dk-btn dk-btn--outline">{{ __('All :count signals', ['count' => $dkCounts['total']]) }}<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
            </span>
        @endif
    </div>
</section>
