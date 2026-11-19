@php
	/*
	 * The default (classic) theme's picker. It has to survive a site whose
	 * `languages` table is empty or missing the active locale: the homepage
	 * 500'd with "Attempt to read property 'flag' on null" when a fresh
	 * install seeded no languages, and a switcher is not worth taking the
	 * whole page down for.
	 *
	 * Resolution lives in LanguageSwitcher so this partial, the Golden and the
	 * user-panel pickers all agree on which row is "current" and on what a
	 * language without a flag image looks like.
	 */
	$switcherLanguages = $languages ?? collect();
	$currentLanguage   = \App\Support\LanguageSwitcher::current($switcherLanguages);
@endphp

@if($currentLanguage && \App\Support\LanguageSwitcher::shouldRender($switcherLanguages))
	<div class="language-selector">
		<div class="dropdown">
			<a href="#" class="dropdown-toggle d-flex align-items-center" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('Select language') }}">
				@if($currentLanguage->flag)
					<img src="{{ asset($currentLanguage->flag) }}" alt="{{ $currentLanguage->name }}" class="flag-icon" loading="lazy">
				@else
					{{-- No flag image: keep the row aligned with a code badge. --}}
					<span class="flag-icon flag-icon-fallback" aria-hidden="true">{{ \App\Support\LanguageSwitcher::flagFallback($currentLanguage) }}</span>
				@endif
				<span class="language-name {{ $textColor ?? '' }}">{{ $currentLanguage->displayName() }}</span>
			</a>
			<ul class="dropdown-menu">
				@foreach($switcherLanguages as $language)
					<li class="mb-0">
						<a href="{{ route('locale-set', $language->code) }}"
						   class="dropdown-item d-flex align-items-center {{ $language->code == app()->getLocale() ? 'active' : '' }}"
						   hreflang="{{ $language->code }}"
						   @if($language->code == app()->getLocale()) aria-current="true" @endif>
							@if($language->flag)
								<img src="{{ asset($language->flag) }}" alt="{{ $language->name }}" class="flag-icon-small me-2" loading="lazy">
							@else
								<span class="flag-icon-small flag-icon-fallback me-2" aria-hidden="true">{{ \App\Support\LanguageSwitcher::flagFallback($language) }}</span>
							@endif
							{{ $language->displayName() }}
						</a>
					</li>
				@endforeach
			</ul>
		</div>
	</div>
@endif
