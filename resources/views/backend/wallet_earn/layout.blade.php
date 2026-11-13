@extends('backend.layouts.app')

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/wallet-earn.css?v=' . config('app.version')) }}">
@endpush

@section('content')
    @php
        $walletEarnMenu = getAdminMenuByCode('wallet-earn-management');
        $visibleMenus = collect($walletEarnMenu['sub_menus'] ?? [])
            ->filter(fn (array $menu): bool => empty($menu['permission']) || (bool) auth()->user()?->can($menu['permission']))
            ->values();
        $pageIcon = trim($__env->yieldContent('wallet_earn_icon', 'trending-up')) ?: 'trending-up';
    @endphp

    <div class="wallet-earn-admin">
        <x-page-header
            :title="trim($__env->yieldContent('wallet_earn_title'))"
            :subtitle="trim($__env->yieldContent('wallet_earn_subtitle'))"
            :icon="$pageIcon"
        >
            @hasSection('wallet_earn_action')
                <x-slot:actions>
                    @yield('wallet_earn_action')
                </x-slot:actions>
            @endif
        </x-page-header>

        <div class="card border-0 we-admin-shell px-3 py-4">
            @if($visibleMenus->isNotEmpty())
                <div class="we-admin-nav-wrap">
                    <ul class="nav nav-pills bg-light we-admin-nav rounded p-1">
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

            <div class="we-admin-body py-3">
                @yield('wallet_earn_content')
            </div>
        </div>
    </div>
@endsection
