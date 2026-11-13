@php
    use App\Services\Dashboard\DashboardQueueService;
    use App\Services\Dashboard\DashboardSla;

    $dkQueues  = collect($dashboard['queues']);
    $dkSummary = $dashboard['queue_summary'];

    /**
     * Two groups, not one list.
     *
     * The panel is read for triage, and a queue with nothing in it answers no
     * triage question — so the rows that need a decision are listed and the
     * rest fold into a single line. The service already sorts the empty ones
     * last, so partitioning here keeps whichever order the request asked for.
     */
    $dkActive = $dkQueues->where('count', '>', 0)->values();
    $dkQuiet  = $dkQueues->where('count', 0)->values();

    /**
     * The glyph and the footer's short word for each queue come from the same
     * map the rows are titled from, so this panel and the modal behind its
     * "Edit limits" button cannot call one queue two different things.
     */
    $dkNaming = DashboardQueueService::naming();

    $dkTargets = collect($dashboard['sla']['targets'])->only($dkQueues->pluck('key')->all());

    /*
     * "2h – 48h" rather than eight figures. The list itself is one click away;
     * the line above it only has to say how wide the spread is.
     */
    $dkLimitsRange = $dkTargets->isEmpty()
        ? null
        : ($dkTargets->min().'h – '.$dkTargets->max().'h');
@endphp

