@extends('backend.layouts.app')
@section('title')
    {{ __('Settings') }}
@endsection
@section('content')
    {{--
        The shell draws no header of its own. Both pages that extend it —
        site settings and the integration centre — open with their own
        <x-page-header>, carrying stats, actions and a status pill this
        wrapper has no way to express. A header here would be a second H1.
    --}}

    <div class="card px-3 py-3">
        <div class="py-3">
            @yield('setting_content')
        </div>
    </div>
@endSection
