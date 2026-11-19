@php
	// Theme is resolved at render time. The page builder already saves each
	// component with its theme tag, but we still gate on activeTheme() so a
	// freshly-switched theme renders only its own blocks on the next request.
	$gdkActiveTheme = activeTheme();
	$gdkThemeSlug   = $gdkActiveTheme->value;

	$gdkLayoutView      = 'frontend.layouts.'.$gdkThemeSlug.'.app';
	$gdkSectionPathBase = 'frontend.pages.sections.'.$gdkThemeSlug;

	// Fall back to the classic layout/sections if the active theme's files
	// aren't present yet (defensive: e.g. mid-deploy of a new theme).
	if (! view()->exists($gdkLayoutView)) {
		$gdkLayoutView      = 'frontend.layouts.app';
		$gdkSectionPathBase = 'frontend.pages.sections';
	}
@endphp

@extends($gdkLayoutView)

@section('content')
	@if($isBreadcrumb)
		@include('frontend.pages.partials._breadcrumb')
	@endif

	@foreach($components as $component)
		{{-- The plans section sells what the plans page offers; with the subscription module off that page is a 404. --}}
		@continue($component->section_name === 'subscription_plans' && ! app(\App\Services\FeatureManager::class)->isVisible('subscription_system', \App\Models\FeatureAccessRule::PANEL_USER))
		@php
			$gdkSectionView = $gdkSectionPathBase.'._'.$component->section_name;
			// Component-level fallback: if the active theme is missing a
			// particular section view, use the classic one for that block.
			if (! view()->exists($gdkSectionView)) {
				$gdkSectionView = 'frontend.pages.sections._'.$component->section_name;
			}

			// Stored media paths are disk-relative but the public disk is served
			// from /storage, so resolve them once here rather than in every
			// section view. In-memory only: the stored references are untouched.
			$component->prepareForDisplay();
		@endphp

		<div class="@isset($isPage) page-section @endisset">
			@include($gdkSectionView, [
				'data'             => $component->content_data,
				'repeatedContents' => $component->repeatedContents,
				'component'        => $component,
			])
		</div>
	@endforeach
@endsection
