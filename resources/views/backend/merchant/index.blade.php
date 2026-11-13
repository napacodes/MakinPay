@php use App\Enums\MerchantStatus; @endphp
@extends('backend.layouts.app')
@section('title', $title)

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/merchant-admin.css?v='.config('app.version').'-'.filemtime(public_path('backend/css/merchant-admin.css'))) }}">
@endpush

@section('content')
    @php
        /*
         * The Disabled slice has no route of its own; MerchantStatus is already
         * a supported value of the existing ?status filter, so the tab points at
         * the list with that filter rather than asking for a fifth endpoint.
         */
        $requestedStatus = request('status');
        $activeScope     = $scope !== 'all'
            ? $scope
            : (in_array($requestedStatus, MerchantStatus::all(), true) ? $requestedStatus : 'all');

        $tabs = [
            ['key' => 'all', 'label' => __('All'), 'url' => route('admin.merchant.index'), 'count' => $counts['all']],
            ['key' => MerchantStatus::PENDING->value, 'label' => __('Pending'), 'url' => route('admin.merchant.pending'), 'count' => $counts[MerchantStatus::PENDING->value], 'warn' => true],
            ['key' => MerchantStatus::APPROVED->value, 'label' => __('Approved'), 'url' => route('admin.merchant.approved'), 'count' => $counts[MerchantStatus::APPROVED->value]],
            ['key' => MerchantStatus::DISABLED->value, 'label' => __('Disabled'), 'url' => route('admin.merchant.index', ['status' => MerchantStatus::DISABLED->value]), 'count' => $counts[MerchantStatus::DISABLED->value]],
            ['key' => MerchantStatus::REJECTED->value, 'label' => __('Rejected'), 'url' => route('admin.merchant.rejected'), 'count' => $counts[MerchantStatus::REJECTED->value]],
        ];

        $headerStats = [
            [
                'label' => __('Pending review'),
                'value' => number_format($stats['pending']),
                'delta' => $stats['oldest_wait'],
                'tone'  => $stats['pending'] > 0 ? 'warning' : 'success',
                'note'  => $stats['pending'] > 0 ? __('longest wait in the queue') : __('nothing waiting'),
                'icon'  => 'kpi-store-queue',
            ],
            [
                'label' => __('Live merchants'),
                'value' => number_format($stats['live']),
                'tone'  => 'success',
                'note'  => __(':percent% of all profiles', ['percent' => $stats['live_share']]),
                'icon'  => 'kpi-storefront',
            ],
            [
                'label' => __('Still in sandbox'),
                'value' => number_format($stats['sandbox']),
                'tone'  => 'brand',
                'note'  => __('approved, not switched to production'),
                'icon'  => 'api',
            ],
            [
                'label' => __('Average fee'),
                'value' => $stats['avg_fee'] !== null ? feeRate($stats['avg_fee']).'%' : '—',
                'tone'  => 'neutral',
                'note'  => $stats['avg_fee_fixed'] !== null
                    ? __('+ :amount :currency fixed, live merchants', [
                        'amount'   => money($stats['avg_fee_fixed'], null, false),
                        'currency' => siteCurrency(),
                    ])
                    : __('live merchants'),
                'icon'  => 'kpi-fees',
            ],
        ];
    @endphp

    <x-page-header
        :title="$title"
        icon="merchant"
        :eyebrow="__('Business profiles')"
        :subtitle="__('Every business accepting payments through the platform, and the fee each one is charged.')"
        :status="$stats['pending'] > 0
            ? ['tone' => 'warning', 'label' => __(':count awaiting review', ['count' => $stats['pending']])]
            : ['tone' => 'success', 'label' => __('Queue clear')]"
        :stats="$headerStats"
    />

    <nav class="ph-tabs" aria-label="{{ __('Merchant status') }}">
        @foreach($tabs as $tab)
            <a href="{{ $tab['url'] }}"
               class="ph-tab"
               @if($activeScope === $tab['key']) aria-current="page" @endif>
                {{ $tab['label'] }}
                <span class="ph-tab__count{{ ($tab['warn'] ?? false) && $tab['count'] > 0 ? ' ph-tab__count--warning' : '' }}">{{ number_format($tab['count']) }}</span>
            </a>
        @endforeach
    </nav>

    <div class="card border-0 mb-4">
        <div class="card-body">

            @include('backend.merchant.partials._filter')

            <div class="table-responsive">
                <table class="table caption-top mb-0">
                    <caption class="visually-hidden">{{ __('Merchant business profiles') }}</caption>
                    <thead class="table-light fw-semibold text-nowrap">
                    <tr class="align-middle">
                        <th scope="col">{{ __('Business') }}</th>
                        <th scope="col">{{ __('Owner') }}</th>
                        <th scope="col">{{ __('Fee') }}</th>
                        <th scope="col">{{ __('Environment') }}</th>
                        <th scope="col">{{ __('Status') }}</th>
                        <th scope="col">{{ __('Requested') }}</th>
                        <th scope="col">{{ __('Action') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($merchants as $merchant)
                        @php
                            $isPending = $merchant->status === MerchantStatus::PENDING;
                            $feeIsSet  = (float) $merchant->fee > 0 || (float) $merchant->fee_fixed > 0;
                            $currencyCount = max($merchant->supportedCurrencies->count(), 1);

                            $bounds = [
                                $merchant->fee_min !== null
                                    ? __('floor :value', ['value' => money($merchant->fee_min, null, false)])
                                    : __('no floor'),
                                $merchant->fee_max !== null
                                    ? __('cap :value', ['value' => money($merchant->fee_max, null, false)])
                                    : __('no cap'),
                                trans_choice('{1} :count currency|[2,*] :count currencies', $currencyCount, ['count' => $currencyCount]),
                            ];
                        @endphp
                        <tr class="align-middle{{ $isPending ? ' ma-row--pending' : '' }}">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img class="ma-logo" width="38" height="38"
                                         src="{{ asset($merchant->business_logo) }}"
                                         alt="" loading="lazy">
                                    <div class="ma-cell">
                                        <div class="ma-business">{{ $merchant->business_name }}</div>
                                        <div class="ma-business__sub">
                                            @if($merchant->site_url)
                                                <a href="{{ safeUrl($merchant->site_url) }}" target="_blank" rel="noopener">{{ $merchant->site_url }}</a>
                                            @endif
                                            <span class="ma-key" dir="ltr">{{ $merchant->merchant_key }}</span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if($merchant->user)
                                    <a href="{{ route('admin.user.manage', $merchant->user->username) }}" class="ma-owner">{{ $merchant->user->name }}</a>
                                    <div class="ma-muted">{{ '@'.$merchant->user->username }}</div>
                                @else
                                    <span class="ma-muted">{{ __('Owner account deleted') }}</span>
                                @endif
                            </td>
                            <td>
                                @if($isPending && ! $feeIsSet)
                                    <span class="ma-fee--unset">{{ __('Not set — decided on approval') }}</span>
                                @else
                                    <div class="ma-fee">{{ feeRate($merchant->fee) }}% + {{ money($merchant->fee_fixed, null, false) }} {{ siteCurrency() }}</div>
                                    <div class="ma-fee__bounds">{{ implode(' · ', $bounds) }}</div>
                                @endif
                            </td>
                            <td>
                                @if($merchant->isSandboxMode())
                                    <span class="ma-env ma-env--sandbox">{{ __('Sandbox') }}</span>
                                @elseif($merchant->isProductionMode())
                                    <span class="ma-env ma-env--production">{{ __('Production') }}</span>
                                @else
                                    <span class="ma-muted">{{ __('—') }}</span>
                                @endif
                            </td>
                            <td class="text-nowrap">
                                <span class="ma-pill ma-pill--{{ $merchant->status->color() }}">
                                    <span class="ma-pill__dot" aria-hidden="true"></span>{{ $merchant->status->label() }}
                                </span>
                            </td>
                            <td>
                                <div class="ma-date">{{ $merchant->created_at->format('Y-m-d') }}</div>
                                <div class="{{ $isPending ? 'ma-wait' : 'ma-muted' }}">
                                    @if($isPending)
                                        {{ __('waiting :age', ['age' => $merchant->created_at->diffForHumans(null, true)]) }}
                                    @else
                                        {{ $merchant->created_at->diffForHumans() }}
                                    @endif
                                </div>
                            </td>
                            <td>
                                @php
                                    $btnText = $isPending ? __('Review') : __('Manage');
                                    $btnIcon = $isPending ? 'fa-clipboard-check' : 'fa-gear';
                                @endphp
                                {{-- One way in. The decision happens on its own
                                     page, never in a modal rendered once per row. --}}
                                <a href="{{ route('admin.merchant.review', $merchant) }}"
                                   class="btn {{ $isPending ? 'btn-primary' : 'btn-outline-secondary' }} d-inline-flex align-items-center gap-2 text-nowrap"
                                   title="{{ $btnText }}">
                                    <i class="fa-solid {{ $btnIcon }}"></i>
                                    <span class="d-none d-sm-inline">{{ $btnText }}</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-admin-not-found
                                    :title="__('No merchants found')"
                                    :message="__('No merchant requests or profiles match the current filters.')"
                                    icon="fa-store"
                                />
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-end mt-3">
                {{ $merchants->links() }}
            </div>

        </div>
    </div>
@endsection
