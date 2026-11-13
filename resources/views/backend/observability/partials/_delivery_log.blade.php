{{--
    Every delivery attempt, including the ones that were deliberately not sent.

    The status column carries the reason a message did not arrive — throttled,
    held for quiet hours, failed — because "nothing arrived" has several
    causes and only one of them is a bug.
--}}
@php
    $channelLabels = collect($cards)->pluck('label', 'key');
    $channelIcons  = ['slack' => 'fa-slack', 'discord' => 'fa-discord', 'teams' => 'fa-microsoft'];
@endphp

<div class="table-responsive obs-log">
    <table class="table mb-0 align-middle obs-log__table">
        <thead>
        <tr>
            <th scope="col">{{ __('When') }}</th>
            <th scope="col">{{ __('Channel') }}</th>
            <th scope="col">{{ __('Severity') }}</th>
            <th scope="col">{{ __('Status') }}</th>
            <th scope="col">{{ __('Response') }}</th>
            <th scope="col" class="obs-log__num">{{ __('Latency') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($log as $entry)
            @php
                $tone = match ($entry->status) {
                    \App\Models\Observability\ErrorDeliveryLog::STATUS_SENT        => 'success',
                    \App\Models\Observability\ErrorDeliveryLog::STATUS_FAILED      => 'danger',
                    \App\Models\Observability\ErrorDeliveryLog::STATUS_RETRYING    => 'warning',
                    \App\Models\Observability\ErrorDeliveryLog::STATUS_QUIET_HOURS => 'primary',
                    default                                                        => 'secondary',
                };
            @endphp
            <tr>
                <td class="obs-log__when">
                    <span class="obs-log__time">{{ $entry->created_at?->format('H:i:s') }}</span>
                    <span class="obs-log__day">
                        {{ $entry->created_at?->isToday() ? __('Today') : ($entry->created_at?->isYesterday() ? __('Yesterday') : $entry->created_at?->format('M j')) }}
                    </span>
                </td>
                <td>
                    <span class="obs-log__channel">
                        <span class="obs-log__icon obs-channel__icon--{{ $entry->channel_key }}" aria-hidden="true">
                            <i class="fa-brands {{ $channelIcons[$entry->channel_key] ?? 'fa-plug' }}"></i>
                        </span>
                        {{ $channelLabels[$entry->channel_key] ?? \Illuminate\Support\Str::headline($entry->channel_key) }}
                    </span>
                </td>
                <td>
                    @if($entry->group)
                        <x-observability.severity-badge :level="$entry->group->level"/>
                    @else
                        <span class="obs-log__muted">—</span>
                    @endif
                </td>
                <td>
                    <span class="obs-status obs-status--{{ $tone }}">
                        <span class="obs-status__dot" aria-hidden="true"></span>
                        {{ \Illuminate\Support\Str::headline($entry->status) }}
                    </span>
                    @if($entry->attempt > 1)
                        <span class="obs-log__muted">{{ __('Attempt :n', ['n' => $entry->attempt]) }}</span>
                    @endif
                </td>
                <td class="obs-log__response" title="{{ $entry->response_summary }}">
                    {{ $entry->response_code ?? '—' }} {{ \Illuminate\Support\Str::limit($entry->response_summary, 60) }}
                </td>
                <td class="obs-log__num">
                    {{ $entry->duration_ms !== null ? __(':ms ms', ['ms' => number_format($entry->duration_ms)]) : '—' }}
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="obs-panel__foot">
    {{ __('Showing the latest :shown of :total attempts.', ['shown' => number_format($log->count()), 'total' => number_format($logCounts[$logFilter] ?? $log->count())]) }}
</div>
