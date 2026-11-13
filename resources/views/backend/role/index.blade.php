@extends('backend.layouts.app')

@section('title', __('Roles & permissions'))

{{--
	Block @php only in this file. Blade matches an inline @php( with the block
	pattern, so one inline directive above a block's @endphp swallows every
	line between them and the view dies on the next @endforeach.
--}}
@push('styles')
	@php
		$roleCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/role-management.css'));
	@endphp
	<link rel="stylesheet" href="{{ asset('backend/css/role-management.css?v='.$roleCssVersion) }}">
@endpush

@push('scripts')
	@php
		$roleJsVersion = config('app.version').'-'.filemtime(public_path('backend/js/role-management.js'));
	@endphp
	<script src="{{ asset('backend/js/role-management.js?v='.$roleJsVersion) }}"></script>
@endpush

@section('content')
	@php
		$canDelete = auth('admin')->user()?->can('role-delete') ?? false;
		$canCreate = auth('admin')->user()?->can('role-create') ?? false;

		$initials = static function (?string $name): string {
			$words = array_values(array_filter(preg_split('/\s+/', trim((string) $name)) ?: []));

			$letters = implode('', array_map(
				static fn (string $word): string => mb_substr($word, 0, 1),
				array_slice($words, 0, 2)
			));

			return $letters === '' ? '?' : mb_strtoupper($letters);
		};

		$avatarTone = static fn (int $id): string => 'g'.(1 + ($id % 6));

		$totalPermissions = max(1, (int) $summary['totalPermissions']);

		$types = [
			'all'       => __('All'),
			'custom'    => __('Custom'),
			'protected' => __('Protected'),
		];
	@endphp

	<div class="role-mgmt-page role-mgmt-page--index"
	     data-role-index
	     data-label-no-match="{{ __('No roles match “:term”.', ['term' => ':term']) }}">

		{{--
			v2.2 gives each count a tonal tile rather than a dot beside its
			label — none of these three figures has a history to trend, and
			the handoff's rule is that a static count never gets a drawn one.
			The reference's fourth cell, unassigned members, has no query
			behind it here, so the strip stays at three.
		--}}
		<x-page-header
			:title="__('Roles & permissions')"
			:eyebrow="__('Access')"
			:subtitle="__('Define scoped access, manage staff, and lock down sensitive areas.')"
			icon="sidebar-role"
			:status="['label' => __(':count roles', ['count' => number_format((int) $summary['totalRoles'])])]"
			:stats="[
				['label' => __('Total Roles'), 'value' => (int) $summary['totalRoles'], 'note' => __('custom and protected'), 'tone' => 'brand', 'icon' => 'role'],
				['label' => __('Assigned Staff'), 'value' => (int) $summary['assignedStaff'], 'note' => __('members holding a role'), 'tone' => 'success', 'icon' => 'users-1'],
				['label' => __('Permissions'), 'value' => (int) $summary['totalPermissions'], 'note' => __('across every module'), 'icon' => 'lock'],
			]"
		>
			@if($canCreate)
				<x-slot:actions>
					<a href="{{ route('admin.role.create') }}" class="ph-btn ph-btn--primary">
						<x-icon name="plus" height="14" width="14"/>
						{{ __('New role') }}
					</a>
				</x-slot:actions>
			@endif
		</x-page-header>

		{{--
			A real GET form, so the filters keep working with the script
			switched off and survive a reload. role-management.js narrows the
			rendered rows as you type instead of round-tripping.
		--}}
		<form class="role-mgmt-table-card" method="GET" action="{{ route('admin.role.index') }}" data-role-filter-form>
			<div class="role-mgmt-toolbar">
				<label class="role-mgmt-search">
					<span class="role-mgmt-search__icon" aria-hidden="true">
						<x-icon name="search" height="14" width="14" class="icon-custom-size"/>
					</span>
					<span class="visually-hidden">{{ __('Search roles') }}</span>
					<input type="search"
					       name="search"
					       value="{{ $search }}"
					       placeholder="{{ __('Search roles') }}"
					       autocomplete="off"
					       data-role-search>
				</label>

				<div class="role-mgmt-seg" role="group" aria-label="{{ __('Filter by role type') }}">
					@foreach($types as $value => $label)
						<button type="submit"
						        name="type"
						        value="{{ $value }}"
						        class="role-mgmt-seg__btn {{ $type === $value ? 'is-active' : '' }}"
						        data-role-type="{{ $value }}"
						        aria-pressed="{{ $type === $value ? 'true' : 'false' }}">{{ $label }}</button>
					@endforeach
				</div>

				<span class="role-mgmt-toolbar__tally">
					{{ __(':roles roles · :permissions permissions', [
						'roles'       => number_format($summary['totalRoles']),
						'permissions' => number_format($summary['totalPermissions']),
					]) }}
				</span>
			</div>

			<div class="role-mgmt-table" role="table" aria-label="{{ __('Roles') }}">
				<div class="role-mgmt-table__inner">
					<div class="role-mgmt-thead" role="row">
						<span role="columnheader">{{ __('Role') }}</span>
						<span role="columnheader">{{ __('Type') }}</span>
						<span role="columnheader">{{ __('Permissions') }}</span>
						<span role="columnheader">{{ __('Members') }}</span>
						<span role="columnheader">{{ __('Updated') }}</span>
						<span role="columnheader" class="role-mgmt-thead__actions">{{ __('Actions') }}</span>
					</div>

					@forelse($roles as $role)
						@php
							$isProtected = $role->name === $protectedRole;
							$memberCount = (int) $role->users_count;
							$permCount = (int) $role->permissions_count;
							$percent = (int) round($permCount / $totalPermissions * 100);
							$label = title($role->name);
							$description = $role->description ?: __('Operational access role');
						@endphp
						<div class="role-mgmt-row {{ $isProtected ? 'is-protected-row' : '' }}"
						     role="row"
						     data-role-row
						     data-search="{{ mb_strtolower($label.' '.$role->name.' '.$description) }}"
						     data-type="{{ $isProtected ? 'protected' : 'custom' }}">
							<div class="role-mgmt-role" role="cell">
								<span class="role-mgmt-role__icon {{ $isProtected ? 'is-protected' : 'is-custom' }}">
									<x-icon :name="$isProtected ? 'lock' : 'role'" height="20" width="20"/>
								</span>
								<span class="role-mgmt-role__content">
									<span class="role-mgmt-role__name">{{ $label }}</span>
									<span class="role-mgmt-role__desc">{{ $description }}</span>
								</span>
							</div>

							<span role="cell">
								<span class="role-mgmt-status {{ $isProtected ? 'is-protected' : 'is-custom' }}">
									<span class="role-mgmt-status__dot"></span>
									{{ $isProtected ? __('Protected') : __('Custom') }}
								</span>
							</span>

							<div class="role-mgmt-meter {{ $isProtected ? 'is-protected' : '' }}" role="cell">
								<span class="role-mgmt-meter__head" dir="ltr">
									<strong>{{ number_format($permCount) }}</strong>
									<small>{{ __('of :total', ['total' => number_format($summary['totalPermissions'])]) }}</small>
									<span class="role-mgmt-meter__pct">{{ $percent }}%</span>
								</span>
								<span class="role-mgmt-meter__bar">
									<span class="role-mgmt-meter__fill" style="width: {{ $percent }}%"></span>
								</span>
							</div>

							<span class="role-mgmt-staff-cell {{ $memberCount === 0 ? 'is-empty' : '' }}" role="cell">
								@if($memberCount > 0)
									<span class="role-mgmt-avatar-stack">
										@foreach($role->users->take(3) as $member)
											<span class="role-mgmt-avatar role-mgmt-avatar--{{ $avatarTone((int) $member->id) }}" title="{{ $member->name ?? __('Staff') }}">
												@if($member->avatar)
													<img src="{{ asset($member->avatar_alt) }}" alt="" loading="lazy">
												@else
													{{ $initials($member->name) }}
												@endif
											</span>
										@endforeach
									</span>
									<span class="role-mgmt-staff-meta">
										<strong>{{ number_format($memberCount) }}</strong>
										<small>{{ trans_choice('member|members', $memberCount) }}</small>
									</span>
								@else
									<span class="role-mgmt-empty-pill">
										<span class="role-mgmt-empty-pill__dot" aria-hidden="true"></span>
										{{ __('Unassigned') }}
									</span>
								@endif
							</span>

							<span class="role-mgmt-updated" role="cell">{{ $role->updated_at?->diffForHumans() ?: __('—') }}</span>

							<div class="role-mgmt-actions" role="cell">
								<a href="{{ route('admin.role.permissions', $role->id) }}" class="role-mgmt-action-btn role-mgmt-action-btn--manage">
									<x-icon name="role" height="14" width="14" class="icon-custom-size"/>
									<span class="role-mgmt-action-btn__label">{{ $isProtected ? __('View permissions') : __('Manage permissions') }}</span>
								</a>

								@if($isProtected)
									<span class="role-mgmt-action-btn role-mgmt-action-btn--locked" title="{{ __('System role — cannot be edited') }}">
										<x-icon name="lock" height="13" width="13" class="icon-custom-size"/>
										<span class="role-mgmt-action-btn__label">{{ __('Locked') }}</span>
									</span>
								@else
									{{--
										No separate Edit control. Renaming a role
										and choosing what it can do is one
										decision, and Manage permissions is where
										both now happen.
									--}}
									@if($canDelete)
										@if($memberCount > 0)
											<span class="role-mgmt-action-btn role-mgmt-action-btn--icon is-disabled"
											      title="{{ __('Reassign this role’s members before deleting it.') }}"
											      aria-disabled="true">
												<x-icon name="delete-2" height="15" width="15" class="icon-custom-size"/>
											</span>
										@else
											<button type="button"
											        class="role-mgmt-action-btn role-mgmt-action-btn--icon role-mgmt-action-btn--danger delete"
											        data-url="{{ route('admin.role.destroy', $role->id) }}"
											        title="{{ __('Delete role') }}"
											        aria-label="{{ __('Delete role') }}">
												<x-icon name="delete-2" height="15" width="15" class="icon-custom-size"/>
											</button>
										@endif
									@endif
								@endif
							</div>
						</div>
					@empty
						<x-admin-not-found
							:title="__('No roles found')"
							:message="__('Create a role to start assigning scoped staff permissions.')"
							icon="fa-user-shield"
						/>
					@endforelse

					<p class="role-mgmt-table__empty" data-role-empty hidden></p>
				</div>
			</div>

			<div class="role-mgmt-tfoot">
				<span>{{ __('Members without a role cannot open the admin. Deleting a role requires reassigning its members first.') }}</span>
				<span class="role-mgmt-tfoot__note">
					<x-icon name="lock" height="12" width="12" class="icon-custom-size"/>
					{{ __('Super Admin is protected') }}
				</span>
			</div>
		</form>
	</div>
@endsection
