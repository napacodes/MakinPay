@if($activity->isEmpty())
    <x-admin-not-found
        :title="__('Nothing has happened to this error yet.')"
        :message="__('Resolving, ignoring or assigning it records an entry here, including an action taken from a notification.')"
        icon="fa-clock-rotate-left"
    />
@else
    <ol class="obs-timeline">
        @foreach($activity as $entry)
            <li class="obs-timeline__item">
                <span class="obs-timeline__time">{{ $entry->created_at?->format('H:i') }}</span>
                <span class="obs-timeline__dot" aria-hidden="true"></span>
                <span>
                    <span class="obs-code-chip">{{ $entry->channel_key }}</span>
                    <span class="obs-error-class d-inline">{{ $entry->status }}</span>
                    @if($entry->acknowledged_by)
                        <span class="obs-culprit">{{ __(':name acknowledged this.', ['name' => $entry->acknowledged_by]) }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ol>
@endif
