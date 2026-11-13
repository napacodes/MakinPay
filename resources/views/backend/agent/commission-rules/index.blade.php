@php use App\Enums\AgentCommissionRuleType; @endphp
@extends('backend.layouts.app')
@section('title', $title)

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/agent-admin.css?v='.config('app.version').'-'.filemtime(public_path('backend/css/agent-admin.css'))) }}">
@endpush

@section('content')
    @php
        $headerStats = [
            [
                'label' => __('Active rules'),
                'value' => number_format($ruleStats['active']),
                'tone'  => 'success',
                'note'  => __('of :count created', ['count' => number_format($ruleStats['total'])]),
                'icon'  => 'check',
            ],
            [
                'label' => __('Global fallbacks'),
                'value' => number_format($ruleStats['global']),
                'tone'  => 'brand',
                'note'  => __('apply without assignment'),
                'icon'  => 'currency',
            ],
            [
                'label' => __('Counters covered'),
                'value' => number_format($ruleStats['assigned_agents']),
                'tone'  => 'neutral',
                'note'  => __('agents with at least one rule'),
                'icon'  => 'kpi-user-active',
            ],
            [
                'label' => __('Never matched'),
                'value' => number_format($ruleStats['unused']),
                'tone'  => $ruleStats['unused'] > 0 ? 'warning' : 'success',
                'note'  => __('active, assigned to nobody'),
                'icon'  => 'warning-2',
            ],
        ];
    @endphp

    <x-page-header
        :title="$title"
        icon="fee"
        :eyebrow="__('Business profiles')"
        :subtitle="__('Reusable rates for cash-in and cash-out, scoped by operation, amount range and currency. Assign them to a counter while reviewing it.')"
        :status="$ruleStats['active'] > 0
            ? ['tone' => 'success', 'label' => __(':count active', ['count' => $ruleStats['active']])]
            : ['tone' => 'warning', 'label' => __('None active')]"
        :stats="$headerStats"
    >
        <x-slot:actions>
            <a href="{{ route('admin.agent.index') }}" class="ph-btn ph-btn--secondary">
                <i class="fa-solid fa-users" aria-hidden="true"></i>{{ __('Agents') }}
            </a>
            <button type="button" class="ph-btn ph-btn--primary" data-coreui-toggle="modal" data-coreui-target="#commission-rule-create">
                <i class="fa-solid fa-plus" aria-hidden="true"></i>{{ __('New Rule') }}
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="card border-0 mb-4">
        <div class="card-body">

            {{--
                Which rule wins was two sentences of prose nobody read. Drawn
                once, it is the thing people point at when they disagree.
            --}}
            <div class="ag-order">
                <span class="ag-order__legend">{{ __('Matching order') }}</span>
                <div class="ag-order__steps">
                    <span class="ag-order__step"><span class="ag-order__num" aria-hidden="true">1</span>{{ __('Currency and operation') }}</span>
                    <x-icon name="angle-down" class="ag-order__arrow" width="14" height="14"/>
                    <span class="ag-order__step"><span class="ag-order__num" aria-hidden="true">2</span>{{ __('Amount range') }}</span>
                    <x-icon name="angle-down" class="ag-order__arrow" width="14" height="14"/>
                    <span class="ag-order__step"><span class="ag-order__num" aria-hidden="true">3</span>{{ __('Lowest priority number') }}</span>
                    <x-icon name="angle-down" class="ag-order__arrow" width="14" height="14"/>
                    <span class="ag-order__step ag-order__step--last">{{ __("The agent's fallback rate") }}</span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table caption-top align-middle mb-0">
                    <caption class="visually-hidden">{{ __('Reusable agent commission rules') }}</caption>
                    <thead class="table-light fw-semibold text-nowrap">
                    <tr>
                        <th scope="col">{{ __('Rule') }}</th>
                        <th scope="col">{{ __('Applies to') }}</th>
                        <th scope="col">{{ __('Amount range') }}</th>
                        <th scope="col">{{ __('Commission') }}</th>
                        <th scope="col">{{ __('Where it applies') }}</th>
                        <th scope="col" class="text-end">{{ __('Action') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($rules as $rule)
                        @php
                            $isActive = (bool) $rule->status;

                            /*
                             * A rule that is active, is not a global fallback and has no
                             * assignment can never fire. Nothing said so before, so one
                             * could sit in the table for months looking healthy.
                             */
                            $isStranded = $isActive && ! $rule->applies_globally && $rule->assignments_count === 0;

                            $min     = (float) $rule->min_amount;
                            $max     = $rule->max_amount !== null ? (float) $rule->max_amount : null;
                            $ceiling = $rangeCeiling > 0 ? $rangeCeiling : max($min, 1);

                            $lead = min(100, ($min / $ceiling) * 100);
                            $span = $max !== null
                                ? max(2, min(100 - $lead, (($max - $min) / $ceiling) * 100))
                                : 100 - $lead;
                        @endphp
                        <tr class="{{ $isStranded ? 'ag-row--pending' : '' }}">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="ag-prio{{ $rule->priority >= 100 ? ' ag-prio--broad' : '' }}" aria-hidden="true">{{ $rule->priority }}</span>
                                    <div class="ag-cell">
                                        <div class="ag-name text-break">{{ $rule->name }}</div>
                                        @if($isStranded)
                                            <div class="ag-wait">{{ __('assigned to nobody — cannot match') }}</div>
                                        @else
                                            <div class="ag-muted">{{ __('priority :value', ['value' => $rule->priority]) }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="ag-chip{{ $rule->operation_type === 'all' ? '' : ' ag-chip--base' }}">
                                    {{ $operationTypes[$rule->operation_type] ?? title($rule->operation_type) }}
                                </span>
                                <div class="ag-muted mt-1">
                                    @if($rule->currency?->code)
                                        <span dir="ltr">{{ $rule->currency->code }}</span>
                                    @else
                                        {{ __('Any currency') }}
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="ag-range">
                                    {{ money($rule->min_amount, null, false) }}
                                    &ndash;
                                    {{ $rule->max_amount ? money($rule->max_amount, null, false) : __('No max') }}
                                </div>
                                <span class="ag-range__bar" aria-hidden="true">
                                    <span class="ag-range__lead" style="width: {{ round($lead, 2) }}%"></span>
                                    <span class="ag-range__fill{{ $isActive ? '' : ' ag-range__fill--muted' }}" style="width: {{ round($span, 2) }}%"></span>
                                </span>
                            </td>
                            <td>
                                @if($rule->calculation_type === AgentCommissionRuleType::PERCENTAGE)
                                    <div class="ag-rate">{{ feeRate($rule->percentage_rate) }}%</div>
                                @else
                                    <div class="ag-rate">{{ money($rule->fixed_amount, null, false) }}</div>
                                @endif
                                <div class="ag-muted">{{ $rule->calculation_type->label() }}</div>
                            </td>
                            <td>
                                <span class="ag-pill ag-pill--{{ $isActive ? 'success' : 'secondary' }}">
                                    <span class="ag-pill__dot" aria-hidden="true"></span>{{ $isActive ? __('Active') : __('Inactive') }}
                                </span>
                                <div class="ag-muted mt-1">
                                    {{ $rule->applies_globally ? __('Global fallback') : __('Assignment only') }}
                                    &middot;
                                    {{ trans_choice('{0} no counters|{1} :count counter|[2,*] :count counters', $rule->assignments_count, ['count' => $rule->assignments_count]) }}
                                </div>
                            </td>
                            <td class="text-end">
                                <div class="ag-rule-actions">
                                    <button type="button" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2"
                                            data-coreui-toggle="modal" data-coreui-target="#commission-rule-{{ $rule->id }}">
                                        <x-icon name="manage" height="16" width="16"/>
                                        <span class="d-none d-sm-inline">{{ __('Edit') }}</span>
                                    </button>
                                    {{--
                                        Not the shared `.delete` + data-url confirm: that
                                        dialog can only ask "Are you sure?", and from five
                                        near-identical rules it never said which one, nor
                                        what the counters earning under it fall back to.
                                    --}}
                                    <button type="button" class="btn btn-outline-danger btn-sm d-inline-flex align-items-center"
                                            data-coreui-toggle="modal" data-coreui-target="#commission-rule-delete-{{ $rule->id }}"
                                            title="{{ __('Delete') }}" aria-label="{{ __('Delete :rule', ['rule' => $rule->name]) }}">
                                        <x-icon name="delete-3" height="16" width="16"/>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-3">
                                <x-admin-not-found
                                    :title="__('No commission rules found')"
                                    :message="__('Create a reusable rule to control cash-in and cash-out commission by operation, amount range, or currency.')"
                                    icon="fa-percent"
                                />
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-end mt-3">
                {{ $rules->links() }}
            </div>
        </div>
    </div>

    <div class="modal fade" id="commission-rule-create" tabindex="-1" aria-labelledby="commission-rule-create-label" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl">
            <div class="modal-content border-0">
                <div class="modal-header ag-review__header">
                    <div class="ag-review__identity">
                        <span class="ag-review__eyebrow">{{ __('Reusable commission template') }}</span>
                        <h5 class="modal-title ag-review__title" id="commission-rule-create-label">{{ __('New Commission Rule') }}</h5>
                    </div>
                    <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <form action="{{ route('admin.agent.commission-rules.store') }}" method="POST" class="ag-modal-form">
                    @csrf
                    <div class="modal-body">
                        <p class="ag-setup__hint mb-3">{{ __('Set the operation, scope, amount range, and commission type in one clean workflow.') }}</p>
                        <div class="row g-3">
                            @include('backend.agent.commission-rules.partials._form', ['rule' => null])
                        </div>
                    </div>
                    <div class="modal-footer ag-review__footer">
                        <p class="ag-review__consequence mb-0">{{ __('A new rule applies from the next operation onward, never to ones already settled.') }}</p>
                        <div class="ag-review__actions">
                            <button type="button" class="btn btn-light" data-coreui-dismiss="modal">{{ __('Close') }}</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-plus me-1" aria-hidden="true"></i>{{ __('Create Rule') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @foreach($rules as $rule)
        <div class="modal fade" id="commission-rule-{{ $rule->id }}" tabindex="-1" aria-labelledby="commission-rule-label-{{ $rule->id }}" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl">
                <div class="modal-content border-0">
                    <div class="modal-header ag-review__header">
                        <div class="ag-review__identity">
                            <span class="ag-review__eyebrow">{{ __('Rule management') }}</span>
                            <div class="ag-review__titleline">
                                <h5 class="modal-title ag-review__title text-truncate" id="commission-rule-label-{{ $rule->id }}">{{ $rule->name }}</h5>
                                <span class="ag-pill ag-pill--{{ $rule->status ? 'success' : 'secondary' }}">
                                    <span class="ag-pill__dot" aria-hidden="true"></span>{{ $rule->status ? __('Active') : __('Inactive') }}
                                </span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <form action="{{ route('admin.agent.commission-rules.update', $rule) }}" method="POST" class="ag-modal-form">
                        @csrf
                        @method('PUT')
                        <div class="modal-body">
                            <div class="row g-3">
                                @include('backend.agent.commission-rules.partials._form', ['rule' => $rule])
                            </div>
                        </div>
                        <div class="modal-footer ag-review__footer">
                            <p class="ag-review__consequence mb-0">
                                {{ trans_choice('{0} No counter is assigned this rule yet.|{1} :count counter earns under this rule.|[2,*] :count counters earn under this rule.', $rule->assignments_count, ['count' => $rule->assignments_count]) }}
                            </p>
                            <div class="ag-review__actions">
                                <button type="button" class="btn btn-light" data-coreui-dismiss="modal">{{ __('Close') }}</button>
                                <button type="submit" class="btn btn-primary">{{ __('Save Changes') }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- A sibling of the edit modal, never nested inside it. --}}
        @include('backend.agent.commission-rules.partials._delete_modal')
    @endforeach
@endsection
