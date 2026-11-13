@php
	/**
	 * One image setting.
	 *
	 * Written out here rather than through `<x-img>`: that component is shared
	 * with a dozen other admin screens, and its remove button posts a
	 * `coevs-remove` sentinel only the Page controller understands — a string
	 * `logo`'s own `mimes:` rule would reject outright. So the markup is local,
	 * but the two names the saving contract depends on are not:
	 * `name="{key}"` and `id="{key}_upload"` are exactly what the component
	 * produced, which is what `_row.blade.php` aims its label at.
	 *
	 * Restoring the packaged default travels as `reset_{key}`, read straight
	 * off the request in `SettingService` rather than through the validator,
	 * for the same reason: an image rule has no way to describe "not a file".
	 *
	 * @var array<string, mixed> $field
	 */
	$key     = (string) $field['key'];
	$default = trim((string) ($field['value'] ?? ''));
	$stored  = trim((string) (setting($key, $default) ?? ''));

	/*
	 * The shared component already knows how a stored path becomes a URL —
	 * public, storage/, or an absolute URL someone pasted in. Borrowing its
	 * resolver keeps one answer to that question instead of two that drift.
	 */
	$sourceUrl  = (new App\View\Components\Img(name: $key, old: $stored !== '' ? $stored : null))->old;
	$defaultUrl = (new App\View\Components\Img(name: $key, old: $default !== '' ? $default : null))->old;

	/*
	 * Restoring is only offered when there is something to restore. Two
	 * fields ship with no packaged file at all (`maintenance_cover`), and a
	 * Reset button there would post a flag the service has nothing to answer
	 * with — a control that looks like it works and does nothing.
	 */
	$isRestorable = $default !== '' && $stored !== '' && $stored !== $default;

	/**
	 * What the file on disk actually is, so the row can say "SVG · 4 KB"
	 * instead of leaving an administrator to guess from a thumbnail.
	 *
	 * @return array{name: string, facts: string}
	 */
	$describe = function (string $path) {
		$name = basename((string) (parse_url($path, PHP_URL_PATH) ?: $path));
		$name = urldecode($name);

		$bytes = 0;

		if ($path !== '' && ! filter_var($path, FILTER_VALIDATE_URL)) {
			foreach ([public_path(ltrim($path, '/')), storage_path('app/public/'.ltrim($path, '/'))] as $candidate) {
				if (is_file($candidate)) {
					$bytes = (int) filesize($candidate);
					break;
				}
			}
		}

		$facts = array_filter([
			strtoupper(pathinfo($name, PATHINFO_EXTENSION)),
			$bytes >= 1048576
				? round($bytes / 1048576, 1).' MB'
				: ($bytes > 0 ? max(1, (int) round($bytes / 1024)).' KB' : ''),
		]);

		return ['name' => $name, 'facts' => implode(' · ', $facts)];
	};

	$current = $describe($stored);
	$seeded  = $describe($default);

	/* Only the formats the field's own rule accepts reach the file picker. */
	$accept = preg_match('/mimes:([a-z0-9,]+)/i', (string) ($field['rules'] ?? ''), $extensions)
		? collect(explode(',', $extensions[1]))->map(fn (string $extension): string => '.'.trim($extension))->implode(',')
		: 'image/*';
@endphp

<div class="ss-image"
     data-ss-image
     data-ss-image-source="{{ $sourceUrl }}"
     data-ss-image-name="{{ $current['name'] }}"
     data-ss-image-facts="{{ $current['facts'] }}"
     data-ss-image-default-source="{{ $defaultUrl }}"
     data-ss-image-default-name="{{ $seeded['name'] }}"
     data-ss-image-default-facts="{{ $seeded['facts'] }}"
     data-ss-image-restorable="{{ $isRestorable ? '1' : '0' }}"
     data-ss-image-label-reset="{{ __('Reset') }}"
     data-ss-image-label-undo="{{ __('Undo') }}"
     data-ss-image-note-reset="{{ __('Restored when you save') }}"
     data-ss-image-note-replace="{{ __('Uploaded when you save') }}">
	<span class="ss-image__frame">
		<span class="ss-image__thumb" data-ss-image-thumb style="background-image: url('{{ $sourceUrl }}')"></span>
	</span>

	<span class="ss-image__meta">
		{{-- Uploaded names run long and get an ellipsis; the title is the only way back to the whole one. --}}
		<span class="ss-image__name" data-ss-image-title dir="ltr" title="{{ $current['name'] }}">{{ $current['name'] }}</span>
		<span class="ss-image__facts" data-ss-image-detail dir="ltr">{{ $current['facts'] }}</span>
		<span class="ss-image__pending" data-ss-image-pending hidden></span>
	</span>

	<span class="ss-image__actions">
		{{-- The label is the button; the input itself is never seen. --}}
		<input type="file"
		       class="ss-image__input"
		       name="{{ $key }}"
		       id="{{ $key }}_upload"
		       accept="{{ $accept }}"
		       data-ss-image-input>
		<label class="ss-image__btn ss-image__btn--primary" for="{{ $key }}_upload">
			<x-icon name="upload" height="14" width="14"/>
			<span>{{ __('Replace') }}</span>
		</label>

		<input type="hidden" name="reset_{{ $key }}" value="0" data-ss-image-flag>
		<button type="button"
		        class="ss-image__btn ss-image__btn--ghost"
		        data-ss-image-secondary
		        @unless($isRestorable) hidden @endunless>
			<x-icon name="undo" height="14" width="14"/>
			<span data-ss-image-secondary-label>{{ __('Reset') }}</span>
		</button>
	</span>
</div>

@include('backend.settings.site.partials.fields._error')
