@php
	use App\Services\AuroraLandingData;

	$locale  = $locale ?? app()->getLocale();
	$landing = app(AuroraLandingData::class);

	// A row tied to a feature (feature_key) disappears with that feature. The
	// first row is the tall featured card with the payment-link mock — but
	// only while it is still the first row, so that switching Payment Links
	// off never dresses another product up as a payment link.
	$cards = $repeatedContents
		->values()
		->map(fn ($repeatedContent, int $index): array => ['rc' => $repeatedContent->content_data ?? [], 'index' => $index])
		->filter(function (array $row) use ($landing): bool {
			$feature = trim((string) ($row['rc']['feature_key'] ?? ''));

			return $feature === '' || $landing->isEnabled($feature);
		})
		->values();
@endphp
@if($cards->isNotEmpty())
	<section class="au-section" id="business">
		<div class="au-container">
			<div class="au-head" data-au-reveal>
				@if($data['subheading'][$locale] ?? null)
					<span class="au-eyebrow">{{ $data['subheading'][$locale] }}</span>
				@endif
				@if($data['heading'][$locale] ?? null)
					<h2 class="au-h2">{{ $data['heading'][$locale] }}</h2>
				@endif
				@if($data['description'][$locale] ?? null)
					<p class="au-lead">{{ $data['description'][$locale] }}</p>
				@endif
			</div>

			<ul class="au-biz">
				@foreach($cards as $card)
					@php $rc = $card['rc']; @endphp
					@if($card['index'] === 0)
						<li class="au-biz__featured" data-au-dark data-au-reveal>
							<span class="au-biz__icon" aria-hidden="true"><i class="{{ $rc['icon'] ?? 'fa-solid fa-link' }}"></i></span>
							<div>
								<h3 class="au-biz__title">{{ $rc['title'][$locale] ?? '' }}</h3>
								<p class="au-biz__text">{{ $rc['text'][$locale] ?? '' }}</p>
							</div>
							<div class="au-paylink" aria-hidden="true">
								<div class="au-paylink__url">
									<i class="fa-solid fa-link"></i>
									<span>{{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'yourbrand.com' }}/pay/studio-rent-oct</span>
									<i class="fa-regular fa-copy"></i>
								</div>
								<div class="au-paylink__foot">
									<div>
										<div class="au-paylink__label">{{ __('Amount due') }}</div>
										<div class="au-paylink__amount">$1,200.00</div>
									</div>
									<span class="au-paylink__pay">{{ __('Pay now') }}</span>
								</div>
							</div>
						</li>
					@else
						<li class="au-biz__card" data-au-reveal style="--i: {{ $loop->index % 3 }}">
							<span class="au-biz__icon" aria-hidden="true"><i class="{{ $rc['icon'] ?? 'fa-solid fa-store' }}"></i></span>
							<div>
								<h3 class="au-biz__title">{{ $rc['title'][$locale] ?? '' }}</h3>
								<p class="au-biz__text">{{ $rc['text'][$locale] ?? '' }}</p>
							</div>
						</li>
					@endif
				@endforeach
			</ul>
		</div>
	</section>
@endif
