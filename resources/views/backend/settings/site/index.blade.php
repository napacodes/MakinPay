@extends('backend.settings.index')

@push('styles')
	{{-- Blade mangles the single-line php directive in a file that also
	     carries a php block, so the cache-buster is built in place here. --}}
	<link rel="stylesheet"
	      href="{{ asset('backend/css/admin-savebar.css?v='.config('app.version').'-'.filemtime(public_path('backend/css/admin-savebar.css'))) }}">
	<link rel="stylesheet"
	      href="{{ asset('backend/css/settings-site.css?v='.config('app.version').'-'.filemtime(public_path('backend/css/settings-site.css'))) }}">
@endpush

@section('setting_content')
	@php
		// A link may open a specific section directly (?section=kyc_settings);
		// otherwise the section flashed by the last save wins.
		$requestedSection = request()->query('section');
		$activeSection    = is_string($requestedSection) && isset($settings[$requestedSection])
			? $requestedSection
			: session()->get('section', 'general_settings');

		if (! isset($settings[$activeSection])) {
			$activeSection = (string) array_key_first($settings);
		}

		$storageStatus = $storageLinkStatus ?? [
			'linked'       => false,
			'public_path'  => public_path('storage'),
			'target_path'  => storage_path('app/public'),
			'status_label' => __('Missing'),
			'status_tone'  => 'danger',
			'functions'    => ['exec' => true, 'symlink' => true],
			'functions_ok' => true,
		];

		/*
		 * The rail groups the thirteen sections under four headings. The order
		 * inside a heading is the controller's order; a section that forgets to
		 * declare a group lands under Platform rather than disappearing.
		 */
		$railOrder  = [__('Platform'), __('Access & security'), __('Communication'), __('Programs & apps')];
		$railGroups = [];

		foreach ($settings as $sectionKey => $section) {
			$railGroups[__($section['group'] ?? 'Platform')][$sectionKey] = $section;
		}

		uksort($railGroups, static fn (string $a, string $b): int
			=> array_search($a, $railOrder, true) <=> array_search($b, $railOrder, true));

		/*
		 * Everything the rail's search box can match, as one flat list. Built
		 * here because the labels are already translated at this point and the
		 * browser has no other way to read config/settings.php.
		 */
		$searchIndex = [];

		foreach ($settings as $sectionKey => $section) {
			$groupOfField = [];

			foreach ($section['groups'] ?? [] as $group) {
				foreach ($group['fields'] ?? [] as $fieldKey) {
					$groupOfField[$fieldKey] = __($group['title']);
				}
			}

			foreach ($section['elements'] ?? [] as $element) {
				if (($element['type'] ?? null) === 'hidden') {
					continue;
				}

				$searchIndex[] = [
					'key'        => $element['key'],
					'label'      => title($element['label']),
					'hint'       => (string) __($element['message'] ?? ''),
					'section'    => __($section['menu_label'] ?? title($sectionKey)),
					'sectionKey' => $sectionKey,
					'group'      => $groupOfField[$element['key']] ?? '',
				];
			}
		}

		$mailHost = (string) config('mail.mailers.'.config('mail.default').'.host', '');
		$uploadMb = $mediaLimits['effective_mb'] ?? null;
		$siteEnv  = (string) setting('site_environment', 'production');
	@endphp

	{{--
		One header, one row. The chips are status an operator reads at a glance
		and controls they can open — storage health used to be a button that
		said the same thing twice.
	--}}
	<x-page-header
		:title="__('Site Settings')"
		:eyebrow="__('Platform')"
		:subtitle="__('Brand, security, email, programs and maintenance in one place.')"
		icon="site-cog"
		{{-- No 'dot': AdminUnifiedPageHeaderTest bans the key across backend
	     blades, because a stat cell left carrying only a dot reads half-drawn
	     since v2.2 replaced it with a tonal tile. --}}
		:status="['label' => title($siteEnv), 'tone' => $siteEnv === 'production' ? 'success' : 'warning']"
	>
		<x-slot:state>
			<span class="ph-state ph-state--success" id="ss-save-state" data-ss-save-state>{{ __('All changes saved') }}</span>
		</x-slot:state>
		<x-slot:actions>
			<span class="ss-health" aria-label="{{ __('Health checks') }}">
				<button type="button"
				        class="ss-health__chip"
				        data-coreui-toggle="modal"
				        data-coreui-target="#storageHealthModal"
				        title="{{ __('Storage') }}: {{ $storageStatus['status_label'] }}"
				        aria-label="{{ __('Open storage health tools') }}">
					<span class="ss-health__icon ss-health__icon--{{ $storageStatus['status_tone'] }}" aria-hidden="true"><x-icon name="linked" height="12" width="12"/></span>
					{{ __('Storage') }} <b class="ss-health__value">{{ $storageStatus['status_label'] }}</b>
				</button>
				<button type="button"
				        class="ss-health__chip"
				        data-coreui-toggle="modal"
				        data-coreui-target="#smtpTestModal"
				        title="{{ $mailHost !== '' ? $mailHost : __('not set') }}"
				        aria-label="{{ __('Send a test email') }}">
					<span class="ss-health__icon ss-health__icon--{{ $mailHost !== '' ? 'success' : 'warning' }}" aria-hidden="true"><x-icon name="cil-send" height="12" width="12"/></span>
					{{ __('Mail') }} <b class="ss-health__value">{{ $mailHost !== '' ? $mailHost : __('not set') }}</b>
				</button>
				@if($uploadMb !== null)
					<button type="button"
					        class="ss-health__chip"
					        data-coreui-toggle="modal"
					        data-coreui-target="#mediaLimitsModal"
					        title="{{ __('Uploads') }}: {{ $uploadMb }} MB"
					        aria-label="{{ __('Show upload limits') }}">
						<span class="ss-health__icon ss-health__icon--{{ ($mediaLimits['exceeds_server'] ?? false) ? 'warning' : 'success' }}" aria-hidden="true"><x-icon name="upload" height="12" width="12"/></span>
						{{ __('Uploads') }} <b class="ss-health__value" dir="ltr">{{ $uploadMb }} MB</b>
					</button>
				@endif
			</span>
		</x-slot:actions>
	</x-page-header>

	<div class="ss-page">
		<script type="application/json" id="ss-search-index">@json($searchIndex)</script>

		<div class="ss-workspace">
			<aside class="ss-rail">
				<label class="ss-search" for="ss-search-input">
					<span class="ss-search__icon" aria-hidden="true"><x-icon name="search" height="14" width="14"/></span>
					<input type="search"
					       id="ss-search-input"
					       class="ss-search__input"
					       autocomplete="off"
					       placeholder="{{ __('Search all settings') }}"
					       aria-label="{{ __('Search all settings') }}"
					       aria-controls="ss-results">
					<kbd class="ss-search__kbd" aria-hidden="true">/</kbd>
					<button type="button" class="ss-search__clear" data-ss-search-clear hidden aria-label="{{ __('Clear search') }}">
						<x-icon name="close" height="12" width="12"/>
					</button>
				</label>

				<div class="ss-results"
				     id="ss-results"
				     aria-label="{{ __('Search results') }}"
				     data-ss-caption="{{ __('matching settings') }}"
				     data-ss-empty="{{ __('No setting matches') }}"
				     hidden></div>

				<nav class="ss-nav" id="settings-site-tablist" role="tablist" aria-orientation="vertical">
					@foreach($railGroups as $railLabel => $railSections)
						<span class="ss-nav__caption">{{ $railLabel }}</span>
						@foreach($railSections as $name => $menu)
							<button class="ss-nav__item ss-tint ss-tint--{{ $name }} {{ $activeSection === $name ? 'active' : '' }}"
							        id="v-pills-{{ $name }}-tab"
							        data-coreui-toggle="pill"
							        data-coreui-target="#v-pills-{{ $name }}"
							        data-ss-section="{{ $name }}"
							        type="button"
							        role="tab"
							        aria-controls="v-pills-{{ $name }}"
							        aria-selected="{{ $activeSection === $name ? 'true' : 'false' }}">
								<span class="ss-nav__icon" aria-hidden="true"><x-icon name="{{ $settingMenus[$name] ?? 'site-cog' }}" height="18" width="18"/></span>
								<span class="ss-nav__text">
									<span class="ss-nav__title">{{ __($menu['menu_label'] ?? title($name)) }}</span>
									@isset($menu['menu_description'])
										<span class="ss-nav__desc">{{ __($menu['menu_description']) }}</span>
									@endisset
								</span>
								<span class="ss-nav__flag" data-ss-nav-flag></span>
							</button>
						@endforeach
					@endforeach
				</nav>
			</aside>

			<section class="ss-body tab-content" id="ss-body">
				@foreach($settings as $sectionKey => $fields)
					@php
						$formId          = 'settings-site-form-'.$sectionKey;
						$elements        = collect($fields['elements'] ?? []);
						$visibleElements = $elements->filter(fn (array $field): bool => ($field['type'] ?? null) !== 'hidden');
						$elementMap      = $visibleElements->keyBy('key');
						$groupedKeys     = collect($fields['groups'] ?? [])->flatMap(fn (array $group): array => $group['fields'] ?? [])->unique();
						$ungrouped       = $visibleElements->reject(fn (array $field): bool => $groupedKeys->contains($field['key'] ?? null));
						$sectionGroups   = collect($fields['groups'] ?? [])
							->filter(fn (array $group): bool => collect($group['fields'] ?? [])->contains(fn ($key): bool => $elementMap->has($key)))
							->values();
						$infoTone = in_array($sectionKey, ['maintenance_mode', 'pwa_settings'], true) ? 'warning' : 'info';
					@endphp

					<div class="tab-pane fade {{ $activeSection === $sectionKey ? 'show active' : '' }}"
					     id="v-pills-{{ $sectionKey }}"
					     role="tabpanel"
					     aria-labelledby="v-pills-{{ $sectionKey }}-tab"
					     tabindex="0">

						{{--
							The head sits outside the form on purpose: the partials
							it renders carry their own modals, and a modal holding an
							input inside the settings form would post a field the
							controller never validated.
						--}}
						<div class="ss-section-head ss-tint ss-tint--{{ $sectionKey }}">
							<span class="ss-section-head__tile" aria-hidden="true">
								<x-icon name="{{ $fields['icon'] ?? 'site-cog' }}" height="20" width="20"/>
							</span>
							<div class="ss-section-head__copy">
								<h2 class="ss-section-head__title">{{ __($fields['menu_label'] ?? $fields['title'] ?? title($sectionKey)) }}</h2>
								<p class="ss-section-head__desc">{{ __($fields['header_description'] ?? $fields['menu_description'] ?? 'Review and update the controls for this section.') }}</p>
							</div>
							<div class="ss-section-head__actions">
								@if (isset($fields['include_partials']))
									@include('backend.settings.site.partials.'.$fields['include_partials'], ['section' => $sectionKey])
								@endif
								<button type="submit" form="{{ $formId }}" class="btn btn-primary settings-site-header-action settings-site-header-save">
									<x-icon name="check" height="18" width="18"/>
									{{ __('Save Changes') }}
								</button>
							</div>

							@if($sectionGroups->count() > 1)
								<div class="ss-jump">
									<span class="ss-jump__label">{{ __('Jump to') }}</span>
									@foreach($sectionGroups as $group)
										<button type="button" class="ss-jump__chip" data-ss-jump="grp-{{ $sectionKey }}-{{ Str::slug($group['title']) }}">{{ __($group['title']) }}</button>
									@endforeach
								</div>
							@endif
						</div>

						<form method="POST"
						      id="{{ $formId }}"
						      action="{{ route('admin.settings.site.update', $sectionKey) }}"
						      class="settings-site-form ss-form"
						      data-ss-section="{{ $sectionKey }}"
						      enctype="multipart/form-data">
							@method('PUT')
							@csrf

							<div id="errorAlert-{{ $sectionKey }}" class="alert alert-danger settings-site-alert d-none" role="alert"></div>

							@if (isset($fields['info']))
								<div class="ss-info ss-info--{{ $infoTone }}">
									<span class="ss-info__icon" aria-hidden="true">
										<x-icon name="{{ $infoTone === 'warning' ? 'warning' : 'info' }}" height="16" width="16"/>
									</span>
									{{-- Kept on one line: the copy uses white-space pre-line, so a
									     newline in the template would render as a blank first line. --}}
									<div class="ss-info__description">{{ trim($fields['info']) }}</div>
								</div>
							@endif

							@forelse($sectionGroups as $group)
								<section class="ss-group" id="grp-{{ $sectionKey }}-{{ Str::slug($group['title']) }}">
									<div class="ss-group__head">
										<h3 class="ss-group__title">{{ __($group['title']) }}</h3>
										@isset($group['description'])
											<p class="ss-group__desc">{{ __($group['description']) }}</p>
										@endisset
									</div>
									<div class="ss-group__body">
										@foreach($group['fields'] as $fieldKey)
											@php $field = $elementMap->get($fieldKey); @endphp
											@if($field)
												@include('backend.settings.site.partials._row', ['field' => $field, 'sectionKey' => $sectionKey])
											@endif
										@endforeach
									</div>
								</section>
							@empty
								<section class="ss-group ss-group--plain">
									<div class="ss-group__body">
										@foreach($visibleElements as $field)
											@include('backend.settings.site.partials._row', ['field' => $field, 'sectionKey' => $sectionKey])
										@endforeach
									</div>
								</section>
							@endforelse

							@if($sectionGroups->isNotEmpty() && $ungrouped->isNotEmpty())
								<section class="ss-group ss-group--plain">
									<div class="ss-group__body">
										@foreach($ungrouped as $field)
											@include('backend.settings.site.partials._row', ['field' => $field, 'sectionKey' => $sectionKey])
										@endforeach
									</div>
								</section>
							@endif
						</form>
					</div>
				@endforeach
			</section>
		</div>

		@include('backend.settings.site.partials._savebar')
	</div>

	@include('backend.settings.site.partials._storage_health')

	@include('backend.settings.site.partials._maintenance_mode')

	{{--
		The dialogues belonging to a section action.

		They are rendered here rather than beside the button that opens them,
		because that button lives in the sticky section head — and a
		backdrop-filter makes its element the containing block for anything
		positioned fixed inside it. A modal declared there is laid out against
		the head instead of the viewport and then clipped away by the scrolling
		body: it opened at 0x0, with no way to close it.

		The stack has to be read after the sections that push onto it, which is
		why this sits at the end.
	--}}
	@stack('settings-site-modals')

@endsection
@push('scripts')
	<script src="{{ asset('backend/js/settings-site.js?v='.config('app.version').'-'.filemtime(public_path('backend/js/settings-site.js'))) }}"></script>
	@include('backend.settings.site.partials._script')
@endpush
