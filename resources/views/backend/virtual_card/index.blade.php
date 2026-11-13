@extends('backend.layouts.app')

@push('styles')
	@php($virtualCardCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/virtual-card-admin.css')))
	<link rel="stylesheet" href="{{ asset('backend/css/virtual-card-admin.css?v=' . $virtualCardCssVersion) }}">
@endpush

@push('scripts')
	<script src="{{ asset('backend/js/virtual-card-admin.js?v=' . config('app.version')) }}"></script>
@endpush

@section('content')
	@php($virtualCardMenu = getAdminMenuByCode('virtual-card-management'))

	<div class="vc-admin-page">
		@yield('virtual_card_header')

		<div class="card border-0 vc-admin-shell"
		     data-vc-loading="{{ __('Loading...') }}"
		     data-vc-testing="{{ __('Testing...') }}"
		     data-vc-probing="{{ __('Probing gateway...') }}"
		     data-vc-test-failed="{{ __('Test endpoint failed') }}">
			@if($virtualCardMenu && isset($virtualCardMenu['sub_menus']))
				<div class="vc-admin-shell__nav">
					<ul class="nav nav-pills vc-admin-nav">
						@foreach($virtualCardMenu['sub_menus'] as $menu)
							<li class="nav-item">
								<a class="nav-link {{ isActive($menu['route'],$menu['params'] ?? [] ) }}" aria-current="page" href="{{ route($menu['route'], $menu['params'] ?? []) }}">
									<x-icon name="{{ $menu['icon'] }}" height="16" width="16"/> {{ title($menu['label']) }}
								</a>
							</li>
						@endforeach
					</ul>
				</div>
			@endif

			@yield('virtual_card_content')
		</div>
	</div>
@endSection
