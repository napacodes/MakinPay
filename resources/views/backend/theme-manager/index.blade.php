@extends('backend.layouts.app')
@section('title', __('Theme Manager'))

@push('styles')
	<link rel="stylesheet" href="{{ asset('general/css/google-fonts-instrument.css') }}">
	@php($themeManagerCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/theme-manager-admin.css')))
	<link rel="stylesheet" href="{{ asset('backend/css/theme-manager-admin.css?v='.$themeManagerCssVersion) }}">
@endpush

@push('scripts')
	@php($themeManagerJsVersion = config('app.version').'-'.filemtime(public_path('backend/js/theme-manager-admin.js')))
	<script src="{{ asset('backend/js/theme-manager-admin.js?v='.$themeManagerJsVersion) }}"></script>
@endpush

@section('content')
	<x-page-header
		:title="__('Theme Manager')"
		:subtitle="__('Pick the visual identity for your public landing pages. The active theme drives both the page-builder block library and the published frontend.')"
		icon="fa-palette"
		:stats="$headerStats"
		class="mb-3"
	/>

	<div class="tm-admin">

		{{-- ====== Theme gallery ====== --}}
		<div class="tm-gallery" role="radiogroup" aria-label="{{ __('Active landing theme') }}">
			@foreach($themes as $theme)
				<article class="tm-card {{ $theme['is_active'] ? 'is-active' : '' }}"
				         data-tm-card="{{ $theme['value'] }}"
				         style="--tm-accent: {{ $theme['accent_color'] }}">

					<span class="tm-card__ring" aria-hidden="true"></span>
					<span class="tm-card__tint" aria-hidden="true"></span>

					<span class="tm-card__badge" aria-hidden="true">
						<span class="tm-card__badge-check"><i class="fa-solid fa-check"></i></span>
						{{ __('Active') }}
					</span>

					<x-admin.theme-preview-thumb :theme="$theme['value']" :mode="$theme['mode_label']" :light-mode="$theme['has_light_mode']" />

					<div class="tm-card__body">
						<div class="tm-card__head">
							<div class="tm-card__head-text">
								<span class="tm-card__kicker">
									{{ __('Theme') }} · {{ str_pad((string) ($loop->index + 1), 2, '0', STR_PAD_LEFT) }}
								</span>
								<h2 class="tm-card__title">{{ $theme['label'] }}</h2>
							</div>

							<div class="tm-card__chips">
								<span class="tm-chip tm-chip--blocks">
									<i class="fa-solid fa-table-cells-large" aria-hidden="true"></i>
									{{ $theme['component_count'] }}
									<span class="visually-hidden">{{ __('blocks') }}</span>
								</span>
								<span class="tm-chip tm-chip--swatch">
									<span class="tm-chip__dot" aria-hidden="true"></span>
									<span class="tm-chip__hex">{{ $theme['accent_color'] }}</span>
								</span>
							</div>
						</div>

						<p class="tm-card__personality">{{ $theme['tagline'] }}</p>

						<span class="tm-card__rule" aria-hidden="true"></span>

						<div class="tm-card__actions">
							<form method="POST"
							      action="{{ route('admin.theme-manager.activate', $theme['value']) }}"
							      class="tm-card__form"
							      data-tm-activate="{{ $theme['value'] }}">
								@csrf
								{{--
									aria-disabled rather than disabled: the checked control of a
									radiogroup must stay reachable, and a disabled button is
									removed from the tab order. It looks and behaves settled,
									JS blocks the submit, and re-activating is a no-op server-side.
								--}}
								<button type="submit"
								        class="tm-btn tm-btn--primary"
								        role="radio"
								        aria-checked="{{ $theme['is_active'] ? 'true' : 'false' }}"
								        data-label-activate="{{ __('Activate') }}"
								        data-label-inuse="{{ __('In Use') }}"
								        @if($theme['is_active']) aria-disabled="true" @endif>
									<i class="fa-solid fa-check tm-btn__check" aria-hidden="true"></i>
									<span data-tm-activate-label>
										{{ $theme['is_active'] ? __('In Use') : __('Activate') }}
									</span>
								</button>
							</form>

							<button type="button"
							        class="tm-btn tm-btn--chevron"
							        data-tm-details-toggle
							        aria-expanded="false"
							        aria-controls="tm-details-{{ $theme['value'] }}">
								<i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
								<span class="visually-hidden">{{ __('Show :theme design details', ['theme' => $theme['label']]) }}</span>
							</button>
						</div>

						<div class="tm-card__details" id="tm-details-{{ $theme['value'] }}" hidden>
							@foreach($theme['design_spec'] as $spec)
								<div class="tm-card__detail">
									<span class="tm-card__detail-label">{{ $spec['label'] }}</span>
									<span class="tm-card__detail-value">{{ $spec['value'] }}</span>
								</div>
							@endforeach
						</div>
					</div>
				</article>
			@endforeach
		</div>

		{{-- ====== Landing Colours ====== --}}
		@include('backend.theme-manager.partials._color_studio')

	</div>
@endsection
