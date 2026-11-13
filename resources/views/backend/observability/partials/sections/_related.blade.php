@if($related->isEmpty())
    <x-admin-not-found
        :title="__('No similar errors were found.')"
        :message="__('Errors from the same source and file appear here, so a shared cause is easier to spot.')"
        icon="fa-diagram-project"
    />
@else
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
            <tr>
                <th scope="col">{{ __('Level') }}</th>
                <th scope="col">{{ __('Error') }}</th>
                <th scope="col">{{ __('Events') }}</th>
                <th scope="col">{{ __('Last seen') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($related as $sibling)
                <tr>
                    <td><x-observability.severity-badge :level="$sibling->level"/></td>
                    <td>
                        <a class="obs-error-class" href="{{ route('admin.observability.issues.show', $sibling) }}">{{ $sibling->title }}</a>
                        <span class="obs-error-message">{{ $sibling->message }}</span>
                    </td>
                    <td>{{ number_format($sibling->event_count) }}</td>
                    <td class="obs-culprit">{{ $sibling->last_seen?->diffForHumans() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
