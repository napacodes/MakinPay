<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ language_direction() }}">
@include('backend.layouts.partials._head')
<body class="backend-admin-layout"
      data-admin-action-label="{{ __('Action') }}"
      data-admin-actions-label="{{ __('Actions') }}">

{{-- Demo Mode Banner (renders only when APP_DEMO=true) --}}
<x-demo-banner variant="admin" />

@include('backend.layouts.partials._sidebar')

<div class="wrapper d-flex flex-column min-vh-100">
    @include('backend.layouts.partials._header')
    
    {{--
        dk-page marks the one part of the shell that differs on the next page.
        The column and the header are identical on every screen, so they stay
        exactly where they are and only this arrives.
    --}}
    <div class="body flex-grow-1 px-0 px-md-4 mb-3">
        <div class="container-lg dk-page">

            {{--
                The trail, where a page asks for one.

                It left the top bar because it was spending header width
                restating the page title. A page that wants one now defines
                @section('breadcrumb'), and AdminShell::breadcrumb() still
                derives a trail from the menu config for a page that would
                rather not write its own.
            --}}
            @hasSection('breadcrumb')
                <nav class="dk-crumbs" aria-label="{{ __('Breadcrumb') }}">
                    @yield('breadcrumb')
                </nav>
            @endif

            {{-- Any Warning Messages Here --}}
            @include('backend.partials._messages')

             {{--  Main Content --}}
             @yield('content')
        </div>
    </div>

    {{-- delete modal --}}
    @include('backend.partials._delete_modal')

    @include('backend.layouts.partials._footer')
</div>

@include('backend.layouts.partials._scripts')
</body>
</html>
