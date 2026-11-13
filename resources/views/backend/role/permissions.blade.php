@extends('backend.layouts.app')

@section('title', __('Permissions for :role', ['role' => title($role->name)]))

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
		$roleJsVersion = config('app.version').'-'.filemtime(public_path('backend/js/role-permissions.js'));
	@endphp
	<script src="{{ asset('backend/js/role-permissions.js?v='.$roleJsVersion) }}"></script>
@endpush

@section('content')
	@php
		$grantedCount     = count($granted);
		$totalPermissions = max(1, (int) $summary['totalPermissions']);
		$moduleCount      = $categories->count();
		$modulesTouched   = $categories->filter(static fn (array $category): bool => $category['granted_count'] > 0)->count();
		$canEditRole      = (auth('admin')->user()?->can('role-edit') ?? false) && ! $isProtected;
	@endphp

	{{--
		One header, not two.

		The editor drew its own head below this one — name, save state,
		Discard, Save and the granted meter — which put the role's identity
		on screen twice. Everything that is page chrome lives here now: the
		state pill, the two controls and the meter, all still carrying the
		hooks role-permissions.js writes to as switches move. `data-role-screen`
		is what lets the script find them, because they sit outside the form.

		The controls reach the form through `form="role-editor"`, so Save
		still submits and Discard still resets without either being inside it.
	--}}
	<div data-role-screen>
		<x-page-header
			:title="title($role->name)"
			:eyebrow="__('Access').' · '.__('Role')"
			icon="role"
			tile="soft"
			:badge="'R-'.$role->id"
			:back="route('admin.role.index')"
			:back-label="__('Back to roles')"
			:status="$isProtected
				? ['tone' => 'warning', 'label' => __('Protected role')]
				: ['tone' => 'brand', 'label' => __('Custom role')]"
			{{--
				Short facts, the way board 2i has them. The description is not
				one: the meta row keeps its items on one line and clips the
				overflow, so a sentence truncates mid-word — and it is already
				an editable field in the form below.
			--}}
			:meta="[
				number_format((int) $role->users_count).' '.trans_choice('member|members', (int) $role->users_count),
				__('Updated :when', ['when' => $role->updated_at?->diffForHumans() ?? __('never')]),
			]"
			:breadcrumbs="[
				['label' => __('Platform')],
				['label' => __('Access')],
				['label' => __('Roles & permissions'), 'url' => route('admin.role.index')],
				['label' => title($role->name)],
			]"
			:progress="[
				'label' => __('Granted'),
				'value' => $grantedCount,
				'total' => $totalPermissions,
				'note'  => __(':count of :total modules touched', [
					'count' => number_format($modulesTouched),
					'total' => number_format($moduleCount),
				]),
				'attrs' => [
					'ratio' => ['data-role-progress-text' => 'true', 'dir' => 'ltr'],
					'fill'  => ['data-role-progress-fill' => 'true'],
					'note'  => ['data-role-modules-text' => 'true', 'dir' => 'ltr'],
				],
			]"
			:stats="[
				[
					'label' => __('Full access'),
					'value' => $categories->filter(fn (array $c): bool => $c['granted_count'] > 0 && $c['granted_count'] === $c['total'])->count(),
					'note'  => __('modules'),
					'tone'  => 'brand',
					'icon'  => 'check',
				],
				[
					'label' => __('Partial'),
					'value' => $categories->filter(fn (array $c): bool => $c['granted_count'] > 0 && $c['granted_count'] < $c['total'])->count(),
					'note'  => __('modules'),
					'icon'  => 'layer',
				],
			]"
		>
			{{--
				`.ph-state` draws its own dot with ::before, and its base is
				the neutral tone — which is what a protected role wants, since
				nothing about it is going to change. The script swaps between
				the success and warning modifiers as the form goes clean or
				dirty.
			--}}
			<x-slot:state>
				<span class="ph-state {{ $isProtected ? '' : 'ph-state--success' }}"
				      data-role-changes
				      aria-live="polite">
					<span data-role-change-text>
						@if($isProtected)
							{{ __('System role — permissions are fixed') }}
						@else
							{{ __('All changes saved') }}
						@endif
					</span>
				</span>
			</x-slot:state>

			<x-slot:actions>
				<button type="button" class="ph-btn ph-btn--secondary" form="role-editor" data-role-discard disabled>
					{{ __('Discard') }}
				</button>
				@if($canEditRole)
					<button type="submit" class="ph-btn ph-btn--primary" form="role-editor" data-role-save disabled>
						<x-icon name="check" height="14" width="14"/>
						{{ __('Save changes') }}
					</button>
				@endif
			</x-slot:actions>

			@if($canEditRole)
				<x-slot:menu>
					<button type="button" class="dropdown-item" data-coreui-toggle="modal" data-coreui-target="#rename_role_modal">
						<span class="ph-menu__tile" aria-hidden="true"><x-icon name="edit" width="15" height="15"/></span>
						<span class="ph-menu__copy">
							<span class="ph-menu__label">{{ __('Rename role') }}</span>
							<span class="ph-menu__hint">{{ __('Change its name and description') }}</span>
						</span>
					</button>
				</x-slot:menu>
			@endif
		</x-page-header>

		@include('backend.role.partials._editor', [
			'mode'        => 'edit',
			'action'      => route('admin.role.update', $role->id),
			'method'      => 'PUT',
			'submitLabel' => __('Save changes'),
		])
	</div>

	@if($canEditRole)
		{{--
			Outside the editor form on purpose: a form cannot nest inside
			another, and the browser drops the inner one without a word.

			It posts to its own endpoint rather than to update(), which syncs
			permissions from whatever it is handed — this dialog hands it none,
			so going through update() would strip every permission the role
			holds. The redirect comes back here with the header's title, the
			meta row and the form's hidden values all re-rendered, so nothing
			is left holding the old name.
		--}}
		<div class="modal fade" id="rename_role_modal" tabindex="-1" aria-labelledby="rename_role_title" aria-hidden="true">
			<div class="modal-dialog modal-dialog-centered">
				<div class="modal-content">
					<form method="POST" action="{{ route('admin.role.rename', $role->id) }}">
						@csrf
						@method('PUT')

						<div class="modal-header">
							<h5 class="modal-title" id="rename_role_title">{{ __('Rename role') }}</h5>
							<button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"></button>
						</div>

						<div class="modal-body">
							<div class="mb-3">
								<label class="form-label" for="rename_role_name">{{ __('Role name') }}</label>
								<input class="form-control @error('role_name') is-invalid @enderror"
								       type="text"
								       id="rename_role_name"
								       name="role_name"
								       value="{{ old('role_name', $role->name) }}"
								       placeholder="{{ __('e.g. finance-manager') }}"
								       maxlength="255"
								       autocomplete="off"
								       required>
								@error('role_name')
									<div class="invalid-feedback">{{ $message }}</div>
								@enderror
							</div>

							<div>
								<label class="form-label" for="rename_role_description">{{ __('Role description') }}</label>
								<input class="form-control @error('description') is-invalid @enderror"
								       type="text"
								       id="rename_role_description"
								       name="description"
								       value="{{ old('description', $role->description) }}"
								       placeholder="{{ __('Describe what this role is for') }}"
								       maxlength="255"
								       autocomplete="off"
								       required>
								@error('description')
									<div class="invalid-feedback">{{ $message }}</div>
								@enderror
							</div>
						</div>

						<div class="modal-footer">
							<button type="button" class="btn btn-outline-secondary" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
							<button type="submit" class="btn btn-primary">{{ __('Save changes') }}</button>
						</div>
					</form>
				</div>
			</div>
		</div>

		@if($errors->hasAny(['role_name', 'description']))
			@push('scripts')
				{{--
					A rejected rename redirects back, and the dialog it came
					from is shut — so the message would sit inside a hidden
					modal and the screen would look like nothing happened.
					Reopening it puts the error back where it was typed.
				--}}
				<script>
					"use strict";

					document.addEventListener("DOMContentLoaded", function () {
						var dialog = document.getElementById("rename_role_modal");

						if (dialog && window.coreui && window.coreui.Modal) {
							window.coreui.Modal.getOrCreateInstance(dialog).show();
						}
					});
				</script>
			@endpush
		@endif
	@endif
@endsection
