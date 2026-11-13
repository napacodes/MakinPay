@php
	$locale = $locale ?? app()->getLocale();
@endphp
<section class="dk-hero">
	<div class="dk-container dk-hero__inner">
		<div class="dk-hero__copy" data-dk-reveal>
			@if($data['subheading'][$locale] ?? null)
				<span class="dk-eyebrow">{{ $data['subheading'][$locale] }}</span>
			@endif

			<h1>{{ $data['heading'][$locale] ?? '' }}</h1>

			@if($data['description'][$locale] ?? null)
				<p>{{ $data['description'][$locale] }}</p>
			@endif

			<div class="dk-hero__actions">
				<a href="{{ $data['button_url'] ?? route('user.register') }}" class="dk-btn dk-btn--primary">
					{{ $data['button_text'][$locale] ?? __('Create your account') }}
				</a>
				@if($data['button2_text'][$locale] ?? null)
					<a href="{{ $data['button2_url'] ?? '#platform' }}" class="dk-btn dk-btn--ghost">
						{{ $data['button2_text'][$locale] }}
					</a>
				@endif
			</div>

			@if($data['trust_items'][$locale] ?? null)
				<ul class="dk-hero__trust" style="list-style:none;margin:0;padding:0">
					@foreach(array_filter(array_map('trim', explode('|', $data['trust_items'][$locale]))) as $trust)
						<li><i class="fa-solid fa-check" aria-hidden="true"></i>{{ $trust }}</li>
					@endforeach
				</ul>
			@endif
		</div>

		<div class="dk-hero__media" data-dk-reveal>
			<div class="dk-hero__frame">
				<div class="dk-hero__chrome" aria-hidden="true"><span></span><span></span><span></span></div>
				<div class="dk-hero__panel">
					<span class="dk-eyebrow">{{ __('Send money') }}</span>
					<span class="dk-hero__amount">$1,250.00</span>
					<div class="dk-hero__row">
						<span style="font-size:.875rem">{{ __('To') }} &middot; Amara O.</span>
						<span class="dk-chip"><i class="fa-solid fa-bolt" aria-hidden="true"></i>{{ __('Instant') }}</span>
					</div>
					<div class="dk-hero__row">
						<span class="dk-num" style="font-size:.8125rem;color:var(--dk-muted)">{{ __('Fee') }} 0.00</span>
						<span class="dk-chip">{{ __('Delivered') }}</span>
					</div>
				</div>
			</div>
		</div>
	</div>
</section>
