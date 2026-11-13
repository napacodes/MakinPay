{{--
	The confirmation an administrator sees before switching on something that
	can lock them out: Maintenance Mode, Force HTTPS, Development Mode.

	One dialog for all three. The copy, the button label and the acknowledgement
	come from the switch that opened it (`enable_warning*` in
	config/settings.php); the recovery block below is the part only Maintenance
	Mode asks for, so it stays hidden until that switch says otherwise.

	Confirming does not save. It ticks the checkbox and hands the change to the
	unsaved-changes bar like any other edit.
--}}
@php
	$riskEnvironment = (string) setting('site_environment', 'production');
@endphp
<div class="modal fade settings-maintenance-modal"
     id="settingsEnableWarningModal"
     tabindex="-1"
     aria-labelledby="settingsEnableWarningModalLabel"
     aria-describedby="settingsEnableWarningModalDescription"
     aria-hidden="true"
     data-settings-enable-warning-modal
     data-settings-secret-pattern="^[A-Za-z0-9._~-]+$"
     data-settings-secret-min="4"
     data-settings-secret-max="64"
     data-settings-secret-required="{{ __('Set a Secret Key before enabling Maintenance Mode.') }}"
     data-settings-secret-invalid="{{ __('These characters cannot be used — the recovery URL would never work and you would be locked out: :chars') }}"
     data-settings-secret-length="{{ __('The Secret Key must be between :min and :max characters.') }}">
	<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header settings-maintenance-modal__header">
				<div class="settings-maintenance-modal__title">
						<span class="settings-maintenance-modal__icon" aria-hidden="true">
							<i class="fas fa-triangle-exclamation"></i>
						</span>
					<div>
						<span class="settings-maintenance-modal__eyebrow">{{ __('Before you continue') }}</span>
						<h5 class="modal-title" id="settingsEnableWarningModalLabel" data-settings-warning-title>
							{{ __('Enable Maintenance Mode?') }}
						</h5>
					</div>
				</div>
				<button type="button"
				        class="settings-maintenance-modal__close"
				        data-coreui-dismiss="modal"
				        aria-label="{{ __('Close') }}">
					<i class="fas fa-times" aria-hidden="true"></i>
				</button>
			</div>
			<div class="modal-body settings-maintenance-modal__body">
				<div class="settings-maintenance-modal__impact" id="settingsEnableWarningModalDescription">
					<span class="settings-maintenance-modal__impact-icon" aria-hidden="true">
						<i class="fas fa-exclamation-triangle"></i>
					</span>
					<div>
						<strong>{{ __('What happens after you enable it') }}</strong>
						<p data-settings-warning-message>
							{{ __('Visitors and staff will see the maintenance screen. The normal admin URL will also be unavailable until you use the private recovery URL below.') }}
						</p>
					</div>
				</div>

				<p class="settings-maintenance-modal__environment">
					<i class="fas fa-circle-info" aria-hidden="true"></i>
					{{-- Only a production install has live users to warn about; saying so
					     on a local copy is the kind of wrong that teaches people to skim. --}}
					{{ $riskEnvironment === 'production'
						? __('This installation is running in Production — the change applies to live users.')
						: __('This installation is running in :environment.', ['environment' => title($riskEnvironment)]) }}
				</p>

				<section class="settings-maintenance-modal__recovery"
				         aria-labelledby="settingsMaintenanceRecoveryTitle"
				         data-settings-warning-recovery
				         hidden>
					<div class="settings-maintenance-modal__section-heading">
						<div>
							<span class="settings-maintenance-modal__required">
								<i class="fas fa-shield-alt" aria-hidden="true"></i>
								{{ __('Required before enabling') }}
							</span>
							<h6 id="settingsMaintenanceRecoveryTitle">{{ __('Save your private recovery URL') }}</h6>
						</div>
						<span class="settings-maintenance-modal__private" title="{{ __('Keep this URL private') }}">
							<i class="fas fa-lock" aria-hidden="true"></i>
							{{ __('Private') }}
						</span>
					</div>

					<p class="settings-maintenance-modal__section-copy">
						{{ __('This URL temporarily bypasses the maintenance screen so you can sign in and turn Maintenance Mode off later. Anyone with the URL can bypass maintenance, so keep it private.') }}
					</p>

					<div class="settings-maintenance-modal__url-card">
						<span class="settings-maintenance-modal__url-label">{{ __('Your recovery URL') }}</span>
						<div class="settings-maintenance-modal__url-row">
							<code data-settings-warning-recovery-url
							      data-settings-warning-base-url="{{ rtrim(url('/'), '/') }}">{{ rtrim(url('/'), '/') }}/<strong data-settings-warning-secret>{{ setting('secret_key', 'secret') }}</strong></code>
							<button type="button"
							        class="settings-maintenance-modal__copy"
							        data-settings-copy-recovery-url
							        data-settings-copy-success="{{ __('Recovery URL copied') }}"
							        aria-label="{{ __('Copy recovery URL') }}">
								<i class="far fa-copy" aria-hidden="true"></i>
								<span data-settings-copy-recovery-label aria-live="polite">{{ __('Copy URL') }}</span>
							</button>
						</div>
						<p class="settings-maintenance-modal__example">
							<i class="fas fa-lightbulb" aria-hidden="true"></i>
							{{ __('Example: if the Secret Key is "my-access", open yourdomain.com/my-access.') }}
						</p>
					</div>

					<div class="settings-maintenance-modal__steps" aria-label="{{ __('How to restore normal access') }}">
						<div class="settings-maintenance-modal__step">
							<span>1</span>
							<p><strong>{{ __('Copy and save') }}</strong>{{ __('Keep the recovery URL in a secure password manager or private note.') }}</p>
						</div>
						<div class="settings-maintenance-modal__step">
							<span>2</span>
							<p><strong>{{ __('Open the recovery URL') }}</strong>{{ __('Use it in your browser when you need admin access during maintenance.') }}</p>
						</div>
						<div class="settings-maintenance-modal__step">
							<span>3</span>
							<p><strong>{{ __('Turn maintenance off') }}</strong>{{ __('Sign in, return to Settings > Maintenance, disable the switch, and save.') }}</p>
						</div>
					</div>
				</section>

				<label class="settings-maintenance-modal__ack" for="settingsEnableWarningAck" data-settings-warning-ack-wrap hidden>
					<input type="checkbox" id="settingsEnableWarningAck" data-settings-warning-ack>
					<span data-settings-warning-ack-label>{{ __('I have saved the recovery URL somewhere safe.') }}</span>
				</label>
			</div>
			<div class="modal-footer settings-maintenance-modal__footer">
				<button type="button" class="btn btn-light" data-coreui-dismiss="modal">
					{{ __('Cancel') }}
				</button>
				<button type="button" class="btn btn-primary" data-settings-enable-warning-confirm>
					<i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
					<span data-settings-enable-warning-confirm-label>{{ __('Enable Maintenance Mode') }}</span>
				</button>
			</div>
		</div>
	</div>
</div>
