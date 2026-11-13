@php
	/**
	 * One setting, as a labelled row.
	 *
	 * The left column is everything an administrator reads before deciding —
	 * the label, what the setting does, what it costs when it is wrong, and
	 * whether it still holds the value it shipped with. The right column is
	 * the control. The old tooltip is gone: a hint nobody hovers is a hint
	 * nobody has.
	 *
	 * `$field` is the element straight out of config/settings.php, so
	 * `$field['value']` is both the seed and the default — there is no second
	 * key to keep in step with it.
	 *
	 * @var array<string, mixed> $field
	 * @var string $sectionKey
	 */
	$key     = (string) $field['key'];
	$type    = (string) $field['type'];
	$default = $field['value'] ?? null;
	$current = setting($key, $default);

	$isSwitch = $type === 'switch';
	$isWide   = in_array($type, ['textarea', 'img'], true);
	$isRisky  = ($field['risk'] ?? false) === true;
	$hasHint  = filled($field['message'] ?? null);

	/*
	 * A default is only worth showing when it is a value a person can read
	 * and put back. Selects seeded from the database carry an empty array,
	 * images carry an empty string, and a secret never leaves the server —
	 * none of those can be compared or restored here.
	 */
	$comparable = ! is_array($default)
		&& $type !== 'password'
		&& $type !== 'img'
		&& filled($default);

	$defaultLabel = ! $comparable
		? ''
		: ($isSwitch
			? (filter_var($default, FILTER_VALIDATE_BOOLEAN) ? __('On') : __('Off'))
			: (string) $default);

	/*
	 * What the file picker will accept, read off the field's own rule so the
	 * sentence cannot drift from what the validator actually enforces. It
	 * belongs here, beside the label, and not on the control: it is something
	 * you read before choosing a file, not after.
	 */
	$imageConstraint = '';

	if ($type === 'img') {
		$imageRules = (string) ($field['rules'] ?? '');

		$formats = preg_match('/mimes:([a-z0-9,]+)/i', $imageRules, $accepted)
			? collect(explode(',', $accepted[1]))
				->map(fn (string $extension): string => strtoupper(trim($extension) === 'jpeg' ? 'jpg' : trim($extension)))
				->unique()
				->implode(', ')
			: '';

		$maxKilobytes = preg_match('/\bmax:(\d+)/', $imageRules, $ceiling) ? (int) $ceiling[1] : 0;

		$imageConstraint = implode(' · ', array_filter([
			$formats,
			$maxKilobytes > 0
				? __('max :size', ['size' => $maxKilobytes >= 1024 ? round($maxKilobytes / 1024, 1).' MB' : $maxKilobytes.' KB'])
				: '',
		]));
	}

	$isModified = $comparable && ($isSwitch
		? filter_var($current, FILTER_VALIDATE_BOOLEAN) !== filter_var($default, FILTER_VALIDATE_BOOLEAN)
		: (string) $current !== (string) $default);
@endphp

<div class="ss-row {{ $isSwitch ? 'ss-row--switch' : '' }} {{ $isWide ? 'ss-row--stacked' : '' }}"
     id="field-{{ $key }}"
     data-ss-field="{{ $key }}"
     data-ss-section="{{ $sectionKey }}"
     @if($comparable) data-ss-default="{{ $isSwitch ? (filter_var($default, FILTER_VALIDATE_BOOLEAN) ? '1' : '0') : $default }}" @endif>
	<div class="ss-row__copy">
		{{--
			Which element the label actually points at. A switch draws its own
			checkbox under `_switch`, and `x-img` gives the file input `_upload`
			while keeping the bare key for the preview div — a label aimed at
			that div reaches nothing, so clicking "Logo" did nothing at all.
		--}}
		<label class="ss-row__label" for="{{ $isSwitch ? $key.'_switch' : ($type === 'img' ? $key.'_upload' : $key) }}">
			{{ title($field['label']) }}

			@if($isModified)
				<span class="ss-badge ss-badge--modified" data-ss-modified>{{ __('Modified') }}</span>
			@endif

			@if($isRisky)
				<span class="ss-badge ss-badge--risk">
					<x-icon name="warning" height="9" width="9"/>
					{{ $isSwitch ? __('Confirmation required') : __('Sensitive') }}
				</span>
			@endif
		</label>

		@if($hasHint)
			{{--
				Shown whole. These were clamped to two lines behind a More
				button for a while, which answered the wrong question: the
				descriptions were too long, so they were shortened instead.
				`SiteSettingsDescriptionsTest` holds them to 26 words, which
				is two lines at this column width.
			--}}
			<p class="ss-row__hint">{{ __($field['message']) }}</p>
		@endif

		@if($imageConstraint !== '')
			<p class="ss-row__constraint">{{ $imageConstraint }}</p>
		@endif

		@isset($field['risk_note'])
			<p class="ss-row__risk">
				<x-icon name="warning" height="13" width="13"/>
				<span>{{ __($field['risk_note']) }}</span>
			</p>
		@endisset

		@if($comparable)
			<p class="ss-row__default" data-ss-default-line @if(! $isModified) hidden @endif>
				{{ __('Default') }}
				<code dir="ltr">{{ $defaultLabel }}</code>
				<button type="button" class="ss-row__reset" data-ss-reset>{{ __('Reset') }}</button>
			</p>
		@endif
	</div>

	<div class="ss-row__control">
		@include('backend.settings.site.partials.fields.' . $type, ['field' => $field])
	</div>
</div>
