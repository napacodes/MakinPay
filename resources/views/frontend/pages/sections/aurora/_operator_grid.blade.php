@php
	$locale = $locale ?? app()->getLocale();
@endphp
@if($repeatedContents->isNotEmpty())
	<section class="au-section au-ops-section">
		<div class="au-container">
			<div class="au-head au-head--center au-head--tight" data-au-reveal>
				@if($data['subheading'][$locale] ?? null)
					<span class="au-eyebrow">{{ $data['subheading'][$locale] }}</span>
				@endif
				@if($data['heading'][$locale] ?? null)
					<h2 class="au-h2">{{ $data['heading'][$locale] }}</h2>
				@endif
			</div>

			<ul class="au-ops" data-au-reveal style="--i: 1">
				@foreach($repeatedContents as $repeatedContent)
					@php
						$rc = $repeatedContent->content_data ?? [];
					@endphp
					<li class="au-op">
						<i class="{{ ($rc['icon'] ?? null) ?: 'fa-solid fa-gear' }}" aria-hidden="true"></i>
						<h3 class="au-op__title">{{ $rc['title'][$locale] ?? '' }}</h3>
						@if($rc['text'][$locale] ?? null)
							<p class="au-op__text">{{ $rc['text'][$locale] }}</p>
						@endif
					</li>
				@endforeach
			</ul>
		</div>
	</section>
@endif
