@extends('backend.layouts.app')

@section('title', __('New role'))

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
	{{--
		The same header the manage screen carries, minus what a role that does
		not exist yet cannot have: no ID chip, no type pill, no member count,
		and a meter that starts at nothing. The name and description are
		fields in the form below.
	--}}
	<div data-role-screen>
		<x-page-header
			:title="__('New role')"
			:eyebrow="__('Access').' · '.__('Role')"
			:subtitle="__('Name the role, say what it is for, then grant the permissions it needs.')"
			icon="role"
			tile="soft"
			:back="route('admin.role.index')"
			:back-label="__('Back to roles')"
			:breadcrumbs="[
				['label' => __('Platform')],
				['label' => __('Access')],
				['label' => __('Roles & permissions'), 'url' => route('admin.role.index')],
				['label' => __('New role')],
			]"
			:progress="[
				'label' => __('Granted'),
				'value' => 0,
				'total' => max(1, (int) $summary['totalPermissions']),
				'note'  => __(':count of :total modules touched', [
					'count' => 0,
					'total' => number_format($categories->count()),
				]),
				'attrs' => [
					'ratio' => ['data-role-progress-text' => 'true', 'dir' => 'ltr'],
					'fill'  => ['data-role-progress-fill' => 'true'],
					'note'  => ['data-role-modules-text' => 'true', 'dir' => 'ltr'],
				],
			]"
		>
			<x-slot:state>
				<span class="ph-state" data-role-changes aria-live="polite">
					<span data-role-change-text>{{ __('Nothing granted yet') }}</span>
				</span>
			</x-slot:state>

			<x-slot:actions>
				<button type="button" class="ph-btn ph-btn--secondary" form="role-editor" data-role-discard disabled>
					{{ __('Discard') }}
				</button>
				@can('role-create')
					<button type="submit" class="ph-btn ph-btn--primary" form="role-editor" data-role-save>
						<x-icon name="check" height="14" width="14"/>
						{{ __('Create role') }}
					</button>
				@endcan
			</x-slot:actions>
		</x-page-header>

		@include('backend.role.partials._editor', [
			'mode'        => 'create',
			'action'      => route('admin.role.store'),
			'method'      => 'POST',
			'submitLabel' => __('Create role'),
		])
	</div>
@endsection
