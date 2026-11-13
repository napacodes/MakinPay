@php
	$locale = $locale ?? app()->getLocale();
@endphp
<section class="dk-section">
	<div class="dk-container">
		<div class="dk-head" data-dk-reveal>
			@if($data['heading'][$locale] ?? null)
				<h2>{{ $data['heading'][$locale] }}</h2>
			@endif
		</div>

		<div class="dk-grid dk-grid--3">
			@foreach($repeatedContents as $repeatedContent)
				@php
					$rc = $repeatedContent->content_data ?? [];
				@endphp
				<figure class="dk-card dk-quote" data-dk-reveal style="--i: {{ $loop->index }};margin:0">
					<blockquote>{{ $rc['quote'][$locale] ?? '' }}</blockquote>
					<figcaption>
						<span class="dk-quote__name">{{ $rc['author_name'] ?? '' }}</span>
						<span class="dk-quote__meta">{{ $rc['author_role'][$locale] ?? '' }} &middot; {{ $rc['author_country'] ?? '' }}</span>
					</figcaption>
				</figure>
			@endforeach
		</div>
	</div>
</section>