<section class="dk-card dk-queues" aria-label="{{ __('Needs your action') }}">
    @if($dashboard['fresh_install'])
        @include('backend.dashboard.partials._setup_checklist')
    @else
        <div class="dk-queues__head">
            <div class="dk-queues__titles">
                <div class="dk-queues__title-row">
                    <h2 class="dk-queues__h">{{ __('Needs your action') }}</h2>

                    {{--
                        The breach count belongs beside the title rather than
                        inside the sentence below it: it is the one number that
                        decides whether this panel is read now or later.
                    --}}
                    <span class="dk-pill dk-pill--late"
                          data-dk-queue-breach {{ $dkSummary['breaches'] === 0 ? 'hidden' : '' }}>
                        <span class="dk-pill__dot" aria-hidden="true"></span><span data-dk-queue-breach-count>{{ number_format($dkSummary['breaches']) }}</span> {{ __('overdue') }}
                    </span>

                    {{--
                        The handoff puts a "Live · auto-updates" pill here too.
                        It was drawn from the panel on its own; on the page the
                        panel actually sits on, the header says the same thing
                        forty pixels above it — and two green pills that close
                        together read as two separate live things rather than
                        as one page that refreshes.
                    --}}
                </div>

                {{--
                    Built by the controller, not here, so the first paint and
                    every poll afterwards word this sentence identically — and
                    so its plural forms and money formats are resolved in the
                    administrator's locale rather than assembled in a browser.
                --}}
                <p class="dk-queues__summary" data-dk-queue-summary>{!! $dkSummary['html'] !!}</p>
            </div>

            <div class="dk-queues__sorting">
                <span class="dk-queues__sort-label" id="dkQueueSortLabel">{{ __('Sort by') }}</span>

                <div class="dk-seg dk-seg--queues" role="group" aria-labelledby="dkQueueSortLabel" data-dk-queue-sort>
                    @foreach(['sla' => __('Most overdue'), 'count' => __('Most pending'), 'value' => __('Highest amount')] as $dkSortKey => $dkSortLabel)
                        <button type="button"
                                class="dk-seg__btn @if($dashboard['queue_sort'] === $dkSortKey) is-active @endif"
                                data-sort="{{ $dkSortKey }}"
                                aria-pressed="{{ $dashboard['queue_sort'] === $dkSortKey ? 'true' : 'false' }}">{{ $dkSortLabel }}</button>
                    @endforeach
                </div>
            </div>
        </div>

        @if($dkQueues->isEmpty())
            <div class="dk-empty">
                <span class="dk-empty__icon" aria-hidden="true"><i class="fa-solid fa-list-check"></i></span>
                <div class="dk-empty__title">{{ __('No review queues on this site') }}</div>
                <div class="dk-empty__body">{{ __('Manual deposits, KYC, merchants and card requests appear here as soon as those modules are switched on.') }}</div>
            </div>
        @else
            <div class="dk-scroll">
                <div class="dk-queues__grid">
                    <div class="dk-queues__cols">
                        <span aria-hidden="true"></span>
                        <span>{{ __('Request type') }}</span>
                        <span class="dk-t-end">{{ __('Pending') }}</span>
                        <span class="dk-t-end">{{ __('Amount') }}</span>
                        <span>{{ __('Waiting since · Deadline') }}</span>
                        <span aria-hidden="true"></span>
                    </div>

                    <div class="dk-queues__rows" data-dk-queue-rows>
                        @foreach($dkActive as $dkQueue)
                            @php
                                $dkState = $dkQueue['state'];
                                $dkRatio = $dkQueue['sla_hours'] > 0 ? $dkQueue['elapsed_hours'] / $dkQueue['sla_hours'] : 0;

                                /*
                                 * Past a month the service stops giving a
                                 * duration and gives "since 12 Mar" instead,
                                 * which already carries the preposition — so
                                 * the column's own "Since" label would say it
                                 * twice. Same cutoff as DATE_AFTER_DAYS.
                                 */
                                $dkDatedWait = $dkQueue['elapsed_hours'] >= 30 * 24;
                            @endphp
                            <a href="{{ $dkQueue['link'] }}"
                               class="dk-queue dk-queue--{{ $dkState }}"
                               data-queue="{{ $dkQueue['key'] }}"
                               data-count="{{ $dkQueue['count'] }}"
                               data-value="{{ $dkQueue['value_base'] ?? 0 }}"
                               data-state="{{ $dkState }}"
                               data-ratio="{{ round($dkRatio, 4) }}"
                               aria-label="{{ __('Review :queue, :count pending', ['queue' => $dkQueue['title'], 'count' => number_format($dkQueue['count'])]) }}">
                                <span class="dk-queue__tile" aria-hidden="true"><i class="fa-solid {{ $dkQueue['icon'] }}"></i></span>

                                <span class="dk-queue__name">
                                    <span class="dk-queue__title">{{ $dkQueue['title'] }}</span>
                                    <span class="dk-queue__sub" title="{{ $dkQueue['subtitle'] }}">{{ $dkQueue['subtitle'] }}</span>
                                </span>

                                <span class="dk-queue__waiting dk-t-end">
                                    <span class="dk-queue__count" dir="ltr">{{ number_format($dkQueue['count']) }}</span>
                                    <span class="dk-queue__unit">{{ trans_choice('{1}request|[2,*]requests', $dkQueue['count']) }}</span>
                                </span>

                                <span class="dk-queue__amount dk-t-end">
                                    <span class="dk-queue__value @if($dkQueue['value_display'] === null) dk-queue__value--none @endif" dir="ltr" title="{{ $dkQueue['value_full'] ?? __('No money held in this queue') }}">{{ $dkQueue['value_display'] ?? '—' }}</span>
                                    <span class="dk-queue__note">{{ $dkQueue['value_display'] === null ? __('no amount') : __('on hold') }}</span>
                                </span>

                                <span class="dk-queue__sla">
                                    <span class="dk-queue__sla-line">
                                        <span class="dk-queue__since">
                                            @unless($dkDatedWait)<span class="dk-queue__since-label">{{ __('Since') }}</span>@endunless
                                            {{-- `auto`, not `ltr`: a duration
                                                 ("6h 12m") resolves to ltr on
                                                 its own, and past a month this
                                                 carries a translated date that
                                                 must not be forced either way. --}}
                                            <span class="dk-queue__age" dir="auto">{{ $dkQueue['oldest_label'] }}</span>
                                        </span>

                                        <span class="dk-queue__pill">
                                            <span class="dk-queue__dot" aria-hidden="true"></span>@switch($dkQueue['state'])
                                                @case(DashboardSla::STATE_OVER){{ __('Overdue · :hours limit', ['hours' => $dkQueue['sla_hours'].'h']) }}@break
                                                @case(DashboardSla::STATE_NEAR){{ __('Due soon · :hours limit', ['hours' => $dkQueue['sla_hours'].'h']) }}@break
                                                @default{{ __('On time · :hours limit', ['hours' => $dkQueue['sla_hours'].'h']) }}
                                            @endswitch
                                        </span>
                                    </span>

                                    <span class="dk-queue__track" aria-hidden="true"><span class="dk-queue__bar" style="width: {{ $dkQueue['sla_percent'] }}%"></span></span>
                                </span>

                                <span class="dk-queue__action">{{ __('Review') }}<i class="fa-solid fa-chevron-right dk-queue__chev" aria-hidden="true"></i></span>
                            </a>
                        @endforeach
                    </div>

                    @if($dkQuiet->isNotEmpty())
                        {{--
                            Nothing waiting, nothing to review — so these rows
                            collapse into the one fact that matters about them,
                            which is that they are clear. Opened, they keep the
                            grid, so the columns still line up with the rows
                            above.
                        --}}
                        <button type="button"
                                class="dk-quiet"
                                aria-expanded="false"
                                aria-controls="dkQuietQueues"
                                data-dk-queue-quiet
                                data-label-show="{{ __('Show') }}"
                                data-label-hide="{{ __('Hide') }}">
                            <span class="dk-quiet__left">
                                <span class="dk-quiet__badge">
                                    <span class="dk-quiet__disc" aria-hidden="true"><i class="fa-solid fa-check"></i></span>{{ trans_choice('{1}:count queue empty|[2,*]:count queues empty', $dkQuiet->count(), ['count' => number_format($dkQuiet->count())]) }}
                                </span>
                                <span class="dk-quiet__names">
                                    @foreach($dkQuiet as $dkQuietQueue)
                                        <span class="dk-quiet__name" data-queue-tint="{{ $dkQuietQueue['key'] }}">
                                            <i class="fa-solid {{ $dkQuietQueue['icon'] }}" aria-hidden="true"></i>{{ $dkQuietQueue['title'] }}
                                        </span>
                                    @endforeach
                                </span>
                            </span>
                            <span class="dk-quiet__right">
                                <span data-dk-queue-quiet-label>{{ __('Show') }}</span>
                                <span class="dk-quiet__chev" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
                            </span>
                        </button>

                        <div class="dk-quiet__rows" id="dkQuietQueues" hidden>
                            @foreach($dkQuiet as $dkQuietQueue)
                                <div class="dk-queue dk-queue--none" data-queue="{{ $dkQuietQueue['key'] }}">
                                    <span class="dk-queue__tile" aria-hidden="true"><i class="fa-solid {{ $dkQuietQueue['icon'] }}"></i></span>

                                    <span class="dk-queue__name">
                                        <span class="dk-queue__title">{{ $dkQuietQueue['title'] }}</span>
                                        <span class="dk-queue__sub" title="{{ $dkQuietQueue['subtitle'] }}">{{ $dkQuietQueue['subtitle'] }}</span>
                                    </span>

                                    <span class="dk-queue__waiting dk-t-end">
                                        <span class="dk-queue__count" dir="ltr">{{ number_format(0) }}</span>
                                    </span>

                                    <span class="dk-queue__amount dk-t-end">
                                        <span class="dk-queue__value" dir="ltr">—</span>
                                    </span>

                                    <span class="dk-queue__sla">
                                        <span class="dk-queue__pill">
                                            <span class="dk-queue__dot" aria-hidden="true"></span>{{ __('No requests · :hours limit', ['hours' => $dkQuietQueue['sla_hours'].'h']) }}
                                        </span>
                                    </span>

                                    <span class="dk-queue__action" aria-disabled="true">{{ __('Review') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif

        <div class="dk-limits">
            <div class="dk-limits__row">
                <button type="button"
                        class="dk-limits__summary"
                        aria-expanded="false"
                        aria-controls="dkQueueLimits"
                        data-dk-queue-limits>
                    <span class="dk-limits__tile" aria-hidden="true"><i class="fa-solid fa-clock"></i></span>
                    <span class="dk-limits__text">
                        <span class="dk-limits__title">{{ __('Response time limits') }}</span>
                        <span class="dk-limits__meta">{!! __(':range across :total queues', [
                            'range' => '<span class="dk-limits__range" dir="ltr">'.e($dkLimitsRange ?? '—').'</span>',
                            'total' => e(number_format($dkQueues->count())),
                        ]) !!}</span>
                    </span>
                </button>

                <div class="dk-limits__actions">
                    <button type="button"
                            class="dk-limits__toggle"
                            aria-expanded="false"
                            aria-controls="dkQueueLimits"
                            data-dk-queue-limits
                            data-label-show="{{ __('View all') }}"
                            data-label-hide="{{ __('Hide') }}">
                        <span data-dk-queue-limits-label>{{ __('View all') }}</span>
                        <i class="fa-solid fa-chevron-down dk-limits__chev" aria-hidden="true"></i>
                    </button>

                    @if($dashboard['can']['sla'])
                        <button type="button" class="dk-limits__edit" data-dk-sla-open>
                            <i class="fa-solid fa-sliders" aria-hidden="true"></i>{{ __('Edit limits') }}
                        </button>
                    @endif
                </div>
            </div>

            <div class="dk-limits__grid" id="dkQueueLimits" hidden>
                @foreach($dkTargets as $dkTargetKey => $dkTargetHours)
                    <span class="dk-limits__item" data-queue-tint="{{ $dkTargetKey }}">
                        <span class="dk-limits__name">
                            <i class="fa-solid {{ $dkNaming[$dkTargetKey]['icon'] ?? 'fa-circle' }}" aria-hidden="true"></i><span>{{ $dkNaming[$dkTargetKey]['short'] ?? $dkTargetKey }}</span>
                        </span>
                        <span class="dk-limits__hours" dir="ltr">{{ $dkTargetHours }}h</span>
                    </span>
                @endforeach
            </div>
        </div>
    @endif
</section>
