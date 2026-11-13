<div class="modal fade" id="mediaUploadModal" tabindex="-1" aria-labelledby="mediaUploadModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-centered">
		<div class="modal-content">
			<form method="POST" action="{{ route('admin.media.store') }}" enctype="multipart/form-data">
				@csrf
				<div class="modal-header">
					<h2 class="modal-title fs-5" id="mediaUploadModalLabel">{{ __('Upload media') }}</h2>
					<button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
				</div>
				<div class="modal-body">
					<div class="row g-3">
						<div class="col-md-6">
							<label class="form-label" for="mediaUploadFiles">{{ __('Files') }}</label>
							<input id="mediaUploadFiles" type="file" name="files[]" class="form-control" multiple required>
							<div class="form-text">{{ __('images, documents, archives, audio, video · up to :size MB', ['size' => $uploadLimitMb]) }}</div>
						</div>
						<div class="col-md-6">
							<label class="form-label" for="mediaUploadProvider">{{ __('Storage provider') }}</label>
							<select id="mediaUploadProvider" name="storage_provider_id" class="form-select">
								@foreach($providers as $provider)
									<option value="{{ $provider->id }}" @selected($provider->id === $activeProvider->id)>{{ $provider->name }}</option>
								@endforeach
							</select>
						</div>
						<div class="col-md-6">
							<label class="form-label" for="mediaUploadFolder">{{ __('Folder') }}</label>
							<input id="mediaUploadFolder" name="folder" class="form-control" value="{{ $selectedFolder }}" placeholder="{{ __('campaigns/home') }}">
						</div>
						<div class="col-md-6">
							<label class="form-label" for="mediaUploadTitle">{{ __('Title') }}</label>
							<input id="mediaUploadTitle" name="title" class="form-control">
						</div>
						<div class="col-md-6">
							<label class="form-label" for="mediaUploadAlt">{{ __('Alt text') }}</label>
							<input id="mediaUploadAlt" name="alt_text" class="form-control">
						</div>
						<div class="col-md-6">
							<label class="form-label" for="mediaUploadCaption">{{ __('Caption') }}</label>
							<textarea id="mediaUploadCaption" name="caption" rows="2" class="form-control"></textarea>
						</div>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-light" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
					<button type="submit" class="btn btn-primary">{{ __('Upload') }}</button>
				</div>
			</form>
		</div>
	</div>
</div>
