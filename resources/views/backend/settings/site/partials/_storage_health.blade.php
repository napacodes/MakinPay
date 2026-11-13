@php
	/**
	 * Everything this dialogue needs, derived here rather than taken from the
	 * page. The page used to unpack the controller array for it, which meant a
	 * partial that renders nowhere else silently depended on three variables
	 * defined two files away.
	 */
	$storageStatus = $storageLinkStatus ?? [
		'linked'       => false,
		'public_path'  => public_path('storage'),
		'target_path'  => storage_path('app/public'),
		'status_label' => __('Missing'),
		'status_tone'  => 'danger',
		'functions'      => ['exec' => true, 'symlink' => true],
		'function_roles' => ['symlink' => 'required', 'exec' => 'optional'],
		'functions_ok'   => true,
	];
	$storageFunctions   = $storageStatus['functions'] ?? ['exec' => true, 'symlink' => true];
	$storageRoles       = $storageStatus['function_roles'] ?? ['symlink' => 'required', 'exec' => 'optional'];
	$storageFunctionsOk = $storageStatus['functions_ok'] ?? true;

	/**
	 * One row per PHP function: whether this server has it switched on, whether
	 * the link actually depends on it, and a sentence saying why — so an admin
	 * never has to guess what a tick next to a function name means.
	 */
	$storageFunctionRows = collect(['symlink', 'exec'])->map(function (string $name) use ($storageFunctions, $storageRoles): array {
		$enabled  = (bool) ($storageFunctions[$name] ?? false);
		$required = ($storageRoles[$name] ?? 'optional') === 'required';

		$purpose = match (true) {
			$name === 'symlink' => __('Creates the public/storage link itself. The link cannot be made without it.'),
			$required           => __('Runs the mklink command that creates the link on a Windows server.'),
			default             => __('Backup method only. Not used while :function is enabled, so it can safely stay disabled.', ['function' => '<code dir="ltr">symlink()</code>']),
		};

		return [
			'name'     => $name,
			'enabled'  => $enabled,
			'required' => $required,
			'purpose'  => $purpose,
			'tone'     => $enabled ? 'ok' : ($required ? 'danger' : 'muted'),
		];
	});
	$storageBlockingFunctions = $storageFunctionRows
		->filter(fn (array $row): bool => $row['required'] && ! $row['enabled'])
		->map(fn (array $row): string => $row['name'].'()')
		->implode(', ');
@endphp

