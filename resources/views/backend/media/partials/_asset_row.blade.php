@php
	/** @var \App\Models\MediaAsset $asset */
	$payload = (new \App\Http\Resources\Backend\MediaAssetResource($asset))->resolve(request());
	$extension = strtoupper((string) $asset->extension);
	$extClass = strtolower($extension);
@endphp

<button type="button"
        class="mlib-list__row {{ $isSelected ? 'is-selected' : '' }}"
        data-media-card
        data-asset-id="{{ $asset->id }}"
        data-asset="{{ json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}"
        aria-pressed="{{ $isSelected ? 'true' : 'false' }}">
	<span class="mlib-list__asset">
		<span class="mlib-list__thumb {{ $asset->isImage() ? ($extClass === 'svg' ? 'mlib-list__thumb--vector' : '') : 'mlib-preview--'.$asset->aggregate_type.' mlib-page-card--'.$extClass }}">
			@if($asset->isImage())
				<img src="{{ $asset->publicUrl() }}" alt="" loading="lazy" data-media-image data-ext="{{ $extension }}">
			@else
				<span dir="ltr">{{ $extension }}</span>
			@endif
		</span>
		<span class="mlib-list__names">
			<span class="mlib-list__name">{{ $asset->displayTitle() }}</span>
			<span class="mlib-list__dims" dir="ltr">{{ $asset->width && $asset->height ? $asset->width.' × '.$asset->height : '—' }}</span>
		</span>
	</span>
	<span class="mlib-list__cell">{{ $asset->aggregate_type }}</span>
	<span class="mlib-list__cell mlib-list__cell--num" dir="ltr">{{ $asset->humanSize() }}</span>
	<span class="mlib-list__cell mlib-list__cell--mono" dir="ltr">{{ $asset->folder ?: '—' }}</span>
	<span class="mlib-list__cell">{{ $asset->storageProvider?->name ?? __('Unknown') }}</span>
	<span class="mlib-list__cell mlib-list__cell--muted">{{ $asset->created_at?->diffForHumans() }}</span>
</button>
