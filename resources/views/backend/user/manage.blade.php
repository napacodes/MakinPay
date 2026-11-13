@extends('backend.layouts.app')
@section('title', __('Manage user'))

@push('styles')
    @php($umCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/user-manage.css')))
    <link rel="stylesheet" href="{{ asset('backend/css/user-manage.css?v='.$umCssVersion) }}">
@endpush

@section('content')
    {{--
        Admin → Users → Manage user.

        The header is <x-page-header>; the tabs, the main column and the
        rail are page content under it. Every modal is a real form that
        posts to a real route — user-manage.js only keeps the previews,
        counters and enable/disable states honest.
    --}}
    <div class="um-page"
         data-user-manage
         data-user-id="{{ $user->id }}"
         data-feature-url="{{ route('admin.user.feature-status.update') }}"
         data-demo="{{ config('app.demo') ? '1' : '0' }}"
         data-i18n-demo="{{ __('This action is disabled in demo mode.') }}"
         data-i18n-error="{{ __('Something went wrong. Please try again.') }}"
         data-i18n-show="{{ __('Show') }}"
         data-i18n-hide="{{ __('Hide') }}"
         data-i18n-strength="{{ __('Strength') }}|{{ __('Weak') }}|{{ __('Fair') }}|{{ __('Good') }}|{{ __('Strong') }}">
        @include('backend.user.partials._user_manage_header')

        <div class="um-body">
            <main class="um-main">
                @include('backend.user.partials._user_manage_tabs')
                @yield('user_manage_content')
            </main>

            <aside class="um-rail">
                @include('backend.user.partials._rail_wallets')
                @can('user-features-manage')
                    @include('backend.user.partials._rail_controls')
                @endcan
            </aside>
        </div>

        @can('user-balance-manage')
            @include('backend.user.partials._funds_modal')
        @endcan
        @can('custom-notify-users')
            @include('backend.user.partials._notify_user_modal')
        @endcan
        @can('user-manage')
            @include('backend.user.partials._reset_password_modal')
            @include('backend.user.partials._suspend_user_modal')
            @if($user->isUser())
                @include('backend.user.partials._convert_to_merchant_modal')
            @endif
        @endcan
        @can('user-delete')
            @include('backend.user.partials._delete_user_modal')
        @endcan
    </div>
@endsection

@push('scripts')
    @php($umJsVersion = config('app.version').'-'.filemtime(public_path('backend/js/user-manage.js')))
    <script src="{{ asset('backend/js/user-manage.js?v='.$umJsVersion) }}"></script>
@endpush
