@extends('backend.layouts.app')
@section('title', __('Create Page'))

@push('styles')
	<link rel="stylesheet" href="{{ asset('backend/css/page-create-admin.css?v='.config('app.version').'-'.filemtime(public_path('backend/css/page-create-admin.css'))) }}">
@endpush

@section('content')
	@php
		// Controller PageController::create passes $totalComponents, $totalLocales,
		// and $defaultLocale explicitly. The ?? fallbacks fire only if a stale
		// compiled view or a degraded controller forgets to send them, so the
		// hero degrades to "0 / 0 / EN" instead of throwing "Undefined variable".
		$totalComponents = $totalComponents ?? (isset($components) ? $components->count() : 0);
		$totalLocales    = $totalLocales    ?? (is_countable($locales ?? null) ? count($locales) : 0);
		$defaultLocale   = $defaultLocale   ?? strtoupper((string) app()->getDefaultLocale());
	@endphp

	<div class="page-create-admin mb-3">
		{{--
			The builder's own header: back to the page list, the component
			library as the secondary action, and the three counters the hero
			pills carried as the strip. The "Step 1 / Step 2" panel is gone —
			the builder below is the guidance.
		--}}
		<x-page-header
			:title="__('Create New Page')"
			:subtitle="__('Build a page route and map components from one compact workspace.')"
			icon="page"
			:back="route('admin.page.site.index')"
			:stats="[
				['label' => __('Components Ready'), 'value' => (int) ($totalComponents ?? 0), 'tone' => 'brand', 'icon' => 'component'],
				['label' => __('Locale Tabs'), 'value' => (int) ($totalLocales ?? 0), 'icon' => 'language'],
				['label' => __('Auto Slug Source'), 'value' => (string) ($defaultLocale ?? strtoupper(app()->getDefaultLocale())), 'icon' => 'code'],
			]"
		>
			<x-slot:actions>
				<a href="{{ route('admin.page.component.index') }}" class="ph-btn ph-btn--secondary">
					<x-icon name="component" width="14" height="14"/>
					{{ __('Manage Components') }}
				</a>
			</x-slot:actions>
		</x-page-header>

		<div class="row g-3 align-items-start">
			<div class="col-12 col-xl-5">
				@include('backend.page.partials._components_list')
			</div>
			<div class="col-12 col-xl-7">
				@include('backend.page.partials._components_form')
			</div>
		</div>
	</div>
@endsection

@push('scripts')
	@include('backend.page.partials._component_script')
@endpush
