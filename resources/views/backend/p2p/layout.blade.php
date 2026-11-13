@extends('backend.layouts.app')

@push('styles')
    @php
        $p2pAdminCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/p2p-admin.css'));
    @endphp
    <link rel="stylesheet" href="{{ asset('backend/css/p2p-admin.css?v=' . $p2pAdminCssVersion) }}">
@endpush

@section('content')
    {{-- P2P Admin Shell: compact module header, tab navigation, and page content slot --}}
    @php
        $p2pMenu = getAdminMenuByCode('p2p-management');
        $visibleP2pMenus = collect($p2pMenu['sub_menus'] ?? [])
            ->filter(fn (array $menu): bool => empty($menu['permission']) || (bool) auth()->user()?->can($menu['permission']))
            ->values();
        $p2pIcon = trim($__env->yieldContent('p2p_icon', 'p2p_trading')) ?: 'p2p_trading';
    @endphp

    <div class="p2p-admin">
    <div class="py-4">
        {{-- The page's title, subtitle, icon and action come from the sections
             each P2P page declares; the header renders them above the shell. --}}
        {{-- A page may hand the header a status pill, meta row and stats
             strip by defining $p2pHeaderStatus / $p2pHeaderMeta /
             $p2pHeaderStats in a top-level @php block: Blade passes the
             child's variables to the layout it extends. Pages that set
             none of them render exactly as before. --}}
        <x-page-header
            :title="trim($__env->yieldContent('p2p_title'))"
            :subtitle="trim($__env->yieldContent('p2p_subtitle'))"
            :icon="$p2pIcon"
            :status="$p2pHeaderStatus ?? null"
            :meta="$p2pHeaderMeta ?? []"
            :stats="$p2pHeaderStats ?? []"
        >
            @hasSection('p2p_state')
                <x-slot:state>
                    @yield('p2p_state')
                </x-slot:state>
            @endif
            @hasSection('p2p_action')
                <x-slot:actions>
                    @yield('p2p_action')
                </x-slot:actions>
            @endif
        </x-page-header>

        {{-- Module Navigation + Page Content --}}
        <div class="card border-0 p2p-shell px-3 py-4">
            @if($visibleP2pMenus->isNotEmpty())
                <div class="p2p-nav-wrap">
                    <ul class="nav nav-pills bg-light p2p-nav rounded p-1">
                        @foreach($visibleP2pMenus as $menu)
                            <li class="nav-item">
                                <a class="nav-link {{ isActive($menu['route'], $menu['params'] ?? []) }}" aria-current="page"
                                   href="{{ route($menu['route'], $menu['params'] ?? []) }}">
                                    <x-icon name="{{ $menu['icon'] }}" height="18" width="18"/>
                                    {{ title($menu['label']) }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="p2p-body py-3">
                @yield('p2p_content')
            </div>
        </div>
    </div>
    </div>
@endsection
