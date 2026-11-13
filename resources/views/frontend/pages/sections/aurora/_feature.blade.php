@php
	use App\Services\AuroraLandingData;

	$locale  = $locale ?? app()->getLocale();
	$landing = app(AuroraLandingData::class);

	// Module-driven: the cards are whatever Feature Management has switched
	// on, so an admin toggling a feature off removes it here too.
	$features   = $landing->featureCatalog();
	$categories = $landing->featureCategories($features);
	$heading    = strtr((string) ($data['heading'][$locale] ?? ''), ['{features}' => (string) count($features)]);
@endphp
@if($features !== [])
	<section class="au-section au-catalog" id="features" data-au-catalog>
		<div class="au-container">
			<div class="au-head au-head--center" data-au-reveal>
				@if($data['subheading'][$locale] ?? null)
					<span class="au-eyebrow">{{ $data['subheading'][$locale] }}</span>
				@endif
				@if($heading !== '')
					<h2 class="au-h2">{{ $heading }}</h2>
				@endif
				@if($data['description'][$locale] ?? null)
					<p class="au-lead">{{ $data['description'][$locale] }}</p>
				@endif
			</div>

			@if(count($categories) > 1)
				<div class="au-filters" role="group" aria-label="{{ __('Filter features by category') }}" data-au-reveal style="--i: 1">
					<button type="button" class="au-filter" aria-pressed="true" data-au-filter="all">
						{{ __('All') }}<span class="au-filter__count">{{ count($features) }}</span>
					</button>
					@foreach($categories as $category)
						<button type="button" class="au-filter" aria-pressed="false" data-au-filter="{{ $category['key'] }}">
							{{ $category['label'] }}<span class="au-filter__count">{{ $category['count'] }}</span>
						</button>
					@endforeach
				</div>
			@endif

			<ul class="au-features">
				@foreach($features as $feature)
					<li class="au-feature" data-au-feature="{{ $feature['category'] }}" data-au-reveal style="--i: {{ $loop->index % 4 }}">
						<div class="au-feature__top">
							<span class="au-feature__icon" aria-hidden="true"><i class="{{ $feature['icon'] }}"></i></span>
							@if($feature['core'] || $feature['kyc'])
								<span class="au-flags">
									@if($feature['core'])
										<span class="au-flag au-flag--core">{{ __('CORE') }}</span>
									@endif
									@if($feature['kyc'])
										<span class="au-flag au-flag--kyc">{{ __('KYC') }}</span>
									@endif
								</span>
							@endif
						</div>
						<h3 class="au-feature__title">{{ $feature['label'] }}</h3>
						<p class="au-feature__desc">{{ $feature['description'] }}</p>
						@if($feature['panels'] !== [])
							<ul class="au-chips" aria-label="{{ __('Available in') }}">
								@foreach($feature['panels'] as $panel)
									<li class="au-chip">{{ $panel }}</li>
								@endforeach
							</ul>
						@endif
					</li>
				@endforeach
			</ul>
		</div>
	</section>
@endif
