@extends('backend.layouts.app')
@section('title', $group->title)

@section('content')
    {{--
        Deliberately no websocket on this screen. A detail view that mutates
        while somebody is reading a stack trace is hostile — the Issues list is
        where new arrivals are announced.
    --}}
    <div class="observability">

        {{--
            No occurrence pager. It was four arrows never wired to anything:
            two permanently disabled, two inert, and a readout saying what the
            Events figure in the header already says. The occurrences
            themselves are listed under Details.
        --}}
        <div class="mb-3">
            <a href="{{ route('admin.observability.issues') }}" class="btn btn-light btn-sm">
                <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>
                {{ __('Back to issues') }}
            </a>
        </div>

        <x-page-header
            :title="$group->title"
            :subtitle="\Illuminate\Support\Str::limit($group->message, 180)"
            icon="fa-bug"
            :stats="$detailStats"
            class="mb-3"
        >
            <x-slot:actions>
                @can('observability-manage')
                    <form method="POST" action="{{ route('admin.observability.issues.resolve', $group) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary">
                            <i class="fa-solid fa-check" aria-hidden="true"></i>{{ __('Resolve') }}
                        </button>
                    </form>
                    <form method="POST" action="{{ route('admin.observability.issues.ignore', $group) }}">
                        @csrf
                        <button type="submit"
                                class="btn btn-outline-secondary"
                                title="{{ __('Stop alerting for this error. It keeps being captured.') }}">
                            <i class="fa-solid fa-bell-slash" aria-hidden="true"></i>{{ __('Ignore') }}
                        </button>
                    </form>
                @endcan
                @can('observability-export')
                    <a href="{{ route('admin.observability.issues.export', ['group' => $group, 'format' => 'md']) }}"
                       class="btn btn-primary">
                        <i class="fa-solid fa-file-code" aria-hidden="true"></i>{{ __('Copy debug report') }}
                    </a>
                @endcan
            </x-slot:actions>
        </x-page-header>

        {{--
            One meta strip instead of two cards.

            Release and trace were a full-width card and a half-width card for
            two short values apiece — most of a screen height above the tabs,
            which is where the reader is actually going.
        --}}
        <div class="obs-meta mb-3">
            <div class="obs-meta__item">
                <span class="obs-meta__label">{{ __('Release') }}</span>
                @forelse($releases as $release)
                    <span class="obs-meta__value obs-meta__value--affected">{{ $release['tag'] }}</span>
                @empty
                    <span class="obs-meta__value">{{ __('Not recorded') }}</span>
                @endforelse
            </div>

            <div class="obs-meta__item">
                <span class="obs-meta__label">{{ __('Source') }}</span>
                <span class="obs-meta__value">{{ $group->source->chipLabel() }}</span>
            </div>

            @if($group->error_code)
                <div class="obs-meta__item">
                    <span class="obs-meta__label">{{ __('Code') }}</span>
                    <a class="obs-meta__value" href="{{ route('admin.observability.codes', ['q' => $group->error_code]) }}">
                        {{ $group->error_code }}
                    </a>
                </div>
            @endif

            @if($latest?->trace_id)
                <div class="obs-meta__item obs-meta__item--wide">
                    <span class="obs-meta__label">{{ __('Trace') }}</span>
                    <span class="obs-meta__value obs-meta__value--mono" title="{{ $latest->trace_id }}">
                        {{ \Illuminate\Support\Str::limit($latest->trace_id, 16, '…') }}
                    </span>
                </div>
            @endif

            @if($group->culprit)
                <div class="obs-meta__item obs-meta__item--grow">
                    <span class="obs-meta__label">{{ __('Culprit') }}</span>
                    <span class="obs-meta__value obs-meta__value--mono" title="{{ $group->culprit }}">{{ $group->culprit }}</span>
                </div>
            @endif
        </div>

        <div class="card border-0 mb-4">
            <nav class="obs-tabs" aria-label="{{ __('Error detail sections') }}">
                @foreach($tabs as $key => $label)
                    <a class="obs-tab @if($activeTab === $key) is-active @endif"
                       href="{{ route('admin.observability.issues.show', ['group' => $group, 'tab' => $key]) }}"
                       @if($activeTab === $key) aria-current="page" @endif>
                        {{ $label }}
                    </a>
                @endforeach
            </nav>

            <div class="card-body">
                @include('backend.observability.partials.tabs._'.$activeTab)
            </div>
        </div>
    </div>
@endsection

@include('backend.observability.partials._scripts')
