@php
	$locale   = $locale ?? app()->getLocale();
	$ctaMark  = setting('small_logo', 'general/static/logo/digikash-mark.svg') ?: 'general/static/logo/digikash-mark.svg';
@endphp
<section class="au-cta-section">
	<div class="au-container">
		<div class="au-cta" data-au-dark data-au-reveal>
			<div class="au-cta__grid" aria-hidden="true"></div>
			<img src="{{ asset($ctaMark) }}" alt="" class="au-cta__logo" width="72" height="72">

			<h2 class="au-cta__title">{{ $data['heading'][$locale] ?? '' }}</h2>

			@if($data['description'][$locale] ?? null)
				<p class="au-cta__lead">{{ $data['description'][$locale] }}</p>
			@endif

			<div class="au-cta__actions">
				<a href="{{ ($data['button_url'] ?? null) ?: route('user.register') }}" class="au-btn au-btn--white">
					{{ $data['button_text'][$locale] ?? __('Open free account') }}
					<i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
				</a>
				@if($data['button2_text'][$locale] ?? null)
					<a href="{{ ($data['button2_url'] ?? null) ?: route('merchant.register') }}" class="au-btn au-btn--outline">
						{{ $data['button2_text'][$locale] }}
					</a>
				@endif
			</div>
		</div>
	</div>
</section>
