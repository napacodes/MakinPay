@php
	$locale = $locale ?? app()->getLocale();
@endphp
<section class="dk-strong">
	<div class="dk-container" style="padding-block:var(--dk-section)">
		<div class="dk-head" data-dk-reveal style="margin-bottom:64px">
			@if($data['subheading'][$locale] ?? null)
				<span class="dk-eyebrow">{{ $data['subheading'][$locale] }}</span>
			@endif
			@if($data['heading'][$locale] ?? null)
				<h2>{{ $data['heading'][$locale] }}</h2>
			@endif
		</div>

		<div class="dk-security__grid">
			@foreach(['item_one', 'item_two', 'item_three', 'item_four', 'item_five', 'item_six'] as $index => $key)
				@if($data[$key][$locale] ?? null)
					@php
						$parts = array_map('trim', explode(':', $data[$key][$locale], 2));
					@endphp
					<div class="dk-security__item" data-dk-reveal style="--i: {{ $index }}">
						<h3>{{ $parts[0] }}</h3>
						@if($parts[1] ?? null)
							<p style="font-size:.9375rem">{{ $parts[1] }}</p>
						@endif
					</div>
				@endif
			@endforeach
		</div>
	</div>
</section>
