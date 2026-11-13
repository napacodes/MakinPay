@extends('backend.layouts.app')

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/mass-payment-admin.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/mass-payment-admin.css'))) }}">
@endpush

@push('scripts')
    <script src="{{ asset('backend/js/mass-payment-admin.js?v=' . config('app.version') . '-' . filemtime(public_path('backend/js/mass-payment-admin.js'))) }}"></script>
@endpush

@section('content')
    @php
        $mpaModuleEnabled = app(\App\Services\FeatureManager::class)->isEnabled('mass_payment');
    @endphp

    <div class="mpa-admin">
        {{-- A page that needs more than a title and subtitle (stats, a back
             link, a status) renders its own <x-page-header> in sub_header
             and the default one steps aside. --}}
        @hasSection('sub_header')
            @yield('sub_header')
        @else
            <x-page-header
                :title="trim($__env->yieldContent('sub_title'))"
                :subtitle="trim($__env->yieldContent('sub_subtitle'))"
                icon="people"
            />
        @endif

        {{--
            In the body rather than the header's status slot, which the batch
            detail page needs for the batch's own status. The screens stay
            open while the module is off: money reserved for a confirmed
            batch must stay reachable.
        --}}
        @unless($mpaModuleEnabled)
            <div class="mpa-notice" role="status" data-mpa-module-off>
                <span class="mpa-notice__icon" aria-hidden="true"><x-icon name="info" width="18" height="18"/></span>
                <p class="mpa-notice__copy">
                    <strong>{{ __("Customers can't use Mass payments right now.") }}</strong>
                    <span>{{ __('Batches already confirmed keep paying out, and these screens stay open. Switch the module on in Feature controls.') }}</span>
                </p>
                <a href="{{ route('admin.features.index') }}" class="mpa-notice__link">{{ __('Open Feature controls') }}</a>
            </div>
        @endunless

        @yield('sub_content')
    </div>
@endsection
