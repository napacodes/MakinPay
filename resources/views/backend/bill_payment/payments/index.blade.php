@extends('backend.bill_payment.layout')

@section('title', __('Bill payments'))

{{--
    Every bill payment, newest first. The header counts payments, never sums
    them: amounts in different currencies are not added together.
--}}
@section('sub_header')
    <x-page-header
        :title="__('Bill payments')"
        :subtitle="__('Every bill customers have paid from their wallets, and where each one stands.')"
        icon="receipt"
        :stats="[
            ['label' => __('Total'), 'value' => (int) $stats['total'], 'tone' => 'brand', 'icon' => 'receipt'],
            ['label' => __('Paid'), 'value' => (int) $stats['paid'], 'tone' => 'success', 'icon' => 'check'],
            ['label' => __('Failed'), 'value' => (int) $stats['failed'], 'tone' => $stats['failed'] > 0 ? 'danger' : 'neutral', 'icon' => 'x-circle'],
            ['label' => __('Awaiting'), 'value' => (int) $stats['awaiting'], 'tone' => $stats['awaiting'] > 0 ? 'warning' : 'neutral', 'icon' => 'clock'],
        ]"
    />
@endsection

@section('sub_content')
    @php
        $filtered = collect($filters)->filter(fn ($value) => $value !== '')->isNotEmpty();
    @endphp

    <div class="card border-0 mb-4 bpa-card">
        <div class="card-body">
            <div class="admin-table-filterbar d-flex mb-3">
                <form action="{{ route('admin.bill-payment.index') }}" method="GET" class="admin-table-filters row g-2 g-md-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="visually-hidden" for="bpa-history-search">{{ __('Search payments') }}</label>
                        <input type="search" id="bpa-history-search" name="search" value="{{ $filters['search'] }}"
                               class="form-control" placeholder="{{ __('Reference, transaction, number or email') }}">
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="visually-hidden" for="bpa-history-status">{{ __('Status') }}</label>
                        <select id="bpa-history-status" name="status" class="form-select">
                            <option value="">{{ __('Any status') }}</option>
                            @foreach($statuses as $status)
                                <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="visually-hidden" for="bpa-history-stage">{{ __('Stage') }}</label>
                        <select id="bpa-history-stage" name="stage" class="form-select">
                            <option value="">{{ __('Any stage') }}</option>
                            @foreach($stages as $stageValue => $stageLabel)
                                <option value="{{ $stageValue }}" @selected($filters['stage'] === $stageValue)>{{ $stageLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="visually-hidden" for="bpa-history-provider">{{ __('Provider') }}</label>
                        <select id="bpa-history-provider" name="provider" class="form-select">
                            <option value="">{{ __('All providers') }}</option>
                            @foreach($providers as $provider)
                                <option value="{{ $provider->id }}" @selected($filters['provider'] === (string) $provider->id)>{{ $provider->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @include('backend.bill_payment.payments._filter_selects', ['idPrefix' => 'bpa-history'])
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="visually-hidden" for="bpa-history-from">{{ __('From') }}</label>
                        <input type="date" id="bpa-history-from" name="from" value="{{ $filters['from'] }}" class="form-control" title="{{ __('From') }}">
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="visually-hidden" for="bpa-history-to">{{ __('To') }}</label>
                        <input type="date" id="bpa-history-to" name="to" value="{{ $filters['to'] }}" class="form-control" title="{{ __('To') }}">
                    </div>
                    <div class="col-12 col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            <span>{{ __('Filter') }}</span>
                        </button>
                        @if($filtered)
                            <a href="{{ route('admin.bill-payment.index') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                        @endif
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <caption class="visually-hidden">{{ __('Bill payments, newest first') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Date') }}</th>
                            <th scope="col">{{ __('Payment') }}</th>
                            <th scope="col">{{ __('Biller') }}</th>
                            <th scope="col">{{ __('Customer number') }}</th>
                            <th scope="col">{{ __('Wallet debit') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col" class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($payments as $payment)
                            <tr>
                                <td class="text-nowrap">
                                    <span class="d-block">{{ $payment->created_at?->format('d M Y') }}</span>
                                    <span class="bpa-muted">{{ $payment->created_at?->format('H:i') }}</span>
                                </td>
                                <td>
                                    <div class="bpa-person">
                                        <a href="{{ route('admin.bill-payment.show', $payment) }}"
                                           class="bpa-ref"
                                           dir="ltr"
                                           title="{{ __('Open :reference', ['reference' => $payment->reference]) }}">{{ $payment->reference }}</a>
                                        <span class="bpa-muted" title="{{ $payment->user?->email }}">{{ $payment->user?->name ?? __('Removed user') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="bpa-biller">
                                        @if($payment->biller?->logo)
                                            <img class="bpa-biller__logo" src="{{ asset($payment->biller->logo) }}" alt="" width="36" height="36" loading="lazy">
                                        @else
                                            <span class="bpa-biller__logo bpa-biller__logo--icon" aria-hidden="true">
                                                <x-icon :name="$payment->biller?->category?->icon ?: 'bill-other'" width="18" height="18"/>
                                            </span>
                                        @endif
                                        <div class="bpa-biller__copy">
                                            <span class="bpa-biller__name">{{ $payment->biller_name }}</span>
                                            <span class="bpa-muted" dir="ltr">{{ $payment->country }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="bpa-muted d-block">{{ $payment->customer_number_label }}</span>
                                    <span class="bpa-mono" dir="ltr">{{ $payment->customer_number }}</span>
                                </td>
                                <td class="text-nowrap">
                                    <strong dir="ltr">{{ money($payment->total_amount, $payment->currency, false) }} {{ $payment->currency }}</strong>
                                    @if(\App\Support\DecimalMoney::compare((string) $payment->fee, '0') > 0)
                                        <span class="bpa-muted d-block" dir="ltr">{{ __('incl. :fee fee', ['fee' => money($payment->fee, $payment->currency, false)]) }}</span>
                                    @endif
                                    @if($payment->tax_amount !== null)
                                        <span class="bpa-muted d-block" dir="ltr">{{ __('incl. :tax tax', ['tax' => money($payment->tax_amount, $payment->currency, false)]) }}</span>
                                    @endif
                                </td>
                                <td>
                                    @include('backend.bill_payment.payments._status', ['payment' => $payment])
                                </td>
                                <td class="text-end">
                                    <div class="admin-table-actions">
                                        <a href="{{ route('admin.bill-payment.show', $payment) }}"
                                           class="btn btn-outline-primary btn-sm admin-table-action"
                                           title="{{ __('Open :reference', ['reference' => $payment->reference]) }}">
                                            <x-icon name="arrow-up-right" width="14" height="14"/>
                                            <span>{{ __('View') }}</span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <x-admin-not-found
                                        :title="$filtered ? __('No payments match your filter') : __('No bill payments yet')"
                                        :message="$filtered ? __('Try a different search or clear the filter.') : __('Payments appear here as soon as customers pay a bill.')"
                                        icon="fa-receipt"
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($payments->hasPages())
                <div class="mt-3">{{ $payments->links() }}</div>
            @endif
        </div>
    </div>
@endsection
