@php
	$locale = $locale ?? app()->getLocale();
@endphp
<section class="dk-strong">
	<div class="dk-container" style="padding-block:clamp(56px,6vw,88px)">
		<span class="dk-eyebrow" style="display:block;margin-bottom:24px">{{ $data['subheading'][$locale] ?? __('Last 30 days') }}</span>

		<div class="dk-stats">
			@foreach($repeatedContents as $repeatedContent)
				@php
					$rc = $repeatedContent->content_data ?? [];
				@endphp
				<div class="dk-stat">
					{{-- aria-live off: a ticking number must never be announced. --}}
					<span class="dk-stat__value"
					      data-dk-count="{{ $rc['stat_value'] ?? 0 }}"
					      data-dk-prefix="{{ $rc['stat_prefix'] ?? '' }}"
					      data-dk-suffix="{{ $rc['stat_suffix'] ?? '' }}"
					      aria-live="off">{{ ($rc['stat_prefix'] ?? '').number_format((float) ($rc['stat_value'] ?? 0)).($rc['stat_suffix'] ?? '') }}</span>
					<span class="dk-stat__label">{{ $rc['stat_label'][$locale] ?? '' }}</span>
				</div>
			@endforeach
		</div>

		@if($data['caption'][$locale] ?? null)
			<p style="margin-top:24px;font-size:.8125rem">{{ $data['caption'][$locale] }}</p>
		@endif
	</div>
</section>
