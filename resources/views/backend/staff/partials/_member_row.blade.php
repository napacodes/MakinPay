@php
	/** @var \App\Models\Admin $staff */
	$roleName = $staff->roleName;
	$isProtected = $roleName === 'super-admin';

	$presence = ! $staff->status
		? 'blocked'
		: ($staff->isOnline ? 'online' : 'offline');

	$payload = [
		'id' => $staff->id,
		'name' => $staff->name,
		'email' => $staff->email,
		'role' => $roleName,
		/* The select needs the raw name; the chip needs the label a person reads. */
		'role_label' => $roleName ? title($roleName) : __('No role'),
		'status' => (bool) $staff->status,
		'two_factor' => (bool) $staff->two_factor_enabled,
		'is_you' => (bool) $staff->isYou,
		'initials' => $initials($staff->name),
		'tone' => $avatarTone((int) $staff->id),
		'avatar' => $staff->avatar ? asset($staff->avatar_alt) : null,
		'presence' => $presence,
		'last_active' => $staff->lastActiveAt?->diffForHumans() ?? __('Never'),
		'joined' => $staff->created_at?->isoFormat('DD MMM YYYY'),
		'last_ip' => $staff->lastIp,
		'sessions' => (int) $staff->sessionsCount,
		'update_url' => route('admin.staff.update', $staff->id),
		'status_url' => route('admin.staff.status', $staff->id),
		'password_reset_url' => route('admin.staff.password-reset', $staff->id),
		'revoke_url' => route('admin.staff.revoke-sessions', $staff->id),
	];
@endphp

<button type="button"
        class="team-row {{ $isSelected ? 'is-selected' : '' }}"
        data-team-row
        data-staff-id="{{ $staff->id }}"
        data-staff="{{ json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}"
        aria-pressed="{{ $isSelected ? 'true' : 'false' }}">
	<span class="team-row__marker" aria-hidden="true"></span>

	<span class="team-member">
		<span class="team-avatar">
			<span class="team-avatar__face team-avatar__face--{{ $avatarTone((int) $staff->id) }}">
				@if($staff->avatar)
					<img src="{{ asset($staff->avatar_alt) }}" alt="" loading="lazy">
				@else
					{{ $initials($staff->name) }}
				@endif
			</span>
			<span class="team-avatar__presence team-avatar__presence--{{ $presence }}"
			      title="{{ $presence === 'online' ? __('Online') : ($presence === 'blocked' ? __('Inactive') : __('Offline')) }}"></span>
		</span>
		<span class="team-member__body">
			<span class="team-member__name">
				{{ title($staff->name) }}
				@if($staff->isYou)
					<span class="team-member__you">{{ __('You') }}</span>
				@endif
			</span>
			<span class="team-member__email" dir="ltr">{{ $staff->email }}</span>
		</span>
	</span>

	<span>
		@if($roleName)
			<span class="team-role {{ $isProtected ? 'team-role--protected' : '' }}">
				<x-icon :name="$isProtected ? 'lock' : 'role'" height="12" width="12"/>
				{{ title($roleName) }}
			</span>
		@else
			<span class="team-role team-role--none">{{ __('No role') }}</span>
		@endif
	</span>

	<span>
		<span class="team-status team-status--{{ $staff->status ? 'active' : 'inactive' }}">
			<span class="team-status__dot" aria-hidden="true"></span>
			{{ $staff->status ? __('Active') : __('Inactive') }}
		</span>
	</span>

	<span class="team-tfa {{ $staff->two_factor_enabled ? 'team-tfa--on' : '' }}">
		<x-icon name="cil-lock-locked" height="12" width="12"/>
		{{ $staff->two_factor_enabled ? __('On') : __('Off') }}
	</span>

	<span class="team-cell">{{ $staff->lastActiveAt?->diffForHumans() ?? __('Never') }}</span>
	<span class="team-cell team-cell--muted">{{ $staff->created_at?->isoFormat('DD MMM YYYY') }}</span>

	<span class="team-row__actions">
		@if($canEdit)
			@if($staff->isYou)
				<a href="{{ route('admin.profile.view') }}" class="team-manage">
					<x-icon name="manage" height="14" width="14"/>
					{{ __('Manage') }}
				</a>
			@else
				{{--
					The only action on the row. There is no second "edit"
					behind a kebab — editing a member and managing one were
					the same thing wearing two names.
				--}}
				<span class="team-manage" data-team-manage>
					<x-icon name="manage" height="14" width="14"/>
					{{ __('Manage') }}
				</span>
			@endif
		@endif
	</span>
</button>
