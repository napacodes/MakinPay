@php
	use App\Services\AuroraLandingData;

	$locale  = $locale ?? app()->getLocale();
	$landing = app(AuroraLandingData::class);

	// One bullet per line: "icon | text", optionally "| feature_key" — a
	// bullet naming a feature leaves with it when the feature is switched off.
	$bullets = collect(preg_split('/\r\n|\r|\n/', (string) ($data['bullets'][$locale] ?? '')))
		->map(fn (string $line): array => array_map('trim', explode('|', $line)))
		->filter(fn (array $parts): bool => ($parts[0] ?? '') !== '')
		->map(fn (array $parts): array => count($parts) === 1
			? ['icon' => 'fa-solid fa-check', 'text' => $parts[0], 'feature' => '']
			: ['icon' => $parts[0], 'text' => $parts[1], 'feature' => $parts[2] ?? ''])
		->filter(fn (array $bullet): bool => $bullet['feature'] === '' || $landing->isEnabled($bullet['feature']))
		->values();

	$showVault     = $landing->isEnabled('vaults');
	$showBudget    = $landing->isEnabled('budgets');
	$showScheduled = $landing->isEnabled('scheduled_payments');
@endphp
<section class="au-save">
	<div class="au-container">
		<div class="au-save__band">
			<div data-au-reveal>
				@if($data['subheading'][$locale] ?? null)
					<span class="au-eyebrow">{{ $data['subheading'][$locale] }}</span>
				@endif
				@if($data['heading'][$locale] ?? null)
					<h2 class="au-save__title">{{ $data['heading'][$locale] }}</h2>
				@endif
				@if($data['description'][$locale] ?? null)
					<p class="au-lead">{{ $data['description'][$locale] }}</p>
				@endif

				@if($bullets->isNotEmpty())
					<ul class="au-bullets">
						@foreach($bullets as $bullet)
							<li class="au-bullet"><i class="{{ $bullet['icon'] }}" aria-hidden="true"></i>{{ $bullet['text'] }}</li>
						@endforeach
					</ul>
				@endif
			</div>

			@if($showVault || $showBudget || $showScheduled)
				{{-- Illustrative goal, budget and schedule — sample figures. --}}
				<div class="au-save__mocks" aria-hidden="true" data-au-reveal style="--i: 1">
					@if($showVault)
						<div class="au-mini au-mini--goal">
							<div class="au-mini__head">
								<div class="au-mini__who">
									<span class="au-mini__icon"><i class="fa-solid fa-vault"></i></span>
									<div>
										<div class="au-mini__name">{{ __('Hajj 2027') }}</div>
										<div class="au-mini__sub">{{ __('Vault · round-ups on') }}</div>
									</div>
								</div>
								<span class="au-mini__pct">68%</span>
							</div>
							<div class="au-progress"><span class="au-progress__bar au-progress__bar--68"></span></div>
							<div class="au-mini__foot">
								<span><b>$6,120</b> {{ __('saved') }}</span>
								<span>{{ __('Goal :amount', ['amount' => '$9,000']) }}</span>
							</div>
						</div>
					@endif

					@if($showBudget || $showScheduled)
						<div class="au-mini__pair">
							@if($showBudget)
								<div class="au-mini">
									<div class="au-mini__k">{{ __('Dining budget') }}</div>
									<div class="au-mini__big">$412 <small>/ $500</small></div>
									<div class="au-progress au-progress--thin"><span class="au-progress__bar au-progress__bar--82"></span></div>
									<div class="au-mini__warn"><i class="fa-solid fa-bell"></i> {{ __('Nearing limit') }}</div>
								</div>
							@endif
							@if($showScheduled)
								<div class="au-mini">
									<div class="au-mini__k">{{ __('Scheduled') }}</div>
									<div class="au-mini__strong">{{ __('Rent to Karim') }}</div>
									<div class="au-mini__text">{{ __('Monthly · 1st') }}</div>
									<div class="au-mini__next"><i class="fa-solid fa-calendar-check"></i> {{ __('Next: :date', ['date' => now()->addMonthNoOverflow()->startOfMonth()->translatedFormat('M j')]) }}</div>
								</div>
							@endif
						</div>
					@endif
				</div>
			@endif
		</div>
	</div>
</section>
