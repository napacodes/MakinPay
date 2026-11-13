@extends('backend.layouts.app')

@section('title', __('Team members'))

{{--
	Block @php only in this file. Blade matches an inline @php( with the block
	pattern, so one inline directive above a block's @endphp swallows every
	line between them and the view dies on the next @endforeach.
--}}
@push('styles')
	@php
		$staffCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/staff-management.css'));
	@endphp
	<link rel="stylesheet" href="{{ asset('backend/css/staff-management.css?v='.$staffCssVersion) }}">
@endpush

@push('scripts')
	@php
		$staffJsVersion = config('app.version').'-'.filemtime(public_path('backend/js/staff-management.js'));
	@endphp
	<script src="{{ asset('backend/js/staff-management.js?v='.$staffJsVersion) }}"></script>
@endpush

@section('content')
	@php
		$canEdit = auth('admin')->user()?->can('staff-edit') ?? false;
		$canCreate = auth('admin')->user()?->can('staff-create') ?? false;
		$selectedStatus = in_array(request('status'), ['active', 'inactive'], true) ? request('status') : null;
		$selectedRole = request('role');

		$queryWith = static fn (array $overrides): array => array_filter(
			array_merge(request()->except(['page']), $overrides),
			static fn ($value): bool => $value !== null && $value !== ''
		);

		/* A member always draws in the same colour; hashing the id keeps it stable across pages. */
		$avatarTone = static fn (int $id): string => 'g'.(1 + ($id % 6));

		$initials = static function (?string $name): string {
			$words = array_values(array_filter(preg_split('/\s+/', trim((string) $name)) ?: []));

			$letters = implode('', array_map(
				static fn (string $word): string => mb_substr($word, 0, 1),
				array_slice($words, 0, 2)
			));

			return $letters === '' ? '?' : mb_strtoupper($letters);
		};

		$selected = $staffs->first();
	@endphp

	<div class="team" data-team>
		{{--
			The shared header every other admin page uses, in place of a
			page-local one. The four figures were a `.team-kpis` row of the
			page's own; v2.2 puts them in the header's strip, which is the
			anatomy every other module already reads with.
		--}}
		<x-page-header
			:title="__('Team members')"
			:eyebrow="__('Access')"
			:subtitle="__('Internal admin accounts, their roles, security posture and activity.')"
			icon="users-1"
			:status="['label' => __(':count members', ['count' => number_format($summary['total'])])]"
			:stats="[
				[
					'label' => __('Members'),
					'value' => $summary['total'],
					'delta' => '+'.number_format($summary['joinedThisMonth']),
					'tone'  => 'success',
					'note'  => __('this month'),
					'icon'  => 'sidebar-identity',
				],
				[
					'label' => __('Active'),
					'value' => $summary['active'],
					'tone'  => 'success',
					'note'  => __(':count inactive', ['count' => number_format($summary['inactive'])]),
					'icon'  => 'kpi-users',
				],
				[
					'label' => __('Roles in use'),
					'value' => $summary['rolesInUse'],
					'tone'  => 'brand',
					'note'  => __('of :count', ['count' => number_format($summary['rolesTotal'])]),
					'icon'  => 'role',
				],
				[
					'label' => __('2FA enabled'),
					'value' => $summary['twoFactorPercent'].'%',
					/* A warning only when somebody is actually missing it. */
					'tone'  => $summary['twoFactorMissing'] > 0 ? 'warning' : 'success',
					'note'  => $summary['twoFactorMissing'] > 0
						? __(':count without', ['count' => number_format($summary['twoFactorMissing'])])
						: __('everyone covered'),
					'icon'  => 'shield',
				],
			]"
		>
			<x-slot:actions>
				<a href="{{ route('admin.activity-log') }}" class="btn btn-outline-secondary">
					<i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
					{{ __('Activity log') }}
				</a>
				@if($canCreate)
					<button type="button" class="btn btn-primary" data-coreui-toggle="modal" data-coreui-target="#new_staff_modal">
						<i class="fa-solid fa-plus" aria-hidden="true"></i>
						{{ __('Invite member') }}
					</button>
				@endif
			</x-slot:actions>
		</x-page-header>

		<div class="team-panes">
			<div class="team-table-card">
				<form class="team-toolbar" method="GET" action="{{ route('admin.staff.index') }}">
					@foreach($queryWith(['search' => null]) as $key => $value)
						<input type="hidden" name="{{ $key }}" value="{{ $value }}">
					@endforeach

					<label class="team-search">
						<span class="team-search__icon" aria-hidden="true"><x-icon name="search" height="14" width="14"/></span>
						<span class="visually-hidden">{{ __('Search by name or email') }}</span>
						<input type="search" name="search" class="team-search__input" value="{{ request('search') }}" placeholder="{{ __('Search by name or email') }}">
					</label>

					<div class="team-chips">
						<a class="team-chip {{ $selectedRole ? '' : 'is-active' }}" href="{{ route('admin.staff.index', $queryWith(['role' => null])) }}">
							{{ __('All roles') }}<span class="team-chip__count">{{ number_format($summary['total']) }}</span>
						</a>
						@foreach($roleCounts as $roleName => $roleCount)
							<a class="team-chip {{ $selectedRole === $roleName ? 'is-active' : '' }}"
							   href="{{ route('admin.staff.index', $queryWith(['role' => $roleName])) }}">
								{{ title($roleName) }}<span class="team-chip__count">{{ number_format($roleCount) }}</span>
							</a>
						@endforeach
					</div>

					<div class="team-segments" role="tablist" aria-label="{{ __('Status') }}">
						<a class="team-seg {{ $selectedStatus === null ? 'is-active' : '' }}" role="tab" aria-selected="{{ $selectedStatus === null ? 'true' : 'false' }}" href="{{ route('admin.staff.index', $queryWith(['status' => null])) }}">{{ __('All') }}</a>
						<a class="team-seg {{ $selectedStatus === 'active' ? 'is-active' : '' }}" role="tab" aria-selected="{{ $selectedStatus === 'active' ? 'true' : 'false' }}" href="{{ route('admin.staff.index', $queryWith(['status' => 'active'])) }}">{{ __('Active') }}</a>
						<a class="team-seg {{ $selectedStatus === 'inactive' ? 'is-active' : '' }}" role="tab" aria-selected="{{ $selectedStatus === 'inactive' ? 'true' : 'false' }}" href="{{ route('admin.staff.index', $queryWith(['status' => 'inactive'])) }}">{{ __('Inactive') }}</a>
					</div>
				</form>

				@if($staffs->isEmpty())
					<div class="team-empty">
						<x-admin-not-found
							:title="__('No team members found')"
							:message="__('Try another role, status or search term.')"
							icon="fa-user-shield"
						/>
					</div>
				@else
					<div class="team-table__scroll">
						<div class="team-table__inner">
							<div class="team-table__head">
								<span>{{ __('Member') }}</span>
								<span>{{ __('Role') }}</span>
								<span>{{ __('Status') }}</span>
								<span>{{ __('2FA') }}</span>
								<span>{{ __('Last active') }}</span>
								<span>{{ __('Joined') }}</span>
								<span class="text-end">{{ __('Actions') }}</span>
							</div>

							@foreach($staffs as $staff)
								{{--
									No row starts selected. The drawer is a response to
									picking somebody, so the page arrives with the table
									at full width and nothing claiming to be open.
								--}}
								@include('backend.staff.partials._member_row', [
									'staff' => $staff,
									'isSelected' => false,
									'canEdit' => $canEdit,
									'avatarTone' => $avatarTone,
									'initials' => $initials,
								])
							@endforeach
						</div>
					</div>
				@endif

				<div class="team-table__foot">
					<span>
						{!! __('Showing :shown of :total members', [
							'shown' => '<strong>'.number_format($staffs->count()).'</strong>',
							'total' => '<strong>'.number_format($staffs->total()).'</strong>',
						]) !!}
					</span>
					@if($staffs->hasPages())
						{{ $staffs->links() }}
					@else
						<span class="team-online">
							<span class="team-online__dot" aria-hidden="true"></span>
							{{ __(':count online now', ['count' => number_format($summary['onlineNow'])]) }}
						</span>
					@endif
				</div>
			</div>

		</div>
	</div>

	{{-- Create New Staff Modal --}}
	@can('staff-create')
		@include('backend.staff.partials._new_staff')
	@endcan

	{{--
		Manage is the only way to edit a member, and it is a modal.

		It sits outside the table so the backdrop covers the page rather than
		one column, and it is seeded from the first member — every control has
		a shape and a URL before anything is picked, and the script fills in
		whoever the admin actually clicks.
	--}}
	@if($selected && $canEdit)
		@include('backend.staff.partials._manage_modal', [
			'staff' => $selected,
			'allRoles' => $allRoles,
			'avatarTone' => $avatarTone,
			'initials' => $initials,
		])
	@endif
@endsection
