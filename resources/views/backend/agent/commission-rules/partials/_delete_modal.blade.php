@php
    use App\Enums\AgentCommissionRuleType;

    /*
     * The shared confirm in backend/partials/_delete_modal.blade.php asks "Are
     * you sure?" and names nothing — from a table of five near-identical rules
     * it cannot tell you which row is about to go, let alone what breaks. This
     * one names the rule, restates what it does, and says where the counters
     * earning under it will land instead.
     *
     * It deliberately does not use the global `.delete` + data-url handler:
     * that handler exists to point one shared form at a URL, and this dialog
     * carries its own form precisely so it can carry the rule's own facts.
     */
    $assignedCount = (int) $rule->assignments_count;

    $impact = match (true) {
        $assignedCount > 0             => [
            'tone' => 'warning',
            'lead' => trans_choice('{1} :count counter earns under this rule.|[2,*] :count counters earn under this rule.', $assignedCount, ['count' => $assignedCount]),
            'text' => __('Once it is gone, a matching operation falls through to the next rule in the order — or to each agent’s own fallback rate if nothing else matches.'),
        ],
        (bool) $rule->applies_globally => [
            'tone' => 'warning',
            'lead' => __('This is a global fallback.'),
            'text' => __('It applies to every counter without being assigned, so deleting it changes what all of them earn.'),
        ],
        default                        => [
            'tone' => 'muted',
            'lead' => __('No counter is assigned this rule.'),
            'text' => __('Deleting it changes nothing that is earning today.'),
        ],
    };
@endphp

<div class="modal fade" id="commission-rule-delete-{{ $rule->id }}" tabindex="-1"
     aria-labelledby="commission-rule-delete-label-{{ $rule->id }}" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <form action="{{ route('admin.agent.commission-rules.destroy', $rule) }}" method="POST">
                @csrf
                @method('DELETE')

                <div class="modal-body ag-confirm">
                    <span class="ag-confirm__icon" aria-hidden="true">
                        <x-icon name="delete-3" height="24" width="24"/>
                    </span>

                    <h5 class="ag-confirm__title" id="commission-rule-delete-label-{{ $rule->id }}">{{ __('Delete this commission rule?') }}</h5>
                    <p class="ag-confirm__lede">{{ __('The rule is removed for every counter assigned to it. Operations already settled keep the commission they earned — this only changes what happens next.') }}</p>

                    <div class="ag-confirm__subject">
                        <div class="ag-confirm__subject-head">
                            <span class="ag-prio{{ $rule->priority >= 100 ? ' ag-prio--broad' : '' }}" aria-hidden="true">{{ $rule->priority }}</span>
                            <strong class="ag-confirm__name">{{ $rule->name }}</strong>
                            <span class="ag-pill ag-pill--{{ $rule->status ? 'success' : 'secondary' }}">
                                <span class="ag-pill__dot" aria-hidden="true"></span>{{ $rule->status ? __('Active') : __('Inactive') }}
                            </span>
                        </div>
                        <dl class="ag-facts ag-facts--confirm">
                            <dt>{{ __('Applies to') }}</dt>
                            <dd>
                                {{ $operationTypes[$rule->operation_type] ?? title($rule->operation_type) }}
                                &middot;
                                @if($rule->currency?->code)
                                    <span dir="ltr">{{ $rule->currency->code }}</span>
                                @else
                                    {{ __('Any currency') }}
                                @endif
                            </dd>
                            <dt>{{ __('Amount range') }}</dt>
                            <dd>
                                {{ money($rule->min_amount, null, false) }}
                                &ndash;
                                {{ $rule->max_amount ? money($rule->max_amount, null, false) : __('No max') }}
                            </dd>
                            <dt>{{ __('Commission') }}</dt>
                            <dd>
                                @if($rule->calculation_type === AgentCommissionRuleType::PERCENTAGE)
                                    {{ feeRate($rule->percentage_rate) }}%
                                @else
                                    {{ money($rule->fixed_amount, null, false) }} {{ __('fixed') }}
                                @endif
                            </dd>
                        </dl>
                    </div>

                    <div class="ag-banner ag-banner--{{ $impact['tone'] }}" role="note">
                        <i class="fa-solid {{ $impact['tone'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-info' }} flex-shrink-0 mt-1" aria-hidden="true"></i>
                        <div>
                            <strong class="ag-banner__title">{{ $impact['lead'] }}</strong>
                            <span class="ag-banner__text">{{ $impact['text'] }}</span>
                        </div>
                    </div>

                    <p class="ag-confirm__final">{{ __('This cannot be undone.') }}</p>
                </div>

                <div class="modal-footer ag-confirm__footer">
                    <button type="button" class="btn btn-light" data-coreui-dismiss="modal">{{ __('Keep the rule') }}</button>
                    <button type="submit" class="btn btn-danger text-white d-inline-flex align-items-center gap-2">
                        <x-icon name="delete-3" height="16" width="16"/>{{ __('Delete rule') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
