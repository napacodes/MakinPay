@php
	/*
	 * The default (classic) theme's picker. It has to survive a site whose
	 * `languages` table is empty or missing the active locale: the homepage
	 * 500'd with "Attempt to read property 'flag' on null" when a fresh
	 * install seeded no languages, and a switcher is not worth taking the
	 * whole page down for.
	 */
	$switcherLanguages = $languages ?? collect();
	$currentLanguage   = $switcherLanguages->firstWhere('code', app()->getLocale()) ?? $switcherLanguages->first();
@endphp

@if($currentLanguage)
	<div class="language-selector">
		<div class="dropdown">
			<a href="#" class="dropdown-toggle d-flex align-items-center" data-bs-toggle="dropdown" aria-expanded="false">
				@if($currentLanguage->flag)
					<img src="{{ asset($currentLanguage->flag) }}" alt="{{ $currentLanguage->name }}" class="flag-icon" loading="lazy">
				@endif
				<span class="language-name {{ $textColor ?? '' }}">{{ $currentLanguage->displayName() }}</span>
			</a>
			<ul class="dropdown-menu">
				@foreach($switcherLanguages as $language)
					<li class="mb-0">
						<a href="{{ route('locale-set', $language->code) }}"
						   class="dropdown-item d-flex align-items-center {{ $language->code == app()->getLocale() ? 'active' : '' }}">
							@if($language->flag)
								<img src="{{ asset($language->flag) }}" alt="{{ $language->name }}" class="flag-icon-small me-2" loading="lazy">
							@endif
							{{ $language->displayName() }}
						</a>
					</li>
				@endforeach
			</ul>
		</div>
	</div>
@endif
