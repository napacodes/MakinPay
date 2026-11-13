@php
	/** @var \App\Models\Admin $staff */
	$roleName = $staff->roleName;

	$presence = ! $staff->status
		? 'blocked'
		: ($staff->isOnline ? 'online' : 'offline');
@endphp

{{--
	Manage, as a modal.

	The inside is the drawer's markup unchanged — same classes, same hooks, so
	the script and the stylesheet did not have to be rewritten to move it. Only
	the shell around it changed, from an aside beside the table to a dialog
	over the page.

	It is seeded from the first member so every control has a shape and a URL
	before anything is picked; the script fills in whoever the admin clicks.
--}}
<div class="modal fade team-modal"
     id="team_manage_modal"
     tabindex="-1"
     aria-hidden="true"
     aria-labelledby="team_manage_title">
	<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
		<div class="modal-content">
			<div class="team-drawer" data-team-drawer>
				<div class="team-drawer__head">
					<button type="button"
					        class="team-drawer__close"
					        data-coreui-dismiss="modal"
					        data-team-close
					        title="{{ __('Close') }}"
					        aria-label="{{ __('Close') }}">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
					</button>

					<div class="team-drawer__identity">
						<span class="team-avatar">
							<span class="team-avatar__face team-avatar__face--{{ $avatarTone((int) $staff->id) }}" data-drawer-avatar>
								@if($staff->avatar)
									<img src="{{ asset($staff->avatar_alt) }}" alt="">
								@else
									{{ $initials($staff->name) }}
								@endif
							</span>
							<span class="team-avatar__presence team-avatar__presence--{{ $presence }}" data-drawer-presence></span>
						</span>
						<div class="team-member__body">
							<div class="team-drawer__name" id="team_manage_title" data-drawer-name>{{ title($staff->name) }}</div>
							<div class="team-drawer__email" dir="ltr" data-drawer-email>{{ $staff->email }}</div>
							<div class="team-drawer__chips">
								<span class="team-drawer__chip" data-drawer-role>{{ $roleName ? title($roleName) : __('No role') }}</span>
								<span class="team-drawer__chip team-drawer__chip--tfa-{{ $staff->two_factor_enabled ? 'on' : 'off' }}" data-drawer-tfa>
									{{ __('2FA :state', ['state' => $staff->two_factor_enabled ? __('On') : __('Off')]) }}
								</span>
							</div>
						</div>
					</div>
				</div>

				<div class="team-drawer__body">
					<form data-team-form data-action="{{ route('admin.staff.update', $staff->id) }}">
						@csrf
						@method('PUT')

						<label class="team-field">
							{{ __('Full name') }}
							<input type="text" name="name" value="{{ $staff->name }}" data-drawer-input-name required>
						</label>

						<label class="team-field mt-2">
							{{ __('Email') }}
							<input type="email" name="email" value="{{ $staff->email }}" dir="ltr" data-drawer-input-email required>
						</label>

						<label class="team-field mt-2">
							{{ __('Role') }}
							<select name="role" data-drawer-input-role required>
								@foreach($allRoles as $role)
									<option value="{{ $role }}" @selected($roleName === $role)>{{ title($role) }}</option>
								@endforeach
							</select>
						</label>
					</form>

					<div class="team-switch-card">
						<div>
							<div class="team-switch-card__title">{{ __('Account active') }}</div>
							<div class="team-switch-card__hint">{{ __('Inactive members cannot sign in.') }}</div>
						</div>
						<button type="button"
						        class="team-switch"
						        role="switch"
						        aria-checked="{{ $staff->status ? 'true' : 'false' }}"
						        aria-label="{{ __('Account active') }}"
						        data-team-status
						        data-action="{{ route('admin.staff.status', $staff->id) }}">
							<span class="team-switch__knob" aria-hidden="true"></span>
						</button>
					</div>

					<div class="team-meta">
						<div>
							<div class="team-meta__label">{{ __('Last active') }}</div>
							<div class="team-meta__value" data-drawer-last-active>{{ $staff->lastActiveAt?->diffForHumans() ?? __('Never') }}</div>
						</div>
						<div>
							<div class="team-meta__label">{{ __('Joined') }}</div>
							<div class="team-meta__value" data-drawer-joined>{{ $staff->created_at?->isoFormat('DD MMM YYYY') }}</div>
						</div>
						<div>
							<div class="team-meta__label">{{ __('Last IP') }}</div>
							<div class="team-meta__value team-meta__value--mono" dir="ltr" data-drawer-ip>{{ $staff->lastIp ?: '—' }}</div>
						</div>
						<div>
							<div class="team-meta__label">{{ __('Sessions') }}</div>
							<div class="team-meta__value" data-drawer-sessions>{{ trans_choice('{0} None|{1} :count active|[2,*] :count active', (int) $staff->sessionsCount, ['count' => (int) $staff->sessionsCount]) }}</div>
						</div>
					</div>

					<div class="team-drawer__actions">
						<button type="button" class="team-save" data-team-save>{{ __('Save changes') }}</button>
						<button type="button" class="team-secondary" data-team-reset data-action="{{ route('admin.staff.password-reset', $staff->id) }}">{{ __('Reset password') }}</button>
					</div>

					<button type="button" class="team-danger" data-team-revoke data-action="{{ route('admin.staff.revoke-sessions', $staff->id) }}">{{ __('Revoke all sessions') }}</button>
				</div>
			</div>
		</div>
	</div>
</div>
