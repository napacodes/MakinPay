@php
	/** @var \App\Models\MediaAsset $asset */
	$payload = (new \App\Http\Resources\Backend\MediaAssetResource($asset))->resolve(request());
	$extension = strtoupper((string) $asset->extension);
	$extClass = strtolower($extension);
	/* A vector is a logo: show it whole rather than cropping the wordmark off. */
	$isVector = $extClass === 'svg';
@endphp

<button type="button"
        class="mlib-card {{ $isSelected ? 'is-selected' : '' }}"
        data-media-card
        data-asset-id="{{ $asset->id }}"
        data-asset="{{ json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}"
        aria-pressed="{{ $isSelected ? 'true' : 'false' }}">
	<span class="mlib-preview {{ $asset->isImage() ? ($isVector ? 'mlib-preview--vector' : '') : 'mlib-preview--'.$asset->aggregate_type }}">
		@if($asset->isImage())
			<img src="{{ $asset->publicUrl() }}"
			     alt="{{ $asset->alt_text ?: $asset->displayTitle() }}"
			     loading="lazy"
			     data-media-image
			     data-ext="{{ $extension }}">
		@else
			<span class="mlib-page-card mlib-page-card--{{ $extClass }}" dir="ltr">{{ $extension }}</span>
		@endif

		<span class="mlib-type">{{ $asset->aggregate_type }}</span>

		<span class="mlib-check" data-media-card-check {{ $isSelected ? '' : 'hidden' }} aria-hidden="true">
			<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="5 12 10 17 19 7"/></svg>
		</span>
	</span>

	<span class="mlib-card__body">
		<span class="mlib-card__title" title="{{ $asset->displayTitle() }}">{{ $asset->displayTitle() }}</span>
		<span class="mlib-card__meta">
			<span dir="ltr">{{ $extension }}</span>
			<span class="mlib-card__sep" aria-hidden="true">·</span>
			<span dir="ltr">{{ $asset->humanSize() }}</span>
			<span class="mlib-card__sep" aria-hidden="true">·</span>
			<span class="mlib-card__provider">{{ $asset->storageProvider?->name ?? __('Unknown') }}</span>
		</span>
	</span>
</button>
