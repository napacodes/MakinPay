@extends('backend.layouts.app')

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/feature-management.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/feature-management.css'))) }}">
    <link rel="stylesheet" href="{{ asset('backend/css/bill-payment-admin.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/bill-payment-admin.css'))) }}">
@endpush

@push('scripts')
    <script src="{{ asset('backend/js/bill-payment-admin.js?v=' . config('app.version') . '-' . filemtime(public_path('backend/js/bill-payment-admin.js'))) }}"></script>
@endpush

@section('content')
    @php
        $bpaAdmin = auth('admin')->user();

        /*
         * Each section is a tab only for an admin allowed to open it, with
         * the same permission the sidebar row uses.
         */
        $bpaTabs = array_values(array_filter([
            [
                'label'      => __('Queue'),
                'route'      => 'admin.bill-payment.queue',
                'pattern'    => 'admin.bill-payment.queue',
                'permission' => 'bill-payment-list',
                'count'      => true,
            ],
            [
                'label'      => __('All payments'),
                'route'      => 'admin.bill-payment.index',
                'pattern'    => ['admin.bill-payment.index', 'admin.bill-payment.show'],
                'permission' => 'bill-payment-list',
            ],
            [
                'label'      => __('Billers'),
                'route'      => 'admin.bill-payment.billers.index',
                'pattern'    => 'admin.bill-payment.billers.*',
                'permission' => 'bill-payment-list',
            ],
            [
                'label'      => __('Categories'),
                'route'      => 'admin.bill-payment.categories.index',
                'pattern'    => 'admin.bill-payment.categories.*',
                'permission' => 'bill-payment-list',
            ],
            [
                'label'      => __('Providers'),
                'route'      => 'admin.bill-payment.providers.index',
                'pattern'    => 'admin.bill-payment.providers.*',
                'permission' => 'bill-payment-manage',
            ],
        ], fn (array $tab): bool => $bpaAdmin !== null && $bpaAdmin->can($tab['permission'])));

        /* The queue tab counts the same rows the queue page, the dashboard and the sidebar badge do. */
        $bpaQueueCount = collect($bpaTabs)->contains(fn (array $tab): bool => $tab['count'] ?? false)
            ? \App\Services\Dashboard\DashboardQueueService::pendingScope('bill_payment')->count()
            : 0;

        $bpaModuleEnabled = app(\App\Services\FeatureManager::class)->isEnabled('bill_payment');
    @endphp

    <div class="bpa-admin">
        {{-- A page that needs more than title, subtitle, icon and action
             (stats, a back link, a status) renders its own <x-page-header>
             in sub_header and the default one steps aside. --}}
        @hasSection('sub_header')
            @yield('sub_header')
        @else
            <x-page-header
                :title="trim($__env->yieldContent('sub_title'))"
                :subtitle="trim($__env->yieldContent('sub_subtitle'))"
                icon="receipt"
            >
                @hasSection('sub_action')
                    <x-slot:actions>
                        @yield('sub_action')
                    </x-slot:actions>
                @endif
            </x-page-header>
        @endif

        @if(count($bpaTabs) > 1)
            <nav class="ph-tabs" aria-label="{{ __('Bill payment sections') }}">
                @foreach($bpaTabs as $tab)
                    <a href="{{ route($tab['route']) }}"
                       class="ph-tab"
                       @if(request()->routeIs($tab['pattern'])) aria-current="page" @endif>
                        {{ $tab['label'] }}
                        @if($tab['count'] ?? false)
                            <span class="ph-tab__count{{ $bpaQueueCount > 0 ? ' ph-tab__count--warning' : '' }}">{{ number_format($bpaQueueCount) }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>
        @endif

        {{--
            In the body rather than the header's status slot, which the
            payment detail page needs for the payment's own status. The
            screens stay open while the module is off: billers are set up
            before launch, and pending money must stay reachable.
        --}}
        @unless($bpaModuleEnabled)
            <div class="bpa-notice" role="status">
                <span class="bpa-notice__icon" aria-hidden="true"><x-icon name="info" width="18" height="18"/></span>
                <p class="bpa-notice__copy">
                    <strong>{{ __("Customers can't see Bill payments yet.") }}</strong>
                    <span>{{ __('Set up billers here first, then switch the module on in Feature controls.') }}</span>
                </p>
                <a href="{{ route('admin.features.index') }}" class="bpa-notice__link">{{ __('Open Feature controls') }}</a>
            </div>
        @endunless

        @yield('sub_content')
    </div>
@endsection
