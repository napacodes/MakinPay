{{--
    The report somebody works a fix from.

    Built at export time, not stored, so tightening a redaction rule applies
    retroactively to every future export.

    Strict mode refuses rather than guesses: if the pipeline could not classify
    a field, the export is blocked and says so, because handing somebody a
    report nobody can vouch for is worse than handing over nothing.

    The preview is the first screen of the report rather than all of it. It is
    here to show what the buttons will hand over; reading it end to end is what
    the download is for, and printing every line inline put tens of thousands
    of characters into a page that is opened when something is already wrong.
--}}

@if($exportBlocked)
    <div class="alert alert-warning" role="alert">
        {{ __('Export blocked. This error contains a field your redaction rules cannot classify. Review it, then export again.') }}
    </div>
@endif

<div class="row g-3">
    <div class="col-12 col-lg-8">
        <x-observability.code-editor
            :pane="$reportPreview"
            :file="$group->group_id.'.md'"
            :badge="__(':count lines', ['count' => number_format($reportLines)])"
            :note="__('The copy and the download carry the whole report.')"
            class="mb-3"
        />

        @can('observability-export')
            <div class="d-flex flex-wrap gap-2">
                <button type="button"
                        class="btn btn-primary"
                        data-obs-copy-report
                        data-obs-report-url="{{ route('admin.observability.issues.export', ['group' => $group, 'format' => 'md']) }}"
                        @disabled($exportBlocked)>
                    <i class="fa-solid fa-file-code me-1" aria-hidden="true"></i>{{ __('Copy debug report') }}
                </button>
                <a class="btn btn-outline-primary @if($exportBlocked) disabled @endif"
                   href="{{ route('admin.observability.issues.export', ['group' => $group, 'format' => 'md', 'download' => 1]) }}">
                    {{ __('Download') }} (.md)
                </a>
                <a class="btn btn-outline-primary @if($exportBlocked) disabled @endif"
                   href="{{ route('admin.observability.issues.export', ['group' => $group, 'format' => 'json', 'download' => 1]) }}">
                    {{ __('Download') }} (.json)
                </a>
            </div>
        @endcan
    </div>

    <div class="col-12 col-lg-4">
        <span class="obs-pci mb-3">
            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
            {{ __('No card data leaves this server') }}
        </span>

        <h2 class="h6 mb-2 mt-3">{{ __('What was redacted') }}</h2>
        <p class="text-body-secondary small">
            {{ __('This list travels inside the export, so whoever reads it knows what is missing and does not assume a value.') }}
        </p>

        @forelse($redactionFields as $field)
            <div class="obs-redaction__item">
                <span>{{ $field['path'] }}</span>
                <span class="obs-verdict obs-verdict--{{ $field['verdict'] }}">{{ mb_strtoupper($field['verdict']) }}</span>
            </div>
        @empty
            <p class="text-body-secondary small mb-0">{{ __('Nothing in this error needed redacting.') }}</p>
        @endforelse

        <p class="text-body-secondary small mt-3 mb-0">
            {{ __('Selecting several errors on the Issues list exports one combined briefing instead. Above fifty it is queued and arrives as a download link.') }}
        </p>
    </div>
</div>
