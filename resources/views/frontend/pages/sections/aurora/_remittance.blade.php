@php
	use App\Services\AuroraLandingData;

	$locale  = $locale ?? app()->getLocale();
	$landing = app(AuroraLandingData::class);
	$enabled = $landing->isEnabled('remittance');

	$quote  = $enabled ? $landing->remittanceCorridors() : null;
	$tiers  = $enabled ? $landing->remittanceTiers() : [];
	$first  = $quote['corridors'][0] ?? null;
	$amount = 500;

	$pointIcons = ['fa-solid fa-building-columns', 'fa-solid fa-arrows-rotate', 'fa-solid fa-address-book', 'fa-solid fa-scale-balanced'];
	$points     = collect(['point_one', 'point_two', 'point_three', 'point_four'])
		->map(fn (string $key): ?string => $data[$key][$locale] ?? null)
		->filter()
		->values()
		->map(function (string $point, int $index) use ($pointIcons): array {
			$parts = array_map('trim', explode(':', $point, 2));

			return ['icon' => $pointIcons[$index] ?? 'fa-solid fa-check', 'title' => $parts[0], 'text' => $parts[1] ?? ''];
		});

	// Server-rendered starting figures, so the card reads correctly before
	// (or without) JavaScript. Same arithmetic the script and QuoteService use.
	$precision = fn (int $decimals): string => $decimals === 0 ? __('no decimals') : __(':count decimals', ['count' => $decimals]);
	if ($first) {
		$startFee     = money($first['fixed_fee'] + ($amount * $first['percent_fee'] / 100), $quote['source']['code'], false);
		$startRate    = number_format($first['rate'], $first['rate'] < 1 ? 4 : 2);
		$startReceive = money($amount * $first['rate'], $first['code'], false);
	}
@endphp
@if($enabled && $first)
	<section class="au-section au-remit" id="remit">
		<div class="au-container au-split">
			<div class="au-calc"
			     data-au-calc="{{ json_encode(['source' => $quote['source'], 'corridors' => $quote['corridors']]) }}"
			     data-label-decimals="{{ __(':count decimals') }}"
			     data-label-no-decimals="{{ __('no decimals') }}"
			     data-au-reveal>
				<div class="au-calc__head">
					<span class="au-calc__title">{{ __('Send money abroad') }}</span>
					<span class="au-pill-info"><i class="fa-solid fa-lock" aria-hidden="true"></i> {{ __('Rate locked :time', ['time' => sprintf('%02d:00', $quote['ttl_minutes'])]) }}</span>
				</div>

				<div class="au-calc__box">
					<label class="au-calc__label" for="au-calc-amount">{{ __('You send') }}</label>
					<div class="au-calc__line">
						<input class="au-calc__input" id="au-calc-amount" type="text" inputmode="decimal" autocomplete="off" value="{{ $amount }}" data-au-calc-amount>
						<span class="au-calc__ccy">{{ $quote['source']['code'] }}</span>
					</div>
				</div>

				<dl class="au-calc__rows">
					<div class="au-calc__row">
						<dt>{{ $data['fee_label'][$locale] ?? __('Transfer fee') }}</dt>
						<dd data-au-calc-fee>+ {{ $startFee }} {{ $quote['source']['code'] }}</dd>
					</div>
					<div class="au-calc__row">
						<dt>{{ __('Locked rate') }}</dt>
						<dd class="au-mono" data-au-calc-rate>1 {{ $quote['source']['code'] }} = {{ $startRate }} {{ $first['code'] }}</dd>
					</div>
				</dl>

				<div class="au-calc__receive">
					<span class="au-calc__label">{{ __('They receive') }}</span>
					<div class="au-calc__line">
						<output class="au-calc__out" for="au-calc-amount" data-au-calc-receive>{{ $startReceive }}</output>
						<span class="au-calc__ccy au-calc__ccy--brand" data-au-calc-code>{{ $first['code'] }}</span>
					</div>
				</div>

				@if(count($quote['corridors']) > 1)
					<div class="au-dests" role="group" aria-label="{{ __('Destination currency') }}">
						@foreach($quote['corridors'] as $corridor)
							<button type="button" class="au-dest" data-au-dest="{{ $corridor['code'] }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}" title="{{ $corridor['name'] }}">{{ $corridor['code'] }}</button>
						@endforeach
					</div>
				@endif

				<div class="au-calc__foot">
					<span data-au-calc-name>{{ $first['name'] }} · {{ $precision($first['decimals']) }}</span>
					<span>{{ $quote['live'] ? __('Indicative rates') : __('Sample rates') }}</span>
				</div>
			</div>

			<div>
				<div data-au-reveal style="--i: 1">
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

				@if($points->isNotEmpty())
					<ul class="au-points" role="list" data-au-reveal style="--i: 2">
						@foreach($points as $point)
							<li class="au-point">
								<i class="{{ $point['icon'] }}" aria-hidden="true"></i>
								<div class="au-point__title">{{ $point['title'] }}</div>
								@if($point['text'] !== '')
									<div class="au-point__text">{{ $point['text'] }}</div>
								@endif
							</li>
						@endforeach
					</ul>
				@endif

				@if($tiers !== [])
					<div class="au-tiers-wrap" data-au-reveal style="--i: 3">
						<table class="au-tiers">
							<caption class="au-visually-hidden">{{ __('Sending limits by verification tier') }}</caption>
							<thead>
								<tr>
									<th scope="col">{{ __('Tier') }}</th>
									<th scope="col">{{ __('Per send') }}</th>
									<th scope="col">{{ __('Daily') }}</th>
									<th scope="col">{{ __('Monthly') }}</th>
								</tr>
							</thead>
							<tbody>
								@foreach($tiers as $tier)
									<tr @class(['is-top' => $tier['top']])>
										<th scope="row">{{ $tier['tier'] }}</th>
										<td>{{ $tier['per_transaction'] }}</td>
										<td>{{ $tier['daily'] }}</td>
										<td>{{ $tier['monthly'] }}</td>
									</tr>
								@endforeach
							</tbody>
						</table>
					</div>
				@endif
			</div>
		</div>
	</section>
@endif
