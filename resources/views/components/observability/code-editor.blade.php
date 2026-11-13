{{--
    The editor pane.

    One component for every block of code this module shows — the failing
    excerpt, the debug report, a request body — so they read as the same
    surface rather than three separate takes on a dark box.

    Two properties here are load-bearing.

    The body scrolls inside itself. A four-hundred-line report, or one minified
    line, sits in its own scroll box instead of stretching the document. Before
    that, a large capture made the browser lay the whole page out around it.

    Line markup comes from CodeHighlighter and is printed unescaped. That is
    safe only because every character of it left that class through e(); no
    other source may be piped into this attribute.
--}}
@props([
    'pane',
    'file' => '',
    'badge' => '',
    'note' => '',
    'copyable' => true,
])

<div {{ $attributes->merge(['class' => 'obs-editor']) }} data-obs-editor>
    <div class="obs-editor__bar">
        <span class="obs-editor__tab" title="{{ $file }}">
            <i class="fa-solid fa-file-code" aria-hidden="true"></i>
            <span class="obs-editor__name">{{ $file }}</span>
        </span>

        @if($badge !== '')
            <span class="obs-editor__badge">{{ $badge }}</span>
        @endif

        @if($copyable)
            <button type="button"
                    class="obs-editor__action"
                    data-obs-copy-code
                    data-obs-copied-label="{{ __('Copied') }}">
                <i class="fa-regular fa-copy" aria-hidden="true"></i>
                <span>{{ __('Copy') }}</span>
            </button>
        @endif
    </div>

    <div class="obs-editor__scroll" tabindex="0" role="region" aria-label="{{ $file ?: __('Source') }}">
        {{--
            No newline between the rows. Each row is its own flex block, so a
            line break left in the markup would be rendered too — by <pre>,
            which honours it — and the pane would come out double-spaced.
        --}}
        <pre class="obs-editor__code"><code>@foreach($pane['lines'] as $line)<span class="obs-editor__line{{ $line['offending'] ? ' is-offending' : '' }}"><span class="obs-editor__num">{{ $line['number'] }}</span><span class="obs-editor__text">{!! $line['html'] !!}</span></span>@endforeach</code></pre>
    </div>

    @if($pane['truncated'] > 0 || $note !== '')
        <div class="obs-editor__foot">
            @if($pane['truncated'] > 0)
                <span>{{ __(':count more lines are not painted here, to keep this screen quick. Nothing was dropped from the download.', ['count' => $pane['truncated']]) }}</span>
            @endif
            @if($note !== '')
                <span>{{ $note }}</span>
            @endif
        </div>
    @endif
</div>
