@php use App\Enums\AgentStatus; use Illuminate\Support\Str; @endphp
@extends('backend.layouts.app')
@section('title', $title)

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/agent-admin.css?v='.config('app.version').'-'.filemtime(public_path('backend/css/agent-admin.css'))) }}">
@endpush

@section('content')
    @php
        /*
         * The queue is not the list with a filter on it. Rows are oldest first,
         * the wait is a column of its own, and what the operator says they do —
         * the thing the decision is actually made on — is on the row instead of
         * behind a modal nobody opens for the ones they mean to reject.
         */
        $canManage   = auth('admin')->user()?->can('agent-manage') ?? false;
        $columnCount = $canManage ? 5 : 4;

        $tabs = [
            ['key' => 'all', 'label' => __('All'), 'url' => route('admin.agent.index'), 'count' => $counts['all']],
            ['key' => AgentStatus::PENDING->value, 'label' => __('Pending'), 'url' => route('admin.agent.pending'), 'count' => $counts[AgentStatus::PENDING->value], 'warn' => true],
            ['key' => AgentStatus::APPROVED->value, 'label' => __('Approved'), 'url' => route('admin.agent.approved'), 'count' => $counts[AgentStatus::APPROVED->value]],
            ['key' => AgentStatus::DISABLED->value, 'label' => __('Disabled'), 'url' => route('admin.agent.index', ['status' => AgentStatus::DISABLED->value]), 'count' => $counts[AgentStatus::DISABLED->value]],
            ['key' => AgentStatus::REJECTED->value, 'label' => __('Rejected'), 'url' => route('admin.agent.rejected'), 'count' => $counts[AgentStatus::REJECTED->value]],
        ];

        $headerStats = [
            [
                'label' => __('Waiting now'),
                'value' => number_format($queue['waiting']),
                'tone'  => $queue['waiting'] > 0 ? 'warning' : 'success',
                'note'  => __('requests with no decision'),
                'icon'  => 'kpi-review-stack',
            ],
            [
                'label' => __('Over :hours hours', ['hours' => $slaHours]),
                'value' => number_format($queue['breaching']),
                'tone'  => $queue['breaching'] > 0 ? 'danger' : 'success',
                'note'  => __('past the review target'),
                'icon'  => 'warning-2',
            ],
            [
                'label' => __('Oldest request'),
                'value' => $queue['oldest_wait'] ?? '—',
                'tone'  => 'neutral',
                'note'  => __('time the first one has waited'),
                'icon'  => 'clock',
            ],
            [
                'label' => __('No description'),
                'value' => number_format($queue['no_description']),
                'tone'  => $queue['no_description'] > 0 ? 'warning' : 'neutral',
                'note'  => __('ask the operator before deciding'),
                'icon'  => 'info',
            ],
        ];
    @endphp

    <x-page-header
        :title="$title"
        icon="sidebar-agent"
        :eyebrow="__('Business profiles')"
        :subtitle="__('Requests waiting on a decision, oldest first. The clock starts the moment the operator submits.')"
        :status="$queue['waiting'] > 0
            ? ['tone' => 'warning', 'label' => __(':count waiting', ['count' => $queue['waiting']])]
            : ['tone' => 'success', 'label' => __('Queue clear')]"
        :stats="$headerStats"
    >
        <x-slot:actions>
            <a href="{{ route('admin.agent.index') }}" class="ph-btn ph-btn--secondary">
                <i class="fa-solid fa-users" aria-hidden="true"></i>{{ __('All agents') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    <nav class="ph-tabs" aria-label="{{ __('Agent status') }}">
        @foreach($tabs as $tab)
            <a href="{{ $tab['url'] }}"
               class="ph-tab"
               @if($scope === $tab['key']) aria-current="page" @endif>
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
                    <caption class="visually-hidden">{{ __('Agent requests awaiting a decision, oldest first') }}</caption>
                    <thead class="table-light fw-semibold text-nowrap">
                    <tr class="align-middle">
                        <th scope="col">{{ __('Agent') }}</th>
                        <th scope="col">{{ __('Operator') }}</th>
                        <th scope="col">{{ __('What they say they do') }}</th>
                        <th scope="col">{{ __('Waiting') }}</th>
                        @if($canManage)
                            <th scope="col">{{ __('Decision') }}</th>
                        @endif
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($agents as $agent)
                        @php
                            $description = trim((string) ($agent->description ?? ''));
                            $waitedHours = $agent->created_at->diffInHours();

                            /*
                             * Three tones against one target: comfortable, nearing it,
                             * past it. The chip always spells the wait out as well, so
                             * the colour is never the only thing carrying the meaning.
                             */
                            $waitTone = match (true) {
                                $waitedHours >= $slaHours     => 'late',
                                $waitedHours >= $slaHours / 2 => 'warn',
                                default                       => 'ok',
                            };

                            $currencyCount = max($agent->supportedCurrencies->count(), 1);
                        @endphp
                        <tr class="align-top ag-row--pending">
                            <td>
                                <div class="d-flex align-items-start gap-2">
                                    <img class="ag-logo" width="40" height="40"
                                         src="{{ asset($agent->logo) }}" alt="" loading="lazy">
                                    <div class="ag-cell">
                                        <div class="ag-name">{{ $agent->agent_name }}</div>
                                        <div class="ag-meta">
                                            <span class="ag-code" dir="ltr">{{ $agent->agent_code }}</span>
                                            <span class="ag-chip">{{ trans_choice('{1} :count currency|[2,*] :count currencies', $currencyCount, ['count' => $currencyCount]) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <a href="{{ route('admin.user.manage', $agent->user->username) }}" class="ag-owner">{{ $agent->user->name }}</a>
                                <div class="ag-muted ag-break">{{ $agent->user->email ?: $agent->user->phone }}</div>
                                <div class="ag-muted">{{ $agent->user->country ?: __('Country not given') }}</div>
                            </td>
                            <td>
                                @if($description === '')
                                    <p class="ag-rate--unset mb-0">{{ __('No description provided.') }}</p>
                                    <span class="ag-chip ag-chip--warning">{{ __('Ask the operator before deciding') }}</span>
                                @else
                                    <p class="ag-said mb-0">{{ Str::limit($description, 200) }}</p>
                                @endif
                            </td>
                            <td>
                                <span class="ag-wait-chip ag-wait-chip--{{ $waitTone }}">
                                    <span class="ag-pill__dot" aria-hidden="true"></span>{{ $agent->created_at->diffForHumans(null, true) }}
                                </span>
                                <div class="ag-muted mt-1 text-nowrap">{{ __('since :when', ['when' => $agent->created_at->format('Y-m-d H:i')]) }}</div>
                            </td>
                            @if($canManage)
                                <td>
                                    <button
                                        type="button"
                                        class="btn btn-primary d-inline-flex align-items-center gap-2 text-nowrap"
                                        data-coreui-toggle="modal"
                                        data-coreui-target="#agent-review-{{ $agent->id }}"
                                        title="{{ __('Review request') }}" aria-label="{{ __('Review request') }}">
                                        <i class="fa-solid fa-clipboard-check"></i>
                                        <span class="d-none d-sm-inline">{{ __('Review request') }}</span>
                                    </button>

                                    @include('backend.agent.partials._review_modal')
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $columnCount }}">
                                <x-admin-not-found
                                    :title="__('Nothing waiting')"
                                    :message="__('Every agent request has been decided.')"
                                    icon="fa-clipboard-check"
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
