@extends('backend.layouts.app')
@section('title', __('Marketplace Vendors'))

@php
    use App\Enums\Marketplace\VendorStatus;

    $activeStatus = in_array(request('status'), ['pending', 'active', 'suspended'], true) ? request('status') : 'all';
    $segments     = [
        'all'       => ['label' => __('All'),       'count' => $statusCounts['all']],
        'pending'   => ['label' => __('Pending'),   'count' => $statusCounts['pending']],
        'active'    => ['label' => __('Active'),     'count' => $statusCounts['active']],
        'suspended' => ['label' => __('Suspended'), 'count' => $statusCounts['suspended']],
    ];
    $canManage = auth('admin')->user()?->can('marketplace-vendor-manage');
    $colspan   = $canManage ? 6 : 5;
@endphp

@section('content')
    <x-page-header
        :title="__('Marketplace Vendors')"
        :subtitle="__('Review applications, approve sellers, and suspend accounts that break the rules.')"
        icon="sidebar-vendor"
    >
        <x-slot:actions>
            <a href="{{ route('admin.marketplace.settings.edit') }}" class="ph-btn ph-btn--secondary">
                <i class="fa-solid fa-gear" aria-hidden="true"></i>{{ __('Marketplace settings') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="card border-0 mb-4">
        <div class="card-body">

            {{-- ── Status segments + search ── --}}
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                <ul class="nav nav-pills gap-1" role="tablist">
                    @foreach($segments as $key => $seg)
                        <li class="nav-item" role="presentation">
                            <a class="nav-link {{ $activeStatus === $key ? 'active' : '' }}"
                               href="{{ route('admin.marketplace.vendors.index', array_filter(['status' => $key === 'all' ? null : $key, 'search' => request('search')])) }}"
                               @if($activeStatus === $key) aria-current="page" @endif>
                                {{ $seg['label'] }}
                                <span class="badge rounded-pill {{ $activeStatus === $key ? 'bg-white text-primary' : 'bg-body-secondary text-muted' }} ms-1">{{ number_format($seg['count']) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                <form action="{{ route('admin.marketplace.vendors.index') }}" method="GET" class="d-flex" role="search">
                    @if($activeStatus !== 'all')<input type="hidden" name="status" value="{{ $activeStatus }}">@endif
                    <div class="input-group input-group-sm">
                        <input type="text" name="search" value="{{ request('search') }}" class="form-control"
                               placeholder="{{ __('Vendor or email...') }}" aria-label="{{ __('Search vendors') }}" style="min-width: 200px;">
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i></button>
                        @if(request('search'))
                            <a href="{{ route('admin.marketplace.vendors.index', array_filter(['status' => $activeStatus === 'all' ? null : $activeStatus])) }}"
                               class="btn btn-light" title="{{ __('Clear') }}"><i class="fa-solid fa-xmark"></i></a>
                        @endif
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table align-middle border mb-0">
                    <thead class="table-light fw-semibold">
                    <tr class="text-nowrap">
                        <th>{{ __('Vendor') }}</th>
                        <th>{{ __('Owner') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="text-end">{{ __('Sales') }}</th>
                        <th>{{ __('Joined') }}</th>
                        @if($canManage)<th class="text-end">{{ __('Actions') }}</th>@endif
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($vendors as $vendor)
                        @php $avatar = getUserAvatarDetails($vendor->display_name); @endphp
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="avatar avatar-md {{ $avatar['class'] }} text-white">{{ $avatar['initials'] }}</div>
                                    <div>
                                        <div class="fw-semibold">{{ $vendor->display_name }}</div>
                                        <div class="small text-muted">{{ $vendor->categoryLabel() }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div>{{ $vendor->user?->name }}</div>
                                <div class="small text-muted">{{ $vendor->user?->email }}</div>
                            </td>
                            <td>
                                <span class="{{ $vendor->status->badgeClass() }}">{{ $vendor->status->label() }}</span>
                                @if($vendor->status === VendorStatus::SUSPENDED && $vendor->suspend_reason)
                                    <i class="fa-solid fa-circle-info text-muted ms-1" role="img"
                                       data-coreui-toggle="tooltip" data-coreui-placement="top"
                                       title="{{ $vendor->suspend_reason }}"
                                       aria-label="{{ __('Suspension reason: :reason', ['reason' => $vendor->suspend_reason]) }}"></i>
                                @endif
                            </td>
                            <td class="text-end fw-semibold">{{ number_format((int) $vendor->completed_sales_count) }}</td>
                            <td>
                                <div>{{ $vendor->created_at?->format('M d, Y') }}</div>
                                <div class="small text-muted">{{ $vendor->created_at?->diffForHumans() }}</div>
                            </td>
                            @if($canManage)
                                <td class="text-end text-nowrap">
                                    @if($vendor->status !== VendorStatus::ACTIVE)
                                        <button type="button" class="btn btn-sm btn-success text-white js-vendor-approve"
                                                data-url="{{ route('admin.marketplace.vendors.approve', $vendor) }}"
                                                data-vendor="{{ $vendor->display_name }}">
                                            <i class="fa-solid fa-check me-1"></i> {{ __('Approve') }}
                                        </button>
                                    @endif
                                    @if($vendor->status !== VendorStatus::SUSPENDED)
                                        <button type="button" class="btn btn-sm btn-outline-danger js-vendor-suspend"
                                                data-url="{{ route('admin.marketplace.vendors.suspend', $vendor) }}"
                                                data-vendor="{{ $vendor->display_name }}">
                                            <i class="fa-solid fa-ban me-1"></i> {{ __('Suspend') }}
                                        </button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $colspan }}">
                                <x-admin-not-found
                                    :title="request('search') ? __('No vendors match your search') : __('No vendors found')"
                                    :message="request('search') ? __('Try a different name or email.') : __('Vendor applications will appear here once users apply.')"
                                    icon="fa-store"
                                />
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if($vendors->hasPages())
                <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                    <span class="small text-muted">{{ __('Showing :n of :t vendors', ['n' => $vendors->count(), 't' => $vendors->total()]) }}</span>
                    {{ $vendors->links() }}
                </div>
            @endif
        </div>
    </div>

    @if($canManage)
        {{-- ── Suspend confirmation (with required reason) ── --}}
        <div class="modal fade" id="vendorSuspendModal" tabindex="-1" aria-labelledby="vendorSuspendLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="" id="vendorSuspendForm">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title" id="vendorSuspendLabel">
                                <i class="fa-solid fa-triangle-exclamation text-danger me-1"></i> {{ __('Suspend vendor') }}
                            </h5>
                            <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-3">
                                {{ __('This will immediately stop') }} <strong data-suspend-vendor-name></strong>
                                {{ __('from receiving any payments. Their storefront disappears until you reactivate them.') }}
                            </p>
                            <label for="suspend_reason" class="form-label">{{ __('Reason') }} <span class="text-danger">*</span></label>
                            <textarea name="reason" id="suspend_reason" rows="3" maxlength="500" required
                                      class="form-control"
                                      placeholder="{{ __('e.g. Repeated fraudulent orders reported by buyers.') }}"></textarea>
                            <div class="form-text">{{ __('Shared with the vendor and kept on record.') }}</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
                            <button type="submit" class="btn btn-danger text-white">
                                <i class="fa-solid fa-ban me-1"></i> {{ __('Suspend vendor') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- ── Approve confirmation ── --}}
        <div class="modal fade" id="vendorApproveModal" tabindex="-1" aria-labelledby="vendorApproveLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="" id="vendorApproveForm">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title" id="vendorApproveLabel">
                                <i class="fa-solid fa-circle-check text-success me-1"></i> {{ __('Approve vendor') }}
                            </h5>
                            <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-0">
                                {{ __('Activate') }} <strong data-approve-vendor-name></strong>?
                                {{ __('Buyers will be able to find and pay this business right away.') }}
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
                            <button type="submit" class="btn btn-success text-white">
                                <i class="fa-solid fa-check me-1"></i> {{ __('Approve vendor') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        "use strict";
        (function ($) {
            $(document).on('click', '.js-vendor-suspend', function () {
                var $btn = $(this);
                $('#vendorSuspendForm').attr('action', $btn.data('url'));
                $('#vendorSuspendForm')[0].reset();
                $('[data-suspend-vendor-name]').text($btn.data('vendor'));
                $('#vendorSuspendModal').modal('show');
            });

            $(document).on('click', '.js-vendor-approve', function () {
                var $btn = $(this);
                $('#vendorApproveForm').attr('action', $btn.data('url'));
                $('[data-approve-vendor-name]').text($btn.data('vendor'));
                $('#vendorApproveModal').modal('show');
            });
        })(jQuery);
    </script>
@endpush
