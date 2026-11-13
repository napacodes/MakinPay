@php
	/** @var \App\Models\MediaAsset $asset */
	$extension = strtoupper((string) $asset->extension);
	$extClass = strtolower($extension);
	/* Resolved against this request, not the host the file was uploaded from. */
	$assetUrl = $asset->publicUrl();
	$isVector = $extClass === 'svg';
@endphp

<aside class="mlib-inspector" data-media-inspector aria-label="{{ __('Media details') }}">
	<div class="mlib-inspector__preview {{ $asset->isImage() ? ($isVector ? 'mlib-preview--vector' : '') : 'mlib-preview--'.$asset->aggregate_type }}" data-inspector-preview>
		@if($asset->isImage())
			<img src="{{ $assetUrl }}"
			     alt="{{ $asset->alt_text ?: $asset->displayTitle() }}"
			     data-inspector-image
			     data-media-image
			     data-ext="{{ $extension }}">
		@else
			<span class="mlib-page-card mlib-page-card--{{ $extClass }}" data-inspector-extcard dir="ltr">{{ $extension }}</span>
		@endif
		<span class="mlib-type" data-inspector-type>{{ $asset->aggregate_type }}</span>
	</div>

	<div class="mlib-inspector__body">
		<div>
			<div class="mlib-inspector__file" data-inspector-file>{{ $asset->original_name }}</div>
			<div class="mlib-inspector__by">
				{!! __('Uploaded :when by :who', [
					'when' => '<span data-inspector-uploaded>'.e($asset->created_at?->diffForHumans() ?? '—').'</span>',
					'who' => '<strong data-inspector-uploader>'.e($asset->uploader?->name ?? __('Unknown')).'</strong>',
				]) !!}
			</div>
		</div>

		<div class="mlib-meta">
			<div>
				<div class="mlib-meta__label">{{ __('Size') }}</div>
				{{-- Byte size and pixel dimensions are Latin-ordered even in Arabic. --}}
				<div class="mlib-meta__value" data-inspector-size dir="ltr">{{ $asset->humanSize() }}</div>
			</div>
			<div>
				<div class="mlib-meta__label">{{ __('Dimensions') }}</div>
				<div class="mlib-meta__value" data-inspector-dimensions dir="ltr">{{ $asset->width && $asset->height ? $asset->width.' × '.$asset->height : '—' }}</div>
			</div>
			<div>
				<div class="mlib-meta__label">{{ __('Provider') }}</div>
				<div class="mlib-meta__value" data-inspector-provider>{{ $asset->storageProvider?->name ?? __('Unknown') }}</div>
			</div>
			<div>
				<div class="mlib-meta__label">{{ __('Folder') }}</div>
				<div class="mlib-meta__value mlib-meta__value--mono" data-inspector-folder dir="ltr">{{ $asset->folder ?: '—' }}</div>
			</div>
		</div>

		<div>
			<span class="mlib-url__label">{{ __('Public URL') }}</span>
			<div class="mlib-url">
				<span class="mlib-url__value" data-inspector-url dir="ltr" title="{{ $assetUrl }}">{{ $assetUrl }}</span>
				<button type="button"
				        class="mlib-url__copy"
				        data-media-copy
				        data-label-copy="{{ __('Copy') }}"
				        data-label-copied="{{ __('Copied') }}">{{ __('Copy') }}</button>
			</div>
		</div>

		@if($canEdit)
			<form class="mlib-fields" data-media-inspector-form data-action="{{ route('admin.media.update', $asset) }}">
				@csrf
				@method('PUT')
				<label class="mlib-field">
					{{ __('Title') }}
					<input type="text" name="title" value="{{ $asset->title }}" data-inspector-title>
				</label>
				<label class="mlib-field">
					{{ __('Alt text') }}
					<input type="text" name="alt_text" value="{{ $asset->alt_text }}" placeholder="{{ __('Describe the image for screen readers') }}" data-inspector-alt>
				</label>
				<label class="mlib-field">
					{{ __('Caption') }}
					<textarea name="caption" rows="2" data-inspector-caption>{{ $asset->caption }}</textarea>
				</label>
			</form>
		@endif

		<div class="mlib-inspector__actions">
			@if($canEdit)
				<button type="button" class="mlib-save" data-media-save>{{ __('Save changes') }}</button>
			@endif
			<a href="{{ $assetUrl }}" target="_blank" rel="noopener" class="mlib-icon-btn" data-inspector-open title="{{ __('Open') }}" aria-label="{{ __('Open') }}">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></svg>
			</a>
			@if($canDelete)
				<button type="button" class="mlib-icon-btn mlib-icon-btn--danger delete" data-url="{{ route('admin.media.destroy', $asset) }}" data-inspector-delete title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">
					<x-icon name="delete-2" height="15" width="15"/>
				</button>
			@endif
		</div>
	</div>
</aside>
