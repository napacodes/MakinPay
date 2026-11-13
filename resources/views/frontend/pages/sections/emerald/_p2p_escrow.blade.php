@php
	$locale = $locale ?? app()->getLocale();
@endphp
<section class="dk-section">
	<div class="dk-container">
		<div class="dk-head" data-dk-reveal>
			@if($data['subheading'][$locale] ?? null)
				<span class="dk-eyebrow">{{ $data['subheading'][$locale] }}</span>
			@endif
			@if($data['heading'][$locale] ?? null)
				<h2>{{ $data['heading'][$locale] }}</h2>
			@endif
			@if($data['description'][$locale] ?? null)
				<p>{{ $data['description'][$locale] }}</p>
			@endif
		</div>

		<div class="dk-split">
			<ol class="dk-steps" style="list-style:none;margin:0;padding:0">
				@foreach(['step_one', 'step_two', 'step_three'] as $index => $key)
					@if($data[$key][$locale] ?? null)
						<li class="dk-step" data-dk-reveal style="--i: {{ $index }}">
							<span class="dk-step__num">0{{ $index + 1 }}</span>
							<p style="font-size:.9375rem">{{ $data[$key][$locale] }}</p>
						</li>
					@endif
				@endforeach
			</ol>

			<div class="dk-split__media">
				<div class="dk-card dk-card--panel" style="padding:22px;display:flex;flex-direction:column;gap:14px" data-dk-reveal>
					<div class="dk-hero__row">
						<span class="dk-eyebrow">{{ __('Sell USDT') }}</span>
						<span class="dk-chip">{{ __('In escrow') }}</span>
					</div>
					<span class="dk-num" style="font-size:1.5rem;font-weight:600;color:var(--dk-ink)">1,000.00 USDT</span>
					<div class="dk-hero__row">
						<span style="font-size:.8125rem;color:var(--dk-muted)">{{ __('Rate') }}</span>
						<span class="dk-num" style="font-size:.8125rem">1.00</span>
					</div>
					<div class="dk-hero__row">
						<span style="font-size:.8125rem;color:var(--dk-muted)">{{ __('Released on confirmation') }}</span>
						<i class="fa-solid fa-lock" style="color:var(--dk-primary)" aria-hidden="true"></i>
					</div>
				</div>
			</div>
		</div>
	</div>
</section>
