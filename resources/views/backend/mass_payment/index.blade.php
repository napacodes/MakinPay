@extends('backend.mass_payment.layout')

@section('title', __('Mass payments'))

{{--
    Every mass payment, newest first. The money figures in the header are in
    the base currency only; amounts in different currencies are never added
    together.
--}}
@section('sub_header')
    <x-page-header
        :title="__('Mass payments')"
        :subtitle="__('Batches merchants pay from one wallet to many, and where each one stands.')"
        icon="people"
        :breadcrumbs="[
            ['label' => __('Money movement')],
            ['label' => __('Mass payments')],
        ]"
        :stats="[
            ['label' => __('Needs attention'), 'value' => (int) $stats['attention'], 'tone' => $stats['attention'] > 0 ? 'warning' : 'neutral', 'icon' => 'warning', 'url' => route('admin.mass-payment.index', ['attention' => 1])],
            ['label' => __('Processing'), 'value' => (int) $stats['processing'], 'tone' => 'brand', 'icon' => 'clock'],
            ['label' => __('Paid today'), 'value' => money($stats['paidToday'], $stats['baseCurrency'], false).' '.$stats['baseCurrency'], 'tone' => 'success', 'icon' => 'send-money'],
            ['label' => __('Released today'), 'value' => money($stats['releasedToday'], $stats['baseCurrency'], false).' '.$stats['baseCurrency'], 'tone' => 'neutral', 'icon' => 'refund'],
        ]"
    />
@endsection

@section('sub_content')
    @php
        $filtered = collect($filters)->filter(fn ($value) => $value !== '')->isNotEmpty();
    @endphp

    <div class="card border-0 mb-4 mpa-card">
        <div class="card-body">
            <div class="admin-table-filterbar d-flex mb-3">
                <form action="{{ route('admin.mass-payment.index') }}" method="GET" class="admin-table-filters row g-2 g-md-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="visually-hidden" for="mpa-search">{{ __('Reference') }}</label>
                        <input type="search" id="mpa-search" name="search" value="{{ $filters['search'] }}"
                               class="form-control" placeholder="{{ __('MP reference or batch ID') }}">
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="visually-hidden" for="mpa-user">{{ __('Merchant') }}</label>
                        <input type="search" id="mpa-user" name="user" value="{{ $filters['user'] }}"
                               class="form-control" placeholder="{{ __('Merchant email, username or name') }}">
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="visually-hidden" for="mpa-status">{{ __('Status') }}</label>
                        <select id="mpa-status" name="status" class="form-select">
                            <option value="">{{ __('Any status') }}</option>
                            @foreach($statuses as $statusOption)
                                <option value="{{ $statusOption->value }}" @selected($filters['status'] === $statusOption->value)>{{ $statusOption->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="visually-hidden" for="mpa-kind">{{ __('Type') }}</label>
                        <select id="mpa-kind" name="kind" class="form-select">
                            <option value="">{{ __('Any type') }}</option>
                            <option value="{{ \App\Enums\MassPayment\MassPaymentKind::Wallet->value }}" @selected($filters['kind'] === \App\Enums\MassPayment\MassPaymentKind::Wallet->value)>{{ __('To wallets') }}</option>
                            <option value="{{ \App\Enums\MassPayment\MassPaymentKind::Payout->value }}" @selected($filters['kind'] === \App\Enums\MassPayment\MassPaymentKind::Payout->value)>{{ __('To external accounts') }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="visually-hidden" for="mpa-attention">{{ __('Needs attention') }}</label>
                        <select id="mpa-attention" name="attention" class="form-select">
                            <option value="">{{ __('All batches') }}</option>
                            <option value="1" @selected($filters['attention'] === '1')>{{ __('Needs attention') }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="visually-hidden" for="mpa-from">{{ __('From') }}</label>
                        <input type="date" id="mpa-from" name="from" value="{{ $filters['from'] }}" class="form-control" title="{{ __('From') }}">
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="visually-hidden" for="mpa-to">{{ __('To') }}</label>
                        <input type="date" id="mpa-to" name="to" value="{{ $filters['to'] }}" class="form-control" title="{{ __('To') }}">
                    </div>
                    <div class="col-12 col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            <span>{{ __('Filter') }}</span>
                        </button>
                        @if($filtered)
                            <a href="{{ route('admin.mass-payment.index') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                        @endif
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <caption class="visually-hidden">{{ __('Mass payments, newest first') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Date') }}</th>
                            <th scope="col">{{ __('Batch') }}</th>
                            <th scope="col">{{ __('Rows') }}</th>
                            <th scope="col">{{ __('Total reserved') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col" class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($batches as $batch)
                            @php
                                $batchStatus = $batch->status();
                            @endphp
                            <tr data-mpa-row="{{ $batch->uuid }}">
                                <td class="text-nowrap">
                                    <span class="d-block">{{ $batch->created_at?->format('d M Y') }}</span>
                                    <span class="mpa-muted">{{ $batch->created_at?->format('H:i') }}</span>
                                </td>
                                <td>
                                    <div class="mpa-person">
                                        <a href="{{ route('admin.mass-payment.show', $batch) }}"
                                           class="mpa-ref"
                                           dir="ltr"
                                           title="{{ __('Open :reference', ['reference' => $batch->reference]) }}">{{ $batch->reference }}</a>
                                        <span class="mpa-muted" title="{{ $batch->user?->email }}">{{ $batch->user?->name ?? __('Removed user') }}</span>
                                        @if($batch->isPayout())
                                            <span class="mpa-muted" data-mpa-kind="payout">{{ __('Payout via :method', ['method' => (string) ($batch->metadata['payout']['method_name'] ?? $batch->payout_driver)]) }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-nowrap">
                                    <span class="d-block">{{ __(':count rows', ['count' => number_format((int) $batch->row_count)]) }}</span>
                                    <span class="mpa-muted">
                                        {{ __(':paid paid · :failed refunded · :pending pending', [
                                            'paid'    => number_format((int) $batch->paid_rows_count),
                                            'failed'  => number_format((int) $batch->failed_rows_count),
                                            'pending' => number_format((int) $batch->pending_rows_count),
                                        ]) }}
                                    </span>
                                </td>
                                <td class="text-nowrap">
                                    <strong dir="ltr">{{ money($batch->total_payable ?? '0', $batch->currency_code, false) }} {{ $batch->currency_code }}</strong>
                                </td>
                                <td>
                                    <span class="mpa-pill mpa-pill--{{ $batchStatus->color() }}">{{ $batchStatus->label() }}</span>
                                    @if($batch->stop_requested_at !== null && ! $batchStatus->isTerminal())
                                        <span class="mpa-muted d-block">{{ __('Stopping') }}</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div class="admin-table-actions">
                                        <a href="{{ route('admin.mass-payment.show', $batch) }}"
                                           class="btn btn-outline-primary btn-sm admin-table-action"
                                           title="{{ __('Open :reference', ['reference' => $batch->reference]) }}">
                                            <x-icon name="arrow-up-right" width="14" height="14"/>
                                            <span>{{ __('View') }}</span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-admin-not-found
                                        :title="$filtered ? __('No batches match your filter') : __('No mass payments yet')"
                                        :message="$filtered ? __('Try a different search or clear the filter.') : __('Batches appear here as soon as a merchant creates one.')"
                                        icon="fa-users"
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($batches->hasPages())
                <div class="mt-3">{{ $batches->links() }}</div>
            @endif
        </div>
    </div>
@endsection
