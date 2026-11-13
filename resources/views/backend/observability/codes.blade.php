@extends('backend.layouts.app')
@section('title', __('Error Code Registry'))

@section('content')
    <x-page-header
        :title="__('Error code registry')"
        :subtitle="__('Every DK code this platform has returned, what it means, and how often it happens. Support can search it by the code a customer quotes.')"
        icon="fa-hashtag"
        class="mb-3"
    />

    <div class="observability card border-0 mb-4">
        <div class="card-body">
            <div class="admin-table-filterbar">
                <form method="GET" action="{{ route('admin.observability.codes') }}" class="row g-2 align-items-end">
                    <div class="col-12 col-lg-8">
                        <label class="form-label" for="obs-code-search">{{ __('Search') }}</label>
                        <input type="search"
                               id="obs-code-search"
                               name="q"
                               value="{{ $search }}"
                               class="form-control"
                               placeholder="{{ __('DK-PAY-4012') }}">
                    </div>
                    <div class="col-12 col-lg-4">
                        <button type="submit" class="btn btn-primary">{{ __('Search') }}</button>
                    </div>
                </form>
            </div>

            @if($codes->isEmpty())
                <x-admin-not-found
                    :title="__('No error code matched.')"
                    :message="__('A code appears here the first time an error carrying it is captured.')"
                    icon="fa-hashtag"
                />
            @else
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead>
                        <tr>
                            <th scope="col">{{ __('Code') }}</th>
                            <th scope="col">{{ __('Level') }}</th>
                            <th scope="col">{{ __('Meaning') }}</th>
                            <th scope="col">{{ __('Source') }}</th>
                            <th scope="col">{{ __('Events') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($codes as $code)
                            <tr>
                                <td><span class="obs-code-chip">{{ $code->error_code }}</span></td>
                                <td><x-observability.severity-badge :level="$code->level"/></td>
                                <td>
                                    <span class="obs-error-class">{{ $code->title }}</span>
                                    <span class="obs-error-message">{{ $code->message }}</span>
                                </td>
                                <td class="obs-culprit">{{ $code->source->chipLabel() }}</td>
                                <td>{{ number_format($code->event_count) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <p class="text-body-secondary small mt-3 mb-0">
                {{ __('A code appears here the first time an error carrying it is captured. Attach one with SystemHealth::report() so support can search by the code a customer quotes.') }}
            </p>
        </div>
    </div>
@endsection

@include('backend.observability.partials._scripts')
