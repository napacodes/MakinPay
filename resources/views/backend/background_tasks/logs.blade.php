@extends('backend.background_tasks.layout')

@section('title', __('Run history'))

@section('bt_content')
    @php
        $btActiveTask   = (string) request('task_key', '');
        $btActiveStatus = (string) request('status', '');
        $btActiveRange  = (string) request('range', '');

        /**
         * The states a run can be in. "Running" is not an outcome yet — it is
         * a run that has not reported back, which is why it carries the same
         * amber as a stalled scheduler rather than a failure's red.
         */
        $btTones = [
            'success' => ['label' => __('Success'), 'class' => 'is-success'],
            'failed'  => ['label' => __('Failed'), 'class' => 'is-failed'],
            'running' => ['label' => __('Running'), 'class' => 'is-running'],
        ];

        $btStatusFilters = [
            ''        => ['label' => __('All'), 'count' => 'all'],
            'success' => ['label' => __('Success'), 'count' => 'success'],
            'failed'  => ['label' => __('Failed'), 'count' => 'failed'],
            'running' => ['label' => __('Running'), 'count' => 'running'],
        ];

        $btRanges = [
            '24h' => __('24h'),
            '7d'  => __('7 days'),
            ''    => __('All time'),
        ];

        $btDuration = function (?int $ms): string {
            if ($ms === null) {
                return '—';
            }

            if ($ms < 1000) {
                return __(':n ms', ['n' => $ms]);
            }

            if ($ms < 60000) {
                return __(':n s', ['n' => number_format($ms / 1000, 1)]);
            }

            return __(':m m :s s', ['m' => intdiv($ms, 60000), 's' => (int) round(($ms % 60000) / 1000)]);
        };

        /*
         * A filter link keeps whatever else is already set, so choosing a
         * status does not silently drop the command or the range. Empty values
         * fall out of the query string rather than being sent as blanks.
         */
        $btFilterUrl = fn (array $changes): string => route(
            'admin.background-tasks.logs',
            array_filter(
                array_merge(request()->except('page'), $changes),
                fn ($value): bool => $value !== '' && $value !== null,
            ),
        );
    @endphp

    <div class="bt-toolbar bt-toolbar--history">
        <span class="bt-select">
            <select data-bt-history-task aria-label="{{ __('Filter by command') }}">
                <option value="{{ route('admin.background-tasks.logs', array_filter(array_merge(request()->except(['page', 'task_key'])))) }}"
                        @selected($btActiveTask === '')>@lang('All commands')</option>
                @foreach($tasks as $btKey => $btTask)
                    <option value="{{ $btFilterUrl(['task_key' => $btKey]) }}" @selected($btActiveTask === $btKey)>{{ __($btTask['label']) }}</option>
                @endforeach
            </select>
            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
        </span>

        <div class="bt-filters" role="group" aria-label="{{ __('Filter by state') }}">
            @foreach($btStatusFilters as $btValue => $btFilter)
                <a class="bt-filters__btn {{ $btActiveStatus === $btValue ? 'is-active' : '' }}"
                   href="{{ $btFilterUrl(['status' => $btValue]) }}"
                   aria-pressed="{{ $btActiveStatus === $btValue ? 'true' : 'false' }}">
                    {{ $btFilter['label'] }}<span class="bt-filters__count" dir="ltr">{{ $statusCounts[$btFilter['count']] }}</span>
                </a>
            @endforeach
        </div>

        <div class="bt-filters" role="group" aria-label="{{ __('Filter by period') }}">
            @foreach($btRanges as $btValue => $btLabel)
                <a class="bt-filters__btn {{ $btActiveRange === $btValue ? 'is-active' : '' }}"
                   href="{{ $btFilterUrl(['range' => $btValue]) }}"
                   aria-pressed="{{ $btActiveRange === $btValue ? 'true' : 'false' }}">{{ $btLabel }}</a>
            @endforeach
        </div>

        <span class="bt-toolbar__count">{{ __(':n runs', ['n' => number_format($logs->total())]) }}</span>
    </div>

    <div class="bt-tablewrap">
        <div class="bt-grid bt-grid--history">
            <div class="bt-grid__head">
                <span></span>
                <span>@lang('Started')</span>
                <span>@lang('Command')</span>
                <span>@lang('Status')</span>
                <span>@lang('Output')</span>
                <span class="bt-grid__num">@lang('Duration')</span>
                <span>@lang('Triggered by')</span>
            </div>

            @forelse($logs as $btLog)
                @php
                    $btTask   = $tasks[$btLog->task_key] ?? null;
                    $btStatus = array_key_exists($btLog->status, $btTones) ? $btLog->status : 'running';
                    $btTone   = $btTones[$btStatus];
                    $btFull   = $btStatus === 'failed'
                        ? trim((string) ($btLog->error_message ?: $btLog->output))
                        : trim((string) $btLog->output);
                    $btFirst  = strtok($btFull, "\n");
                    $btBy     = $btLog->executor?->name ?? __('System');
                    $btAuto   = $btLog->executor === null;

                    /*
                     * Two letters from the name we actually have. A scheduled
                     * run has nobody behind it, so it reads as System rather
                     * than borrowing whichever admin happened to be signed in.
                     */
                    $btInitials = mb_strtoupper(mb_substr(preg_replace('/\s+/', '', $btBy) ?: '?', 0, 2));

                    $btOptions = collect((array) ($btLog->options ?? []))
                        ->map(fn ($value, string $name): string => is_bool($value) ? '--'.$name : '--'.$name.'='.$value)
                        ->values();
                @endphp
                <div class="bt-erow" data-bt-expand aria-expanded="false" role="button" tabindex="0">
                    <div class="bt-erow__row">
                        <span class="bt-chevbox" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>

                        <span class="bt-when">
                            <span class="bt-when__time" dir="ltr">{{ $btLog->created_at?->format('H:i:s') }}</span>
                            <span class="bt-when__date" dir="ltr">{{ $btLog->created_at?->format('d M Y') }}</span>
                        </span>

                        <span class="bt-histcmd">
                            <span class="bt-histcmd__label">{{ __($btTask['label'] ?? $btLog->task_key) }}</span>
                            <span class="bt-histcmd__meta">
                                <span class="bt-histcmd__sig" dir="ltr">{{ $btLog->command_signature ?: ($btTask['signature'] ?? '') }}</span>
                                @foreach($btOptions as $btOption)
                                    <span class="bt-optchip" dir="ltr">{{ $btOption }}</span>
                                @endforeach
                            </span>
                        </span>

                        <span class="bt-state {{ $btTone['class'] }}">
                            <span class="bt-state__dot" aria-hidden="true"></span>{{ $btTone['label'] }}
                        </span>

                        <span class="bt-histout {{ $btStatus === 'failed' ? 'is-failed' : '' }}">{{ $btFirst === false ? '' : $btFirst }}</span>

                        <span class="bt-histdur" dir="ltr">{{ $btDuration($btLog->duration_ms) }}</span>

                        <span class="bt-by">
                            <span class="bt-by__tile {{ $btAuto ? '' : 'is-admin' }}" aria-hidden="true" dir="ltr">{{ $btInitials }}</span>
                            <span class="bt-by__name">{{ $btBy }}</span>
                        </span>
                    </div>

                    <div class="bt-erow__panel">
                        <pre class="bt-pre {{ $btStatus === 'failed' ? 'is-failed' : '' }}">{{ $btFull !== '' ? $btFull : __('No output captured.') }}</pre>
                    </div>
                </div>
            @empty
                <div class="bt-empty">
                    <span class="bt-empty__tile" aria-hidden="true"><i class="fa-solid fa-clock-rotate-left"></i></span>
                    <span class="bt-empty__title">@lang('No runs match these filters')</span>
                    <a href="{{ route('admin.background-tasks.logs') }}" class="bt-empty__action">@lang('Clear filters')</a>
                </div>
            @endforelse
        </div>
    </div>

    @if($logs->hasPages())
        <div class="bt-tablefoot">
            {{ $logs->links() }}
        </div>
    @endif
@endsection
