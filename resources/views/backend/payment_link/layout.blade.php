@extends('backend.layouts.app')

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/payment-link-admin.css?v=' . config('app.version')) }}">
@endpush

@section('content')
    @php
        $pageIcon = trim($__env->yieldContent('sub_icon', 'payment')) ?: 'payment';
    @endphp

    <div class="pla-admin">
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

        <div class="card border-0 pla-admin-shell px-3 py-4">
            <div class="pla-admin-body">
                @yield('sub_content')
            </div>
        </div>
    </div>
@endsection
