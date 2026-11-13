@extends('backend.layouts.app')
@section('title', $transfer->reference)

@section('content')
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-1"><i class="fa-solid fa-paper-plane text-primary"></i> {{ $transfer->reference }}</h4>
                <p class="text-muted small mb-0">{{ __('Sent on :date', ['date' => $transfer->created_at_time]) }}</p>
            </div>
            <span class="badge bg-{{ $transfer->status->badgeColor() }} fs-6">{{ $transfer->status->label() }}</span>
        </div>

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-header"><strong>{{ __('Money Movement') }}</strong></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="text-muted small">{{ __('Sender paid') }}</div>
                                <strong>{{ money($transfer->total_paid, $transfer->source_currency, false) }} {{ $transfer->source_currency }}</strong>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">{{ __('Recipient receives') }}</div>
                                <strong class="text-success">{{ money($transfer->receive_amount, $transfer->destination_currency, false) }} {{ $transfer->destination_currency }}</strong>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">{{ __('Send amount') }}</div>
                                {{ money($transfer->send_amount, $transfer->source_currency, false) }} {{ $transfer->source_currency }}
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">{{ __('Fee') }}</div>
                                {{ money($transfer->fee_amount, $transfer->source_currency, false) }} {{ $transfer->source_currency }}
                            </div>
                            @if ($transfer->tax_amount !== null)
                                <div class="col-md-4" data-tax-total>
                                    <div class="text-muted small">{{ __('Tax') }}</div>
                                    {{ money($transfer->tax_amount, $transfer->source_currency, false) }} {{ $transfer->source_currency }}
                                    @foreach ((array) $transfer->tax_breakdown as $taxLine)
                                        <div class="small text-muted">{{ $taxLine['name'] ?? '' }}: {{ money($taxLine['amount'] ?? 0, $transfer->source_currency, false) }}</div>
                                    @endforeach
                                </div>
                            @endif
                            <div class="col-md-4">
                                <div class="text-muted small">{{ __('Exchange rate') }}</div>
                                {{ \App\Support\Money::formatRate($transfer->exchange_rate) }}
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">{{ __('Payout method') }}</div>
                                {{ $transfer->payout_method->label() }}
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">{{ __('Payout gateway') }}</div>
                                <code>{{ $transfer->payout_gateway }}</code>
                                @if ($transfer->gateway_reference)
                                    <div class="text-muted small">{{ $transfer->gateway_reference }}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><strong>{{ __('Payout operations') }}</strong></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="text-muted small">{{ __('Dispatch state') }}</div>
                                <strong>{{ __(config('remittance.dispatch_states.'.$transfer->dispatch_state, \Illuminate\Support\Str::headline($transfer->dispatch_state))) }}</strong>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">{{ __('Dispatch attempts') }}</div>
                                <strong>{{ $transfer->dispatch_attempts }}</strong>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">{{ __('Last dispatch activity') }}</div>
                                <strong>{{ $transfer->last_dispatch_at?->format('M d, Y h:i A') ?? __('Not attempted') }}</strong>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">{{ __('Reconciliation attempts') }}</div>
                                <strong>{{ $transfer->reconciliation_attempts }}</strong>
                            </div>
                            <div class="col-md-8">
                                <div class="text-muted small">{{ __('Last provider reconciliation') }}</div>
                                <strong>{{ $transfer->last_reconciled_at?->format('M d, Y h:i A') ?? __('Not checked') }}</strong>
                            </div>
                        </div>
                        @if ($transfer->reconciliation_error)
                            <div class="alert alert-danger mt-3 mb-0" role="alert">
                                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                                {{ $transfer->reconciliation_error }}
                            </div>
                        @endif
                        @if ($transfer->failure_reason)
                            <div class="alert alert-warning mt-3 mb-0" role="alert">
                                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                                {{ $transfer->failure_reason }}
                            </div>
                        @endif
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>{{ __('Immutable status timeline') }}</strong>
                        <span class="badge bg-light text-dark">{{ __('Audit log') }}</span>
                    </div>
                    <div class="card-body">
                        @if ($transfer->statusEvents->isEmpty())
                            <x-admin-not-found
                                :title="__('No audit events recorded')"
                                :message="__('Legacy transfers may not have immutable status events. New status changes appear here automatically.')"
                                icon="fa-clock-rotate-left"
                            />
                        @else
                            <ul class="list-unstyled mb-0">
                                @foreach ($transfer->statusEvents as $event)
                                    @php
                                        $eventStatus = \App\Enums\RemittanceStatus::tryFrom($event->to_status);
                                    @endphp
                                    <li class="d-flex gap-2 mb-3">
                                        <i class="fa-solid fa-circle-check text-primary mt-1" aria-hidden="true"></i>
                                        <div>
                                            <span class="badge bg-{{ $eventStatus?->badgeColor() ?? 'secondary' }}">
                                                {{ $eventStatus?->label() ?? \Illuminate\Support\Str::headline($event->to_status) }}
                                            </span>
                                            @if ($event->note)
                                                <div class="mt-1">{{ $event->note }}</div>
                                            @endif
                                            <div class="text-muted small mt-1">
                                                {{ $event->occurred_at->format('M d, Y h:i A') }}
                                                · {{ __('Source: :source', ['source' => $event->source]) }}
                                                @if ($event->actor_type)
                                                    · {{ __('Actor: :actor', ['actor' => $event->actor_type.($event->actor_id ? ' #'.$event->actor_id : '')]) }}
                                                @endif
                                            </div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                @if ($transfer->compliance_result)
                    <div class="card mb-3">
                        <div class="card-header"><strong>{{ __('Compliance screening') }}</strong></div>
                        <div class="card-body">
                            <p class="mb-1">
                                <strong>{{ __('Outcome:') }}</strong>
                                @if ($transfer->compliance_result['passed'] ?? false)
                                    <span class="badge bg-success">{{ __('Passed') }}</span>
                                @else
                                    <span class="badge bg-danger">{{ __('Flagged') }}</span>
                                @endif
                            </p>
                            <p class="mb-1"><strong>{{ __('Risk score:') }}</strong> {{ $transfer->compliance_result['risk_score'] ?? '—' }}</p>
                            @if (! empty($transfer->compliance_result['reason']))
                                <p class="mb-0 text-muted small">{{ $transfer->compliance_result['reason'] }}</p>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            <div class="col-lg-4">
                @php
                    $manualActionable = $transfer->payout_gateway === 'manual'
                        && in_array($transfer->status, [
                            \App\Enums\RemittanceStatus::Pending,
                            \App\Enums\RemittanceStatus::Submitted,
                            \App\Enums\RemittanceStatus::Processing,
                        ], true);
                    $safeBeforeDispatch = $transfer->status === \App\Enums\RemittanceStatus::Pending
                        && in_array($transfer->dispatch_state, ['not_started', 'scheduled'], true)
                        && $transfer->dispatch_attempts === 0;
                    $definitivelyFailed = $transfer->status === \App\Enums\RemittanceStatus::Failed
                        && $transfer->dispatch_state === 'definitively_failed';
                    $refundable = $safeBeforeDispatch || $definitivelyFailed;
                @endphp

                <div class="card mb-3">
                    <div class="card-header"><strong>{{ __('Sender') }}</strong></div>
                    <div class="card-body">
                        <p class="mb-1"><strong>{{ $transfer->user->name }}</strong></p>
                        <p class="text-muted small mb-0">{{ $transfer->user->email }}</p>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><strong>{{ __('Recipient') }}</strong></div>
                    <div class="card-body">
                        <p class="mb-1"><strong>{{ $transfer->recipient_name }}</strong></p>
                        <p class="text-muted small mb-1">{{ $transfer->recipient_country }} · {{ $transfer->masked_recipient_account }}</p>
                        <p class="text-muted small mb-1">{{ __('Locked recipient snapshot captured at submission.') }}</p>
                        @if ($transfer->beneficiary->relationship)
                            <p class="text-muted small mb-0">{{ $transfer->beneficiary->relationship }}</p>
                        @endif
                    </div>
                </div>

                @can('remittance-transfer-manage')
                    @if ($manualActionable || $refundable)
                        <div class="card">
                            <div class="card-header"><strong>{{ __('Admin actions') }}</strong></div>
                            <div class="card-body">
                                @if ($manualActionable)
                                    <form action="{{ route('admin.remittance.transfers.approve', $transfer) }}"
                                          method="POST" class="mb-2"
                                          onsubmit="return confirm('{{ __('Mark this transfer as completed?') }}');">
                                        @csrf
                                        <button class="btn btn-success w-100" type="submit">
                                            <i class="fa-solid fa-check"></i> {{ __('Mark Completed') }}
                                        </button>
                                    </form>

                                    <form action="{{ route('admin.remittance.transfers.fail', $transfer) }}"
                                          method="POST" class="mb-2">
                                        @csrf
                                        <label for="manual-failure-reason" class="form-label">{{ __('Failure reason') }}</label>
                                        <input type="text" id="manual-failure-reason" name="reason" class="form-control mb-2"
                                               placeholder="{{ __('Optional operational note') }}" maxlength="500">
                                        <button class="btn btn-warning w-100" type="submit"
                                                onclick="return confirm('{{ __('Mark this transfer as failed?') }}');">
                                            <i class="fa-solid fa-xmark"></i> {{ __('Mark Failed') }}
                                        </button>
                                    </form>
                                @endif

                                @if ($refundable)
                                    <form action="{{ route('admin.remittance.transfers.refund', $transfer) }}"
                                          method="POST"
                                          onsubmit="return confirm('{{ __('Refund this transfer to the sender wallet?') }}');">
                                        @csrf
                                        <label for="refund-reason" class="form-label">{{ __('Refund reason') }}</label>
                                        <input type="text" id="refund-reason" name="reason" class="form-control mb-2"
                                               placeholder="{{ __('Optional operational note') }}" maxlength="500">
                                        <button class="btn btn-danger w-100" type="submit">
                                            <i class="fa-solid fa-rotate-left"></i> {{ __('Refund to Sender') }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="card">
                            <div class="card-body">
                                <x-admin-not-found
                                    :title="__('No safe manual actions')"
                                    :message="__('Provider-managed or unresolved payouts must be reconciled before an admin can change funds or status.')"
                                    icon="fa-shield-halved"
                                />
                            </div>
                        </div>
                    @endif
                @endcan
            </div>
        </div>
    </div>
@endsection
