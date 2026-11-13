@extends('backend.layouts.app')
@section('title', __('Edit Page'))

@push('styles')
	<link rel="stylesheet" href="{{ asset('backend/css/page-create-admin.css?v='.config('app.version').'-'.filemtime(public_path('backend/css/page-create-admin.css'))) }}">
	<link rel="stylesheet" href="{{ asset('backend/css/page-edit-admin.css?v='.config('app.version').'-'.filemtime(public_path('backend/css/page-edit-admin.css'))) }}">
@endpush

@section('content')
	@php
		// Controller PageController::edit passes $totalComponents, $totalLocales,
		// $isProtected explicitly. The ?? fallbacks fire only if a stale compiled
		// view or a partial-payload controller forgets to send them, so the
		// hero degrades to "0 / 0 / Editable" instead of throwing "Undefined variable".
		$componentIds    = is_array($page->component_ids ?? null) ? $page->component_ids : [];
		$totalComponents = $totalComponents ?? count($componentIds);
		$totalLocales    = $totalLocales    ?? (is_countable($locales ?? null) ? count($locales) : 0);
		$isProtected     = $isProtected     ?? (bool) ($page->is_protected ?? false);
	@endphp

	<div class="page-edit-admin page-create-admin mb-3">
		{{--
			The editor's own header: back to the page list, the page's label as
			the ID chip, protected / live-edit as the pill, the component
			library as the secondary action, and the three counters the hero
			pills carried as the strip.
		--}}
		<x-page-header
			:title="__('Edit Page')"
			:subtitle="__('Update route details and block order from one compact workspace.')"
			icon="page"
			:back="route('admin.page.site.index')"
			:badge="$page->label"
			:status="['tone' => ($isProtected ?? false) ? 'warning' : 'success', 'label' => ($isProtected ?? false) ? __('Protected') : __('Live Edit')]"
			:stats="[
				['label' => __('Mapped Blocks'), 'value' => (int) ($totalComponents ?? 0), 'tone' => 'brand', 'icon' => 'component'],
				['label' => __('Locale Tabs'), 'value' => (int) ($totalLocales ?? 0), 'icon' => 'language'],
				['label' => __('Page State'), 'value' => ($isProtected ?? false) ? __('Locked') : __('Editable'), 'tone' => ($isProtected ?? false) ? 'warning' : 'success', 'icon' => 'lock'],
			]"
		>
			<x-slot:actions>
				<a href="{{ route('admin.page.component.index') }}" class="ph-btn ph-btn--secondary">
					<x-icon name="component" width="14" height="14"/>
					{{ __('Manage Components') }}
				</a>
			</x-slot:actions>
		</x-page-header>

		<div class="row g-4">
			<div class="col-12 col-xl-5">
				@include('backend.page.partials._components_list', ['components' => $components, 'selected' => $page->component_ids])
			</div>
			<div class="col-12 col-xl-7">
				@include('backend.page.partials._components_form_edit', ['page' => $page])
			</div>
		</div>
	</div>
@endsection

@push('scripts')
	@include('backend.page.partials._component_script')
@endpush
