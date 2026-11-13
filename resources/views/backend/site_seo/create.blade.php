@extends('backend.layouts.app')
@section('title', __('New SEO Data'))
@section('content')
	@php
		$pageId = request('page_id');
        $pageName = $pageId ? \App\Models\Page::find($pageId)->label : '';
	@endphp
	<div class="py-4">
		<x-page-header
			:title="__('New SEO')"
			icon="seo"
			:badge="$pageId ? $pageName : null"
			:back="route('admin.site-seo.index')"
			:back-label="__('Back to SEO Management')"
		>
			<x-slot:actions>
				<a href="{{ route('admin.page.site.index') }}" class="ph-btn ph-btn--secondary">
					<x-icon name="page" height="14" width="14"/>{{ __('Page Management') }}
				</a>
			</x-slot:actions>
		</x-page-header>
		
		<div class="card border-0 shadow-sm mb-4">
			<div class="card-body">
				<form action="{{ route('admin.site-seo.store') }}" method="POST" enctype="multipart/form-data">
					@csrf
					
					@unless($pageId)
						{{-- Page Dropdown --}}
						<div class="mb-3">
							<label class="form-label">{{ __('Select Page') }}</label>
							<select name="page_id" class="form-select">
								<option value="">{{ __('-- Select a page --') }}</option>
								@foreach($pages as $page)
									<option value="{{ $page->id }}" @selected(old('page_id',request('page_id')) == $page->id)>
										{{ $page->label }}
									</option>
								@endforeach
							</select>
						</div>
					@else
						<input type="hidden" name="page_id" value="{{ $pageId }}">
					@endif
					
					
					{{-- Language Tabs --}}
					<ul class="nav nav-tabs mb-3" role="tablist">
						@foreach($locales as $locale => $label)
							<li class="nav-item" role="presentation">
								<button class="nav-link {{ $loop->first ? 'active' : '' }}"
								        id="create-lang-tab-{{ $locale }}"
								        data-coreui-toggle="tab"
								        data-coreui-target="#create-lang-{{ $locale }}"
								        type="button" role="tab">
									{{ $label }}
								</button>
							</li>
						@endforeach
					</ul>
					
					{{-- Tab Content --}}
					<div class="tab-content">
						@foreach($locales as $locale => $label)
							<div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="create-lang-{{ $locale }}" role="tabpanel">
								<div class="mb-3">
									<label class="form-label">{{ __('Meta Title') }} ({{ strtoupper($locale) }})</label>
									<input type="text" name="meta_title[{{ $locale }}]" class="form-control" value="{{ old('meta_title.' . $locale) }}">
								</div>
								
								<div class="mb-3">
									<label class="form-label">{{ __('Meta Description') }} ({{ strtoupper($locale) }})</label>
									<textarea name="meta_description[{{ $locale }}]" class="form-control" rows="2">{{ old('meta_description.' . $locale) }}</textarea>
								</div>
							</div>
						@endforeach
					</div>
					
					<div class="mb-3">
						<label class="form-label">{{ __('Meta Keywords') }}</label>
						<input type="text" name="meta_keywords" class="form-control tags-evs p-0" value="{{ old('meta_keywords') }}">
					</div>
					
					{{-- Canonical URL --}}
					<div class="mb-3">
						<label class="form-label">{{ __('Canonical URL') }}</label>
						<input type="url" name="canonical_url" class="form-control" placeholder="{{ __('Example: https://example.com/page') }}" value="{{ old('canonical_url') }}">
					</div>
					
					{{-- Robots --}}
					<div class="mb-3">
						<label class="form-label">{{ __('Robots Directive') }}</label>
						<select name="robots" class="form-select">
							<option value="index,follow" @selected(old('robots') == 'index,follow')>Index, Follow</option>
							<option value="noindex,nofollow" @selected(old('robots') == 'noindex,nofollow')>No Index, No Follow</option>
						</select>
					</div>
					
					{{-- Image --}}
					<div class="mb-3">
						<label class="form-label">{{ __('SEO Image (Optional)') }}</label>
						<x-img name="image"/>
						<div class="form-text">
							{{ __('Recommended size: 1200x630 pixels') }}
						</div>
					</div>
					
					{{-- Submit --}}
					<x-form.submit-button icon="check">
						{{ __('Create SEO') }}
					</x-form.submit-button>
				
				</form>
			</div>
		</div>
	</div>
@endsection
