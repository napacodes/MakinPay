@php
	use App\Services\AuroraLandingData;

	$locale  = $locale ?? app()->getLocale();
	$landing = app(AuroraLandingData::class);

	$defaultCta = [
		'user'     => route('user.register'),
		'merchant' => route('merchant.register'),
		'agent'    => route('agent.register'),
	];

	// The Agent tab goes when the agent programme is switched off, along with
	// every other agent surface.
	$portals = $repeatedContents
		->map(function ($repeatedContent) use ($locale, $defaultCta): array {
			$rc  = $repeatedContent->content_data ?? [];
			$key = strtolower(trim((string) ($rc['portal_key'] ?? ''))) ?: 'portal-'.$repeatedContent->id;

			$items = collect(preg_split('/\r\n|\r|\n/', (string) ($rc['items'][$locale] ?? '')))
				->map(fn (string $line): array => array_map('trim', explode('|', $line, 2)))
				->filter(fn (array $parts): bool => ($parts[0] ?? '') !== '')
				->map(fn (array $parts): array => isset($parts[1])
					? ['icon' => $parts[0], 'text' => $parts[1]]
					: ['icon' => 'fa-solid fa-check', 'text' => $parts[0]])
				->values();

			return [
				'key'   => $key,
				'label' => $rc['tab_label'][$locale] ?? ucfirst($key),
				'icon'  => $rc['tab_icon'] ?? 'fa-solid fa-user',
				'title' => $rc['title'][$locale] ?? '',
				'blurb' => $rc['blurb'][$locale] ?? '',
				'cta'   => $rc['cta_text'][$locale] ?? null,
				'url'   => ($rc['cta_url'] ?? null) ?: ($defaultCta[$key] ?? route('user.register')),
				'items' => $items,
			];
		})
		->reject(fn (array $portal): bool => $portal['key'] === 'agent' && ! $landing->isEnabled('agent_program'))
		->values();
@endphp
@if($portals->isNotEmpty())
	<section class="au-section" id="portals">
		<div class="au-container">
			<div class="au-portals__head">
				<div class="au-head au-head--tight" data-au-reveal>
					@if($data['subheading'][$locale] ?? null)
						<span class="au-eyebrow">{{ $data['subheading'][$locale] }}</span>
					@endif
					@if($data['heading'][$locale] ?? null)
						<h2 class="au-h2">{{ $data['heading'][$locale] }}</h2>
					@endif
				</div>

				@if($portals->count() > 1)
					<div class="au-tabs" role="tablist" aria-label="{{ __('Portals') }}" data-au-tabs data-au-reveal style="--i: 1">
						@foreach($portals as $portal)
							<button type="button"
							        class="au-tab"
							        role="tab"
							        id="au-portal-tab-{{ $portal['key'] }}"
							        aria-controls="au-portal-panel-{{ $portal['key'] }}"
							        aria-selected="{{ $loop->first ? 'true' : 'false' }}"
							        tabindex="{{ $loop->first ? '0' : '-1' }}">
								<i class="{{ $portal['icon'] }}" aria-hidden="true"></i>{{ $portal['label'] }}
							</button>
						@endforeach
					</div>
				@endif
			</div>

			@foreach($portals as $portal)
				<div class="au-panel"
				     id="au-portal-panel-{{ $portal['key'] }}"
				     @if($portals->count() > 1) role="tabpanel" aria-labelledby="au-portal-tab-{{ $portal['key'] }}" tabindex="0" @endif
				     @unless($loop->first) hidden @endunless
				     @if($loop->first) data-au-reveal style="--i: 2" @endif>
					<div class="au-panel__copy">
						<div>
							<h3 class="au-panel__title">{{ $portal['title'] }}</h3>
							@if($portal['blurb'])
								<p class="au-panel__blurb">{{ $portal['blurb'] }}</p>
							@endif
						</div>
						@if($portal['cta'])
							<a href="{{ $portal['url'] }}" class="au-btn au-btn--primary au-btn--md">
								{{ $portal['cta'] }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
							</a>
						@endif
					</div>

					@if($portal['items']->isNotEmpty())
						<ul class="au-items">
							@foreach($portal['items'] as $item)
								<li class="au-item">
									<span class="au-item__icon" aria-hidden="true"><i class="{{ $item['icon'] }}"></i></span>
									<span>{{ $item['text'] }}</span>
								</li>
							@endforeach
						</ul>
					@endif
				</div>
			@endforeach
		</div>
	</section>
@endif
