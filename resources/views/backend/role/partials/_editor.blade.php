{{--
	One screen for a role: what it is called and what it can do.

	Editing a role and editing its permissions were two pages, and the split
	was arbitrary — a role's name and its capabilities are the same decision.
	Creating a role and editing one are now the same form, so there is nothing
	to keep in step between them.

	Every module is in the markup; the page shows one at a time. The switches
	are the form's own `permission[]` checkboxes, so the form saves correctly
	before role-permissions.js has run at all — the script only keeps the
	tallies honest. Permission keys (`app-clear-cache` and the like) are not
	shown: an admin grants capabilities by their name, not by their slug.

	Block @php only in this file. Blade matches an inline @php( with the block
	pattern, so one inline directive above a block's @endphp swallows every
	line between them and the view dies on the next @endforeach.
--}}
@php
	$isCreate = $mode === 'create';
	$canEdit = ($isCreate
		? (auth('admin')->user()?->can('role-create') ?? false)
		: (auth('admin')->user()?->can('role-edit') ?? false)) && ! $isProtected;
	$grantedMap = array_flip($granted);
	$grantedCount = count($granted);
	$totalPermissions = max(1, (int) $summary['totalPermissions']);
	$grantedPercent = (int) round($grantedCount / $totalPermissions * 100);
	$roleLabel = $role ? title($role->name) : __('New role');
	$moduleCount = $categories->count();
	$modulesTouched = $categories->filter(static fn (array $category): bool => $category['granted_count'] > 0)->count();
	$firstSection = $sections->first();
	$activeKey = $firstSection ? ($firstSection['categories']->first()['key'] ?? null) : null;
@endphp

<form class="role-mgmt-page role-mgmt-page--permissions"
      id="role-editor"
      method="POST"
      action="{{ $action }}"
      data-role-permissions
      data-mode="{{ $mode }}"
      data-total-permissions="{{ $totalPermissions }}"
      data-total-modules="{{ $moduleCount }}"
      data-can-edit="{{ $canEdit ? '1' : '0' }}"
      data-protected="{{ $isProtected ? '1' : '0' }}"
      data-read-only="{{ json_encode(array_values($readOnly)) }}"
      {{-- Wording is translated here, not assembled in JavaScript. --}}
      data-label-clean="{{ __('All changes saved') }}"
      data-label-dirty="{{ __('Unsaved changes') }}"
      data-label-locked="{{ __('System role — permissions are fixed') }}"
      data-label-new="{{ __('Nothing granted yet') }}"
      data-label-granted="{{ __(':count of :total permissions granted', ['count' => ':count', 'total' => ':total']) }}"
      {{-- fillCount() escapes the template and wraps :count in <strong> itself. --}}
      data-label-ratio="{{ __(':count / :total', ['count' => ':count', 'total' => ':total']) }}"
      data-label-granted-short="{{ __(':count of :total granted', ['count' => ':count', 'total' => ':total']) }}"
      data-label-modules="{{ __(':count of :total modules touched', ['count' => ':count', 'total' => ':total']) }}"
      data-label-enabled="{{ __(':count of :total enabled', ['count' => ':count', 'total' => ':total']) }}"
      data-label-leave="{{ __('You have unsaved permission changes. Leave without saving?') }}">
	@csrf
	@if($method === 'PUT')
		@method('PUT')
	@endif

	@if($isCreate)
		{{--
			A new role has to be named somewhere, and this is the only screen
			that offers it — so the pair stays here, and only here.
		--}}
		<section class="role-mgmt-fields">
			<label class="role-mgmt-field role-mgmt-field--name">
				<span class="role-mgmt-field__label">{{ __('Role name') }}</span>
				<input type="text"
				       name="role_name"
				       value="{{ old('role_name', $role?->name) }}"
				       placeholder="{{ __('e.g. finance-manager') }}"
				       maxlength="255"
				       required
				       autocomplete="off"
				       data-role-name
				       @readonly($isProtected)>
				@error('role_name')
					<small class="role-mgmt-field__error">{{ $message }}</small>
				@enderror
			</label>

			<label class="role-mgmt-field role-mgmt-field--desc">
				<span class="role-mgmt-field__label">{{ __('Role description') }}</span>
				<input type="text"
				       name="description"
				       value="{{ old('description', $role?->description) }}"
				       placeholder="{{ __('Describe what this role is for') }}"
				       maxlength="255"
				       required
				       autocomplete="off"
				       @readonly($isProtected)>
				@error('description')
					<small class="role-mgmt-field__error">{{ $message }}</small>
				@enderror
			</label>

			@error('permission')
				<p class="role-mgmt-fields__error">{{ $message }}</p>
			@enderror
		</section>

	@else
		{{--
			The manage screen does not show these.

			<x-page-header> above states the role — its title, its ID chip, its
			type and its description — so a second block naming it underneath was
			the same identity twice, which is what the header replaced everywhere
			else. Creating a role still needs them: there is nowhere else to type
			a name, so the pair stays on that screen.

			UpdateRoleRequest requires both and update() writes both back, so the
			current values ride along unchanged rather than being dropped — take
			these out and saving permissions would fail validation, or blank the
			role's name.
		--}}
		<input type="hidden" name="role_name" value="{{ old('role_name', $role?->name) }}" data-role-name>
		<input type="hidden" name="description" value="{{ old('description', $role?->description) }}">

		@error('role_name')
			<p class="role-mgmt-fields__error">{{ $message }}</p>
		@enderror
		@error('description')
			<p class="role-mgmt-fields__error">{{ $message }}</p>
		@enderror
		@error('permission')
			<p class="role-mgmt-fields__error">{{ $message }}</p>
		@enderror
	@endif

	<div class="role-mgmt-editor-body">
		<aside class="role-mgmt-groups" aria-label="{{ __('Permission modules') }}">
			<div class="role-mgmt-groups__head">
				<label class="role-mgmt-search">
					<span class="role-mgmt-search__icon" aria-hidden="true">
						<x-icon name="search" height="15" width="15" class="icon-custom-size"/>
					</span>
					<span class="visually-hidden">{{ __('Search modules') }}</span>
					<input type="search" placeholder="{{ __('Search modules') }}" autocomplete="off" data-role-group-search>
				</label>

				<div class="role-mgmt-seg role-mgmt-seg--sm" role="group" aria-label="{{ __('Filter modules by state') }}">
					<button type="button" class="role-mgmt-seg__btn is-active" data-role-group-filter="all" aria-pressed="true">{{ __('All') }}</button>
					<button type="button" class="role-mgmt-seg__btn" data-role-group-filter="granted" aria-pressed="false">{{ __('Granted') }}</button>
					<button type="button" class="role-mgmt-seg__btn" data-role-group-filter="none" aria-pressed="false">{{ __('None') }}</button>
				</div>
			</div>

			<div class="role-mgmt-groups__list">
				@foreach($sections as $section)
					<div class="role-mgmt-section" data-role-section="{{ $section['key'] }}">
						<div class="role-mgmt-section__head">
							<span class="role-mgmt-section__label">{{ $section['label'] }}</span>
							<span class="role-mgmt-section__tally" data-role-section-tally="{{ $section['key'] }}" dir="ltr">{{ $section['categories']->sum('granted_count') }}/{{ $section['categories']->sum('total') }}</span>
						</div>

						@foreach($section['categories'] as $category)
							@php
								$fill = $category['granted_count'] === 0 ? 'none' : ($category['granted_count'] === $category['total'] ? 'full' : 'partial');
							@endphp
							<button type="button"
							        class="role-mgmt-group {{ $category['key'] === $activeKey ? 'is-active' : '' }}"
							        data-role-group="{{ $category['key'] }}"
							        data-search="{{ mb_strtolower($category['label'].' '.$category['key']) }}"
							        data-granted="{{ $category['granted_count'] }}"
							        data-total="{{ $category['total'] }}"
							        aria-pressed="{{ $category['key'] === $activeKey ? 'true' : 'false' }}">
								<span class="role-mgmt-group__marker" aria-hidden="true"></span>
								<span class="role-mgmt-group__icon" aria-hidden="true">
									<x-icon :name="$category['icon']" height="16" width="16" class="icon-custom-size"/>
								</span>
								<span class="role-mgmt-group__label">{{ $category['label'] }}</span>
								<span class="role-mgmt-group__tally" data-role-group-tally="{{ $category['key'] }}" data-fill="{{ $fill }}" dir="ltr">
									<span class="role-mgmt-group__dot" aria-hidden="true"></span>
									<span class="role-mgmt-group__count">{{ $category['granted_count'] }}/{{ $category['total'] }}</span>
								</span>
							</button>
						@endforeach
					</div>
				@endforeach

				<p class="role-mgmt-groups__empty" data-role-groups-empty hidden>{{ __('No modules match.') }}</p>
			</div>

			<div class="role-mgmt-groups__foot">
				<span data-role-groups-granted dir="ltr">
					{!! __(':count of :total granted', [
						'count' => '<strong>'.number_format($grantedCount).'</strong>',
						'total' => number_format($summary['totalPermissions']),
					]) !!}
				</span>
				<span class="role-mgmt-legend" aria-hidden="true">
					<span class="role-mgmt-legend__item"><span class="role-mgmt-legend__dot is-full"></span>{{ __('Full') }}</span>
					<span class="role-mgmt-legend__item"><span class="role-mgmt-legend__dot is-partial"></span>{{ __('Partial') }}</span>
				</span>
			</div>
		</aside>

		<div class="role-mgmt-toggles">
			@foreach($categories as $category)
				<section class="role-mgmt-pane" data-role-pane="{{ $category['key'] }}" {{ $category['key'] === $activeKey ? '' : 'hidden' }}>
					<div class="role-mgmt-pane__head">
						<div class="role-mgmt-pane__title">
							<span class="role-mgmt-pane__icon" aria-hidden="true">
								<x-icon :name="$category['icon']" height="20" width="20"/>
							</span>
							<div>
								<h2>{{ $category['label'] }}</h2>
								<p>{{ $category['description'] ?: $category['summary'] }}</p>
							</div>
						</div>

						<div class="role-mgmt-quickset">
							<span class="role-mgmt-quickset__caption">{{ __('Quick set') }}</span>
							<div class="role-mgmt-seg role-mgmt-seg--quick" role="group" aria-label="{{ __('Quick set') }}">
								<button type="button" class="role-mgmt-seg__btn" data-role-preset="view" data-role-preset-group="{{ $category['key'] }}" aria-pressed="false" @disabled(! $canEdit)>{{ __('View only') }}</button>
								<button type="button" class="role-mgmt-seg__btn" data-role-preset="all" data-role-preset-group="{{ $category['key'] }}" aria-pressed="false" @disabled(! $canEdit)>{{ __('Full access') }}</button>
								<button type="button" class="role-mgmt-seg__btn" data-role-preset="none" data-role-preset-group="{{ $category['key'] }}" aria-pressed="false" @disabled(! $canEdit)>{{ __('None') }}</button>
							</div>
						</div>
					</div>

					<div class="role-mgmt-grant-all">
						<button type="button"
						        class="role-mgmt-switch"
						        role="switch"
						        aria-checked="{{ $category['granted_count'] === $category['total'] && $category['total'] > 0 ? 'true' : 'false' }}"
						        aria-label="{{ __('Grant all in :module', ['module' => $category['label']]) }}"
						        data-role-grant-all="{{ $category['key'] }}"
						        data-fill="{{ $category['granted_count'] === 0 ? 'none' : ($category['granted_count'] === $category['total'] ? 'full' : 'partial') }}"
						        @disabled(! $canEdit)>
							<span class="role-mgmt-switch__knob" aria-hidden="true"></span>
						</button>
						<span class="role-mgmt-grant-all__label">{{ __('Grant all in :module', ['module' => $category['label']]) }}</span>
						<span class="role-mgmt-grant-all__count {{ $category['granted_count'] > 0 ? 'is-on' : '' }}" data-role-group-enabled="{{ $category['key'] }}" dir="ltr">
							{!! __(':count of :total enabled', [
								'count' => '<strong>'.$category['granted_count'].'</strong>',
								'total' => $category['total'],
							]) !!}
						</span>
					</div>

					<div class="role-mgmt-pane__rows">
						@forelse($category['permissions'] as $permission)
							<label class="role-mgmt-perm {{ isset($grantedMap[$permission->name]) ? 'is-on' : '' }}">
								<input type="checkbox"
								       class="role-mgmt-perm__input"
								       role="switch"
								       name="permission[]"
								       value="{{ $permission->name }}"
								       data-role-perm="{{ $permission->name }}"
								       data-role-perm-group="{{ $category['key'] }}"
								       @checked(isset($grantedMap[$permission->name]))
								       @disabled(! $canEdit)>
								<span class="role-mgmt-switch" aria-hidden="true">
									<span class="role-mgmt-switch__knob"></span>
								</span>
								<span class="role-mgmt-perm__copy">
									<span class="role-mgmt-perm__title">{{ $permission->display_name ?: title($permission->name) }}</span>
									@if($permission->description)
										<span class="role-mgmt-perm__hint">{{ $permission->description }}</span>
									@endif
								</span>
							</label>
						@empty
							<p class="role-mgmt-pane__empty">{{ __('No permissions in this module.') }}</p>
						@endforelse
					</div>

					<div class="role-mgmt-pane__foot">
						<span>
							@if($isCreate)
								{{ __('Members can be assigned to this role once it exists.') }}
							@else
								{!! __('Changes apply to every member of :role after saving.', ['role' => '<strong data-role-foot-name>'.e($roleLabel).'</strong>']) !!}
							@endif
						</span>
						<span class="role-mgmt-pane__nav">
							<button type="button" class="role-mgmt-pane__step" data-role-step="prev">{{ __('Previous module') }}</button>
							<button type="button" class="role-mgmt-pane__step" data-role-step="next">{{ __('Next module') }}</button>
						</span>
					</div>
				</section>
			@endforeach
		</div>
	</div>
</form>
