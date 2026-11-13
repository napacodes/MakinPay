@php
	$locale = $locale ?? app()->getLocale();
@endphp
<section class="dk-section" id="features">
	<div class="dk-container">
		<div class="dk-head" data-dk-reveal>
			@if($data['subheading'][$locale] ?? null)
				<span class="dk-eyebrow">{{ $data['subheading'][$locale] }}</span>
			@endif
			@if($data['heading'][$locale] ?? null)
				<h2>{{ $data['heading'][$locale] }}</h2>
			@endif
		</div>

		<div class="dk-grid dk-grid--3">
			@foreach($repeatedContents as $repeatedContent)
				@php
					$rc = $repeatedContent->content_data ?? [];
				@endphp
				<article class="dk-card dk-card--interactive dk-feature" data-dk-reveal style="--i: {{ $loop->index }}">
					<span class="dk-feature__icon" aria-hidden="true">
						<i class="{{ $rc['feature_icon_class'] ?? 'fa-solid fa-bolt' }}"></i>
					</span>
					<h3>{{ $rc['feature_title'][$locale] ?? '' }}</h3>
					<p style="font-size:.9375rem">{{ $rc['feature_text'][$locale] ?? '' }}</p>
				</article>
			@endforeach
		</div>
	</div>
</section>
