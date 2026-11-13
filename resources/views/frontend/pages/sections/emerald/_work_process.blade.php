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
		</div>

		<ol class="dk-steps" style="list-style:none;margin:0;padding:0">
			@foreach($repeatedContents as $repeatedContent)
				@php
					$rc = $repeatedContent->content_data ?? [];
				@endphp
				<li class="dk-step" data-dk-reveal style="--i: {{ $loop->index }}">
					<span class="dk-step__num">{{ str_pad((string) ($loop->index + 1), 2, '0', STR_PAD_LEFT) }}</span>
					<h3 style="font-size:1.0625rem">{{ $rc['step_title'][$locale] ?? '' }}</h3>
					<p style="font-size:.9375rem">{{ $rc['step_text'][$locale] ?? '' }}</p>
				</li>
			@endforeach
		</ol>
	</div>
</section>
