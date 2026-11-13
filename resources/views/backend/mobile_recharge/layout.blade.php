@extends('backend.layouts.app')

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/feature-management.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/feature-management.css'))) }}">
    <link rel="stylesheet" href="{{ asset('backend/css/mobile-recharge-admin.css?v=' . config('app.version')) }}">
@endpush

@push('scripts')
    <script src="{{ asset('backend/js/mobile-recharge-admin.js?v=' . config('app.version')) }}"></script>
@endpush

@section('content')
    @php
        $pageIcon = trim($__env->yieldContent('sub_icon', 'mobile-recharge')) ?: 'mobile-recharge';
    @endphp

    <div class="mra-admin">
        {{-- A page that needs more than title, subtitle, icon and action
             (the recharge detail: status, meta, stats) renders its own
             <x-page-header> in sub_header and the default one steps aside. --}}
        @hasSection('sub_header')
            @yield('sub_header')
        @else
            <x-page-header
                :title="trim($__env->yieldContent('sub_title'))"
                :subtitle="trim($__env->yieldContent('sub_subtitle'))"
                :icon="$pageIcon"
            >
                @hasSection('sub_action')
                    <x-slot:actions>
                        @yield('sub_action')
                    </x-slot:actions>
                @endif
            </x-page-header>
        @endif

        <div class="card border-0 mra-admin-shell px-3 py-4">
            <div class="mra-admin-body">
                @yield('sub_content')
            </div>
        </div>
    </div>
@endsection
