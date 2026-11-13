@php
	$locale = $locale ?? app()->getLocale();
@endphp
<section class="dk-section" id="platform">
	<div class="dk-container">
		<div class="dk-head" data-dk-reveal>
			@if($data['heading'][$locale] ?? null)
				<h2>{{ $data['heading'][$locale] }}</h2>
			@endif
		</div>

		<div class="dk-showcase">
			@foreach($repeatedContents as $repeatedContent)
				@php
					$rc      = $repeatedContent->content_data ?? [];
					$flip    = $loop->index % 2 === 1;
					$bullets = array_filter(array_map('trim', explode('|', $rc['service_bullets'][$locale] ?? '')));
				@endphp
				<div class="dk-split {{ $flip ? 'dk-split--flip' : '' }}" data-dk-reveal>
					<div class="dk-showcase__copy">
						<span class="dk-eyebrow">{{ $rc['service_eyebrow'][$locale] ?? '' }}</span>
						<hr class="dk-rule">
						<h3 style="font-size:1.75rem">{{ $rc['service_title'][$locale] ?? '' }}</h3>
						<p>{{ $rc['service_text'][$locale] ?? '' }}</p>

						@if($bullets)
							<ul class="dk-showcase__list">
								@foreach($bullets as $bullet)
									<li><i class="fa-solid fa-check" aria-hidden="true"></i><span>{{ $bullet }}</span></li>
								@endforeach
							</ul>
						@endif
					</div>

					<div class="dk-split__media">
						<div class="dk-mock" data-dk-mock="{{ $rc['service_mock'] ?? 'wallet' }}" aria-hidden="true">
							<div class="dk-mock__bar dk-mock__bar--brand"></div>
							<div class="dk-mock__tile">
								<div class="dk-mock__bar" style="width:70%"></div>
								<div class="dk-mock__bar" style="width:45%"></div>
							</div>
							<div class="dk-mock__tile">
								<div class="dk-mock__bar" style="width:55%"></div>
								<div class="dk-mock__bar" style="width:80%"></div>
							</div>
						</div>
					</div>
				</div>
			@endforeach
		</div>
	</div>
</section>
