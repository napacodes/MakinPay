@extends('backend.layouts.app')
@section('title', __('Issues'))

@section('content')
    <x-page-header
        :title="__('Issues')"
        :subtitle="__('One row is one bug, not one occurrence. Filter with level:fatal source:gateway is:unresolved, then resolve, ignore or export.')"
        icon="fa-bug"
        :stats="$headerStats"
        class="mb-3"
    />

    <div class="observability card border-0 mb-4" data-obs-issues>
        <div class="card-body">

            {{-- Saved views. The five built-in tabs are always present so an
                 upgrade cannot leave the strip half empty. --}}
            <nav class="obs-views" aria-label="{{ __('Saved views') }}">
                @foreach($savedViews as $view)
                    <a class="obs-view @if($activeView === $view['slug']) is-active @endif"
                       href="{{ route('admin.observability.issues', ['q' => $view['query']]) }}"
                       @if($activeView === $view['slug']) aria-current="page" @endif>
                        <span>{{ $view['label'] }}</span>
                        <span class="obs-view__count">{{ $view['count'] }}</span>
                    </a>
                @endforeach
            </nav>

            <div class="admin-table-filterbar">
                <form method="GET" action="{{ route('admin.observability.issues') }}" class="row g-2 align-items-end">
                    <div class="col-12 col-lg-8">
                        <label class="form-label" for="obs-query">{{ __('Search') }}</label>
                        <input type="search"
                               id="obs-query"
                               name="q"
                               value="{{ $query }}"
                               class="form-control"
                               placeholder="{{ __('level:fatal source:gateway is:unresolved') }}"
                               aria-describedby="obs-query-help">
                        <span id="obs-query-help" class="visually-hidden">
                            {{ __('Filter with key:value pairs. An unknown filter is shown as invalid and is not applied.') }}
                        </span>
                    </div>
                    <div class="col-12 col-lg-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                        @if($query !== '')
                            <a href="{{ route('admin.observability.issues') }}" class="btn btn-light">{{ __('Clear') }}</a>
                        @endif
                    </div>
                </form>

                @if(! empty($tokens))
                    <div class="obs-pills mt-2">
                        @foreach($tokens as $token)
                            <x-observability.query-pill :token="$token"/>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Appears only on selection. Under fifty groups the action runs
                 inline, because an installation with no queue worker would
                 otherwise show a success message for work that never happens. --}}
            @can('observability-manage')
                <form method="POST"
                      action="{{ route('admin.observability.issues.bulk') }}"
                      class="obs-bulk d-none"
                      data-obs-bulk>
                    @csrf
                    <span class="obs-bulk__count">
                        <span data-obs-bulk-count>0</span> {{ __('selected') }}
                    </span>

                    {{-- Filled by the selection handler with one groups[] input per
                         checked row, so the request validates each id against the table
                         rather than trusting a comma-separated string. --}}
                    <span data-obs-bulk-ids></span>

                    <button type="submit" name="action" value="resolve" class="obs-bulk__action">
                        {{ __('Resolve') }}
                    </button>
                    <button type="submit"
                            name="action"
                            value="ignore"
                            class="obs-bulk__action"
                            title="{{ __('Stop alerting for these errors. They keep being captured.') }}">
                        {{ __('Ignore') }}
                    </button>
                    <button type="submit" name="action" value="assign" class="obs-bulk__action">
                        {{ __('Assign to me') }}
                    </button>
                </form>
            @endcan

            @includeWhen($groups->isNotEmpty(), 'backend.observability.partials._issues_table')

            @if($groups->isEmpty())
                @if($query !== '')
                    <x-admin-not-found
                        :title="__('No errors match this filter.')"
                        :message="__('Try removing one condition.')"
                        icon="fa-filter"
                        :action-url="route('admin.observability.issues')"
                        :action-label="__('Clear the filter')"
                        action-icon="fa-rotate-left"
                    />
                @else
                    <x-admin-not-found
                        :title="__('No unresolved errors.')"
                        :message="__('Nothing is broken right now.')"
                        icon="fa-circle-check"
                    />
                @endif
            @endif

            @if($groups->hasPages())
                <div class="d-flex justify-content-end mt-3">
                    {{ $groups->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection

@include('backend.observability.partials._scripts')
