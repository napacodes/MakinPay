@extends('backend.layouts.app')

@section('title', __('Activity history'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/activity-history.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/activity-history.css'))) }}">
@endpush

@section('content')
    @php
        /*
         * Every URL and every translated string the script needs rides on the
         * page root: public/backend/js is not run through __(), so a literal
         * in there would ship in English on an Arabic install.
         */
        $isDemo = (bool) config('app.demo', false);
    @endphp

    <div class="ah-page"
         data-ah-page
         data-demo="{{ $isDemo ? '1' : '0' }}"
         data-show-url="{{ route('admin.activity-log.show', ['activity' => 0]) }}"
         data-i18n-error="{{ __('Something went wrong. Please try again.') }}"
         data-i18n-demo="{{ __('This action is disabled in demo mode.') }}"
         data-i18n-loading="{{ __('Loading…') }}"
         data-i18n-open-account="{{ __('Open account') }}"
         data-i18n-risk="{{ __('Risk assessment') }}"
         data-i18n-session="{{ __('Session') }}"
         data-i18n-history="{{ __('Recent sign-ins for this account') }}"
         data-i18n-close="{{ __('Close') }}">

        @include('backend.activity.partials._header')

        <section class="ah-card">
            @include('backend.activity.partials._toolbar')
            @include('backend.activity.partials._table')
            @include('backend.activity.partials._foot')
        </section>
    </div>

    @include('backend.activity.partials._drawer')
@endsection

@push('scripts')
    <script src="{{ asset('backend/js/activity-history.js') }}?v={{ config('app.version') }}-{{ filemtime(public_path('backend/js/activity-history.js')) }}"></script>
@endpush
