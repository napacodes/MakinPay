@php
	$locale = $locale ?? app()->getLocale();
@endphp
<section class="dk-section" id="faq">
	<div class="dk-container">
		<div class="dk-head dk-head--center" data-dk-reveal>
			@if($data['heading'][$locale] ?? null)
				<h2>{{ $data['heading'][$locale] }}</h2>
			@endif
		</div>

		<div class="dk-faq">
			@foreach($repeatedContents as $repeatedContent)
				@php
					$rc      = $repeatedContent->content_data ?? [];
					$panelId = 'dk-faq-panel-'.$repeatedContent->id;
				@endphp
				<div class="dk-faq__item">
					<h3 style="margin:0;font-size:inherit">
						<button type="button"
						        class="dk-faq__toggle"
						        data-dk-faq-toggle
						        aria-expanded="false"
						        aria-controls="{{ $panelId }}">
							<span>{{ $rc['question'][$locale] ?? '' }}</span>
							<i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
						</button>
					</h3>
					<div class="dk-faq__panel" id="{{ $panelId }}">
						<div><p>{{ $rc['answer'][$locale] ?? '' }}</p></div>
					</div>
				</div>
			@endforeach
		</div>
	</div>
</section>
