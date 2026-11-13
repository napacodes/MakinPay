@extends('backend.layouts.app')

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/subscription-admin.css?v=' . config('app.version')) }}">
@endpush

@section('content')
    @php
        $subMenu    = getAdminMenuByCode('subscription-management');
        $visibleMenus = collect($subMenu['sub_menus'] ?? [])
            ->filter(fn (array $menu): bool => empty($menu['permission']) || (bool) auth()->user()?->can($menu['permission']))
            ->values();
        $pageIcon = trim($__env->yieldContent('sub_icon', 'layer')) ?: 'layer';
    @endphp

    <div class="sub-admin">
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

        <div class="card border-0 sa-admin-shell px-3 py-4">
            @if($visibleMenus->isNotEmpty())
                <div class="we-admin-nav-wrap mb-3">
                    <ul class="nav nav-pills bg-light sa-admin-nav rounded p-1">
                        @foreach($visibleMenus as $menu)
                            <li class="nav-item">
                                <a class="nav-link {{ isActive($menu['route'], $menu['params'] ?? []) }}"
                                   href="{{ route($menu['route'], $menu['params'] ?? []) }}">
                                    <x-icon name="{{ $menu['icon'] }}" height="18" width="18"/>
                                    {{ title($menu['label']) }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="sa-admin-body">
                @yield('sub_content')
            </div>
        </div>
    </div>
@endsection
