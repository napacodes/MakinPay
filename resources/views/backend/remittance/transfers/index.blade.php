@extends('backend.layouts.app')
@section('title', __('Remittance Transfers'))

@section('content')
    <div class="container-fluid py-4">
        <x-page-header
            :title="__('International Transfers')"
            :subtitle="__('Monitor every outbound remittance, approve manual transfers, or trigger refunds.')"
            icon="send-money"
            :status="$operations['stuck'] > 0 ? ['tone' => 'warning', 'label' => __(':count need reconciliation', ['count' => $operations['stuck']])] : null"
        />

        @if ($operations['stuck'] > 0)
            <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                <div>
                    <strong>{{ trans_choice(':count payout requires reconciliation.|:count payouts require reconciliation.', $operations['stuck'], ['count' => $operations['stuck']]) }}</strong>
                    {{ __('Review provider status before attempting refunds or manual changes.') }}
                </div>
            </div>
        @endif

        <div class="row g-3 mb-3">
            <div class="col-xl-3 col-sm-6">
                <div class="card h-100 border-start border-primary border-4">
                    <div class="card-body">
                        <div class="text-muted small">{{ __('Scheduled for dispatch') }}</div>
                        <div class="fs-3 fw-semibold">{{ $operations['scheduled'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <a href="{{ route('admin.remittance.transfers.index', ['dispatch_state' => 'ambiguous']) }}" class="text-decoration-none text-body">
                    <div class="card h-100 border-start border-warning border-4">
                        <div class="card-body">
                            <div class="text-muted small">{{ __('Needs reconciliation') }}</div>
                            <div class="fs-3 fw-semibold">{{ $operations['ambiguous'] }}</div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="card h-100 border-start border-info border-4">
                    <div class="card-body">
                        <div class="text-muted small">{{ __('Provider in-flight') }}</div>
                        <div class="fs-3 fw-semibold">{{ $operations['in_flight'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <a href="{{ route('admin.remittance.transfers.index', ['dispatch_state' => 'definitively_failed']) }}" class="text-decoration-none text-body">
                    <div class="card h-100 border-start border-danger border-4">
                        <div class="card-body">
                            <div class="text-muted small">{{ __('Definitively failed') }}</div>
                            <div class="fs-3 fw-semibold">{{ $operations['definitively_failed'] }}</div>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.remittance.transfers.index') }}" class="row g-3">
                    <div class="col-lg-4 col-md-6">
                        <label for="remittance-search" class="form-label">{{ __('Search') }}</label>
                        <input type="search" id="remittance-search" name="search" class="form-control"
                               placeholder="{{ __('Search by reference, gateway, sender email…') }}"
                               value="{{ request('search') }}" maxlength="120">
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <label for="remittance-status" class="form-label">{{ __('Status') }}</label>
                        <select id="remittance-status" name="status" class="form-select">
                            <option value="">{{ __('All statuses') }}</option>
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <label for="remittance-gateway" class="form-label">{{ __('Gateway') }}</label>
                        <select id="remittance-gateway" name="gateway" class="form-select">
                            <option value="">{{ __('All gateways') }}</option>
                            @foreach ($gateways as $value => $label)
                                <option value="{{ $value }}" @selected(request('gateway') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <label for="remittance-dispatch-state" class="form-label">{{ __('Dispatch state') }}</label>
                        <select id="remittance-dispatch-state" name="dispatch_state" class="form-select">
                            <option value="">{{ __('All dispatch states') }}</option>
                            @foreach ($dispatchStates as $value => $label)
                                <option value="{{ $value }}" @selected(request('dispatch_state') === $value)>{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <label for="remittance-corridor" class="form-label">{{ __('Corridor') }}</label>
                        <select id="remittance-corridor" name="corridor_id" class="form-select">
                            <option value="">{{ __('All corridors') }}</option>
                            @foreach ($corridors as $corridor)
                                <option value="{{ $corridor->id }}" @selected((string) request('corridor_id') === (string) $corridor->id)>
                                    {{ $corridor->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label for="remittance-date-from" class="form-label">{{ __('From date') }}</label>
                        <input type="date" id="remittance-date-from" name="date_from" class="form-control"
                               value="{{ request('date_from') }}">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label for="remittance-date-to" class="form-label">{{ __('To date') }}</label>
                        <input type="date" id="remittance-date-to" name="date_to" class="form-control"
                               value="{{ request('date_to') }}">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label for="remittance-per-page" class="form-label">{{ __('Rows') }}</label>
                        <select id="remittance-per-page" name="per_page" class="form-select">
                            @foreach ([20, 50, 100] as $size)
                                <option value="{{ $size }}" @selected((int) request('per_page', 20) === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-6 d-flex flex-wrap align-items-end gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-filter"></i> {{ __('Apply filters') }}
                        </button>
                        <a href="{{ route('admin.remittance.transfers.index') }}" class="btn btn-light">
                            <i class="fa-solid fa-rotate-left"></i> {{ __('Reset') }}
                        </a>
                        @can('remittance-transfer-list')
                            <a href="{{ route('admin.remittance.transfers.export', request()->query()) }}"
                               class="btn btn-light-success">
                                <i class="fa-solid fa-file-csv"></i> {{ __('Export CSV') }}
                            </a>
                        @endcan
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                @if ($transfers->isEmpty())
                    <x-admin-not-found
                        :title="__('No transfers match')"
                        :message="__('Adjust filters or wait for users to send their first international transfer.')"
                        icon="fa-paper-plane"
                    />
                @else
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ __('Reference') }}</th>
                                    <th>{{ __('Sender') }}</th>
                                    <th>{{ __('Recipient') }}</th>
                                    <th>{{ __('Sent') }}</th>
                                    <th>{{ __('Receives') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Created') }}</th>
                                    <th class="text-end">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($transfers as $transfer)
                                    <tr>
                                        <td>
                                            <code>{{ $transfer->reference }}</code>
                                            <div class="text-muted small">{{ $transfer->payout_gateway }}</div>
                                        </td>
                                        <td>
                                            <strong>{{ $transfer->user->name }}</strong>
                                            <div class="text-muted small">{{ $transfer->user->email }}</div>
                                        </td>
                                        <td>
                                            <strong>{{ $transfer->recipient_name }}</strong>
                                            <div class="text-muted small">{{ $transfer->recipient_country }}</div>
                                        </td>
                                        <td>{{ money($transfer->total_paid, $transfer->source_currency, false) }} {{ $transfer->source_currency }}</td>
                                        <td>{{ money($transfer->receive_amount, $transfer->destination_currency, false) }} {{ $transfer->destination_currency }}</td>
                                        <td>
                                            <span class="badge bg-{{ $transfer->status->badgeColor() }}">{{ $transfer->status->label() }}</span>
                                            <div class="text-muted small">{{ __($dispatchStates[$transfer->dispatch_state] ?? \Illuminate\Support\Str::headline($transfer->dispatch_state)) }}</div>
                                        </td>
                                        <td class="small text-muted">{{ $transfer->created_at_time }}</td>
                                        <td class="text-end">
                                            <a href="{{ route('admin.remittance.transfers.show', $transfer) }}"
                                               class="btn btn-sm btn-light-primary">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            @if ($transfers->hasPages())
                <div class="card-footer">{{ $transfers->links() }}</div>
            @endif
        </div>
    </div>
@endsection
