@php
	$locale = $locale ?? app()->getLocale();
@endphp
<section class="dk-section" style="padding-block:clamp(48px,5vw,72px)">
	<div class="dk-container">
		<div class="dk-head dk-head--center" style="margin-bottom:32px">
			@if($data['heading'][$locale] ?? null)
				<span class="dk-eyebrow">{{ $data['heading'][$locale] }}</span>
			@endif
			@if($data['caption'][$locale] ?? null)
				<p style="font-size:.9375rem">{{ $data['caption'][$locale] }}</p>
			@endif
		</div>
	</div>

	{{-- Decorative scroller; the accessible list below carries the same names. --}}
	<div class="dk-marquee" aria-hidden="true">
		<div class="dk-marquee__track" data-dk-marquee>
			@foreach($repeatedContents as $repeatedContent)
				@php
					$rc = $repeatedContent->content_data ?? [];
				@endphp
				<span class="dk-marquee__item">{{ $rc['gateway_name'] ?? '' }}</span>
			@endforeach
		</div>
	</div>

	<ul class="dk-visually-hidden">
		@foreach($repeatedContents as $repeatedContent)
			@php
				$rc = $repeatedContent->content_data ?? [];
			@endphp
			<li>{{ $rc['gateway_name'] ?? '' }}</li>
		@endforeach
	</ul>
</section>
