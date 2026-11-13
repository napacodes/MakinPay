@php use App\Enums\MerchantStatus; use Illuminate\Support\Str; @endphp
@extends('backend.layouts.app')
@section('title', $title)

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/merchant-admin.css?v='.config('app.version').'-'.filemtime(public_path('backend/css/merchant-admin.css'))) }}">
@endpush

@section('content')
    @php
        $tabs = [
            ['key' => 'all', 'label' => __('All'), 'url' => route('admin.merchant.index'), 'count' => $counts['all']],
            ['key' => MerchantStatus::PENDING->value, 'label' => __('Pending'), 'url' => route('admin.merchant.pending'), 'count' => $counts[MerchantStatus::PENDING->value], 'warn' => true],
            ['key' => MerchantStatus::APPROVED->value, 'label' => __('Approved'), 'url' => route('admin.merchant.approved'), 'count' => $counts[MerchantStatus::APPROVED->value]],
            ['key' => MerchantStatus::DISABLED->value, 'label' => __('Disabled'), 'url' => route('admin.merchant.index', ['status' => MerchantStatus::DISABLED->value]), 'count' => $counts[MerchantStatus::DISABLED->value]],
            ['key' => MerchantStatus::REJECTED->value, 'label' => __('Rejected'), 'url' => route('admin.merchant.rejected'), 'count' => $counts[MerchantStatus::REJECTED->value]],
        ];

        $queueStats = [
            [
                'label' => __('Waiting now'),
                'value' => number_format($queue['waiting']),
                'tone'  => $queue['waiting'] > 0 ? 'warning' : 'success',
                'note'  => __('requests in the queue'),
                'icon'  => 'kpi-store-queue',
            ],
            [
                'label' => __('Over :hours hours', ['hours' => $slaHours]),
                'value' => number_format($queue['breaching']),
                'tone'  => $queue['breaching'] > 0 ? 'danger' : 'success',
                'note'  => $queue['breaching'] > 0 ? __('decide these first') : __('nothing is late'),
                'icon'  => 'shield-ban',
            ],
            [
                'label' => __('Oldest request'),
                'value' => $queue['oldest_wait'] ?? '—',
                'tone'  => 'neutral',
                'note'  => __('longest anyone has waited'),
                'icon'  => 'clock',
            ],
            [
                'label' => __('No description'),
                'value' => number_format($queue['no_description']),
                'tone'  => $queue['no_description'] > 0 ? 'warning' : 'neutral',
                'note'  => __('nothing written to review'),
                'icon'  => 'file',
            ],
        ];
    @endphp

    <x-page-header
        :title="$title"
        icon="merchant-alert"
        :eyebrow="__('Business profiles')"
        :subtitle="__('Approving a request also sets what the merchant is charged, so every decision goes through review.')"
        :back="route('admin.merchant.index')"
        :back-label="__('Back to Merchants')"
        :status="$queue['waiting'] > 0
            ? ['tone' => 'warning', 'label' => __(':count waiting', ['count' => $queue['waiting']])]
            : ['tone' => 'success', 'label' => __('Queue clear')]"
        :stats="$queueStats"
    />

    <nav class="ph-tabs" aria-label="{{ __('Merchant status') }}">
        @foreach($tabs as $tab)
            <a href="{{ $tab['url'] }}"
               class="ph-tab"
               @if($tab['key'] === $scope) aria-current="page" @endif>
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
                    <caption class="visually-hidden">{{ __('Merchant requests awaiting a decision, oldest first') }}</caption>
                    <thead class="table-light fw-semibold text-nowrap">
                    <tr class="align-middle">
                        <th scope="col">{{ __('Business') }}</th>
                        <th scope="col">{{ __('Owner') }}</th>
                        <th scope="col">{{ __('What they say they do') }}</th>
                        <th scope="col">{{ __('Waiting') }}</th>
                        <th scope="col">{{ __('Decision') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($merchants as $merchant)
                        @php
                            /*
                             * Three bands, and the text always states the wait as
                             * well — colour is never the only thing carrying it.
                             */
                            $hoursWaited   = $merchant->created_at->diffInHours(now());
                            $waitTone      = $hoursWaited < 24 ? 'ok' : ($hoursWaited < $slaHours ? 'warn' : 'late');
                            $description   = trim((string) $merchant->business_description);
                            $currencyCount = max($merchant->supportedCurrencies->count(), 1);
                        @endphp
                        <tr>
                            <td class="align-top">
                                <div class="d-flex align-items-start gap-2">
                                    <img class="ma-logo" width="40" height="40"
                                         src="{{ asset($merchant->business_logo) }}"
                                         alt="" loading="lazy">
                                    <div class="ma-cell">
                                        <div class="ma-business">{{ $merchant->business_name }}</div>
                                        @if($merchant->site_url)
                                            <div class="ma-business__sub">
                                                <a href="{{ safeUrl($merchant->site_url) }}" target="_blank" rel="noopener">{{ $merchant->site_url }}</a>
                                            </div>
                                        @endif
                                        <div class="ma-business__sub">
                                            <span class="ma-key" dir="ltr">{{ $merchant->merchant_key }}</span>
                                            <span class="ma-chip">{{ trans_choice('{1} :count currency|[2,*] :count currencies', $currencyCount, ['count' => $currencyCount]) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="align-top">
                                @if($merchant->user)
                                    <a href="{{ route('admin.user.manage', $merchant->user->username) }}" class="ma-owner">{{ $merchant->user->name }}</a>
                                @else
                                    <span class="ma-muted">{{ __('Owner account deleted') }}</span>
                                @endif
                                <div class="ma-muted ma-break">{{ $merchant->business_email ?: $merchant->user?->email }}</div>
                            </td>
                            <td class="align-top">
                                @if($description === '')
                                    <p class="ma-fee--unset mb-0">{{ __('No description provided.') }}</p>
                                    <span class="ma-chip ma-chip--warning">{{ __('Ask the owner before deciding') }}</span>
                                @else
                                    <p class="ma-said mb-0">{{ Str::limit($description, 200) }}</p>
                                @endif
                            </td>
                            <td class="align-top">
                                <span class="ma-wait-chip ma-wait-chip--{{ $waitTone }}">
                                    <span class="ma-pill__dot" aria-hidden="true"></span>{{ $merchant->created_at->diffForHumans(null, true) }}
                                </span>
                                <div class="ma-muted mt-1 text-nowrap">{{ __('since :when', ['when' => $merchant->created_at->format('Y-m-d H:i')]) }}</div>
                            </td>
                            <td class="align-top">
                                {{-- One way in. The decision happens on its own
                                     page, never in a modal rendered once per row. --}}
                                <a href="{{ route('admin.merchant.review', $merchant) }}"
                                   class="btn btn-primary d-inline-flex align-items-center gap-2 text-nowrap"
                                   title="{{ __('Review request') }}">
                                    <i class="fa-solid fa-clipboard-check"></i>
                                    <span class="d-none d-sm-inline">{{ __('Review') }}</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-admin-not-found
                                    :title="__('Nothing waiting')"
                                    :message="__('Every merchant request has been decided. New ones land here and appear in the sidebar count.')"
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
