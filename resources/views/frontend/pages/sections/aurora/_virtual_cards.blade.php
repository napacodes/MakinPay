@php
	use App\Services\AuroraLandingData;

	$locale  = $locale ?? app()->getLocale();
	$landing = app(AuroraLandingData::class);

	$enabled   = $landing->isEnabled('virtual_card');
	$providers = $enabled ? $landing->cardProviders() : [];
	$labels    = $landing->cardCapabilityLabels();

	// A capability row nobody supports is noise; drop it.
	$rows = collect($labels)
		->filter(fn (string $label, string $capability): bool => collect($providers)->contains(fn (array $provider): bool => $provider['capabilities'][$capability] ?? false));

	$themes = [
		'midnight' => __('Midnight'),
		'ocean'    => __('Ocean'),
		'graphite' => __('Graphite'),
		'emerald'  => __('Emerald'),
		'violet'   => __('Violet'),
	];

	$cardMark = setting('small_logo', 'general/static/logo/digikash-mark.svg') ?: 'general/static/logo/digikash-mark.svg';
@endphp
@if($enabled)
	<section class="au-section au-cards" id="cards">
		<div class="au-container au-split">
			<div>
				<div data-au-reveal>
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

				@if($providers !== [] && $rows->isNotEmpty())
					<div class="au-matrix-wrap" data-au-reveal style="--i: 1">
						<table class="au-matrix">
							<caption class="au-visually-hidden">{{ __('What each card provider supports') }}</caption>
							<thead>
								<tr>
									<th scope="col">{{ __('Capability') }}</th>
									@foreach($providers as $provider)
										<th scope="col">{{ $provider['name'] }}</th>
									@endforeach
								</tr>
							</thead>
							<tbody>
								@foreach($rows as $capability => $label)
									<tr>
										<th scope="row">{{ $label }}</th>
										@foreach($providers as $provider)
											<td>
												@if($provider['capabilities'][$capability] ?? false)
													<i class="fa-solid fa-circle-check au-yes" aria-hidden="true"></i>
													<span class="au-visually-hidden">{{ __('Supported') }}</span>
												@else
													<i class="fa-solid fa-minus au-no" aria-hidden="true"></i>
													<span class="au-visually-hidden">{{ __('Not supported') }}</span>
												@endif
											</td>
										@endforeach
									</tr>
								@endforeach
							</tbody>
						</table>
					</div>
				@endif
			</div>

			{{-- Card preview: a design sample, not a real card number. --}}
			<div class="au-cardstage" data-au-cardstage data-au-reveal style="--i: 1">
				<div class="au-vcard-wrap" data-au-dark data-au-card-theme="midnight">
					<div class="au-vcard__glow" aria-hidden="true"></div>
					<div class="au-vcard" role="img" aria-label="{{ __('Sample virtual card') }}">
						<div class="au-sheen"></div>
						<div class="au-vcard__ring au-vcard__ring--lg"></div>
						<div class="au-vcard__ring au-vcard__ring--sm"></div>
						<div class="au-vcard__row">
							<span class="au-vcard__brand"><img src="{{ asset($cardMark) }}" alt="">{{ setting('site_title') }}</span>
							<span class="au-vcard__tag">{{ __('VIRTUAL') }}</span>
						</div>
						<div class="au-vcard__chiprow">
							<span class="au-vcard__chip"></span>
							<i class="fa-solid fa-wifi au-contactless"></i>
						</div>
						<div class="au-vcard__pan">4242 •••• •••• 7710</div>
						<div class="au-vcard__row au-vcard__row--end">
							<div>
								<div class="au-vcard__k">{{ __('CARDHOLDER') }}</div>
								<div class="au-vcard__v">AMINA RAHMAN</div>
							</div>
							<div>
								<div class="au-vcard__k">{{ __('EXPIRES') }}</div>
								<div class="au-vcard__v au-mono">09/29</div>
							</div>
							<span class="au-vcard__network">VISA</span>
						</div>
						<div class="au-vcard__frozen" data-au-frozen hidden>
							<i class="fa-solid fa-snowflake"></i>
							<span>{{ __('CARD FROZEN') }}</span>
						</div>
					</div>
				</div>

				<div class="au-cardctl">
					<div class="au-swatches" role="group" aria-label="{{ __('Card design') }}">
						@foreach($themes as $key => $name)
							<button type="button"
							        class="au-swatch"
							        data-au-card-theme="{{ $key }}"
							        data-au-swatch="{{ $key }}"
							        data-au-swatch-name="{{ $name }}"
							        aria-pressed="{{ $loop->first ? 'true' : 'false' }}"
							        title="{{ $name }}">
								<span class="au-visually-hidden">{{ $name }}</span>
							</button>
						@endforeach
					</div>
					<button type="button"
					        class="au-freeze"
					        data-au-freeze
					        aria-pressed="false"
					        data-label-freeze="{{ __('Freeze card') }}"
					        data-label-unfreeze="{{ __('Unfreeze card') }}">
						<i class="fa-solid fa-snowflake" aria-hidden="true"></i><span data-au-freeze-label>{{ __('Freeze card') }}</span>
					</button>
				</div>

				<p class="au-cardstage__caption">
					{{ __('Theme:') }} <span data-au-card-theme-name>{{ $themes['midnight'] }}</span> · {{ __('Freeze, limits, top-up & withdraw from the wallet') }}
				</p>
			</div>
		</div>
	</section>
@endif
