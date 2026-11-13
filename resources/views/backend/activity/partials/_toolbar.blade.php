@php
    /*
     * One GET form. Every control writes into it and submits, so the filters,
     * the pagination links and the export URL all read the same query string
     * and nothing has to be kept in sync by hand.
     */
    $currentRole = (string) request('type', '');
    $currentRisk = (string) request('risk', '');
@endphp

<form method="GET" action="{{ route('admin.activity-log') }}" class="ah-toolbar" data-ah-filters>
    <label class="ah-search">
        <x-icon name="search" width="14" height="14"/>
        <input type="search"
               name="search"
               value="{{ request('search') }}"
               placeholder="{{ __('Search name, username, IP or city') }}"
               aria-label="{{ __('Search sign-ins') }}">
    </label>

    <div class="ah-seg" role="group" aria-label="{{ __('Role') }}">
        @foreach($roleFilters as $value => $label)
            <button type="submit" name="type" value="{{ $value }}"
                    class="ah-seg__item {{ $currentRole === (string) $value ? 'is-on' : '' }}"
                    @if($currentRole === (string) $value) aria-current="true" @endif>{{ $label }}</button>
        @endforeach
    </div>

    <div class="ah-seg" role="group" aria-label="{{ __('Risk') }}">
        @foreach($riskFilters as $value => $label)
            <button type="submit" name="risk" value="{{ $value }}"
                    class="ah-seg__item {{ $currentRisk === (string) $value ? 'is-on' : '' }}"
                    @if($currentRisk === (string) $value) aria-current="true" @endif>
                @if($value === 'flagged')
                    <span class="ah-dot ah-dot--warning"></span>
                @elseif($value !== '')
                    <span class="ah-dot ah-dot--danger"></span>
                @endif
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{--
        The picker writes "YYYY-MM-DD,YYYY-MM-DD" here and submits the form.
        Named `range`, not `daterange`: a legacy picker script included on
        every admin page overwrites any input called `daterange` with its own
        default. The controller maps this onto the shared filter.
    --}}
    {{-- The effective range, not request('range'): the default is merged onto
         the form request, which the request() helper does not see. Rendering
         the helper's empty value would submit `range=`, and an explicitly
         empty range means "all time". --}}
    <input type="hidden" name="range" value="{{ $rangeValue }}" data-ah-daterange-value>
    <input type="hidden" name="per_page" value="{{ $perPage }}">

    <button type="button" class="ah-range" data-ah-daterange data-ah-range="{{ $rangeValue }}" data-i18n-all="{{ __('All time') }}">
        <x-icon name="calendar" width="14" height="14"/>{{ $rangeLabel }}
        <span class="ah-range__caret" aria-hidden="true"></span>
    </button>
</form>

@if($chips !== [])
    <div class="ah-chips">
        <span class="ah-chips__label">{{ __('Filters:') }}</span>

        @foreach($chips as $chip)
            <a class="ah-chip" href="{{ route('admin.activity-log', array_merge(request()->except(['page', $chip['key']]))) }}">
                {{ $chip['label'] }}
                <span class="ah-chip__x" aria-hidden="true">&times;</span>
                <span class="ah-visually-hidden">{{ __('Remove filter') }}</span>
            </a>
        @endforeach

        <a class="ah-chips__clear" href="{{ route('admin.activity-log') }}">{{ __('Clear all') }}</a>
    </div>
@endif
