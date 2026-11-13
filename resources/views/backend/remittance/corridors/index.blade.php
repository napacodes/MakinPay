@extends('backend.layouts.app')
@section('title', __('Remittance Corridors'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/remittance.css?v=' . filemtime(public_path('backend/css/remittance.css'))) }}">
@endpush

@section('content')
    @php
        /*
         * Status registry for corridors. Active / Disabled only,
         * but kept in the same shape as the gift-card admin status
         * map so the .rmt-admin-pill component stays consistent.
         */
        $statusFilterOptions = [
            'active'   => ['label' => __('Active'),   'cls' => 'success'],
            'disabled' => ['label' => __('Disabled'), 'cls' => 'secondary'],
        ];

        $siteCurrencySymbol = siteCurrency('symbol') ?? '$';
    @endphp

    <div class="remittance-admin">

        {{--
            The hero's decorative world map and the four KPI cards below it
            are both gone: the figures are the header's strip, and a dotted
            map behind a page title is exactly the decoration the header
            contract rules out.
        --}}
        <x-page-header
            :title="__('Corridors')"
            :subtitle="__('Manage country pairs, FX margin, fees, and payout gateways for cross-border transfers.')"
            icon="exchange"
            :status="$stats['active'] > 0 ? ['tone' => 'success', 'label' => __(':count Active', ['count' => number_format($stats['active'])])] : null"
            :stats="[
                ['label' => __('Total Corridors'), 'value' => (int) $stats['total'], 'icon' => 'layer'],
                ['label' => __('Active'), 'value' => (int) $stats['active'], 'tone' => 'success', 'icon' => 'check'],
                ['label' => __('Transfers'), 'value' => (int) $stats['transfers'], 'tone' => 'brand', 'icon' => 'withdraw-1'],
                ['label' => __('Completed Volume'), 'value' => money($stats['completed_value']), 'icon' => 'money'],
            ]"
        >
            @can('remittance-corridor-manage')
                <x-slot:actions>
                    <a href="{{ route('admin.remittance.corridors.create') }}" class="ph-btn ph-btn--primary">
                        <x-icon name="plus" width="14" height="14"/>
                        {{ __('Add Corridor') }}
                    </a>
                </x-slot:actions>
            @endcan
        </x-page-header>

        {{-- Filter + table --}}
        <div class="card border-0 mb-4 remittance-admin__card">
            <div class="card-body">
                <form method="GET" class="remittance-admin__filter mb-3">
                    <div class="remittance-admin__filter-search">
                        <i class="fa-solid fa-magnifying-glass remittance-admin__filter-search-icon" aria-hidden="true"></i>
                        <input type="text"
                               name="q"
                               value="{{ $search }}"
                               class="form-control remittance-admin__filter-input"
                               placeholder="{{ __('Search by name, country, currency, or gateway') }}">
                    </div>
                    <select name="status" class="form-select remittance-admin__filter-select">
                        <option value="">{{ __('All statuses') }}</option>
                        @foreach ($statusFilterOptions as $key => $meta)
                            <option value="{{ $key }}" {{ $statusFilter === $key ? 'selected' : '' }}>{{ $meta['label'] }}</option>
                        @endforeach
                    </select>
                    <button class="btn btn-primary remittance-admin__filter-submit" type="submit">
                        <x-icon name="search" width="16" height="16"/>
                        <span>{{ __('Filter') }}</span>
                    </button>
                </form>

                <div class="table-responsive">
                    <table class="table align-middle mb-0 rmt-admin-table">
                        <thead>
                            <tr>
                                <th>{{ __('Corridor') }}</th>
                                <th>{{ __('Gateway') }}</th>
                                <th>{{ __('FX Spread') }}</th>
                                <th>{{ __('Fee') }}</th>
                                <th>{{ __('Limits') }}</th>
                                <th>{{ __('Transfers') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th class="text-end">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($corridors as $corridor)
                                @php
                                    $statusMeta = $corridor->status
                                        ? ['label' => __('Active'),   'cls' => 'success']
                                        : ['label' => __('Disabled'), 'cls' => 'secondary'];
                                @endphp
                                <tr>
                                    <td>
                                        <div class="rmt-admin-corridor">
                                            <span class="rmt-admin-corridor__name">{{ $corridor->name }}</span>
                                            <span class="rmt-admin-corridor__pair" aria-label="{{ $corridor->source_country }} to {{ $corridor->destination_country }}">
                                                <span class="rmt-admin-corridor__code rmt-admin-corridor__code--from">{{ $corridor->source_country }}</span>
                                                <span class="rmt-admin-corridor__arrow" aria-hidden="true">
                                                    <span class="rmt-admin-corridor__arrow-line"></span>
                                                    <i class="fa-solid fa-caret-right"></i>
                                                </span>
                                                <span class="rmt-admin-corridor__code rmt-admin-corridor__code--to">{{ $corridor->destination_country }}</span>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="rmt-admin-gateway">
                                            <i class="fa-solid fa-plug rmt-admin-gateway__icon" aria-hidden="true"></i>
                                            {{ $corridor->payout_gateway }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="rmt-admin-stat">
                                            {{ feeRate($corridor->fx_spread_percent) }}<span class="rmt-admin-stat__unit">%</span>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="rmt-admin-fee">
                                            <span class="rmt-admin-fee__main">{{ money($corridor->fixed_fee, $corridor->source_currency ?? null, false) }}</span>
                                            <span class="rmt-admin-fee__meta">+ {{ feeRate($corridor->percent_fee) }}%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="rmt-admin-limits">
                                            <span class="rmt-admin-limits__range">{{ money($corridor->min_amount, $corridor->source_currency ?? null, false) }} – {{ money($corridor->max_amount, $corridor->source_currency ?? null, false) }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="rmt-admin-count">{{ number_format($corridor->transfers_count) }}</span>
                                    </td>
                                    <td>
                                        <span class="rmt-admin-pill rmt-admin-pill--{{ $statusMeta['cls'] }}">
                                            <span class="rmt-admin-pill__dot" aria-hidden="true"></span>
                                            {{ $statusMeta['label'] }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="rmt-admin-actions">
                                            @can('remittance-corridor-manage')
                                                <a href="{{ route('admin.remittance.corridors.edit', $corridor) }}"
                                                   class="btn btn-outline-primary btn-sm rmt-admin-btn"
                                                   title="{{ __('Edit corridor') }}">
                                                    <x-icon name="edit" width="14" height="14"/>
                                                    <span>{{ __('Edit') }}</span>
                                                </a>

                                                <form action="{{ route('admin.remittance.corridors.destroy', $corridor) }}"
                                                      method="POST"
                                                      class="rmt-admin-actions__form">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            class="btn btn-outline-danger btn-sm rmt-admin-btn"
                                                            onclick="return confirm('{{ __('Delete this corridor?') }}')"
                                                            title="{{ __('Delete corridor') }}">
                                                        <x-icon name="trash" width="14" height="14"/>
                                                        <span>{{ __('Delete') }}</span>
                                                    </button>
                                                </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8">
                                        <x-admin-not-found
                                            :title="$search || $statusFilter ? __('No corridors match your filter') : __('No corridors yet')"
                                            :message="$search || $statusFilter ? __('Try a different search term or clear the filter.') : __('Add your first corridor to start accepting international transfers.')"
                                            icon="fa-globe"
                                        />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($corridors->hasPages())
                    <div class="mt-3">
                        {{ $corridors->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>

@endsection
