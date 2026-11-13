<div class="table-responsive">
    <table class="table mb-0 align-middle">
        <thead>
        <tr>
            <th scope="col">{{ __('When') }}</th>
            <th scope="col">{{ __('Level') }}</th>
            <th scope="col">{{ __('Release') }}</th>
            <th scope="col">{{ __('User') }}</th>
            <th scope="col">{{ __('Trace') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($occurrences as $occurrence)
            <tr>
                <td class="obs-culprit">{{ $occurrence->created_at?->format('Y-m-d H:i:s') }}</td>
                <td><x-observability.severity-badge :level="$occurrence->level"/></td>
                <td class="obs-culprit">{{ $occurrence->release ?? '—' }}</td>
                <td class="obs-culprit">{{ $occurrence->user_id ?? '—' }}</td>
                <td class="obs-culprit">{{ \Illuminate\Support\Str::limit((string) $occurrence->trace_id, 16, '') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@if($retentionNotice)
    <p class="text-body-secondary small mt-2 mb-0">{{ $retentionNotice }}</p>
@endif
