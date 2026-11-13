{{--
    The module's behaviour: issue selection, the bulk bar, the filter pills and
    the copy-report button.

    Pulled in by every System Health screen rather than only the issue list,
    because the copy button lives on the detail screen and was silently inert
    while this was loaded in one place.
--}}
@push('scripts')
    <script src="{{ asset('backend/js/observability.js?v='.config('app.version').'-'.filemtime(public_path('backend/js/observability.js'))) }}"></script>
@endpush
