@extends('backend.bill_payment.layout')

@section('title', __('Bill payment queue'))

@push('styles')
    {{-- The mark-paid and mark-failed dialogs reuse the manual-request review modal's look. --}}
    <link rel="stylesheet" href="{{ asset('backend/css/transaction-admin.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/transaction-admin.css'))) }}">
@endpush

@section('sub_header')
    @php
        $oldestTone = match ($stats['oldestTone']) {
            \App\Services\Dashboard\DashboardSla::STATE_OVER => 'danger',
            \App\Services\Dashboard\DashboardSla::STATE_NEAR => 'warning',
            default                                          => 'neutral',
        };
    @endphp

    <x-page-header
        :title="__('Bill payment queue')"
        :subtitle="__('Bills customers have paid for, waiting to be paid at the biller. Oldest first.')"
        icon="receipt"
        :stats="[
            ['label' => __('Awaiting action'), 'value' => (int) $stats['awaiting'], 'tone' => $stats['awaiting'] > 0 ? 'warning' : 'neutral', 'icon' => 'request-1'],
            ['label' => __('Needs review'), 'value' => (int) $stats['review'], 'tone' => $stats['review'] > 0 ? 'danger' : 'neutral', 'icon' => 'warning'],
            ['label' => __('Oldest waiting'), 'value' => $stats['oldest'] ?? '—', 'tone' => $oldestTone, 'note' => __('Target :hours h', ['hours' => $targetHours]), 'icon' => 'clock'],
            ['label' => __('Paid today'), 'value' => (int) $stats['paidToday'], 'tone' => 'success', 'icon' => 'check'],
        ]"
    />
@endsection

@section('sub_content')
    @php
        $canAct   = auth('admin')->user()?->can('bill-payment-action') ?? false;
        $filtered = collect($filters)->filter(fn ($value) => $value !== '')->isNotEmpty();
    @endphp

    @include('backend.bill_payment.payments._errors')

    <div class="card border-0 mb-4 bpa-card">
        <div class="card-body">
            <div class="admin-table-filterbar d-flex mb-3">
                <form action="{{ route('admin.bill-payment.queue') }}" method="GET" class="admin-table-filters row g-2 g-md-3 align-items-center">
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="visually-hidden" for="bpa-queue-search">{{ __('Search payments') }}</label>
                        <input type="search" id="bpa-queue-search" name="search" value="{{ $filters['search'] }}"
                               class="form-control" placeholder="{{ __('Reference, transaction, number or email') }}">
                    </div>
                    @include('backend.bill_payment.payments._filter_selects', ['idPrefix' => 'bpa-queue'])
                    <div class="col-12 col-md-auto">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="mine" value="1" id="bpa-queue-mine" @checked($filters['mine'] === '1')>
                            <label class="form-check-label" for="bpa-queue-mine">{{ __('Claimed by me') }}</label>
                        </div>
                    </div>
                    <div class="col-12 col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            <span>{{ __('Filter') }}</span>
                        </button>
                        @if($filtered)
                            <a href="{{ route('admin.bill-payment.queue') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                        @endif
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0 bpa-queue">
                    <caption class="visually-hidden">{{ __('Bill payments awaiting action, oldest first') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Age') }}</th>
                            <th scope="col">{{ __('Payment') }}</th>
                            <th scope="col">{{ __('Biller') }}</th>
                            <th scope="col">{{ __('Customer number') }}</th>
                            <th scope="col">{{ __('Bill amount') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col" class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($payments as $payment)
                            @php
                                $row          = $rows[$payment->id];
                                $billDecimals = \App\Support\Money::decimalsIfKnown((string) $payment->bill_currency) ?? 8;
                            @endphp
                            <tr data-bpa-row="{{ $payment->uuid }}">
                                <td class="text-nowrap">
                                    <span class="bpa-age bpa-age--{{ $row['age'] }}" title="{{ $payment->created_at?->format('d M Y, H:i') }}">{{ $row['ageLabel'] }}</span>
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
                                    <span class="bpa-copyable">
                                        <span class="bpa-mono" dir="ltr">{{ $payment->customer_number }}</span>
                                        <button type="button" class="bpa-copy" data-bpa-copy="{{ $payment->customer_number }}"
                                                aria-label="{{ __('Copy the customer number of :reference', ['reference' => $payment->reference]) }}" title="{{ __('Copy') }}">
                                            <x-icon name="clipboard" width="14" height="14"/>
                                        </button>
                                    </span>
                                    @if($payment->item_name)
                                        <span class="bpa-muted d-block">{{ $payment->item_name }}</span>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    <span class="bpa-copyable">
                                        <strong dir="ltr">{{ money($payment->bill_amount, $payment->bill_currency, false) }} {{ $payment->bill_currency }}</strong>
                                        <button type="button" class="bpa-copy" data-bpa-copy="{{ \App\Support\DecimalMoney::normalize((string) $payment->bill_amount, $billDecimals) }}"
                                                aria-label="{{ __('Copy the amount of :reference', ['reference' => $payment->reference]) }}" title="{{ __('Copy') }}">
                                            <x-icon name="clipboard" width="14" height="14"/>
                                        </button>
                                    </span>
                                    <span class="bpa-muted d-block" dir="ltr">{{ __('Fee :fee', ['fee' => money($payment->fee, $payment->currency, false).' '.$payment->currency]) }}</span>
                                </td>
                                <td>
                                    @include('backend.bill_payment.payments._status', ['payment' => $payment])
                                    @include('backend.bill_payment.payments._claim', ['row' => $row, 'compact' => true])
                                </td>
                                <td class="text-end">
                                    <div class="admin-table-actions">
                                        @unless($canAct)
                                            <a href="{{ route('admin.bill-payment.show', $payment) }}"
                                               class="btn btn-outline-primary btn-sm admin-table-action"
                                               title="{{ __('Open :reference', ['reference' => $payment->reference]) }}">
                                                <x-icon name="arrow-up-right" width="14" height="14"/>
                                                <span>{{ __('View') }}</span>
                                            </a>
                                        @endunless

                                        @if($canAct)
                                            @if($row['claim'] !== 'mine')
                                                <form action="{{ route('admin.bill-payment.claim', $payment) }}"
                                                      method="POST"
                                                      class="admin-table-actions__form"
                                                      @if($row['claim'] === 'other') data-bpa-confirm="{{ __('Claimed by :name :ago. Take it over?', ['name' => $row['claimHolder'], 'ago' => $row['claimAgo']]) }}" @endif>
                                                    @csrf
                                                    @if($row['claim'] === 'other')
                                                        <input type="hidden" name="take_over" value="1">
                                                    @endif
                                                    <button type="submit"
                                                            class="btn btn-outline-primary btn-sm admin-table-action"
                                                            title="{{ __('Claim :reference', ['reference' => $payment->reference]) }}">
                                                        <x-icon name="user-security" width="14" height="14"/>
                                                        <span>{{ $row['claim'] === 'other' ? __('Take over') : __('Claim') }}</span>
                                                    </button>
                                                </form>
                                            @endif

                                            @if($row['canMarkPaid'])
                                                <button type="button"
                                                        class="btn btn-outline-success btn-sm admin-table-action admin-table-action--success"
                                                        data-bpa-open="mark-paid"
                                                        @include('backend.bill_payment.payments._trigger', ['payment' => $payment])
                                                        title="{{ __('Mark :reference paid', ['reference' => $payment->reference]) }}">
                                                    <x-icon name="check" width="14" height="14"/>
                                                    <span>{{ __('Mark paid') }}</span>
                                                </button>
                                            @endif

                                            @if($row['canMarkFailed'])
                                                <button type="button"
                                                        class="btn btn-outline-danger btn-sm admin-table-action admin-table-action--danger"
                                                        data-bpa-open="mark-failed"
                                                        @include('backend.bill_payment.payments._trigger', ['payment' => $payment])
                                                        title="{{ __('Mark :reference failed and refund it', ['reference' => $payment->reference]) }}">
                                                    <x-icon name="x-circle" width="14" height="14"/>
                                                    <span>{{ __('Mark failed') }}</span>
                                                </button>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <x-admin-not-found
                                        :title="$filtered ? __('No payments match your filter') : __('Nothing waiting')"
                                        :message="$filtered ? __('Try a different search or clear the filter.') : __('Every submitted bill has been paid or refunded.')"
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

    @if($canAct)
        @include('backend.bill_payment.payments._mark_paid_modal')
        @include('backend.bill_payment.payments._mark_failed_modal')
    @endif
@endsection
