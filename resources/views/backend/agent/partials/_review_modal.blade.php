@php
    use App\Enums\AgentCommissionRuleType;
    use App\Enums\AgentOperationType;
    use App\Enums\AgentStatus;

    /*
     * Where the request stands, said once, before anything asks for an input —
     * the actions in the footer differ by status, so the banner has to explain
     * why the ones on screen are the ones on screen.
     */
    $banners = [
        AgentStatus::PENDING->value => [
            'tone'  => 'info',
            'icon'  => 'fa-hourglass-half',
            'title' => __('This request has not been decided yet'),
            'text'  => __('Set a fallback rate, tick the rules this counter should earn under, then approve. The operator is e-mailed either way.'),
        ],
        AgentStatus::APPROVED->value => [
            'tone'  => 'success',
            'icon'  => 'fa-circle-check',
            'title' => __('This counter is live'),
            'text'  => __('It can process cash-in and cash-out now. Changes to the setup apply to operations from here on, never to ones already settled.'),
        ],
        AgentStatus::DISABLED->value => [
            'tone'  => 'warning',
            'icon'  => 'fa-triangle-exclamation',
            'title' => __('This counter is disabled'),
            'text'  => __('It cannot take new operations until it is enabled again. Its history and commission setup are untouched.'),
        ],
        AgentStatus::REJECTED->value => [
            'tone'  => 'danger',
            'icon'  => 'fa-circle-xmark',
            'title' => __('This request was rejected'),
            'text'  => __('Nothing further can be decided here. The operator can submit a new request.'),
        ],
    ];

    $banner           = $banners[$agent->status->value] ?? $banners[AgentStatus::PENDING->value];
    $assignedRules    = $agent->commissionRuleAssignments->keyBy('agent_commission_rule_id');
    $availableRules   = $commissionRules ?? collect();
    $description      = trim((string) ($agent->description ?? ''));
    $operationOptions = ['all' => __('All Operations')] + AgentOperationType::options();
    $baseCode         = $agent->currency?->code;
    $isPending        = $agent->status === AgentStatus::PENDING;
    $isRejected       = $agent->status === AgentStatus::REJECTED;

    $cashIn   = (int) ($agent->cash_in_count ?? 0);
    $cashOut  = (int) ($agent->cash_out_count ?? 0);
    $opsTotal = $cashIn + $cashOut;
    $inShare  = $opsTotal > 0 ? (int) round($cashIn / $opsTotal * 100) : 0;

    /*
     * Only rejections taken after the column existed carry one. An older row
     * genuinely has no reason on record, so the panel says that rather than
     * drawing an empty quote.
     */
    $rejectionReason = trim((string) ($agent->rejection_reason ?? ''));

    /*
     * commission is cast decimal:4, so the stored rate reads "0.3500" in a box
     * the admin is about to edit and submit back. Casting through float drops
     * the trailing zeros without touching the value — never number_format to
     * two places, which would quietly round a rate like 0.3525 on the way in
     * and then save the rounded figure.
     */
    $commissionValue = old('commission', $agent->commission === null ? '' : (string) (float) $agent->commission);

    $selectedCount = $availableRules
        ->filter(fn ($rule): bool => (bool) old("commission_rules.{$rule->id}.enabled", $assignedRules->has($rule->id)))
        ->count();
@endphp

