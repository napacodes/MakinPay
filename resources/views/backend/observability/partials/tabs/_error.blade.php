@php
    /*
     * The default tab, and for most visits the only one.
     *
     * The order is the order the question gets answered in: the line that
     * threw, the path that reached it, then the facts a fix has to hold to.
     * Everything below the excerpt is short by construction — the controller
     * cuts the frame lists before they arrive — so this screen stays one
     * layout pass however busy the error is.
     *
     * The excerpt came out of the snapshot taken at throw time, never off the
     * filesystem. Re-reading the file would render the trace against whatever
     * has since moved into those line numbers and quietly lie about the code
     * that actually failed.
     */
    $refs = (array) data_get($latest?->state, 'domain_refs', []);
    $tags = array_filter((array) $group->tags);
@endphp

@if($excerpt)
    <x-observability.code-editor
        :pane="$excerpt"
        :file="$excerpt['file']"
        :badge="__('Root cause')"
        :note="__('Captured at throw time from the deployed code.')"
        class="mb-3"
    />
@else
    <x-admin-not-found
        :title="__('No source excerpt was captured.')"
        :message="__('The failing file was not readable when the error was recorded.')"
        icon="fa-file-code"
    />
@endif

<h2 class="h6 mb-2">{{ __('Application frames') }}</h2>
<div class="mb-3">
    @forelse($appFrames as $frame)
        <span class="obs-frame">
            {{ $frame['file'] ?? '' }}:{{ $frame['line'] ?? '' }}
            @if(! empty($frame['function']))
                <span class="obs-culprit">{{ $frame['class'] ?? '' }}{{ empty($frame['class']) ? '' : '::' }}{{ $frame['function'] }}</span>
            @endif
        </span>
    @empty
        <p class="text-body-secondary small mb-0">{{ __('No application frames were recorded for this occurrence.') }}</p>
    @endforelse

    @if($appOmitted > 0)
        <p class="text-body-secondary small mt-2 mb-0">
            {{ __(':count deeper application frames are in the debug report.', ['count' => $appOmitted]) }}
        </p>
    @endif
</div>

@if($vendorFrames !== [])
    {{--
        Framework frames stay closed. The fix is never inside the framework,
        and eighty of them between the excerpt and the environment is what made
        this screen read as a dump rather than a report.
    --}}
    <details class="mb-3">
        <summary class="obs-frame__toggle">
            {{ __('Show :count framework frames', ['count' => $vendorTotal]) }}
        </summary>
        <div class="mt-2">
            @foreach($vendorFrames as $frame)
                <span class="obs-frame obs-frame--vendor">
                    {{ $frame['file'] ?? '' }}:{{ $frame['line'] ?? '' }}
                </span>
            @endforeach
        </div>
    </details>
@endif

@if($refs !== [])
    {{--
        "Money at risk" is the fintech-specific panel and the reason this
        module exists in a payments product. It reads state.domain_refs, which
        the collector keeps whole because order and wallet ids are internal
        identifiers, not personal data.

        It is what turns "an exception" into "this much has not been credited",
        which is the sentence that gets a bug prioritised correctly.
    --}}
    <div class="obs-money mb-3">
        <h2 class="h6 mb-3">{{ __('Money at risk') }}</h2>
        <div class="row g-3">
            @foreach($moneyAtRisk as $figure)
                <div class="col-6 col-md-3">
                    <span class="obs-money__figure">{{ $figure['value'] }}</span>
                    <span class="obs-money__label">{{ $figure['label'] }}</span>
                </div>
            @endforeach
        </div>
    </div>
@endif

<h2 class="h6 mb-2">{{ __('Environment') }}</h2>
<dl class="obs-facts mb-3">
    @foreach($environment as $label => $value)
        <div class="obs-facts__item">
            <dt>{{ $label }}</dt>
            <dd title="{{ $value }}">{{ $value ?: __('Not recorded') }}</dd>
        </div>
    @endforeach
</dl>

@if($tags !== [])
    <div class="d-flex flex-wrap gap-1 mb-3">
        @foreach($tags as $tag)
            <span class="obs-code-chip">{{ $tag }}</span>
        @endforeach
    </div>
@endif

{{--
    The grouping rule is reference material, not something read on every visit,
    so it collapses. The fingerprint stays visible because it is the one value
    somebody copies when comparing two installations.
--}}
<details class="obs-note">
    <summary>{{ __('Grouping rule') }} <code>{{ \Illuminate\Support\Str::limit($group->fingerprint, 12, '') }}</code></summary>
    <p class="mb-0">
        {{ __('Errors are grouped by exception class, the normalised message, and the first application frame. Ids, numbers and quoted values are stripped from the message before hashing, so one bug stays one row.') }}
    </p>
</details>
