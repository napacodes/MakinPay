@extends('backend.layouts.app')

@section('title', __('Media library'))

{{--
	Block @php only in this file. Blade matches an inline @php( with the block
	pattern, so one inline directive above a block's @endphp swallows every
	line between them and the view dies on the next @endforeach.
--}}
@push('styles')
	@php
		$mediaLibraryCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/media-library.css'));
	@endphp
	<link rel="stylesheet" href="{{ asset('backend/css/media-library.css?v='.$mediaLibraryCssVersion) }}">
@endpush

@push('scripts')
	@php
		$mediaLibraryJsVersion = config('app.version').'-'.filemtime(public_path('backend/js/media-library.js'));
	@endphp
	<script src="{{ asset('backend/js/media-library.js?v='.$mediaLibraryJsVersion) }}"></script>
@endpush

{{--
	No breadcrumb here. The shared header already carries the trail: its
	eyebrow reads "Site builder" and its title "Media library", so a crumb
	line above it only repeats the same two words.
--}}

@section('content')
	@php
		$activeTab = request('tab') === 'storage' ? 'storage' : 'library';
		$isGrid = $view === 'grid';
		$canUpload = auth('admin')->user()?->can('media-upload') ?? false;
		$canEdit = auth('admin')->user()?->can('media-edit') ?? false;
		$canDelete = auth('admin')->user()?->can('media-delete') ?? false;
		$canManageStorage = auth('admin')->user()?->can('media-storage-manage') ?? false;

		$assetTypes = [
			'image' => __('Images'),
			'document' => __('Documents'),
			'video' => __('Videos'),
			'audio' => __('Audio'),
			'archive' => __('Archives'),
			'other' => __('Other'),
		];

		$sortOptions = [
			'newest' => __('Newest first'),
			'oldest' => __('Oldest first'),
			'largest' => __('Largest'),
			'name' => __('Name A–Z'),
		];

		/* Everything except the one parameter a control owns, so a chip keeps the folder and a folder keeps the chip. */
		$queryWithout = static fn (array $overrides): array => array_filter(
			array_merge(request()->except(['page', 'tab']), $overrides),
			static fn ($value): bool => $value !== null && $value !== ''
		);

		/*
		 * A folder always draws in the same colour. Hashing the name rather than
		 * numbering the rows means the swatch survives a new folder appearing
		 * above it in the alphabet.
		 */
		$folderSwatch = static fn (string $folder): string => 'c'.(1 + (crc32($folder) % 6));

		$usagePercent = $activeProvider->usagePercent();
		$quotaHuman = $activeProvider->quotaHuman();

		$selectedAsset = $assets->first();
	@endphp

	<div class="mlib" data-media-library>
		{{-- The shared admin header, same as every other module screen. --}}
		<x-page-header
			:title="__('Media library')"
			:subtitle="__('Reusable brand assets, documents and uploads — routed to local or S3-compatible storage.')"
			icon="fa-photo-video"
		>
			{{--
				No "Storage providers" button here: the tab bar below carries
				the same label pointing at the same URL, about twenty pixels
				away. Switching sections is the tabs' job — a second control
				for it in the header was one more thing to read, not one fewer
				click.
			--}}
			<x-slot:actions>
				@if($canUpload)
					<button type="button" class="btn btn-primary" data-coreui-toggle="modal" data-coreui-target="#mediaUploadModal">
						<i class="fa-solid fa-upload" aria-hidden="true"></i>
						{{ __('Upload media') }}
					</button>
				@endif
			</x-slot:actions>
		</x-page-header>

		<div class="mlib-tabs" role="tablist" aria-label="{{ __('Media library sections') }}">
			<a class="mlib-tab {{ $activeTab === 'library' ? 'is-active' : '' }}"
			   role="tab"
			   aria-selected="{{ $activeTab === 'library' ? 'true' : 'false' }}"
			   href="{{ route('admin.media.index') }}">{{ __('Library') }}</a>
			<a class="mlib-tab {{ $activeTab === 'storage' ? 'is-active' : '' }}"
			   role="tab"
			   aria-selected="{{ $activeTab === 'storage' ? 'true' : 'false' }}"
			   href="{{ route('admin.media.index', ['tab' => 'storage']) }}">{{ __('Storage providers') }}</a>
		</div>

		@if($activeTab === 'library')
			<div class="mlib-kpis">
				<div class="mlib-kpi">
					<span class="mlib-kpi__icon mlib-kpi__icon--brand" aria-hidden="true"><x-icon name="sidebar-palette" height="20" width="20"/></span>
					<div class="mlib-kpi__body">
						<div class="mlib-kpi__label">{{ __('Total assets') }}</div>
						<div class="mlib-kpi__value">{{ number_format($metrics['total']) }}</div>
					</div>
					<span class="mlib-kpi__delta">{{ __('+:count this week', ['count' => number_format($metrics['addedThisWeek'])]) }}</span>
				</div>
				<div class="mlib-kpi">
					<span class="mlib-kpi__icon mlib-kpi__icon--success" aria-hidden="true"><x-icon name="sidebar-page" height="20" width="20"/></span>
					<div class="mlib-kpi__body">
						<div class="mlib-kpi__label">{{ __('Images') }}</div>
						<div class="mlib-kpi__value">{{ number_format($metrics['images']) }}</div>
					</div>
					{{-- A byte size is a number and a Latin unit; it reads back to front when the paragraph is Arabic. --}}
					<span class="mlib-kpi__delta" dir="ltr">{{ \App\Support\ByteSize::human($metrics['imageBytes'], 1) }}</span>
				</div>
				<div class="mlib-kpi">
					<span class="mlib-kpi__icon mlib-kpi__icon--warning" aria-hidden="true"><x-icon name="sidebar-receipt" height="20" width="20"/></span>
					<div class="mlib-kpi__body">
						<div class="mlib-kpi__label">{{ __('Documents') }}</div>
						<div class="mlib-kpi__value">{{ number_format($metrics['documents']) }}</div>
					</div>
					<span class="mlib-kpi__delta" dir="ltr">{{ \App\Support\ByteSize::human($metrics['documentBytes'], 1) }}</span>
				</div>
				<div class="mlib-kpi">
					<span class="mlib-kpi__icon mlib-kpi__icon--info" aria-hidden="true"><x-icon name="sidebar-settings" height="20" width="20"/></span>
					<div class="mlib-kpi__body">
						<div class="mlib-kpi__label">{{ __('Active storage') }}</div>
						<div class="mlib-kpi__value" title="{{ $activeProvider->name }}">{{ $activeProvider->name }}</div>
					</div>
					<span class="mlib-kpi__delta">
						@if($activeProvider->last_checked_at)
							{{ __('Tested :when', ['when' => $activeProvider->last_checked_at->diffForHumans()]) }}
						@else
							{{ __('Not tested') }}
						@endif
					</span>
				</div>
			</div>

			<div class="mlib-panes">
				<aside class="mlib-rail" aria-label="{{ __('Folders') }}">
					<div class="mlib-rail__head">
						<span class="mlib-rail__caption">{{ __('Folders') }}</span>
						@if($canUpload)
							{{--
								`icon-custom-size` is app.css's own opt-out. Twelve of the
								shipped SVGs carry `icon icon-sm`, which that stylesheet
								pins to 14px and which would otherwise ignore the 10px
								asked for here.
							--}}
							<button type="button" class="mlib-rail__add" title="{{ __('New folder') }}" aria-label="{{ __('New folder') }}" data-coreui-toggle="modal" data-coreui-target="#mediaUploadModal">
								<x-icon name="plus" height="10" width="10" class="icon-custom-size"/>
							</button>
						@endif
					</div>

					<div class="mlib-rail__list">
						<a class="mlib-folder {{ $selectedFolder === null ? 'is-active' : '' }}"
						   href="{{ route('admin.media.index', $queryWithout(['folder' => null])) }}">
							<span class="mlib-folder__swatch mlib-folder__swatch--all" aria-hidden="true"></span>
							<span class="mlib-folder__name">{{ __('All files') }}</span>
							<span class="mlib-folder__count">{{ number_format($metrics['total']) }}</span>
						</a>

						@foreach($folders as $folderName => $folderCount)
							<a class="mlib-folder {{ $selectedFolder === $folderName ? 'is-active' : '' }}"
							   href="{{ route('admin.media.index', $queryWithout(['folder' => $folderName])) }}">
								<span class="mlib-folder__swatch mlib-folder__swatch--{{ $folderSwatch($folderName) }}" aria-hidden="true"></span>
								<span class="mlib-folder__name mlib-folder__name--path" dir="ltr">{{ $folderName }}</span>
								<span class="mlib-folder__count">{{ number_format($folderCount) }}</span>
							</a>
						@endforeach
					</div>

					<div class="mlib-rail__foot">
						<div class="mlib-usage">
							<span>{{ __('Storage used') }}</span>
							<span class="mlib-usage__value" dir="ltr">
								{{ $activeProvider->usedHuman() }}@if($quotaHuman) / {{ $quotaHuman }}@endif
							</span>
						</div>
						@if($usagePercent !== null)
							<div class="mlib-usage__bar" role="progressbar" aria-valuenow="{{ $usagePercent }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('Storage used') }}">
								<div class="mlib-usage__fill" style="width: {{ $usagePercent }}%"></div>
							</div>
						@endif
						<div class="mlib-usage__active">
							<span class="mlib-usage__dot" aria-hidden="true"></span>
							{{ __('Active:') }} <strong class="mlib-usage__provider">{{ $activeProvider->name }}</strong>
						</div>
					</div>
				</aside>

				<div class="mlib-assets">
					<form class="mlib-toolbar" method="GET" action="{{ route('admin.media.index') }}" data-media-filters>
						@foreach($queryWithout(['search' => null]) as $key => $value)
							<input type="hidden" name="{{ $key }}" value="{{ $value }}">
						@endforeach

						<label class="mlib-search">
							<span class="mlib-search__icon" aria-hidden="true"><x-icon name="search" height="14" width="14"/></span>
							<span class="visually-hidden">{{ __('Search media') }}</span>
							<input type="search" name="search" class="mlib-search__input" value="{{ request('search') }}" placeholder="{{ __('Name, title, alt text') }}">
						</label>

						<div class="mlib-chips">
							<a class="mlib-chip {{ $selectedType === null ? 'is-active' : '' }}"
							   href="{{ route('admin.media.index', $queryWithout(['type' => null])) }}">
								{{ __('All') }}<span class="mlib-chip__count">{{ number_format($typeCounts['all'] ?? 0) }}</span>
							</a>
							@foreach($assetTypes as $typeKey => $typeLabel)
								@continue(($typeCounts[$typeKey] ?? 0) === 0 && $selectedType !== $typeKey)
								<a class="mlib-chip {{ $selectedType === $typeKey ? 'is-active' : '' }}"
								   href="{{ route('admin.media.index', $queryWithout(['type' => $typeKey])) }}">
									{{ $typeLabel }}<span class="mlib-chip__count">{{ number_format($typeCounts[$typeKey] ?? 0) }}</span>
								</a>
							@endforeach
						</div>

						<div class="mlib-toolbar__end">
							<select name="provider" class="mlib-select" aria-label="{{ __('Provider') }}" data-media-autosubmit>
								<option value="">{{ __('All providers') }}</option>
								@foreach($providers as $provider)
									<option value="{{ $provider->id }}" @selected((string) request('provider') === (string) $provider->id)>{{ $provider->name }}</option>
								@endforeach
							</select>

							<select name="sort" class="mlib-select" aria-label="{{ __('Sort') }}" data-media-autosubmit>
								@foreach($sortOptions as $sortKey => $sortLabel)
									<option value="{{ $sortKey }}" @selected($sort === $sortKey)>{{ $sortLabel }}</option>
								@endforeach
							</select>

							<div class="mlib-views">
								<a class="mlib-view {{ $isGrid ? 'is-active' : '' }}"
								   href="{{ route('admin.media.index', $queryWithout(['view' => 'grid'])) }}"
								   title="{{ __('Grid') }}" aria-label="{{ __('Grid') }}">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><rect x="4" y="4" width="6" height="6" rx="1.5"/><rect x="14" y="4" width="6" height="6" rx="1.5"/><rect x="4" y="14" width="6" height="6" rx="1.5"/><rect x="14" y="14" width="6" height="6" rx="1.5"/></svg>
								</a>
								<a class="mlib-view {{ $isGrid ? '' : 'is-active' }}"
								   href="{{ route('admin.media.index', $queryWithout(['view' => 'list'])) }}"
								   title="{{ __('List') }}" aria-label="{{ __('List') }}">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M5 7h14M5 12h14M5 17h14"/></svg>
								</a>
							</div>
						</div>
					</form>

					@if($canUpload)
						<div class="mlib-drop"
						     data-media-dropzone
						     data-upload-url="{{ route('admin.media.store') }}"
						     data-folder="{{ $selectedFolder }}"
						     data-provider="{{ $activeProvider->id }}">
							<span class="mlib-drop__icon" aria-hidden="true">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V5M7 10l5-5 5 5M4 19h16"/></svg>
							</span>
							<span>
								{!! __('Drop files here to upload to :folder', ['folder' => '<strong>'.e($selectedFolder ?: __('the library root')).'</strong>']) !!}
								<span class="mlib-drop__hint">· {{ __('images, documents, archives, audio, video · up to :size MB', ['size' => $uploadLimitMb]) }}</span>
								<span class="mlib-drop__progress" data-media-drop-progress hidden></span>
							</span>
						</div>
					@endif

					@if($assets->isEmpty())
						<div class="mlib-empty">
							<x-admin-not-found
								:title="__('No media files found')"
								:message="__('Try another type, folder or search term.')"
								icon="fa-photo-video"
							/>
						</div>
					@elseif($isGrid)
						<div class="mlib-grid">
							@foreach($assets as $asset)
								@include('backend.media.partials._asset_card', ['asset' => $asset, 'isSelected' => $selectedAsset?->id === $asset->id])
							@endforeach
						</div>
					@else
						<div class="mlib-list">
							<div class="mlib-list__scroll">
								<div class="mlib-list__inner">
									<div class="mlib-list__head">
										<span>{{ __('Asset') }}</span>
										<span>{{ __('Type') }}</span>
										<span>{{ __('Size') }}</span>
										<span>{{ __('Folder') }}</span>
										<span>{{ __('Provider') }}</span>
										<span>{{ __('Uploaded') }}</span>
									</div>
									@foreach($assets as $asset)
										@include('backend.media.partials._asset_row', ['asset' => $asset, 'isSelected' => $selectedAsset?->id === $asset->id])
									@endforeach
								</div>
							</div>
						</div>
					@endif

					<div class="mlib-foot">
						<span>
							{!! __('Showing :shown of :total assets', [
								'shown' => '<strong>'.number_format($assets->count()).'</strong>',
								'total' => '<strong>'.number_format($assets->total()).'</strong>',
							]) !!}
						</span>
						@if($assets->hasPages())
							{{ $assets->links() }}
						@endif
					</div>
				</div>

				@if($selectedAsset)
					@include('backend.media.partials._inspector', [
						'asset' => $selectedAsset,
						'canEdit' => $canEdit,
						'canDelete' => $canDelete,
					])
				@endif
			</div>
		@else
			<div class="mlib-storage">
				<div class="mlib-storage__top">
					<div class="mlib-hero">
						<div class="mlib-hero__row">
							<span class="mlib-hero__icon" aria-hidden="true"><x-icon name="sidebar-settings" height="24" width="24"/></span>
							<div>
								<div class="mlib-hero__eyebrow">{{ __('Active provider') }}</div>
								<div class="mlib-hero__name">{{ $activeProvider->name }}</div>
								<div class="mlib-hero__spec" dir="ltr">{{ strtoupper($activeProvider->driver) }} / {{ strtoupper($activeProvider->visibility) }} · {{ $activeProvider->resolvedPrefix() }}/</div>
							</div>
						</div>
						<p class="mlib-hero__note">{{ __('New uploads use the active provider. Existing media keeps loading from the provider that originally stored it.') }}</p>
					</div>

					@if($canManageStorage)
						<div class="mlib-form-card">
							<div class="mlib-form-card__head">
								<h2 class="mlib-form-card__title">{{ __('Add storage provider') }}</h2>
								<span class="mlib-form-card__pill">{{ __('S3-compatible') }}</span>
							</div>
							<form method="POST" action="{{ route('admin.media.storage-providers.store') }}" data-media-provider-form>
								@csrf
								@include('backend.media.partials._provider_form', ['provider' => null])
								<div class="mlib-providers__actions">
									<button type="submit" class="mlib-btn mlib-btn--primary">
										<x-icon name="plus" height="12" width="12"/>
										<span>{{ __('Create provider') }}</span>
									</button>
								</div>
							</form>
						</div>
					@endif
				</div>

				<div class="mlib-providers">
					<div class="mlib-providers__scroll">
						<div class="mlib-providers__inner">
							<div class="mlib-providers__head">
								<span>{{ __('Provider') }}</span>
								<span>{{ __('Driver') }}</span>
								<span>{{ __('Prefix') }}</span>
								<span>{{ __('Files') }}</span>
								<span>{{ __('Last test') }}</span>
								<span class="text-end">{{ __('Actions') }}</span>
							</div>

							@forelse($providers as $provider)
								@php
									$dotModifier = $provider->is_active
										? 'active'
										: ($provider->last_checked_at ? '' : 'untested');
									$testStatus = $provider->last_checked_at ? (string) $provider->last_check_status : 'untested';
								@endphp
								<div class="mlib-providers__row" data-provider-row="{{ $provider->id }}">
									<div class="mlib-provider">
										<span class="mlib-provider__dot {{ $dotModifier ? 'mlib-provider__dot--'.$dotModifier : '' }}" aria-hidden="true"></span>
										<span class="mlib-provider__body">
											<span class="mlib-provider__name">
												{{ $provider->name }}
												@if($provider->is_active)
													<span class="mlib-provider__active">{{ __('Active') }}</span>
												@endif
											</span>
											<span class="mlib-provider__slug" dir="ltr">{{ $provider->slug }}</span>
										</span>
									</div>
									<span class="mlib-driver" dir="ltr">{{ strtoupper($provider->driver) }}</span>
									<span class="mlib-list__cell mlib-list__cell--mono" dir="ltr">{{ $provider->resolvedPrefix() }}</span>
									<span class="mlib-list__cell mlib-list__cell--num">{{ number_format($provider->assets_count) }}</span>
									<span class="mlib-test">
										<span class="mlib-test__status mlib-test__status--{{ $testStatus }}"
										      data-provider-test-status
										      data-label-success="{{ __('Success') }}"
										      data-label-failed="{{ __('Failed') }}">
											@if(! $provider->last_checked_at)
												{{ __('Not tested') }}
											@elseif($provider->last_check_status === 'success')
												{{ __('Success') }}
											@else
												{{ __('Failed') }}
											@endif
										</span>
										<span class="mlib-test__at" data-provider-test-at>{{ $provider->last_checked_at?->diffForHumans() ?? '—' }}</span>
									</span>
									<div class="mlib-providers__actions">
										@if($canManageStorage)
											@if(! $provider->is_active)
												<form method="POST" action="{{ route('admin.media.storage-providers.activate', $provider) }}">
													@csrf
													<button type="submit" class="mlib-mini mlib-mini--activate">{{ __('Activate') }}</button>
												</form>
											@endif
											<form method="POST" action="{{ route('admin.media.storage-providers.test', $provider) }}" data-provider-test>
												@csrf
												<button type="submit" class="mlib-mini">{{ __('Test') }}</button>
											</form>
											<button type="button" class="mlib-mini" data-coreui-toggle="collapse" data-coreui-target="#providerEdit{{ $provider->id }}">{{ __('Edit') }}</button>
											@if(! $provider->is_active && $provider->assets_count === 0)
												<button type="button" class="mlib-mini mlib-mini--danger delete" data-url="{{ route('admin.media.storage-providers.destroy', $provider) }}">{{ __('Delete') }}</button>
											@endif
										@endif
									</div>
								</div>

								@if($canManageStorage)
									<div class="collapse mlib-provider-edit" id="providerEdit{{ $provider->id }}">
										<form method="POST" action="{{ route('admin.media.storage-providers.update', $provider) }}" data-media-provider-form>
											@csrf
											@method('PUT')
											@include('backend.media.partials._provider_form', ['provider' => $provider])
											<div class="mlib-providers__actions">
												<button type="submit" class="mlib-btn mlib-btn--primary">{{ __('Save provider') }}</button>
											</div>
										</form>
									</div>
								@endif
							@empty
								<div class="mlib-empty">
									<x-admin-not-found
										:title="__('No storage providers found')"
										:message="__('A local provider will be created automatically the first time the media library opens.')"
										icon="fa-database"
									/>
								</div>
							@endforelse
						</div>
					</div>
				</div>
			</div>
		@endif
	</div>

	@if($canUpload)
		@include('backend.media.partials._upload_modal', ['providers' => $providers, 'activeProvider' => $activeProvider])
	@endif
@endsection
