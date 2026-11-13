@php use App\Enums\ComponentType; @endphp
@extends('backend.layouts.app')
@section('title', __('Page Component'))

@push('styles')
	<link rel="stylesheet" href="{{ asset('backend/css/page-component-admin.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/page-component-admin.css'))) }}">
@endpush

@push('scripts')
	<script src="{{ asset('backend/js/page-component-admin.js') }}"></script>
@endpush

@section('content')
	@php
		$totalComponents = $components->count();
		$activeComponents = $components->where('is_active', true)->count();
		$dynamicComponents = $components->filter(fn ($component) => $component->type === ComponentType::Dynamic)->count();
		$staticComponents = $totalComponents - $dynamicComponents;
		$protectedComponents = $components->where('is_protected', true)->count();
		$activeRatio = $totalComponents > 0 ? (int) round(($activeComponents / $totalComponents) * 100) : 0;
		$canManageComponents = app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('component-manage');
	@endphp
	
	<div class="page-component-admin my-3">
		{{--
			The library's own header: back to the page list, Add New Component
			behind the same gate, the active ratio as the progress cell and the
			block counts the hero pills and status panel carried as the strip.
		--}}
		<x-page-header
			:title="__('Page Component Library')"
			:subtitle="__('Manage reusable blocks from one compact library.')"
			icon="component"
			:back="route('admin.page.site.index')"
			:progress="['label' => __('Active'), 'value' => $activeComponents, 'total' => $totalComponents]"
			:stats="[
				['label' => __('Static'), 'value' => $staticComponents, 'tone' => 'brand', 'icon' => 'component'],
				['label' => __('Dynamic'), 'value' => $dynamicComponents, 'tone' => 'warning', 'icon' => 'code'],
				['label' => __('Protected'), 'value' => $protectedComponents, 'icon' => 'lock'],
			]"
		>
			@if($canManageComponents)
				<x-slot:actions>
					<a href="{{ route('admin.page.component.create') }}" class="ph-btn ph-btn--primary">
						<x-icon name="plus" width="14" height="14"/>
						{{ __('Add New Component') }}
					</a>
				</x-slot:actions>
			@endif
		</x-page-header>
		
		<section class="card border-0 pc-shell">
			<div class="pc-toolbar">
				<div>
					<h2 class="pc-toolbar__title mb-1">{{ __('Component Catalog') }}</h2>
					<p class="pc-toolbar__subtitle mb-0">{{ __('Search, inspect, and manage every reusable block without leaving the component workspace.') }}</p>
				</div>
				<div class="pc-toolbar__actions">
					<span class="pc-toolbar__meta">
						<span>{{ __('Visible') }}</span>
						<strong data-component-results-count>{{ $totalComponents }}</strong>
					</span>
					<label class="pc-search" for="pageComponentSearch">
						<span class="pc-search__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" fill="none">
								<circle cx="11" cy="11" r="6" stroke="currentColor" stroke-width="1.8"/>
								<path d="M16 16L20 20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
							</svg>
						</span>
						<input type="search" id="pageComponentSearch" class="form-control pc-search__input" placeholder="{{ __('Search by name, key, type, or state') }}">
					</label>
				</div>
			</div>
			
			<div class="table-responsive">
				<table class="table align-middle mb-0 pc-table">
					<thead>
					<tr>
						<th>{{ __('Component') }}</th>
						<th>{{ __('Type') }}</th>
						<th>{{ __('Status') }}</th>
						@if($canManageComponents)
							<th class="text-start">{{ __('Action') }}</th>
						@endif
					</tr>
					</thead>
					<tbody>
					@forelse($components as $component)
						@php
							$isDynamic = $component->type === ComponentType::Dynamic;
							$searchTokens = strtolower(implode(' ', array_filter([
								$component->component_name,
								$component->component_key,
								$component->type->value,
								$component->is_active ? 'active' : 'inactive',
								$component->is_protected ? 'protected' : 'editable',
							])));
						@endphp
						<tr data-component-row data-search="{{ $searchTokens }}">
							<td data-label="{{ __('Component') }}" class="pc-table__cell--component">
								<div class="pc-component-cell">
									@include('backend.page_component.partials._component_icon', [
										'component' => $component,
										'wrapperClass' => 'pc-component-thumb component-admin-thumb--lg',
									])
									<div class="pc-component-cell__content">
										<div class="pc-component-cell__title">{{ $component->component_name }}</div>
										<div class="pc-component-cell__meta">
											<span>{{ __('Key') }}</span>
											<code>{{ $component->component_key }}</code>
										</div>
									</div>
								</div>
							</td>
							<td data-label="{{ __('Type') }}">
								<span @class([
									'pc-type-badge',
									'pc-type-badge--dynamic' => $isDynamic,
									'pc-type-badge--static' => ! $isDynamic,
								])>
									<span class="pc-type-badge__icon" aria-hidden="true">
										@if($isDynamic)
											<svg viewBox="0 0 24 24" fill="none">
												<path d="M8 8L16 16M16 8L8 16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
												<path d="M12 4L13.9 8.1L18 10L13.9 11.9L12 16L10.1 11.9L6 10L10.1 8.1L12 4Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
											</svg>
										@else
											<svg viewBox="0 0 24 24" fill="none">
												<rect x="5" y="5" width="14" height="14" rx="3" stroke="currentColor" stroke-width="1.7"/>
												<path d="M9 9H15V15H9V9Z" fill="currentColor" fill-opacity=".18"/>
											</svg>
										@endif
									</span>
									{{ $isDynamic ? __('Dynamic') : __('Static') }}
								</span>
							</td>
							<td data-label="{{ __('Status') }}">
								<div class="pc-status-stack">
									<span @class([
										'pc-status-pill',
										'pc-status-pill--success' => $component->is_active,
										'pc-status-pill--danger' => ! $component->is_active,
									])>
										{{ $component->is_active ? __('Active') : __('Inactive') }}
									</span>
									<span @class([
										'pc-status-pill',
										'pc-status-pill--warning' => $component->is_protected,
										'pc-status-pill--neutral' => ! $component->is_protected,
									])>
										{{ $component->is_protected ? __('Protected') : __('Editable') }}
									</span>
								</div>
							</td>
							@if($canManageComponents)
								<td data-label="{{ __('Action') }}" class="text-start">
									<div class="pc-action-group">
										@unless($component->is_protected)
											<a href="{{ route('admin.page.component.edit', $component->id) }}" class="pc-action-btn pc-action-btn--primary">
												<span class="pc-action-btn__icon" aria-hidden="true">
													<svg viewBox="0 0 24 24" fill="none">
														<path d="M5 19H9L18 10C18.6 9.4 18.6 8.4 18 7.8L16.2 6C15.6 5.4 14.6 5.4 14 6L5 15V19Z" stroke="currentColor" stroke-width="1.7"
														      stroke-linejoin="round"/>
														<path d="M13 7L17 11" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
													</svg>
												</span>
												<span>{{ __('Manage') }}</span>
											</a>
										@else
											<span class="pc-action-btn pc-action-btn--muted is-disabled">
												<span class="pc-action-btn__icon" aria-hidden="true">
													<svg viewBox="0 0 24 24" fill="none">
														<path d="M8 10V8.3C8 5.9 9.8 4 12 4C14.2 4 16 5.9 16 8.3V10" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
														<rect x="6" y="10" width="12" height="9" rx="2.5" stroke="currentColor" stroke-width="1.7"/>
													</svg>
												</span>
												<span>{{ __('Protected') }}</span>
											</span>
										@endunless
										
										@if($isDynamic)
											<a href="javascript:void(0)" class="pc-action-btn pc-action-btn--danger delete" data-url="{{ route('admin.page.component.destroy', $component->id) }}">
												<span class="pc-action-btn__icon" aria-hidden="true">
													<svg viewBox="0 0 24 24" fill="none">
														<path d="M5 7H19" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
														<path d="M9 7V5.8C9 5.4 9.4 5 9.8 5H14.2C14.6 5 15 5.4 15 5.8V7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
														<path d="M8 10V17M12 10V17M16 10V17" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
														<path d="M7 7L7.7 18.1C7.8 18.6 8.2 19 8.8 19H15.2C15.8 19 16.2 18.6 16.3 18.1L17 7" stroke="currentColor" stroke-width="1.7"
														      stroke-linejoin="round"/>
													</svg>
												</span>
												<span>{{ __('Delete') }}</span>
											</a>
										@else
											<span class="pc-action-btn pc-action-btn--ghost is-static">
												<span class="pc-action-btn__icon" aria-hidden="true">
													<svg viewBox="0 0 24 24" fill="none">
														<path d="M7 12L10.5 15.5L17 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
													</svg>
												</span>
												<span>{{ __('System Asset') }}</span>
											</span>
										@endif
									</div>
								</td>
							@endif
						</tr>
					@empty
						<tr>
							<td colspan="{{ $canManageComponents ? 4 : 3 }}">
								<x-admin-not-found
									:title="__('No components found')"
									:message="__('Create the first component to start building reusable page sections.')"
									icon="fa-layer-group"
								/>
							</td>
						</tr>
					@endforelse
					
					@if($components->isNotEmpty())
						<tr class="d-none" data-component-empty>
							<td colspan="{{ $canManageComponents ? 4 : 3 }}">
								<div class="pc-empty-state pc-empty-state--search">
									<div class="pc-empty-state__icon" aria-hidden="true">
										<svg viewBox="0 0 24 24" fill="none">
											<circle cx="11" cy="11" r="6" stroke="currentColor" stroke-width="1.8"/>
											<path d="M16 16L20 20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
											<path d="M9.5 9.5H12.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
										</svg>
									</div>
									<h3>{{ __('No matching components') }}</h3>
									<p class="mb-0">{{ __('Try a different keyword for the component name, key, type, or status.') }}</p>
								</div>
							</td>
						</tr>
					@endif
					</tbody>
				</table>
			</div>
		</section>
	</div>

@endsection
