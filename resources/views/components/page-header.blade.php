{{--
	The one header every admin page starts with.

	Breadcrumb → identity row → controls → optional stats strip. Every slot is
	optional and the layout never breaks when one is empty. Section tabs,
	filters and toolbars are page content: render them below this component.

	Styles live only in public/backend/css/page-header.css (prefix ph-).
--}}
@php
	$hasSearch  = isset($search) && $search->hasActualContent();
	$hasActions = isset($actions) && $actions->hasActualContent();
	$hasMenu    = isset($menu) && $menu->hasActualContent();
	$hasState   = isset($state) && $state->hasActualContent();
	$hasControls = $hasSearch || $hasActions || $hasMenu || $hasState;
	$columns    = min(6, max(1, $stripColumns()));
@endphp

<div {{ $attributes->class([
	'ph',
	'ph--card'     => $variant === 'card',
	'ph--compact'  => $variant === 'compact',
	'ph--has-menu' => $hasMenu,
]) }}>
	@if($breadcrumbs !== [] || $back)
		{{--
			The return link lives at the end of this row, not beside the tile.
			An unlabelled 40px arrow next to a labelled trail read as a second,
			competing control; here it names where it goes.
		--}}
		<nav class="ph__crumbs" aria-label="{{ __('Breadcrumb') }}">
			<ol class="ph__crumb-list">
				@foreach($breadcrumbs as $crumb)
					<li class="ph__crumb" @if($loop->last) aria-current="page" @endif>
						@if(! $loop->last && ! empty($crumb['url']))
							<a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
						@else
							<span>{{ $crumb['label'] }}</span>
						@endif
					</li>
				@endforeach
			</ol>
			@if($back)
				<a href="{{ $back }}" class="ph__crumb-back">
					<x-icon name="back" width="14" height="14"/>{{ $backLabel }}
				</a>
			@endif
		</nav>
	@endif

	<header class="ph__head">
		@if($variant === 'card')
			{{--
				The brand wash and the corner glow. Both are decoration and
				both are inert: they sit behind the row, take no pointer
				events, and are dropped entirely in the compact variant.

				They live in this clipped layer rather than on the card so
				the card itself needs no overflow rule — one on .ph__head
				would cut off the ⋯ menu, which has to escape it.
			--}}
			<span class="ph__decor" aria-hidden="true">
				<span class="ph__wash" aria-hidden="true"></span>
				<span class="ph__orb" aria-hidden="true"></span>
			</span>
		@endif

		<div class="ph__row">
			@if($tileKind === 'avatar')
				<span class="ph__avatar">
					<img src="{{ $avatarUrl }}" alt="" loading="lazy">
				</span>
			@elseif($tileKind === 'initials')
				<span class="ph__avatar" aria-hidden="true">{{ $initials }}</span>
			@elseif($tileKind === 'fa')
				<span class="ph__tile ph__tile--{{ $tile }}" aria-hidden="true"><i class="fa-solid {{ $tileFontAwesome }}"></i></span>
			@elseif($tileKind === 'icon')
				<span class="ph__tile ph__tile--{{ $tile }}" aria-hidden="true"><x-icon :name="$tileIcon" width="24" height="24"/></span>
			@endif

			<div class="ph__identity">
				@if($eyebrow)
					<span class="ph__eyebrow">{{ $eyebrow }}</span>
				@endif

				<div class="ph__titleline">
					<h1 class="ph__title">{{ $title }}</h1>

					@if($badge)
						<span class="ph__badge" dir="ltr">{{ $badge }}</span>
					@endif

					@if($status)
						<span class="ph-pill ph-pill--{{ $status['tone'] }}">
							@if($status['dot'])
								<span class="ph-pill__dot" aria-hidden="true"></span>
							@endif
							{{ $status['label'] }}
						</span>
					@endif
				</div>

				@if($subtitle)
					<p class="ph__subtitle">{{ $subtitle }}</p>
				@endif

				@if($meta !== [])
					<ul class="ph__meta">
						@foreach($meta as $item)
							<li class="ph__meta-item">
								@if($item instanceof \Illuminate\Contracts\Support\Htmlable)
									{!! $item->toHtml() !!}
								@elseif(! empty($item['url']))
									<a href="{{ $item['url'] }}">{{ $item['label'] }}</a>
								@else
									{{ $item['label'] }}
								@endif
							</li>
						@endforeach
					</ul>
				@endif
			</div>

			@if($hasControls)
				<div class="ph__actions">
					@if($hasState)
						<div class="ph__state">{{ $state }}</div>
					@endif

					@if($hasSearch)
						<label class="ph__search">
							<span class="ph__search-icon" aria-hidden="true"><x-icon name="search" width="14" height="14"/></span>
							{{ $search }}
						</label>
					@endif

					@if($hasActions)
						{{ $actions }}
					@endif

					@if($hasMenu)
						<div class="dropdown ph__more">
							<button type="button"
							        class="ph-more"
							        data-coreui-toggle="dropdown"
							        data-coreui-offset="0,8"
							        aria-expanded="false"
							        aria-haspopup="true"
							        aria-label="{{ __('More actions') }}">
								<span class="ph-more__dot" aria-hidden="true"></span>
								<span class="ph-more__dot" aria-hidden="true"></span>
								<span class="ph-more__dot" aria-hidden="true"></span>
							</button>
							<div class="dropdown-menu dropdown-menu-end ph-menu">
								<span class="ph-menu__caption">{{ __('More actions') }}</span>
								{{ $menu }}
							</div>
						</div>
					@endif
				</div>
			@endif
		</div>

		@if($hasStrip())
			{{--
				--ph-units is the strip's width budget in stat-cell widths
				(the progress cell counts 1.5), which is how a cell computes
				its own flex-basis instead of the sheet carrying a media
				query per column count.
			--}}
			<div class="ph__strip ph__strip--{{ $columns }} {{ $progress ? 'ph__strip--progress' : '' }}"
			     style="--ph-units: {{ $stripUnits() }}">
				@if($progress)
					<div class="ph__cell ph__cell--progress">
						<div class="ph__progress-head">
							<span class="ph__label">{{ $progress['label'] }}</span>
							{{--
								The three parts a page's script may rewrite as
								the page is used carry whatever hooks it asked
								for in `progress.attrs`.
							--}}
							<span class="ph__ratio" @foreach($progress['attrs']['ratio'] as $name => $value) {{ $name }}="{{ $value }}" @endforeach><strong>{{ $progress['value'] }}</strong> / {{ $progress['total'] }}</span>
						</div>
						<div class="ph__bar"
						     role="progressbar"
						     aria-label="{{ $progress['label'] }}"
						     aria-valuenow="{{ $progress['percent'] }}"
						     aria-valuemin="0"
						     aria-valuemax="100">
							<span class="ph__bar-fill" style="inline-size: {{ $progress['percent'] }}%" @foreach($progress['attrs']['fill'] as $name => $value) {{ $name }}="{{ $value }}" @endforeach></span>
						</div>
						@if($progress['note'])
							<span class="ph__note" @foreach($progress['attrs']['note'] as $name => $value) {{ $name }}="{{ $value }}" @endforeach>{{ $progress['note'] }}</span>
						@endif
					</div>
				@endif

				@foreach($stats as $stat)
					@php
						/*
							A cell with a `url` is the link itself, not a figure with a
							link inside it. Background Tasks kept its health facts out of
							the strip as loose chips for exactly this reason, and they
							stopped reading at all once the page lost the dark hero those
							chips had been coloured for.
						*/
						$cellTag = $stat['url'] ? 'a' : 'div';
					@endphp

					<{{ $cellTag }} class="ph__cell{{ $stat['url'] ? ' ph__cell--link' : '' }}"@if($stat['url']) href="{{ $stat['url'] }}"@endif>
						<div class="ph__cell-copy">
							<span class="ph__label">
								@if($stat['dot'])
									<span class="ph__dot ph__dot--{{ $stat['dot'] }}" aria-hidden="true"></span>
								@endif
								{{ $stat['label'] }}
							</span>
							<span class="ph__value{{ in_array($stat['tone'], ['danger', 'warning'], true) ? ' ph__value--'.$stat['tone'] : '' }}"@if($stat['id']) id="{{ $stat['id'] }}"@endif>{{ $stat['value'] }}</span>
							@if($stat['delta'] || $stat['note'])
								<span class="ph__note">
									@if($stat['delta'])
										<span class="ph__delta ph__delta--{{ $stat['tone'] }}">{{ $stat['delta'] }}</span>
									@endif
									{{ $stat['note'] }}
								</span>
							@endif
						</div>

						@if($stat['spark'])
							{{-- Shape only: the figure above states the number. --}}
							<span class="ph__spark" aria-hidden="true">
								@foreach($stat['spark'] as $height)
									<span class="ph__spark-bar{{ $loop->last ? ' is-current' : '' }}" style="block-size: {{ $height }}%"></span>
								@endforeach
							</span>
						@elseif($stat['icon'])
							{{-- A count gets a tonal tile, never a trend line it does not have. --}}
							<span class="ph__stat-icon ph__stat-icon--{{ $stat['tone'] }}" aria-hidden="true">
								@if($stat['iconKind'] === 'fa')
									<i class="fa-solid {{ $stat['icon'] }}"></i>
								@else
									<x-icon :name="$stat['icon']" width="18" height="18"/>
								@endif
							</span>
						@endif
					</{{ $cellTag }}>
				@endforeach
			</div>
		@endif
	</header>
</div>
