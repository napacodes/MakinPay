{{--
    Landing Colours. The slot list is whatever the theme defines in
    Theme::colorSlots() — Classic ships Primary/Secondary/Accent, Golden ships
    Gold/Deep Gold/Light Gold — so the panel never hardcodes slot names.
--}}
<section class="tm-colors" aria-labelledby="tm-colors-title" data-tm-colors>

	<div class="tm-colors__head">
		<div class="tm-colors__intro">
			<h2 class="tm-colors__title" id="tm-colors-title">{{ __('Landing Colours') }}</h2>
			<p class="tm-colors__sub">{{ __('Applies to your public landing pages once saved. Dashboards are unaffected.') }}</p>
		</div>

		<div class="tm-tabs" role="tablist" aria-label="{{ __('Choose a theme to edit') }}">
			@foreach($palettes as $palette)
				@php($isActive = $palette['value'] === $activeTheme->value)
				<button type="button"
				        class="tm-tab {{ $isActive ? 'is-selected' : '' }}"
				        role="tab"
				        id="tm-tab-{{ $palette['value'] }}"
				        data-tm-tab="{{ $palette['value'] }}"
				        aria-selected="{{ $isActive ? 'true' : 'false' }}"
				        aria-controls="tm-panel-{{ $palette['value'] }}"
				        tabindex="{{ $isActive ? '0' : '-1' }}"
				        style="--tm-accent: {{ $palette['accent_color'] }}">
					<span class="tm-tab__dot" aria-hidden="true"></span>
					{{ $palette['label'] }}
				</button>
			@endforeach
		</div>
	</div>

	@foreach($palettes as $palette)
		@php($isActive = $palette['value'] === $activeTheme->value)
		<div class="tm-panel {{ $isActive ? 'is-shown' : '' }}"
		     id="tm-panel-{{ $palette['value'] }}"
		     role="tabpanel"
		     aria-labelledby="tm-tab-{{ $palette['value'] }}"
		     data-tm-panel="{{ $palette['value'] }}"
		     @unless($isActive) hidden @endunless>

			<form method="POST"
			      action="{{ route('admin.theme-manager.colors.update', $palette['value']) }}"
			      class="tm-panel__form"
			      data-tm-save-form="{{ $palette['value'] }}">
				@csrf

				<div class="tm-fields">
					@foreach($palette['slots'] as $slot)
						@php($fieldId = 'tm-'.$palette['value'].'-'.$slot['key'])
						<div class="tm-field"
						     data-tm-field="{{ $slot['key'] }}"
						     data-tm-default="{{ $slot['default'] }}"
						     style="--tm-field-value: {{ $slot['value'] }}">

							<label class="tm-field__swatch" data-tm-swatch>
								<span class="visually-hidden" data-tm-swatch-name>
									{{ __(':label colour', ['label' => $slot['label']]) }},
									<span data-tm-swatch-value>{{ $slot['value'] }}</span>
									— {{ __('change') }}
								</span>
								<input type="color"
								       class="visually-hidden"
								       id="{{ $fieldId }}"
								       value="{{ $slot['value'] }}"
								       data-tm-color
								       tabindex="-1">
							</label>

							<div class="tm-field__body">
								<div class="tm-field__row">
									<label class="tm-field__label" for="{{ $fieldId }}-hex">{{ $slot['label'] }}</label>
									<input type="text"
									       class="tm-field__hex"
									       id="{{ $fieldId }}-hex"
									       name="colors[{{ $slot['key'] }}]"
									       value="{{ $slot['value'] }}"
									       maxlength="7"
									       size="7"
									       spellcheck="false"
									       autocomplete="off"
									       aria-describedby="{{ $fieldId }}-hint"
									       data-tm-hex>
								</div>

								<span class="tm-field__hint" id="{{ $fieldId }}-hint">{{ $slot['hint'] }}</span>

								@error('colors.'.$slot['key'])
									<span class="tm-field__error" role="alert">
										<i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>{{ $message }}
									</span>
								@enderror
							</div>

							<button type="button"
							        class="tm-field__reset"
							        data-tm-field-reset
							        title="{{ __('Reset to default') }}">
								<i class="fa-solid fa-arrow-rotate-left" aria-hidden="true"></i>
								<span class="visually-hidden">{{ __('Reset :label to default', ['label' => $slot['label']]) }}</span>
							</button>
						</div>
					@endforeach
				</div>

				@if($palette['modes'] !== [])
					@php($modeId = 'tm-'.$palette['value'].'-default-mode')
					@php($modeLabels = ['dark' => __('Dark'), 'light' => __('Light'), 'system' => __('Follow the visitor\'s device')])
					<div class="tm-mode">
						<div class="tm-mode__copy">
							<label class="tm-mode__label" for="{{ $modeId }}">{{ __('Default mode') }}</label>
							<span class="tm-mode__hint" id="{{ $modeId }}-hint">{{ __('What a first-time visitor sees. Their own toggle is remembered after that.') }}</span>
						</div>
						<select class="tm-mode__select"
						        id="{{ $modeId }}"
						        name="default_mode"
						        aria-describedby="{{ $modeId }}-hint"
						        data-tm-mode
						        data-tm-mode-default="{{ $palette['modes'][0] }}">
							@foreach($palette['modes'] as $mode)
								<option value="{{ $mode }}" @selected($palette['mode'] === $mode)>{{ $modeLabels[$mode] ?? $mode }}</option>
							@endforeach
						</select>
						@error('default_mode')
							<span class="tm-field__error" role="alert">
								<i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>{{ $message }}
							</span>
						@enderror
					</div>
				@endif

				<div class="tm-panel__footer">
					<button type="submit"
					        form="tm-reset-{{ $palette['value'] }}"
					        class="tm-btn tm-btn--ghost"
					        data-tm-reset-all="{{ $palette['value'] }}">
						<i class="fa-solid fa-arrow-rotate-left" aria-hidden="true"></i>{{ __('Reset to defaults') }}
					</button>

					<button type="submit"
					        class="tm-btn tm-btn--save"
					        data-tm-save
					        data-label-idle="{{ __('Save :theme palette', ['theme' => $palette['label']]) }}"
					        data-label-saving="{{ __('Saving…') }}"
					        data-label-saved="{{ __('Saved') }}"
					        data-label-error="{{ __('Could not save — retry') }}">
						<span class="tm-btn__icon" aria-hidden="true"></span>
						<span data-tm-save-label>{{ __('Save :theme palette', ['theme' => $palette['label']]) }}</span>
					</button>
				</div>

				<p class="visually-hidden" role="status" aria-live="polite" data-tm-status></p>
			</form>

			{{-- Target of the footer reset button, kept out of the save form. --}}
			<form method="POST"
			      action="{{ route('admin.theme-manager.colors.reset', $palette['value']) }}"
			      id="tm-reset-{{ $palette['value'] }}"
			      class="d-none"
			      data-tm-reset-form="{{ $palette['value'] }}">
				@csrf
			</form>
		</div>
	@endforeach
</section>
