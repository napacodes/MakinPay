@extends('frontend.layouts.user.index')
@section('title', __('Make Payment'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/frontend/css/marketplace.css') }}?v={{ filemtime(public_path('assets/frontend/css/marketplace.css')) }}">
@endpush

@section('content')

    <div class="single-form-card">
        <x-user-feature-header
            :title="__('Make Payment')"
            :subtitle="__('Find and pay any business on :app — shops, service providers, and sellers.', ['app' => config('app.name')])"
            icon="fas fa-paper-plane"
        >
            <a class="btn btn-light-primary btn-sm" href="{{ route('user.marketplace.orders') }}">
                <i class="fa-solid fa-receipt"></i> {{ __('My Orders') }}
            </a>
            @unless($ownVendor)
                <a class="btn btn-light-success btn-sm" href="{{ route('user.marketplace.apply') }}">
                    <i class="fa-solid fa-store"></i> {{ __('Become a Vendor') }}
                </a>
            @endunless
        </x-user-feature-header>

        <div class="card-main">

            {{-- ── Own business pinned banner ── --}}
            @if($ownVendor)
                <div class="mkt-own-banner">
                    <span class="mkt-own-banner__avatar" aria-hidden="true">{{ $ownVendor->initials() }}</span>
                    <div class="mkt-own-banner__body">
                        <div class="mkt-own-banner__title">
                            <span>{{ $ownVendor->display_name }}</span>
                            <span class="mkt-own-banner__tag">{{ __('Your business') }}</span>
                        </div>
                        <div class="mkt-own-banner__meta">
                            {{ $ownVendorSales }} {{ trans_choice('sale|sales', $ownVendorSales) }} · {{ $ownVendor->status->label() }}
                        </div>
                    </div>
                    <a href="{{ route('user.marketplace.orders') }}" class="btn btn-light-primary">
                        <i class="fa-solid fa-chart-line me-1"></i> {{ __('View sales dashboard') }}
                    </a>
                </div>
            @endif

            {{-- ── Recently paid ── --}}
            @if($recentVendors->isNotEmpty())
                <div class="mkt-recents">
                    <div class="mkt-recents__label">{{ __('Recently paid') }}</div>
                    <div class="mkt-recents__row">
                        @foreach($recentVendors as $recent)
                            <a href="{{ route('user.marketplace.pay', $recent) }}" class="mkt-recent-card">
                                <span class="mkt-recent-card__avatar mkt-avatar--{{ $recent->avatarVariant() }}" aria-hidden="true">
                                    {{ $recent->initials() }}
                                </span>
                                <span class="mkt-recent-card__name">{{ \Illuminate\Support\Str::limit($recent->display_name, 18) }}</span>
                                <span class="mkt-recent-card__cta">{{ __('Pay again') }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ── Sticky finder bar ── --}}
            <form action="{{ route('user.marketplace.index') }}" method="GET" class="mkt-finder" role="search">
                <div class="mkt-toolbar">
                    <div class="mkt-search">
                        <span class="mkt-search__icon"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <label for="search" class="visually-hidden">{{ __('Search shop or business') }}</label>
                        <input type="text" id="search" name="search" value="{{ request('search') }}"
                               placeholder="{{ __('Search shop or business...') }}">
                        <button type="submit" class="mkt-search__submit" aria-label="{{ __('Search') }}">
                            <i class="fa-solid fa-arrow-right"></i>
                        </button>
                    </div>

                    <div class="mkt-toolbar__sort">
                        <label for="sort" class="visually-hidden">{{ __('Sort by') }}</label>
                        <select name="sort" id="sort" class="form-select" onchange="this.form.submit()">
                            <option value="sales" @selected($sort === 'sales')>{{ __('Most sales') }}</option>
                            <option value="newest" @selected($sort === 'newest')>{{ __('Newest') }}</option>
                            <option value="name" @selected($sort === 'name')>{{ __('Name (A–Z)') }}</option>
                        </select>
                    </div>
                    @if($category)
                        <input type="hidden" name="category" value="{{ $category }}">
                    @endif
                </div>

                <div class="mkt-cats-wrap">
                    <div class="mkt-cats" role="group" aria-label="{{ __('Filter by category') }}">
                        <a href="{{ route('user.marketplace.index', array_filter(['search' => request('search'), 'sort' => $sort])) }}"
                           class="mkt-cat-chip {{ $category === null ? 'is-active' : '' }}"
                           @if($category === null) aria-current="true" @endif>{{ __('All') }}</a>
                        @foreach(\App\Models\Vendor::CATEGORIES as $catKey => $catLabel)
                            <a href="{{ route('user.marketplace.index', array_filter(['search' => request('search'), 'sort' => $sort, 'category' => $catKey])) }}"
                               class="mkt-cat-chip {{ $category === $catKey ? 'is-active' : '' }}"
                               @if($category === $catKey) aria-current="true" @endif>{{ __($catLabel) }}</a>
                        @endforeach
                    </div>

                    <div class="mkt-finder__meta" aria-live="polite">
                        @if(request()->filled('search'))
                            <b>{{ $vendors->total() }}</b> {{ trans_choice('result|results', $vendors->total()) }} · <a href="{{ route('user.marketplace.index') }}">{{ __('Clear') }}</a>
                        @elseif($category)
                            <b>{{ $vendors->total() }}</b> {{ __(\App\Models\Vendor::CATEGORIES[$category]) }}
                        @else
                            <b>{{ $totalVendors }}</b> {{ trans_choice('business|businesses', $totalVendors) }}
                        @endif
                    </div>
                </div>
            </form>

            {{-- ── Business grid ── --}}
            @if($vendors->count() > 0)
                <div class="mkt-vendor-grid">
                    @foreach($vendors as $vendor)
                        <article class="mkt-vendor-card">
                            <div class="mkt-vendor-card__head">
                                <span class="mkt-vendor-card__avatar mkt-avatar--{{ $vendor->avatarVariant() }}" aria-hidden="true">
                                    {{ $vendor->initials() }}
                                </span>
                                <div class="mkt-vendor-card__identity">
                                    <span class="mkt-vendor-card__name">
                                        {{ $vendor->display_name }}
                                        <i class="fa-solid fa-circle-check" title="{{ __('Verified business') }}"
                                           data-bs-toggle="tooltip" data-bs-placement="top"
                                           aria-label="{{ __('Verified business') }}" role="img"></i>
                                    </span>
                                    <span class="mkt-vendor-card__owner">{{ $vendor->user?->name }}</span>
                                </div>
                            </div>

                            <div class="mkt-vendor-card__meta">
                                <span class="mkt-chip mkt-chip--neutral">{{ $vendor->categoryLabel() }}</span>
                                <span class="mkt-chip mkt-chip--success">
                                    <i class="fa-solid fa-coins" aria-hidden="true"></i>
                                    {{ (int) $vendor->completed_sales_count }} {{ trans_choice('sale|sales', (int) $vendor->completed_sales_count) }}
                                </span>
                                <span class="mkt-chip mkt-chip--neutral">
                                    {{ __('Since') }} {{ ($vendor->approved_at ?? $vendor->created_at)->format('M Y') }}
                                </span>
                            </div>

                            <div class="mkt-vendor-card__footer">
                                <a href="{{ route('user.marketplace.pay', $vendor) }}" class="btn btn-base btn-sm">
                                    <i class="fa-solid fa-paper-plane me-1"></i> {{ __('Make Payment') }}
                                </a>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="d-flex justify-content-end mt-4">
                    {{ $vendors->links() }}
                </div>
            @elseif(request()->filled('search') || $category)
                <x-user-not-found
                    :title="request()->filled('search') ? __('No businesses match “:q”', ['q' => request('search')]) : __('No businesses in this category yet')"
                    :message="__('Try a different name, or browse another category.')"
                    icon="fa-magnifying-glass"
                    :action-url="route('user.marketplace.index')"
                    :action-label="__('Clear Filters')"
                    action-icon="fa-rotate-left"
                />
            @else
                <x-user-not-found
                    :title="__('No businesses available yet')"
                    :message="__('Approved businesses will appear here. Want to receive payments on this platform? Apply to become a vendor.')"
                    icon="fa-store"
                    :action-url="route('user.marketplace.apply')"
                    :action-label="__('Become a Vendor')"
                    action-icon="fa-store"
                />
            @endif
        </div>
    </div>

@endsection
