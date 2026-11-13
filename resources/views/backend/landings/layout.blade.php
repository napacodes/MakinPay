@extends('backend.layouts.app')

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/custom-landing-admin.css?v=' . config('app.version')) }}">
@endpush

@section('content')
    @php
        $pageIcon = trim($__env->yieldContent('sub_icon', 'custom-landing')) ?: 'custom-landing';
    @endphp

    <div class="cla-admin">
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

        <div class="cla-shell">
            @yield('sub_content')
        </div>
    </div>
@endsection
