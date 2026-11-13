@extends('backend.virtual_card.index')
@section('title', __('Virtual Card Fee Settings'))

@section('virtual_card_header')
    <x-page-header
        icon="fa-tags"
        :title="__('Virtual Card Fee Settings')"
        :subtitle="__('Configure thresholds, operation fees, min/max ranges, and provider currency rules.')"
        :stats="[['label' => __('Rules'), 'value' => $feeSettings->total()]]"
    >
        <x-slot:actions>
            <a href="#new_fee_setting_modal" data-coreui-toggle="modal" class="btn btn-primary">
                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                {{ __('Add New') }}
            </a>
        </x-slot:actions>
    </x-page-header>
@endsection

@section('virtual_card_content')
    <div class="card-body vc-admin-board">
        <div class="table-responsive vc-admin-table vc-admin-table--fees">
            <table class="table align-middle mb-0 vc-admin-table__table">
                <thead>
                <tr class="align-middle text-nowrap">
                    <th class="vc-admin-table__cell--provider">@lang('Provider') | @lang('Currency')</th>
                    <th class="vc-admin-table__cell--threshold">@lang('Threshold') | @lang('Fee Amount')</th>
                    <th class="vc-admin-table__cell--range">@lang('Min') | @lang('Max Amount')</th>
                    <th class="vc-admin-table__cell--status">@lang('Status')</th>
                    <th class="text-end admin-table-actions-heading">@lang('Actions')</th>
                </tr>
                </thead>
                <tbody>
                @forelse($feeSettings as $setting)
                    @php
                        $op = $setting->operation;
                        $siteSymbol = siteCurrency('symbol');
                    @endphp
                    <tr class="align-middle">
                        <td class="vc-admin-table__cell--provider">
                            <div class="fw-semibold">
                                {{ $setting->provider->name ?? '-' }}
                                <span class="vc-admin-chip ms-1">{{ $setting->currency->code ?? '-' }}</span>
                            </div>
                            <div class="text-muted small mt-1">
                                <span class="badge text-white text-bg-{{ $op?->cssClass() }}">
                                    <i class="{{ $op?->icon() }}"></i>
                                    {{ $op?->label() }}
                                </span>
                            </div>
                        </td>
                        <td class="vc-admin-table__cell--threshold">
                            <div class="fw-semibold">{{ $setting->approval_threshold ? money($setting->approval_threshold) : '-' }}</div>
                            <div class="small text-muted">{{ money($setting->fee_amount) }} + {{ feeRate($setting->fee_percent) }}%</div>
                        </td>
                        <td class="vc-admin-table__cell--range">
                            <div class="fw-semibold">{{ money($setting->min_amount) }}</div>
                            <div class="small text-muted">{{ $setting->max_amount ? money($setting->max_amount) : '-' }}</div>
                        </td>
                        <td class="vc-admin-table__cell--status">
                            <span class="badge bg-{{ $setting->active ? 'success' : 'secondary' }} text-uppercase">
                                {{ $setting->active ? __('Active') : __('Inactive') }}
                            </span>
                        </td>
                        <td class="text-end vc-admin-table__cell--actions admin-table-actions-cell">
                            <div class="admin-table-actions">
                                <a href="javascript:void(0)" data-edit-url="{{ route('admin.virtual-card.fee-settings.edit', $setting) }}" class="btn btn-outline-primary admin-table-action edit-modal">
                                    <x-icon name="edit" height="14" width="14"/> {{ __('Edit') }}
                                </a>
                                <a href="javascript:void(0)" class="btn btn-outline-danger admin-table-action admin-table-action--danger delete" data-url="{{ route('admin.virtual-card.fee-settings.destroy', $setting) }}">
                                    <x-icon name="delete-3" height="14" width="14"/> {{ __('Delete') }}
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <x-admin-not-found
                                :title="__('No fee settings found')"
                                :message="__('Create fee settings to control virtual card pricing rules.')"
                                icon="fa-credit-card"
                            />
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-end mt-3">
            {{ $feeSettings->withQueryString()->links() }}
        </div>
    </div>

    @include('backend.virtual_card.fee_settings.partials._new_fee_setting_modal')
    @include('backend.virtual_card.fee_settings.partials._edit_fee_setting_modal')
@endsection
