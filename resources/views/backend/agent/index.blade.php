@php use App\Enums\AgentStatus; @endphp
@extends('backend.layouts.app')
@section('title', $title)

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/agent-admin.css?v='.config('app.version').'-'.filemtime(public_path('backend/css/agent-admin.css'))) }}">
@endpush

@section('content')
    @php
        /*
         * The Disabled slice has no route of its own. AgentStatus::DISABLED is
         * already a supported value of the existing ?status filter, so the tab
         * points at the list with that filter rather than asking for a fifth
         * endpoint — and the slice stops being unreachable, which it was from
         * everywhere before this.
         */
        $requestedStatus = request('status');
        $activeScope     = $scope !== 'all'
            ? $scope
            : (in_array($requestedStatus, AgentStatus::all(), true) ? $requestedStatus : 'all');

        /*
         * One expression for the permission, used by the header cell and the
         * empty-state colspan alike. They were derived separately before, so a
         * reader without agent-manage got a row spanning one column more than
         * the table had.
         */
        $canManage   = auth('admin')->user()?->can('agent-manage') ?? false;
        $columnCount = $canManage ? 7 : 6;

        $tabs = [
            ['key' => 'all', 'label' => __('All'), 'url' => route('admin.agent.index'), 'count' => $counts['all']],
            ['key' => AgentStatus::PENDING->value, 'label' => __('Pending'), 'url' => route('admin.agent.pending'), 'count' => $counts[AgentStatus::PENDING->value], 'warn' => true],
            ['key' => AgentStatus::APPROVED->value, 'label' => __('Approved'), 'url' => route('admin.agent.approved'), 'count' => $counts[AgentStatus::APPROVED->value]],
            ['key' => AgentStatus::DISABLED->value, 'label' => __('Disabled'), 'url' => route('admin.agent.index', ['status' => AgentStatus::DISABLED->value]), 'count' => $counts[AgentStatus::DISABLED->value]],
            ['key' => AgentStatus::REJECTED->value, 'label' => __('Rejected'), 'url' => route('admin.agent.rejected'), 'count' => $counts[AgentStatus::REJECTED->value]],
        ];

        /*
         * Counts and rates, never a network-wide total. Counters take cash in
         * whatever currencies they support, so one headline volume would be a
         * sum of unlike amounts wearing a single currency code.
         */
        $headerStats = [
            [
                'label' => __('Pending review'),
                'value' => number_format($stats['pending']),
                'delta' => $stats['oldest_wait'],
                'tone'  => $stats['pending'] > 0 ? 'warning' : 'success',
                'note'  => $stats['pending'] > 0 ? __('longest wait in the queue') : __('nothing waiting'),
                'icon'  => 'kpi-review-stack',
            ],
            [
                'label' => __('Live counters'),
                'value' => number_format($stats['live']),
                'tone'  => 'success',
                'note'  => __(':percent% of all agents', ['percent' => $stats['live_share']]),
                'icon'  => 'kpi-user-active',
            ],
            [
                'label' => __('Operations, 30 days'),
                'value' => number_format($stats['operations']),
                'tone'  => 'brand',
                'note'  => __('completed cash-in and cash-out'),
                'icon'  => 'exchange-money',
            ],
            [
                'label' => __('Average fallback rate'),
                'value' => $stats['avg_commission'] !== null ? feeRate($stats['avg_commission']).'%' : '—',
                'tone'  => 'neutral',
                'note'  => __('live counters, before rules apply'),
                'icon'  => 'kpi-fees',
            ],
        ];
    @endphp

    <x-page-header
        :title="$title"
        icon="sidebar-agent"
        :eyebrow="__('Business profiles')"
        :subtitle="__('Every cash-in and cash-out counter on the network, and the commission each one earns.')"
        :status="$stats['pending'] > 0
            ? ['tone' => 'warning', 'label' => __(':count awaiting review', ['count' => $stats['pending']])]
            : ['tone' => 'success', 'label' => __('Queue clear')]"
        :stats="$headerStats"
    >
        @can('agent-commission-rules-manage')
            <x-slot:actions>
                <a href="{{ route('admin.agent.commission-rules.index') }}" class="ph-btn ph-btn--secondary">
                    <i class="fa-solid fa-percent" aria-hidden="true"></i>{{ __('Commission Rules') }}
                </a>
            </x-slot:actions>
        @endcan
    </x-page-header>

    <nav class="ph-tabs" aria-label="{{ __('Agent status') }}">
        @foreach($tabs as $tab)
            <a href="{{ $tab['url'] }}"
               class="ph-tab"
               @if($activeScope === $tab['key']) aria-current="page" @endif>
                {{ $tab['label'] }}
                <span class="ph-tab__count{{ ($tab['warn'] ?? false) && $tab['count'] > 0 ? ' ph-tab__count--warning' : '' }}">{{ number_format($tab['count']) }}</span>
            </a>
        @endforeach
    </nav>

    <div class="card border-0 mb-4">
        <div class="card-body">

            @include('backend.agent.partials._filter')

            <div class="table-responsive">
                <table class="table caption-top mb-0">
                    <caption class="visually-hidden">{{ __('Agent business profiles') }}</caption>
                    <thead class="table-light fw-semibold text-nowrap">
                    <tr class="align-middle">
                        <th scope="col">{{ __('Agent') }}</th>
                        <th scope="col">{{ __('Operator') }}</th>
                        <th scope="col">{{ __('Commission') }}</th>
                        <th scope="col">{{ __('Volume moved') }}</th>
                        <th scope="col">{{ __('Status') }}</th>
                        <th scope="col">{{ __('Requested') }}</th>
                        @if($canManage)
                            <th scope="col">{{ __('Action') }}</th>
                        @endif
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($agents as $agent)
                        @php
                            $isPending = $agent->status === AgentStatus::PENDING;
                            $rateIsSet = (float) $agent->commission > 0;
                            $ruleCount = $agent->commissionRuleAssignments->count();

                            $baseCode        = $agent->currency?->code;
                            $extraCurrencies = $agent->supportedCurrencies
                                ->pluck('code')
                                ->reject(fn ($code): bool => $code === $baseCode)
                                ->count();

                            /*
                             * A share of operation counts, not of summed amounts: the
                             * two halves have to be comparable, and amounts taken in
                             * different currencies are not.
                             */
                            $cashIn   = (int) ($agent->cash_in_count ?? 0);
                            $cashOut  = (int) ($agent->cash_out_count ?? 0);
                            $opsTotal = $cashIn + $cashOut;
                            $inShare  = $opsTotal > 0 ? (int) round($cashIn / $opsTotal * 100) : 0;
                        @endphp
                        <tr class="align-middle{{ $isPending ? ' ag-row--pending' : '' }}">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img class="ag-logo" width="38" height="38"
                                         src="{{ asset($agent->logo) }}" alt="" loading="lazy">
                                    <div class="ag-cell">
                                        <div class="ag-name">{{ $agent->agent_name }}</div>
                                        <div class="ag-meta">
                                            <span class="ag-code" dir="ltr">{{ $agent->agent_code }}</span>
                                            @if($baseCode)
                                                <span class="ag-chip ag-chip--base" dir="ltr">{{ $baseCode }}</span>
                                            @endif
                                            @if($extraCurrencies > 0)
                                                <span class="ag-chip" dir="ltr">+{{ $extraCurrencies }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <a href="{{ route('admin.user.manage', $agent->user->username) }}" class="ag-owner">{{ $agent->user->name }}</a>
                                <div class="ag-muted ag-break">{{ '@'.$agent->user->username }}</div>
                            </td>
                            <td>
                                @if($isPending && ! $rateIsSet)
                                    <span class="ag-rate--unset">{{ __('Not set — decided on approval') }}</span>
                                @else
                                    <div class="ag-rate">{{ feeRate($agent->commission) }}%</div>
                                    <div class="ag-meta">
                                        <span class="ag-chip">{{ trans_choice('{0} no rules|{1} :count rule|[2,*] :count rules', $ruleCount, ['count' => $ruleCount]) }}</span>
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if($opsTotal > 0)
                                    <div class="ag-figure">{{ money($agent->completed_volume ?? 0, $baseCode) }}</div>
                                    <div class="ag-split">
                                        <span class="ag-split__bar" aria-hidden="true">
                                            <span class="ag-split__in" style="width: {{ $inShare }}%"></span>
                                            <span class="ag-split__out"></span>
                                        </span>
                                        <span class="ag-split__label">{{ __('in :in / out :out', ['in' => $cashIn, 'out' => $cashOut]) }}</span>
                                    </div>
                                    <div class="ag-figure__sub">{{ __('earned :amount', ['amount' => money($agent->earned_commission ?? 0, $baseCode)]) }}</div>
                                @else
                                    <span class="ag-muted">{{ __('No operations yet') }}</span>
                                @endif
                            </td>
                            <td class="text-nowrap">
                                <span class="ag-pill ag-pill--{{ $agent->status->color() }}">
                                    <span class="ag-pill__dot" aria-hidden="true"></span>{{ $agent->status->label() }}
                                </span>
                            </td>
                            <td>
                                <div class="ag-date">{{ $agent->created_at->format('Y-m-d') }}</div>
                                <div class="{{ $isPending ? 'ag-wait' : 'ag-muted' }}">
                                    @if($isPending)
                                        {{ __('waiting :age', ['age' => $agent->created_at->diffForHumans(null, true)]) }}
                                    @else
                                        {{ $agent->created_at->diffForHumans() }}
                                    @endif
                                </div>
                            </td>
                            @if($canManage)
                                <td>
                                    @php
                                        $btnText = match ($agent->status) {
                                            AgentStatus::PENDING  => __('Review'),
                                            AgentStatus::REJECTED => __('View'),
                                            default               => __('Manage'),
                                        };
                                        $btnIcon = $isPending ? 'fa-clipboard-check' : 'fa-gear';
                                    @endphp
                                    <button
                                        type="button"
                                        class="btn {{ $isPending ? 'btn-primary' : 'btn-outline-secondary' }} d-inline-flex align-items-center gap-2 text-nowrap"
                                        data-coreui-toggle="modal"
                                        data-coreui-target="#agent-review-{{ $agent->id }}"
                                        title="{{ $btnText }}" aria-label="{{ $btnText }}">
                                        <i class="fa-solid {{ $btnIcon }}"></i>
                                        <span class="d-none d-sm-inline">{{ $btnText }}</span>
                                    </button>

                                    @include('backend.agent.partials._review_modal')
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $columnCount }}">
                                <x-admin-not-found
                                    :title="__('No agents found')"
                                    :message="__('No agent requests or profiles match the current filters.')"
                                    icon="fa-user-tie"
                                />
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-end mt-3">
                {{ $agents->links() }}
            </div>

        </div>
    </div>
@endsection