<div class="modal fade settings-storage-modal"
     id="storageHealthModal"
     tabindex="-1"
     aria-labelledby="storageHealthModalLabel"
     aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-lg">
		<div class="modal-content">
			<div class="modal-header settings-storage-modal__header settings-storage-modal__header--{{ $storageStatus['status_tone'] }}">
				<div class="settings-storage-modal__title">
                        <span class="settings-storage-modal__icon">
                            <i class="fas {{ $storageStatus['linked'] ? 'fa-check-circle' : 'fa-link' }}"></i>
                        </span>
					<div>
						<span class="settings-storage-modal__eyebrow">{{ __('Storage Health') }}</span>
						<h5 class="modal-title" id="storageHealthModalLabel">{{ __('Public Storage Link') }}</h5>
					</div>
				</div>
				<span class="settings-storage-modal__status-badge settings-storage-modal__status-badge--{{ $storageStatus['status_tone'] }}">
                        <i class="fas {{ $storageStatus['linked'] ? 'fa-check-circle' : 'fa-exclamation-triangle' }}"></i>
                        {{ $storageStatus['status_label'] }}
                    </span>
				<button type="button"
				        class="settings-maintenance-modal__close"
				        data-coreui-dismiss="modal"
				        aria-label="{{ __('Close') }}">
					<x-icon name="close" height="18" width="18"/>
				</button>
			</div>
			<div class="modal-body settings-storage-modal__body">
				<section class="settings-storage-link-panel settings-storage-link-panel--{{ $storageStatus['status_tone'] }}" aria-labelledby="settings-storage-link-title">
					<div class="settings-storage-link-panel__main">
                            <span class="settings-storage-link-panel__icon" aria-hidden="true">
                                <i class="fas fa-link"></i>
                            </span>
						<div class="settings-storage-link-panel__content">
							<h5 id="settings-storage-link-title">{{ __('Regenerate Public Storage Link') }}</h5>
							<p>
								{{ __('Uploaded logos, user files, previews, and public documents need this link before they can load from the browser. Regenerate it any time after server moves or broken uploads. An existing link is unlinked first, then recreated.') }}
							</p>
							<div class="settings-storage-link-panel__paths">
                                    <span>
                                        <strong>{{ __('Command') }}</strong>
                                        <code>php artisan storage:link --force</code>
                                    </span>
								<span>
                                        <strong>{{ __('Public Link') }}</strong>
                                        <code>{{ $storageStatus['public_path'] }}</code>
                                    </span>
								<span>
                                        <strong>{{ __('Target') }}</strong>
                                        <code>{{ $storageStatus['target_path'] }}</code>
                                    </span>
							</div>
						</div>
					</div>
				</section>
				
				<section class="settings-storage-requirement settings-storage-requirement--{{ $storageFunctionsOk ? 'ok' : 'danger' }}" aria-labelledby="settings-storage-requirement-title">
					<header class="settings-storage-requirement__head">
                        <span class="settings-storage-requirement__icon" aria-hidden="true">
                            <i class="fas {{ $storageFunctionsOk ? 'fa-shield-alt' : 'fa-triangle-exclamation' }}"></i>
                        </span>
						<div class="settings-storage-requirement__content">
							<strong id="settings-storage-requirement-title">{{ __('PHP functions on this server') }}</strong>
							<p>
								@if ($storageFunctionsOk)
									{{ __('Every function the storage link needs is enabled. You can regenerate the link safely.') }}
								@else
									{!! __(':functions is disabled, so the storage link cannot be created until it is enabled.', ['functions' => '<code dir="ltr">'.e($storageBlockingFunctions).'</code>']) !!}
								@endif
							</p>
						</div>
						<span class="settings-storage-requirement__verdict settings-storage-requirement__verdict--{{ $storageFunctionsOk ? 'ok' : 'danger' }}">
                            <i class="fas {{ $storageFunctionsOk ? 'fa-check' : 'fa-xmark' }}" aria-hidden="true"></i>
                            {{ $storageFunctionsOk ? __('Ready') : __('Action needed') }}
                        </span>
					</header>

					<ul class="settings-storage-requirement__list">
						@foreach ($storageFunctionRows as $row)
							<li class="settings-storage-requirement__row settings-storage-requirement__row--{{ $row['tone'] }}"
							    data-storage-function="{{ $row['name'] }}"
							    data-storage-function-enabled="{{ $row['enabled'] ? '1' : '0' }}"
							    data-storage-function-role="{{ $row['required'] ? 'required' : 'optional' }}">
                                <span class="settings-storage-requirement__mark" aria-hidden="true">
                                    <i class="fas {{ $row['enabled'] ? 'fa-check' : 'fa-xmark' }}"></i>
                                </span>
								<div class="settings-storage-requirement__row-copy">
									<div class="settings-storage-requirement__row-title">
										<code dir="ltr">{{ $row['name'] }}()</code>
										<span class="settings-storage-requirement__role settings-storage-requirement__role--{{ $row['required'] ? 'required' : 'optional' }}">
                                            {{ $row['required'] ? __('Required') : __('Optional') }}
                                        </span>
									</div>
									<p>{!! $row['purpose'] !!}</p>
								</div>
								<span class="settings-storage-requirement__state settings-storage-requirement__state--{{ $row['tone'] }}">
                                    <span class="settings-storage-requirement__dot" aria-hidden="true"></span>
                                    {{ $row['enabled'] ? __('Enabled') : __('Disabled') }}
                                </span>
							</li>
						@endforeach
					</ul>

					@unless ($storageFunctionsOk)
						<div class="settings-storage-requirement__fix">
							<strong>{{ __('How to enable it') }}</strong>
							<ol>
								<li>{{ __('Open cPanel → Select PHP Version → Options, or edit php.ini on the server.') }}</li>
								<li>{!! __('Find :setting and remove :functions from the list.', ['setting' => '<code dir="ltr">disable_functions</code>', 'functions' => '<code dir="ltr">'.e($storageBlockingFunctions).'</code>']) !!}</li>
								<li>{{ __('Save, restart PHP, then reopen this window. Ask your hosting provider if you cannot change it yourself.') }}</li>
							</ol>
						</div>
					@endunless
				</section>
				
				<form method="POST"
				      action="{{ route('admin.settings.site.storage-link') }}"
				      class="settings-storage-modal__form"
				      data-storage-link-form>
					@csrf
					<div class="settings-storage-modal__progress" data-storage-link-progress aria-live="polite">
						<div class="settings-storage-modal__progress-header">
							<span>{{ __('Regenerating storage link') }}</span>
							<strong>{{ __('Working') }}</strong>
						</div>
						<div class="progress settings-storage-modal__progressbar" role="progressbar" aria-label="{{ __('Storage link progress') }}" aria-valuemin="0" aria-valuemax="100">
							<div class="progress-bar progress-bar-striped progress-bar-animated settings-storage-modal__progressbar-fill"></div>
						</div>
						<p>{{ __('Please wait while the current storage link is refreshed and connected again.') }}</p>
					</div>
					
					<div class="settings-storage-modal__actions">
						<button type="button" class="btn btn-light" data-coreui-dismiss="modal">
							<x-icon name="close-1" height="18" width="18"/>
							{{ __('Cancel') }}
						</button>
						<button type="submit"
						        class="btn btn-primary settings-site-header-action"
						        data-storage-link-button
						        data-loading-label="{{ __('Regenerating...') }}">
							<i class="fas fa-terminal"></i>
							<span data-storage-link-button-label>{{ __('Regenerate Storage Link') }}</span>
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>