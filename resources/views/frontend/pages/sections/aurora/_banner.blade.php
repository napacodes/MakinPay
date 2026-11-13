@php
	use App\Services\AuroraLandingData;

	$locale  = $locale ?? app()->getLocale();
	$landing = app(AuroraLandingData::class);

	// Tokens an administrator can drop into the copy so it never goes stale:
	// {version} is the installed release, {features} the count of features
	// switched on in Feature Management, {card_issuers} the card-issuer
	// drivers this release ships.
	$tokens = [
		'{version}'      => (string) config('app.version'),
		'{features}'     => (string) count($landing->featureCatalog()),
		'{card_issuers}' => (string) count((array) config('virtual_card.providers', [])),
	];
	$fill = fn (?string $text): string => strtr((string) $text, $tokens);

	$stats = collect(['stat_one', 'stat_two', 'stat_three'])
		->map(fn (string $key): ?string => $data[$key][$locale] ?? null)
		->filter()
		->map(function (string $stat) use ($fill): array {
			$parts = array_map('trim', explode(':', $fill($stat), 2));

			return ['value' => $parts[0], 'label' => $parts[1] ?? ''];
		});
@endphp
<section class="au-hero" id="top">
	<div class="au-gridlines" aria-hidden="true"></div>

	<div class="au-container au-hero__inner">
		<div>
			@if($data['badge_text'][$locale] ?? null)
				<div class="au-badge" data-au-reveal>
					@if($data['badge_label'][$locale] ?? null)
						<span class="au-badge__tag">{{ $data['badge_label'][$locale] }}</span>
					@endif
					<span class="au-badge__text">{{ $fill($data['badge_text'][$locale]) }}</span>
				</div>
			@endif

			<h1 class="au-hero__title" data-au-reveal style="--i: 1">
				{{ $data['heading'][$locale] ?? '' }}
				@if($data['heading_highlight'][$locale] ?? null)
					<span class="au-gradient-text">{{ $data['heading_highlight'][$locale] }}</span>
				@endif
			</h1>

			@if($data['description'][$locale] ?? null)
				<p class="au-hero__lead" data-au-reveal style="--i: 2">{{ $data['description'][$locale] }}</p>
			@endif

			<div class="au-hero__actions" data-au-reveal style="--i: 3">
				<a href="{{ ($data['button_url'] ?? null) ?: route('user.register') }}" class="au-btn au-btn--primary au-btn--lg">
					{{ $data['button_text'][$locale] ?? __('Launch your wallet') }}
					<i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
				</a>
				@if($data['button2_text'][$locale] ?? null)
					<a href="{{ ($data['button2_url'] ?? null) ?: '#features' }}" class="au-btn au-btn--secondary">
						{{ $data['button2_text'][$locale] }}
					</a>
				@endif
			</div>

			@if($stats->isNotEmpty())
				<ul class="au-stats" data-au-reveal style="--i: 4">
					@foreach($stats as $stat)
						<li class="au-stat">
							<span class="au-stat__value">{{ $stat['value'] }}</span>
							<span class="au-stat__label">{{ $stat['label'] }}</span>
						</li>
					@endforeach
				</ul>
			@endif
		</div>

		{{-- Illustrative wallet: placeholder figures, never a real account, and
		     hidden from assistive tech so a screen reader does not read out a
		     balance nobody owns. --}}
		<div class="au-comp" data-au-dark aria-hidden="true" data-au-reveal style="--i: 2">
			@if($landing->isEnabled('virtual_card'))
				<div class="au-comp__card">
					<div class="au-sheen"></div>
					<div class="au-comp__card-top">
						<span class="au-comp__card-brand">{{ setting('site_title') }}</span>
						<i class="fa-solid fa-wifi au-contactless"></i>
					</div>
					<div class="au-comp__chip"></div>
					<div class="au-comp__pan">•••• 7710</div>
				</div>
			@endif

			<div class="au-wallet">
				<div class="au-wallet__head">
					<div class="au-wallet__who">
						<div class="au-avatar">AR</div>
						<div>
							<div class="au-wallet__greet">{{ __('Good evening') }}</div>
							<div class="au-wallet__name">Amina Rahman</div>
						</div>
					</div>
					<span class="au-pill-ok"><i class="fa-solid fa-circle-check"></i> {{ __('KYC verified') }}</span>
				</div>

				<div class="au-balance">
					<div class="au-balance__top">
						<span class="au-balance__label">{{ __('Total balance') }}</span>
						<span class="au-balance__ccy">USD</span>
					</div>
					<div class="au-balance__amount">$48,751<small>.72</small></div>
					<div class="au-balance__id"><i class="fa-regular fa-copy"></i> DK-USD-9F2A-E710</div>
				</div>

				<div class="au-quick">
					<div class="au-quick__item"><span class="au-quick__icon"><i class="fa-solid fa-circle-arrow-down"></i></span>{{ __('Deposit') }}</div>
					<div class="au-quick__item"><span class="au-quick__icon"><i class="fa-solid fa-paper-plane"></i></span>{{ __('Send') }}</div>
					<div class="au-quick__item"><span class="au-quick__icon"><i class="fa-solid fa-hand-holding-dollar"></i></span>{{ __('Request') }}</div>
					<div class="au-quick__item"><span class="au-quick__icon"><i class="fa-solid fa-right-left"></i></span>{{ __('Exchange') }}</div>
				</div>

				<div class="au-activity__label">{{ __('Recent activity') }}</div>
				<ul class="au-activity">
					@if($landing->isEnabled('remittance'))
						<li class="au-activity__row">
							<span class="au-activity__icon au-activity__icon--info"><i class="fa-solid fa-earth-americas"></i></span>
							<div class="au-activity__body">
								<div class="au-activity__title">{{ __('Remittance to Dhaka') }}</div>
								<div class="au-activity__meta">{{ __('Locked rate · via Bitnob') }}</div>
							</div>
							<span class="au-activity__amount">−$250.00</span>
						</li>
					@endif
					@if($landing->isEnabled('vaults'))
						<li class="au-activity__row">
							<span class="au-activity__icon au-activity__icon--ok"><i class="fa-solid fa-vault"></i></span>
							<div class="au-activity__body">
								<div class="au-activity__title">{{ __('Round-up to “Hajj 2027”') }}</div>
								<div class="au-activity__meta">{{ __('Vault auto-save') }}</div>
							</div>
							<span class="au-activity__amount au-activity__amount--in">+$0.64</span>
						</li>
					@endif
					@if($landing->isEnabled('virtual_card'))
						<li class="au-activity__row">
							<span class="au-activity__icon au-activity__icon--violet"><i class="fa-solid fa-credit-card"></i></span>
							<div class="au-activity__body">
								<div class="au-activity__title">{{ __('Card top-up') }}</div>
								<div class="au-activity__meta">{{ __('Virtual card •• 7710') }}</div>
							</div>
							<span class="au-activity__amount">−$120.00</span>
						</li>
					@endif
				</ul>
			</div>

			@if($landing->isEnabled('remittance') || $landing->isEnabled('exchange_money'))
				<div class="au-float">
					<span class="au-float__icon"><i class="fa-solid fa-lock"></i></span>
					<div>
						<div class="au-float__label">{{ __('FX rate locked') }}</div>
						<div class="au-float__value">1 USD = 121.84 BDT</div>
					</div>
				</div>
			@endif
		</div>
	</div>
</section>
