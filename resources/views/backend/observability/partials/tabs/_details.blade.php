{{--
    Everything that used to have a tab of its own.

    Five tabs for five short tables meant the reader had to open all five to
    learn there was nothing in four of them. Gathered here they are one scroll,
    and the ones with nothing to show say so in a line instead of costing a
    page load to find out.

    The whole tab is queried only when it is opened, which is the reason this
    screen was built around tabs in the first place. Row counts are capped in
    the controller, not here.
--}}

<h2 class="h6 mb-2">{{ __('Request') }}</h2>
@include('backend.observability.partials.sections._request')

<h2 class="h6 mb-2 mt-4">{{ __('Recent occurrences') }}</h2>
@include('backend.observability.partials.sections._occurrences')

<h2 class="h6 mb-2 mt-4">{{ __('Breadcrumbs') }}</h2>
@include('backend.observability.partials.sections._breadcrumbs')

<h2 class="h6 mb-2 mt-4">{{ __('Related errors') }}</h2>
@include('backend.observability.partials.sections._related')

<h2 class="h6 mb-2 mt-4">{{ __('Activity') }}</h2>
@include('backend.observability.partials.sections._activity')
