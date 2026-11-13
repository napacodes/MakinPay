@php
	$locale = $locale ?? app()->getLocale();
@endphp
<section class="dk-strong">
	<div class="dk-container dk-cta" style="padding-block:var(--dk-section)">
		<h2 data-dk-reveal>{{ $data['heading'][$locale] ?? '' }}</h2>

		@if($data['description'][$locale] ?? null)
			<p data-dk-reveal style="--i: 1">{{ $data['description'][$locale] }}</p>
		@endif

		<div class="dk-cta__actions" data-dk-reveal style="--i: 2">
			<a href="{{ $data['button_url'] ?? route('user.register') }}" class="dk-btn dk-btn--primary">
				{{ $data['button_text'][$locale] ?? __('Open a wallet') }}
			</a>
			@if($data['button2_text'][$locale] ?? null)
				<a href="{{ $data['button2_url'] ?? '#pricing' }}" class="dk-btn dk-btn--ghost">
					{{ $data['button2_text'][$locale] }}
				</a>
			@endif
		</div>

		@if($data['reassurance'][$locale] ?? null)
			<p data-dk-reveal style="--i: 3;font-size:.8125rem">{{ $data['reassurance'][$locale] }}</p>
		@endif
	</div>
</section>
