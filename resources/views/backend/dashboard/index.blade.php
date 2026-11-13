@extends('backend.layouts.app')
@section('title')
    {{ __('Dashboard') }}
@endsection

@push('styles')
    @php($dashboardCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/dashboard.css')))
    <link rel="stylesheet" href="{{ asset('backend/css/dashboard.css?v='.$dashboardCssVersion) }}">
@endpush

@section('content')

    {{--
        One screen, four questions, in the order an operator asks them: what
        needs my action, how is money moving, is the platform healthy, is
        anything risky. Every panel is gated on the permission of the data it
        draws — the controller does not even compute a panel this administrator
        cannot see, so an @can here hides markup that has no payload behind it.
    --}}
    <div class="dk-dash"
         data-dk-dashboard
         data-live-url="{{ route('admin.dashboard.live') }}"
         data-range-url="{{ route('admin.dashboard') }}"
         data-sla-url="{{ route('admin.dashboard.sla.update') }}"
         data-poll-seconds="60"
         data-msg-range-error="{{ __('Range not loaded') }}"
         data-msg-saved="{{ __('Saved') }}">

        @include('backend.dashboard.partials._page_header')

        @if($dashboard['counters'] !== null)
            @include('backend.dashboard.partials._counters')
        @endif

        <div class="dk-dash__body">
            <div class="dk-dash__main">
                @if($dashboard['can']['queue'])
                    @include('backend.dashboard.partials._queues')
                @endif

                @if($dashboard['can']['stats'] || $dashboard['can']['chart'])
                    @include('backend.dashboard.partials._money')
                @endif

                @if($dashboard['can']['trx'])
                    @include('backend.dashboard.partials._transactions')
                @endif
            </div>

            <div class="dk-dash__rail">
                @if($dashboard['can']['system'])
                    @include('backend.dashboard.partials._health')
                @endif

                @if($dashboard['treasury'] !== null)
                    @include('backend.dashboard.partials._treasury')
                @endif

                @if($dashboard['risk'] !== null)
                    @include('backend.dashboard.partials._risk')
                @endif
            </div>
        </div>

        @if($dashboard['can']['chart'] || $dashboard['can']['stats'])
            @include('backend.dashboard.partials._range_modal')
        @endif

        @if($dashboard['can']['sla'])
            @include('backend.dashboard.partials._sla_modal')
        @endif

        {{-- The latest-transactions panel shows the ledger's own table, whose
             rows open this. Without it a row here would look clickable and do
             nothing. --}}
        @include('backend.transaction.partials._detail_modal')
    </div>
@endsection

@push('scripts')
    @php($dashboardJsVersion = config('app.version').'-'.filemtime(public_path('backend/js/dashboard.js')))
    <script src="{{ asset('backend/js/dashboard.js?v='.$dashboardJsVersion) }}"></script>
    <script src="{{ asset('backend/js/transaction-detail.js').'?v='.config('app.version').'-'.filemtime(public_path('backend/js/transaction-detail.js')) }}"></script>
@endpush
