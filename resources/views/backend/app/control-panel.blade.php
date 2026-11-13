@extends('backend.layouts.app')
@section('title', __('Control Panel'))

@push('styles')
	{{-- The cache-buster is built in place: the single-line php directive
	     breaks in a file that also carries a php block, and this one does. --}}
	<link rel="stylesheet"
	      href="{{ asset('backend/css/control-panel.css?v='.config('app.version').'-'.filemtime(public_path('backend/css/control-panel.css'))) }}">
@endpush

@section('content')
	@php
		$sections = $controlPanelData ?? [];

		/*
		 * The config is free to name any colour; the sheet draws six. An
		 * unknown one falls back rather than rendering an untinted tile.
		 */
		$tones = ['primary', 'success', 'info', 'warning', 'danger', 'secondary'];
		$tone  = static fn (?string $colour): string => in_array($colour, $tones, true) ? $colour : 'secondary';

		/*
		 * A section can be linked: ?section=wallet_payments opens it, and
		 * ?view=az opens the index. The server picks both so the right view
		 * paints first. An unknown code falls through to the first section
		 * rather than an empty stage.
		 */
		$codes     = array_column($sections, 'code');
		$requested = (string) request()->query('section', '');
		$openCode  = in_array($requested, $codes, true) ? $requested : ($codes[0] ?? '');
		$view      = request()->query('view') === 'az' ? 'az' : 'sections';
		$dockSize  = 8;

		/*
		 * Each section's modules, grouped by the badge the config gives them.
		 * A module with no badge joins "General". Groups keep the order the
		 * config lists them in, so the config stays the one place to reorder.
		 */
		$index = [];

		foreach ($sections as $position => $section) {
			$groups = [];

			foreach ($section['features'] as $feature) {
				$group = ! empty($feature['badge']) ? __($feature['badge']) : __('General');
				$groups[$group][] = $feature;

				$index[] = [
					'key'     => $section['code'].'.'.$feature['code'],
					'label'   => __($feature['label']),
					'url'     => $feature['url'],
					'icon'    => $feature['icon'],
					'tone'    => $tone($feature['color']),
					'section' => __($section['label']),
				];
			}

			$sections[$position]['groups'] = $groups;
		}

		$totalFeatures = count($index);

		/*
		 * The A–Z index sorts the translated labels, so an Arabic panel files
		 * modules under Arabic initials. Only letters that have a module get a
		 * jump link — a full Latin alphabet would be wrong in any other script.
		 */
		usort($index, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

		$letters = [];

		foreach ($index as $module) {
			$letters[mb_strtoupper(mb_substr($module['label'], 0, 1))][] = $module;
		}
	@endphp

	<x-page-header
		:breadcrumbs="[
			['label' => __('Dashboard'), 'url' => route('admin.dashboard')],
			['label' => __('Control Panel')],
		]"
		:title="__('Control Panel')"
		:subtitle="__('Every module your role can open, one click away.')"
		icon="apps"
	>
		{{-- No stats strip: the counts it carried — modules, sections,
		     pinned — are already on the rail and in the dock, and a row of
		     numbers read as a dashboard on a page that is only a launcher. --}}
		<x-slot:actions>
			{{-- Opens admin-palette.js, which searches every admin page. --}}
			<button type="button" class="ph-btn ph-btn--secondary" data-dk-palette-open>
				<x-icon name="search" width="14" height="14"/>
				{{ __('Search all admin pages') }}
				<kbd class="cp-kbd">/</kbd>
			</button>
		</x-slot:actions>
	</x-page-header>

	@if($sections === [])
		<div class="cp-empty cp-empty--page">
			<div class="cp-empty__icon" aria-hidden="true">
				<x-icon name="apps" width="24" height="24"/>
			</div>
			<h2 class="cp-empty__title">@lang('No modules available')</h2>
			<p class="cp-empty__text">@lang('Your role does not grant access to any of the modules listed here. Ask an administrator to review your permissions.')</p>
		</div>
	@else
		<div class="cp"
		     data-control-panel
		     data-view="{{ $view }}"
		     data-section="{{ $openCode }}"
		     data-total="{{ $totalFeatures }}"
		     data-dock-size="{{ $dockSize }}"
		     data-why-name="{{ __('Name') }}"
		     data-why-keyword="{{ __('Keyword “:keyword”') }}"
		     data-why-group="{{ __('Group “:group”') }}"
		     data-why-description="{{ __('Description') }}"
		     data-why-section="{{ __('Section “:section”') }}"
		     data-open-label="{{ __('Open') }}"
		     data-dock-full="{{ __('The dock holds :count modules. Unpin one first.', ['count' => $dockSize]) }}"
		     data-pinned-one="{{ __(':module pinned') }}"
		     data-unpinned-one="{{ __(':module unpinned') }}">

			{{-- The console: one field that reaches every module, the dock of
			     pinned modules, and what was opened last. Dark in both themes
			     on purpose — it is the page's one launch surface. --}}
			<section class="cp-console" aria-label="{{ __('Quick launch') }}">
				<div class="cp-console__main">
					<span class="cp-console__eyebrow">@lang('Jump to anything')</span>

					<div class="cp-field" role="search">
						<span class="cp-field__icon" aria-hidden="true">
							<x-icon name="search" width="18" height="18"/>
						</span>
						<label class="cp-sr" for="cp-search">@lang('Search modules')</label>
						<input type="search"
						       id="cp-search"
						       class="cp-field__input"
						       data-cp-search
						       role="combobox"
						       aria-expanded="false"
						       aria-controls="cp-results"
						       aria-autocomplete="list"
						       aria-describedby="cp-search-keys"
						       placeholder="{{ __('Type a module, a task or a keyword — refund, KYC, SMTP…') }}"
						       autocomplete="off"
						       spellcheck="false">
						<button type="button" class="cp-field__clear" data-cp-clear hidden aria-label="{{ __('Clear search') }}">
							{{-- x-alt, not x: `x.svg` is a screen-with-a-cross glyph. --}}
							<x-icon name="x-alt" width="13" height="13"/>
						</button>
						<span class="cp-field__keys" id="cp-search-keys">
							<kbd class="cp-kbd cp-kbd--dark">↑</kbd><kbd class="cp-kbd cp-kbd--dark">↓</kbd>
							<span>@lang('choose')</span>
							<kbd class="cp-kbd cp-kbd--dark">↵</kbd>
							<span>@lang('open')</span>
							<kbd class="cp-kbd cp-kbd--dark">Esc</kbd>
							<span>@lang('clear')</span>
						</span>
					</div>

					<div class="cp-dock">
						<div class="cp-dock__head">
							<span class="cp-dock__title">@lang('Pinned')</span>
							<span class="cp-dock__hint">@lang('Pin any module with its pin button — kept in this browser')</span>
						</div>
						<div class="cp-dock__list" data-cp-dock></div>
						<p class="cp-dock__empty" data-cp-dock-empty>@lang('Nothing pinned yet. Use the pin on any module card to keep it here.')</p>
					</div>
				</div>

				<div class="cp-console__side">
					<span class="cp-console__label">@lang('Recently opened')</span>
					<div class="cp-recent" data-cp-recent></div>
					<p class="cp-console__empty" data-cp-recent-empty>@lang('Modules you open from this page will show up here.')</p>
				</div>
			</section>

			<div class="cp-browse">
				<aside class="cp-rail" aria-label="{{ __('Browse modules') }}">
					<div class="cp-seg" role="group" aria-label="{{ __('Browse by') }}">
						<button type="button" class="cp-seg__btn" data-cp-view="sections" aria-pressed="{{ $view === 'sections' ? 'true' : 'false' }}">
							<x-icon name="dashboard" width="14" height="14"/>@lang('Sections')
						</button>
						<button type="button" class="cp-seg__btn" data-cp-view="az" aria-pressed="{{ $view === 'az' ? 'true' : 'false' }}">
							<x-icon name="list" width="14" height="14"/>@lang('A–Z')
						</button>
					</div>

					<span class="cp-rail__label"
					      data-cp-rail-label
					      data-browse="{{ __('Browse by section') }}"
					      data-search="{{ __('Matches per section') }}">@lang('Browse by section')</span>

					<nav class="cp-rail__list" aria-label="{{ __('Sections') }}">
						@foreach($sections as $section)
							@php
								$isOpen = $view === 'sections' && $section['code'] === $openCode;
							@endphp
							<button type="button"
							        class="cp-navi"
							        data-cp-section="{{ $section['code'] }}"
							        aria-current="{{ $isOpen ? 'true' : 'false' }}"
							        aria-controls="cp-stage">
								<span class="cp-ai cp-ai--sm cp-ai--{{ $tone($section['color']) }}" aria-hidden="true">
									<x-icon name="{{ $section['icon'] }}" width="16" height="16"/>
								</span>
								<span class="cp-navi__text">
									<span class="cp-navi__label">{{ __($section['label']) }}</span>
									@if($section['hint'] !== '')
										<span class="cp-navi__hint">{{ __($section['hint']) }}</span>
									@endif
								</span>
								<span class="cp-navi__count" data-cp-count data-total="{{ count($section['features']) }}">{{ count($section['features']) }}</span>
							</button>
						@endforeach
					</nav>

					<div class="cp-rail__tip">
						<span class="cp-rail__tip-title">@lang('Anywhere in the admin')</span>
						<span class="cp-rail__tip-text">@lang('Press') <kbd class="cp-kbd">/</kbd> @lang('to search every admin page, not just these modules.')</span>
					</div>
				</aside>

				<div class="cp-stage" id="cp-stage">
					{{-- Section view. Every module is rendered here once; search
					     and the dock read these cards rather than keeping a
					     second copy that could drift. --}}
					@foreach($sections as $section)
						@php
							$sectionLabel = __($section['label']);
							$groupCount   = count($section['groups']);
						@endphp
						<section class="cp-sec"
						         data-cp-panel="{{ $section['code'] }}"
						         aria-labelledby="cp-sec-{{ $section['code'] }}"
						         @if($view !== 'sections' || $section['code'] !== $openCode) hidden @endif>
							<header class="cp-sec__head">
								<span class="cp-ai cp-ai--lg cp-ai--{{ $tone($section['color']) }}" aria-hidden="true">
									<x-icon name="{{ $section['icon'] }}" width="24" height="24"/>
								</span>
								<div class="cp-sec__text">
									<h2 class="cp-sec__title" id="cp-sec-{{ $section['code'] }}">{{ $sectionLabel }}</h2>
									<p class="cp-sec__desc">{{ __($section['description']) }}</p>
								</div>
								<span class="cp-sec__summary">
									{{ trans_choice(':count module|:count modules', count($section['features']), ['count' => count($section['features'])]) }}
									·
									{{ trans_choice(':count group|:count groups', $groupCount, ['count' => $groupCount]) }}
								</span>
							</header>

							@foreach($section['groups'] as $groupName => $features)
								<div class="cp-group">
									<h3 class="cp-group__title">
										<span>{{ $groupName }}</span>
										<span class="cp-group__count">{{ count($features) }}</span>
									</h3>
									<div class="cp-grid">
										@foreach($features as $feature)
											@php
												$label    = __($feature['label']);
												$desc     = __($feature['description']);
												$badge    = ! empty($feature['badge']) ? __($feature['badge']) : '';
												$keywords = array_map(static fn (string $keyword): string => __($keyword), $feature['keywords'] ?? []);

												/*
												 * One lowercased haystack per module, built once here
												 * rather than re-read from the card on every keystroke.
												 * The config's own English keywords go in beside their
												 * translations, so "audit" still finds Activity Logs on
												 * a panel running in another language.
												 */
												$haystack = mb_strtolower(implode(' ', array_filter([
													$label,
													$desc,
													$badge,
													$sectionLabel,
													implode(' ', $keywords),
													implode(' ', $feature['keywords'] ?? []),
												])));
											@endphp
											<div class="cp-card"
											     data-cp-item
											     data-key="{{ $section['code'] }}.{{ $feature['code'] }}"
											     data-section="{{ $section['code'] }}"
											     data-section-label="{{ $sectionLabel }}"
											     data-label="{{ $label }}"
											     data-desc="{{ $desc }}"
											     data-group="{{ $badge }}"
											     data-keywords="{{ json_encode($keywords, JSON_UNESCAPED_UNICODE) }}"
											     data-search="{{ $haystack }}">
												<span class="cp-ai cp-ai--{{ $tone($feature['color']) }}" aria-hidden="true">
													<x-icon name="{{ $feature['icon'] }}" width="20" height="20"/>
												</span>
												<a class="cp-card__link" data-cp-link href="{{ $feature['url'] }}">
													<span class="cp-card__name">
														{{ $label }}
														<span class="cp-card__go" aria-hidden="true"><x-icon name="arrow-right" width="14" height="14"/></span>
													</span>
													<span class="cp-card__desc">{{ $desc }}</span>
												</a>
												@if($keywords !== [])
													<div class="cp-card__tags">
														@foreach(array_slice($keywords, 0, 3) as $keyword)
															<span class="cp-tag">{{ $keyword }}</span>
														@endforeach
													</div>
												@endif
												{{-- A sibling of the link, never a child: a button nested
												     in an anchor is invalid markup and a keyboard cannot
												     reach the inner one. --}}
												<button type="button"
												        class="cp-pin"
												        data-cp-pin
												        aria-pressed="false"
												        aria-label="{{ __('Pin :module', ['module' => $label]) }}"
												        data-label-pin="{{ __('Pin :module', ['module' => $label]) }}"
												        data-label-unpin="{{ __('Unpin :module', ['module' => $label]) }}">
													<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 17v5M9 10.8a2 2 0 0 1-1.1 1.8l-1.8.9A2 2 0 0 0 5 15.2v.8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.8a2 2 0 0 0-1.1-1.8l-1.8-.9a2 2 0 0 1-1.1-1.8V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/></svg>
												</button>
											</div>
										@endforeach
									</div>
								</div>
							@endforeach
						</section>
					@endforeach

					{{-- A–Z index: for when the name is known and the section is not. --}}
					<section class="cp-az" data-cp-az aria-labelledby="cp-az-title" @if($view !== 'az') hidden @endif>
						<header class="cp-az__head">
							<h2 class="cp-az__title" id="cp-az-title">@lang('All modules, A to Z')</h2>
							<span class="cp-az__note">{{ trans_choice(':count module|:count modules', $totalFeatures, ['count' => $totalFeatures]) }} · @lang('for when you know the name, not the section')</span>
						</header>
						<nav class="cp-az__index" aria-label="{{ __('Jump to letter') }}">
							@foreach(array_keys($letters) as $position => $letter)
								<a class="cp-az__jump" href="#cp-az-{{ $position }}">{{ $letter }}</a>
							@endforeach
						</nav>
						<div class="cp-az__cols">
							@foreach(array_values($letters) as $position => $modules)
								<div class="cp-az__group" id="cp-az-{{ $position }}">
									<span class="cp-az__letter" aria-hidden="true">{{ array_keys($letters)[$position] }}</span>
									@foreach($modules as $module)
										<a class="cp-az__item" data-cp-link data-key="{{ $module['key'] }}" href="{{ $module['url'] }}">
											<span class="cp-ai cp-ai--xs cp-ai--{{ $module['tone'] }}" aria-hidden="true">
												<x-icon name="{{ $module['icon'] }}" width="12" height="12"/>
											</span>
											<span class="cp-az__name">{{ $module['label'] }}</span>
											<span class="cp-az__where">{{ $module['section'] }}</span>
										</a>
									@endforeach
								</div>
							@endforeach
						</div>
					</section>

					{{-- Search results: ranked, keyboard-driven, and each one says
					     why it matched. Rows are built by the script from the cards. --}}
					<section class="cp-results" data-cp-results aria-labelledby="cp-results-title" hidden>
						<header class="cp-results__head">
							<h2 class="cp-results__title"
							    id="cp-results-title"
							    data-cp-results-title
							    data-one="{{ __(':count module for “:query”') }}"
							    data-many="{{ __(':count modules for “:query”') }}"></h2>
							<span class="cp-results__note">@lang('Ranked by name, then keyword, then description')</span>
						</header>
						<div class="cp-results__list" id="cp-results" role="listbox" aria-label="{{ __('Matching modules') }}" data-cp-results-list></div>
						<div class="cp-empty" data-cp-empty hidden>
							<div class="cp-empty__icon" aria-hidden="true">
								<x-icon name="search" width="22" height="22"/>
							</div>
							<h3 class="cp-empty__title">@lang('Nothing matched')</h3>
							<p class="cp-empty__text">@lang('No name, keyword or description matched. Try a shorter word, or press / to search every admin page.')</p>
						</div>
					</section>
				</div>
			</div>
		</div>

		<p class="cp-sr" id="cp-live" role="status" aria-live="polite"
		   data-one="{{ __(':count module matches') }}"
		   data-many="{{ __(':count modules match') }}"></p>
	@endif
@endsection

@push('scripts')
	<script src="{{ asset('backend/js/control-panel.js?v='.config('app.version').'-'.filemtime(public_path('backend/js/control-panel.js'))) }}"></script>
@endpush
