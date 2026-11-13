@php
    $request = (array) $latest?->request;
    $headers = (array) ($request['headers'] ?? []);
@endphp

<div class="row g-3">
    <div class="col-12 col-lg-5">
        <div class="table-responsive mb-3">
            <table class="table mb-0">
                <tbody>
                <tr><th scope="row">{{ __('Method') }}</th><td class="obs-culprit">{{ $request['method'] ?? '—' }}</td></tr>
                <tr><th scope="row">{{ __('Route') }}</th><td class="obs-culprit">{{ $request['route'] ?? '—' }}</td></tr>
                <tr><th scope="row">{{ __('Action') }}</th><td class="obs-culprit">{{ $request['action'] ?? '—' }}</td></tr>
                <tr><th scope="row">{{ __('IP') }}</th><td class="obs-culprit">{{ $request['ip'] ?? '—' }}</td></tr>
                <tr><th scope="row">{{ __('User') }}</th><td class="obs-culprit">{{ $request['user_id'] ?? '—' }}</td></tr>
                </tbody>
            </table>
        </div>

        <h2 class="h6 mb-2">{{ __('Middleware') }}</h2>
        @forelse((array) ($request['middleware'] ?? []) as $middleware)
            <span class="obs-code-chip me-1 mb-1">{{ $middleware }}</span>
        @empty
            <p class="text-body-secondary small">{{ __('No middleware recorded.') }}</p>
        @endforelse
    </div>

    <div class="col-12 col-lg-7">
        <h2 class="h6 mb-2">{{ __('Headers') }}</h2>
        <div class="mb-3">
            @foreach($headers as $name => $value)
                <div class="obs-redaction__item">
                    <span>{{ $name }}</span>
                    @if(in_array($value, ['[DROPPED]', '[MASKED]', '[UNCLASSIFIED]'], true))
                        <span class="obs-verdict obs-verdict--{{ $value === '[DROPPED]' ? 'dropped' : 'masked' }}">{{ __('REDACTED') }}</span>
                    @else
                        <span>{{ $value }}</span>
                    @endif
                </div>
            @endforeach
        </div>

        <h2 class="h6 mb-2">{{ __('Body') }}</h2>
        <x-observability.code-editor
            :pane="$requestBody"
            file="body.json"
            :note="__('Values were removed in the collector, before this row was written.')"
            class="mb-3"
        />

        <h2 class="h6 mb-2">{{ __('Database queries') }}</h2>
        @forelse($queries as $query)
            <div class="obs-redaction__item">
                <span>{{ $query['sql'] ?? '' }}</span>
                <span>{{ $query['ms'] ?? '—' }} ms</span>
            </div>
        @empty
            <p class="text-body-secondary small mb-0">{{ __('No queries were recorded for this occurrence.') }}</p>
        @endforelse
    </div>
</div>
