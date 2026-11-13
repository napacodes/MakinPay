{{--
	The phone field with its country picker. Markup only: phone-sign-in.js
	drives the picker, phone-sign-in.css draws it, and the Golden theme
	restyles the same markup through .psf--golden.
--}}
@php
	$menuId   = $id.'-countries';
	$hintId   = $id.'-hint';
	$hasHome  = $home !== null;
	$isLocal  = $isLocal();
	$hintNone = __('Pick your country, or type your number with its + code.');
	$hintHome = __('Type your number the way you dial it at home — no :dial needed.', ['dial' => '__DIAL__']);
	$hintAway = __('International number: we add :dial for you. Check the country is right before you continue.', ['dial' => '__DIAL__']);
	$hint     = $selected === null ? $hintNone : str_replace('__DIAL__', $selected['dial'], $isLocal ? $hintHome : $hintAway);
@endphp
<div class="psf {{ $golden ? 'psf--golden' : '' }}"
     data-phone-field
     data-home="{{ $home['code'] ?? '' }}"
     data-hint-none="{{ $hintNone }}"
     data-hint-home="{{ $hintHome }}"
     data-hint-away="{{ $hintAway }}">
	<div class="psf__head">
		<label class="psf__label" for="{{ $id }}">{{ __('Phone number') }}</label>
		@if($hasHome)
			<span class="psf__pill psf__pill--local" data-psf-pill="local" @if(! $isLocal) hidden @endif>
				<i class="fa-solid fa-location-dot" aria-hidden="true"></i>{{ __('Local number') }}
			</span>
			<span class="psf__pill psf__pill--away" data-psf-pill="away" @if($isLocal || $selected === null) hidden @endif>
				<i class="fa-solid fa-globe" aria-hidden="true"></i>{{ __('International number') }}
			</span>
		@endif
	</div>

	<div class="psf__control">
		<button type="button"
		        class="psf__country"
		        data-psf-toggle
		        aria-haspopup="listbox"
		        aria-expanded="false"
		        aria-controls="{{ $menuId }}"
		        aria-label="{{ __('Country: :name. Change', ['name' => $selected['name'] ?? __('none')]) }}"
		        @disabled($disabled)>
			<span class="psf__code" data-psf-code>{{ $selected['code'] ?? '—' }}</span>
			<span class="psf__dial" data-psf-dial>{{ $selected['dial'] ?? '+' }}</span>
			<i class="fa-solid fa-chevron-down psf__chevron" aria-hidden="true"></i>
		</button>
		<input type="tel"
		       class="psf__input"
		       id="{{ $id }}"
		       name="{{ $name }}"
		       value="{{ $value }}"
		       inputmode="tel"
		       autocomplete="tel"
		       placeholder="{{ __('Your phone number') }}"
		       aria-describedby="{{ $hintId }}"
		       required
		       @disabled($disabled)
		       @if($autofocus) autofocus @endif>
		<input type="hidden" name="{{ $countryName }}" value="{{ $selected['code'] ?? '' }}" data-psf-country @disabled($disabled)>
	</div>

	<p class="psf__hint" id="{{ $hintId }}" data-psf-hint>
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<span data-psf-hint-text>{{ $hint }}</span>
	</p>

	<div class="psf__menu" id="{{ $menuId }}" data-psf-menu hidden>
		<label class="visually-hidden psf__sr" for="{{ $id }}-search">{{ __('Search country') }}</label>
		<input type="search" class="psf__search" id="{{ $id }}-search" placeholder="{{ __('Search country or code') }}" autocomplete="off" data-psf-search>
		<div class="psf__list" role="listbox" aria-label="{{ __('Choose country') }}">
			@if($hasHome)
				<div class="psf__group" data-psf-group>{{ __('Home country') }}</div>
				<button type="button" class="psf__option {{ $isLocal ? 'is-selected' : '' }}" role="option"
				        aria-selected="{{ $isLocal ? 'true' : 'false' }}"
				        data-psf-option data-code="{{ $home['code'] }}" data-dial="{{ $home['dial_code'] }}" data-name="{{ $home['name'] }}">
					<span class="psf__code">{{ $home['code'] }}</span>
					<span class="psf__option-copy">
						<span class="psf__option-name">{{ $home['name'] }}</span>
						<span class="psf__option-note">{{ __('No country code needed') }}</span>
					</span>
					<span class="psf__option-dial">{{ $home['dial_code'] }}</span>
				</button>
				<div class="psf__group" data-psf-group>{{ __('Other countries') }}</div>
			@endif
			@foreach($others() as $country)
				@php($picked = ($selected['code'] ?? null) === $country['code'])
				<button type="button" class="psf__option {{ $picked ? 'is-selected' : '' }}" role="option"
				        aria-selected="{{ $picked ? 'true' : 'false' }}"
				        data-psf-option data-code="{{ $country['code'] }}" data-dial="{{ $country['dial'] }}" data-name="{{ $country['name'] }}">
					<span class="psf__code">{{ $country['code'] }}</span>
					<span class="psf__option-copy">
						<span class="psf__option-name">{{ $country['name'] }}</span>
					</span>
					<span class="psf__option-dial">{{ $country['dial'] }}</span>
				</button>
			@endforeach
			<p class="psf__empty" data-psf-empty hidden>{{ __('No country matches your search.') }}</p>
		</div>
	</div>
</div>
