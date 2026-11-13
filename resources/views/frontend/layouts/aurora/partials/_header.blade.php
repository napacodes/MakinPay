@php
	use App\Services\FeatureManager;

	$auroraMark     = setting('small_logo', 'general/static/logo/digikash-mark.svg') ?: 'general/static/logo/digikash-mark.svg';

	// The bundled mark is its own blue tile. An uploaded one is often a white
	// glyph made for dark surfaces, so in light mode it is set on a brand tile.
	$auroraMarkClass = $auroraMark === 'general/static/logo/digikash-mark.svg' ? 'au-brand__mark' : 'au-brand__mark au-brand__mark--tile';
	$auroraFeatures = app(FeatureManager::class);

	// Only used when no navigation has been configured: point at the sections
	// this theme renders, skipping any whose module is switched off.
	$auroraFallbackNav = array_values(array_filter([
		['href' => '#portals', 'label' => __('Platform')],
		['href' => '#features', 'label' => __('Features')],
		$auroraFeatures->isEnabled('virtual_card') ? ['href' => '#cards', 'label' => __('Cards')] : null,
		$auroraFeatures->isEnabled('remittance') ? ['href' => '#remit', 'label' => __('Remittance')] : null,
		['href' => '#business', 'label' => __('Business')],
		['href' => '#security', 'label' => __('Security')],
	]));

	$auroraNav = isset($navigations) && $navigations->isNotEmpty()
		? $navigations->map(fn ($navigation): array => ['href' => $navigation->url, 'label' => $navigation->label, 'target' => $navigation->target])->all()
		: array_map(fn (array $item): array => $item + ['href' => route('home').$item['href'], 'target' => '_self'], $auroraFallbackNav);
@endphp
<header class="au-header" data-au-header>
	<div class="au-container au-header__inner">
		<a href="{{ route('home') }}" class="au-brand">
			<img src="{{ asset($auroraMark) }}" alt="" class="{{ $auroraMarkClass }}" width="38" height="38">
			<span class="au-brand__name">{{ setting('site_title') }}</span>
		</a>

		<nav class="au-header__nav" aria-label="{{ __('Primary') }}">
			@foreach($auroraNav as $item)
				<a href="{{ $item['href'] }}" target="{{ $item['target'] }}">{{ $item['label'] }}</a>
			@endforeach
		</nav>

		<div class="au-header__actions">
			<button type="button"
			        class="au-icon-btn au-mode"
			        data-au-mode-toggle
			        data-label-to-light="{{ __('Switch to light mode') }}"
			        data-label-to-dark="{{ __('Switch to dark mode') }}"
			        aria-label="{{ __('Switch to light mode') }}"
			        title="{{ __('Switch to light mode') }}">
				<i class="fa-solid fa-sun au-mode__sun" aria-hidden="true"></i>
				<i class="fa-solid fa-moon au-mode__moon" aria-hidden="true"></i>
			</button>
			<a href="{{ route('user.login') }}" class="au-btn au-btn--ghost au-header__login">{{ __('Log in') }}</a>
			<a href="{{ route('user.register') }}" class="au-btn au-btn--primary">{{ __('Open account') }}</a>
			<button type="button"
			        class="au-icon-btn au-header__toggle"
			        data-au-drawer-open
			        aria-expanded="false"
			        aria-controls="au-drawer">
				<i class="fa-solid fa-bars" aria-hidden="true"></i>
				<span class="au-visually-hidden">{{ __('Open menu') }}</span>
			</button>
		</div>
	</div>
</header>

<div class="au-drawer" id="au-drawer" data-au-drawer role="dialog" aria-modal="true" aria-label="{{ __('Menu') }}" hidden>
	<div class="au-drawer__head">
		<span class="au-brand">
			<img src="{{ asset($auroraMark) }}" alt="" class="{{ $auroraMarkClass }}" width="38" height="38">
			<span class="au-brand__name">{{ setting('site_title') }}</span>
		</span>
		<button type="button" class="au-icon-btn" data-au-drawer-close>
			<i class="fa-solid fa-xmark" aria-hidden="true"></i>
			<span class="au-visually-hidden">{{ __('Close menu') }}</span>
		</button>
	</div>

	<nav class="au-drawer__nav" aria-label="{{ __('Mobile') }}">
		@foreach($auroraNav as $item)
			<a class="au-drawer__link" style="--i: {{ $loop->index }}" href="{{ $item['href'] }}" target="{{ $item['target'] }}">{{ $item['label'] }}</a>
		@endforeach
	</nav>

	<div class="au-drawer__actions">
		<a href="{{ route('user.login') }}" class="au-btn au-btn--ghost">{{ __('Log in') }}</a>
		<a href="{{ route('user.register') }}" class="au-btn au-btn--primary">{{ __('Open account') }}</a>
	</div>
</div>