<div class="modal fade" id="agent-review-{{ $agent->id }}" tabindex="-1" aria-labelledby="agent-review-label-{{ $agent->id }}" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl">
        <div class="modal-content border-0">

            <div class="modal-header ag-review__header">
                <img src="{{ asset($agent->logo) }}" alt="" class="ag-logo" width="48" height="48" loading="lazy">
                <div class="ag-review__identity">
                    <span class="ag-review__eyebrow">{{ __('Agent review') }}</span>
                    <div class="ag-review__titleline">
                        <h5 class="modal-title ag-review__title text-truncate" id="agent-review-label-{{ $agent->id }}">{{ $agent->agent_name }}</h5>
                        <span class="ag-code" dir="ltr">{{ $agent->agent_code }}</span>
                        <span class="ag-pill ag-pill--{{ $agent->status->color() }}">
                            <span class="ag-pill__dot" aria-hidden="true"></span>{{ $agent->status->label() }}
                        </span>
                    </div>
                </div>
                <a href="{{ route('admin.user.manage', $agent->user->username) }}" target="_blank" rel="noopener"
                   class="btn btn-outline-secondary btn-sm d-none d-lg-inline-flex align-items-center gap-2 text-nowrap">
                    {{ __('Open operator profile') }}
                </a>
                <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>

            <form action="{{ route('admin.agent.request-action') }}" method="post" class="ag-modal-form">
                @csrf
                <input type="hidden" name="agent_id" value="{{ $agent->id }}">

                <div class="modal-body p-0">
                    <div class="ag-review__body">

                        {{-- The rail carries who is asking. --}}
                        <aside class="ag-review__rail">
                            <section class="ag-panel">
                                <h6 class="ag-panel__title">{{ __('Applicant') }}</h6>
                                <dl class="ag-facts">
                                    <dt>{{ __('Operator') }}</dt>
                                    <dd><a href="{{ route('admin.user.manage', $agent->user->username) }}" class="ag-owner" target="_blank" rel="noopener">{{ $agent->user->name }}</a></dd>
                                    <dt>{{ __('Username') }}</dt>
                                    <dd>{{ '@'.$agent->user->username }}</dd>
                                    <dt>{{ __('E-mail') }}</dt>
                                    <dd>{{ $agent->user->email ?: __('Not provided') }}</dd>
                                    <dt>{{ __('Phone') }}</dt>
                                    <dd dir="ltr">{{ $agent->user->phone ?: __('Not provided') }}</dd>
                                    <dt>{{ __('Country') }}</dt>
                                    <dd>{{ $agent->user->country ?: __('Not provided') }}</dd>
                                    <dt>{{ __('Requested') }}</dt>
                                    <dd>{{ $agent->created_at?->format('Y-m-d H:i') }}</dd>
                                </dl>
                            </section>

                            <section class="ag-panel">
                                <h6 class="ag-panel__title">{{ __('Currencies handled') }}</h6>
                                <div class="d-flex flex-wrap gap-1">
                                    @forelse($agent->supportedCurrencies as $currency)
                                        <span class="ag-chip{{ $currency->code === $baseCode ? ' ag-chip--base' : '' }}" dir="ltr">{{ $currency->code }}</span>
                                    @empty
                                        @if($baseCode)
                                            <span class="ag-chip ag-chip--base" dir="ltr">{{ $baseCode }}</span>
                                        @else
                                            <span class="ag-muted">{{ __('Not provided') }}</span>
                                        @endif
                                    @endforelse
                                </div>
                            </section>

                            @if($description !== '')
                                <section class="ag-panel">
                                    <h6 class="ag-panel__title">{{ __('What the operator wrote') }}</h6>
                                    <p class="ag-note">{!! nl2br(e($description)) !!}</p>
                                </section>
                            @endif

                            <section class="ag-panel">
                                <h6 class="ag-panel__title">{{ __('Track record') }}</h6>
                                <div class="ag-track">
                                    <div>
                                        <strong class="ag-track__value">{{ number_format($opsTotal) }}</strong>
                                        <span class="ag-track__label">{{ __('operations') }}</span>
                                    </div>
                                    <div>
                                        <strong class="ag-track__value">{{ $opsTotal > 0 ? money($agent->completed_volume ?? 0, $baseCode) : '—' }}</strong>
                                        <span class="ag-track__label">{{ __('volume') }}</span>
                                    </div>
                                    <div>
                                        <strong class="ag-track__value">{{ $opsTotal > 0 ? money($agent->earned_commission ?? 0, $baseCode) : '—' }}</strong>
                                        <span class="ag-track__label">{{ __('commission') }}</span>
                                    </div>
                                </div>
                                @if($opsTotal > 0)
                                    {{-- Counts, not amounts: the two halves have to be comparable. --}}
                                    <div class="ag-split ag-split--rail">
                                        <span class="ag-split__bar" aria-hidden="true">
                                            <span class="ag-split__in" style="width: {{ $inShare }}%"></span>
                                            <span class="ag-split__out"></span>
                                        </span>
                                        <span class="ag-split__label">{{ __('in :in / out :out', ['in' => $cashIn, 'out' => $cashOut]) }}</span>
                                    </div>
                                @else
                                    <p class="ag-note mt-2">{{ __('Nothing yet — this counter has never been approved to trade.') }}</p>
                                @endif
                            </section>
                        </aside>

                        {{-- The main column carries what is decided. --}}
                        <div class="ag-review__main">

                            <div class="ag-banner ag-banner--{{ $banner['tone'] }}" role="status">
                                <i class="fa-solid {{ $banner['icon'] }} flex-shrink-0 mt-1" aria-hidden="true"></i>
                                <div>
                                    <strong class="ag-banner__title">{{ $banner['title'] }}</strong>
                                    <span class="ag-banner__text">{{ $banner['text'] }}</span>
                                </div>
                            </div>

                            @if($isRejected)
                                {{--
                                    What was actually sent. Commission setup is not
                                    drawn for a rejected request — there is nothing
                                    to configure until a counter is approved — so
                                    this is what the main column is for.
                                --}}
                                <section class="ag-decision">
                                    <div class="ag-decision__head">
                                        {{-- fa-circle-info, not fa-align-left: the shipped woff2 is
                                             subsetted, and fa-align-left is drawn on no other admin
                                             page, so nothing proves it has a glyph rather than an
                                             empty tile. The tile's tint already carries the tone. --}}
                                        <span class="ag-decision__icon" aria-hidden="true">
                                            <i class="fa-solid fa-circle-info"></i>
                                        </span>
                                        <div>
                                            <h6 class="ag-decision__title">{{ __('Reason sent to the operator') }}</h6>
                                            <p class="ag-decision__meta">{{ __('The operator received this with the rejection e-mail.') }}</p>
                                        </div>
                                    </div>
                                    <div class="ag-decision__body">
                                        @if($rejectionReason !== '')
                                            <blockquote class="ag-quote">{!! nl2br(e($rejectionReason)) !!}</blockquote>
                                        @else
                                            <p class="ag-rate--unset mb-0">{{ __('No reason was recorded for this rejection.') }}</p>
                                        @endif
                                    </div>
                                </section>

                                <div class="ag-banner ag-banner--muted" role="note">
                                    <i class="fa-solid fa-circle-info flex-shrink-0 mt-1" aria-hidden="true"></i>
                                    <div>
                                        <span class="ag-banner__text">{{ __('If the operator reapplies it arrives as a fresh request at the top of the queue.') }}</span>
                                    </div>
                                </div>
                            @endif

                            @unless($isRejected)
                                <section class="ag-setup">
                                    <div class="ag-setup__head">
                                        <div class="ag-setup__copy">
                                            <h6 class="ag-setup__title">{{ __('Commission setup') }}</h6>
                                            <p class="ag-setup__hint">{{ __('Rules are matched by specificity first, then priority. The fallback rate is used only when nothing matches.') }}</p>
                                        </div>
                                        <div class="ag-fallback">
                                            <label class="form-label small fw-semibold mb-1" for="agent_commission_{{ $agent->id }}">{{ __('Fallback Rate') }}</label>
                                            <div class="input-group ag-fallback__control">
                                                <input type="text" oninput="this.value = validateDouble(this.value)"
                                                       class="form-control @error('commission') is-invalid @enderror"
                                                       id="agent_commission_{{ $agent->id }}"
                                                       name="commission"
                                                       placeholder="0.00" inputmode="decimal" autocomplete="off"
                                                       value="{{ $commissionValue }}">
                                                <span class="input-group-text">%</span>
                                            </div>
                                            @error('commission')
                                                <div class="invalid-feedback d-block">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="ag-setup__bar">
                                        <div>
                                            <strong class="d-block small">{{ __('Rules this counter earns under') }}</strong>
                                            <span class="ag-muted">{{ __(':selected of :total selected', ['selected' => $selectedCount, 'total' => $availableRules->count()]) }}</span>
                                        </div>
                                        <a href="{{ route('admin.agent.commission-rules.index') }}" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2">
                                            <i class="fa-solid fa-plus" aria-hidden="true"></i><span>{{ __('Manage Rules') }}</span>
                                        </a>
                                    </div>

                                    @if($availableRules->isNotEmpty())
                                        <div class="table-responsive">
                                            <table class="table table-sm align-middle mb-0">
                                                <caption class="visually-hidden">{{ __('Commission rules available to this agent') }}</caption>
                                                <thead class="table-light">
                                                <tr>
                                                    <th scope="col">{{ __('Use') }}</th>
                                                    <th scope="col">{{ __('Rule') }}</th>
                                                    <th scope="col">{{ __('Applies to') }}</th>
                                                    <th scope="col">{{ __('Priority') }}</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                @foreach($availableRules as $rule)
                                                    @php
                                                        $assignment          = $assignedRules->get($rule->id);
                                                        $assignmentOperation = old("commission_rules.{$rule->id}.operation_type", $assignment?->operation_type ?? $rule->operation_type);
                                                        $assignmentPriority  = old("commission_rules.{$rule->id}.priority", $assignment?->priority ?? $rule->priority);
                                                        $isOn                = (bool) old("commission_rules.{$rule->id}.enabled", $assignment !== null);
                                                        $ruleOperationLabel  = $rule->operation_type === 'all'
                                                            ? __('Any operation')
                                                            : (AgentOperationType::tryFrom($rule->operation_type)?->label() ?? $rule->operation_type);
                                                    @endphp
                                                    <tr class="{{ $isOn ? 'ag-rule--on' : '' }}">
                                                        <td>
                                                            <input class="form-check-input admin-check" type="checkbox"
                                                                   id="agent-{{ $agent->id }}-rule-{{ $rule->id }}"
                                                                   name="commission_rules[{{ $rule->id }}][enabled]"
                                                                   value="1"
                                                                   @checked($isOn)>
                                                        </td>
                                                        <td>
                                                            <label class="ag-name d-block mb-0" for="agent-{{ $agent->id }}-rule-{{ $rule->id }}">{{ $rule->name }}</label>
                                                            <div class="ag-muted">
                                                                {{ $rule->currency?->code ?? __('Any currency') }}
                                                                &middot;
                                                                {{ $ruleOperationLabel }}
                                                                &middot;
                                                                {{ $rule->applies_globally ? __('Global fallback') : __('Assignment only') }}
                                                                &middot;
                                                                @if($rule->calculation_type === AgentCommissionRuleType::PERCENTAGE)
                                                                    {{ feeRate($rule->percentage_rate) }}%
                                                                @else
                                                                    {{ money($rule->fixed_amount, null, false) }} {{ __('fixed') }}
                                                                @endif
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <select name="commission_rules[{{ $rule->id }}][operation_type]"
                                                                    aria-label="{{ __('Operation for :rule', ['rule' => $rule->name]) }}"
                                                                    class="form-select form-select-sm ag-rule-op">
                                                                @foreach($operationOptions as $value => $label)
                                                                    <option value="{{ $value }}" @selected($assignmentOperation === $value)>{{ $label }}</option>
                                                                @endforeach
                                                            </select>
                                                        </td>
                                                        <td>
                                                            <input type="number" name="commission_rules[{{ $rule->id }}][priority]"
                                                                   min="1" max="999"
                                                                   aria-label="{{ __('Priority for :rule', ['rule' => $rule->name]) }}"
                                                                   class="form-control form-control-sm ag-rule-priority"
                                                                   value="{{ $assignmentPriority }}">
                                                        </td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @else
                                        <div class="p-3">
                                            <x-admin-not-found
                                                :title="__('No commission rules created')"
                                                :message="__('Create reusable rules first, then assign them while approving agents.')"
                                                icon="fa-percent"
                                                :action-url="route('admin.agent.commission-rules.index')"
                                                :action-label="__('Create Rules')"
                                                action-icon="fa-plus"
                                            />
                                        </div>
                                    @endif
                                </section>
                            @endunless

                            @if($isPending)
                                {{--
                                    AgentActionRequest has always validated rejection_reason and the
                                    rejection notification has always interpolated it — with no field
                                    to fill, every rejection e-mail fell back to "No reason provided".
                                --}}
                                <section class="ag-reason">
                                    <div class="ag-reason__head">
                                        <label class="ag-reason__label" for="agent_reason_{{ $agent->id }}">{{ __('Reason for rejection — sent to the operator') }}</label>
                                        <span class="ag-reason__count">{{ __('max :count characters', ['count' => 500]) }}</span>
                                    </div>
                                    <textarea class="form-control ag-reason__field @error('rejection_reason') is-invalid @enderror"
                                              id="agent_reason_{{ $agent->id }}"
                                              name="rejection_reason" rows="2" maxlength="500"
                                              placeholder="{{ __('Tell them what is missing, so they can fix it and reapply.') }}">{{ old('rejection_reason') }}</textarea>
                                    @error('rejection_reason')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </section>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="modal-footer ag-review__footer">
                    <p class="ag-review__consequence mb-0">
                        @if($isRejected)
                            {{ __('This agent is rejected. No further actions available.') }}
                        @else
                            {{ __('The decision applies immediately and e-mails the operator.') }}
                        @endif
                    </p>
                    <div class="ag-review__actions">
                        @if($isPending)
                            <button type="button" class="btn btn-light" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
                            <button type="submit" value="reject" name="action" class="btn btn-outline-danger">
                                <i class="fa-solid fa-times me-2" aria-hidden="true"></i>{{ __('Reject request') }}
                            </button>
                            <button type="submit" value="approve" name="action" class="btn btn-success text-white">
                                <i class="fa-solid fa-check me-2" aria-hidden="true"></i>{{ __('Approve Agent') }}
                            </button>
                        @elseif($agent->status === AgentStatus::APPROVED)
                            <button type="submit" value="disable" name="action" class="btn btn-outline-danger">
                                <i class="fa-solid fa-ban me-2" aria-hidden="true"></i>{{ __('Disable Agent') }}
                            </button>
                            <button type="submit" value="approve" name="action" class="btn btn-primary">
                                <i class="fa-solid fa-rotate me-2" aria-hidden="true"></i>{{ __('Update Setup') }}
                            </button>
                        @elseif($agent->status === AgentStatus::DISABLED)
                            {{--
                                'approve' on a disabled agent saves the setup without
                                changing the status — the controller only promotes a
                                PENDING row. The label says so, because "Update Setup"
                                beside "Enable Agent" read as though it did both.
                            --}}
                            <button type="submit" value="approve" name="action" class="btn btn-outline-primary">
                                <i class="fa-solid fa-rotate me-2" aria-hidden="true"></i>{{ __('Update setup only') }}
                            </button>
                            <button type="submit" value="enable" name="action" class="btn btn-success text-white">
                                <i class="fa-solid fa-power-off me-2" aria-hidden="true"></i>{{ __('Enable Agent') }}
                            </button>
                        @else
                            <button type="button" class="btn btn-light" data-coreui-dismiss="modal">{{ __('Close') }}</button>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
