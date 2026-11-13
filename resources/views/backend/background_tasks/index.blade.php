@extends('backend.background_tasks.layout')

@section('title', __('Background Tasks'))

@section('bt_content')
    @php
        /**
         * Tile tint and glyph per command domain, matched on the task key's
         * prefix. Keyed by prefix rather than by the full key so a command
         * added to the registry inherits its family's colour instead of
         * falling back to grey. Longer prefixes come first — remittance_alert
         * is a remittance command and would otherwise never be reached.
         */
        $btDomains = [
            'wallet_earn'           => ['bg' => 'var(--color-palette-ecfdf5)', 'fg' => 'var(--color-success-deep)', 'icon' => 'fa-chart-line'],
            'merchant_subscription' => ['bg' => 'var(--tint-teal)', 'fg' => 'var(--color-palette-0f766e)', 'icon' => 'fa-store'],
            'subscription'          => ['bg' => 'var(--tint-violet)', 'fg' => 'var(--color-violet)', 'icon' => 'fa-layer-group'],
            'p2p'                   => ['bg' => 'var(--color-palette-fff1f2)', 'fg' => 'var(--color-rose)', 'icon' => 'fa-handshake'],
            'remittance_alert'      => ['bg' => 'var(--tint-info)', 'fg' => 'var(--color-info-deep)', 'icon' => 'fa-bell'],
            'remittance'            => ['bg' => 'var(--tint-info)', 'fg' => 'var(--color-info-deep)', 'icon' => 'fa-globe'],
            'dashboard'             => ['bg' => 'var(--tint-cyan)', 'fg' => 'var(--color-palette-0891b2)', 'icon' => 'fa-list-check'],
            'currency'              => ['bg' => 'var(--tint-teal)', 'fg' => 'var(--color-palette-0f766e)', 'icon' => 'fa-right-left'],
            'money_audit'           => ['bg' => 'var(--color-palette-fef9c3)', 'fg' => 'var(--color-palette-a16207)', 'icon' => 'fa-scale-balanced'],
        ];

        $btDomainOf = function (string $key) use ($btDomains): array {
            foreach ($btDomains as $prefix => $meta) {
                if (str_starts_with($key, $prefix)) {
                    return $meta;
                }
            }

            return ['bg' => 'var(--color-surface-soft)', 'fg' => 'var(--color-text-subtle)', 'icon' => 'fa-terminal'];
        };

        /*
         * Cadence arrives from the controller. It cannot be read here: the
         * schedule registers through Artisan::starting, which an HTTP request
         * never fires, so Schedule::events() is empty in the browser even
         * though it is full under `php artisan`.
         */
        $btCadence = $cadence ?? [];

        /**
         * The four states a command can be in, and the one word each gets.
         * "Never run" is not a failure — a command with no history has simply
         * never been needed, and colouring it red sends an operator chasing
         * something that is not wrong.
         */
        $btTones = [
            'success' => ['label' => __('Success'), 'class' => 'is-success'],
            'failed'  => ['label' => __('Failed'), 'class' => 'is-failed'],
            'running' => ['label' => __('Running'), 'class' => 'is-running'],
            'never'   => ['label' => __('Never run'), 'class' => 'is-never'],
        ];

        $btDuration = function (?int $ms): ?string {
            if ($ms === null) {
                return null;
            }

            if ($ms < 1000) {
                return __(':n ms', ['n' => $ms]);
            }

            if ($ms < 60000) {
                return __(':n s', ['n' => number_format($ms / 1000, 1)]);
            }

            return __(':m m :s s', ['m' => intdiv($ms, 60000), 's' => (int) round(($ms % 60000) / 1000)]);
        };

        $btRows = collect($enriched)->map(function (array $task, string $key) use ($btDomainOf, $btCadence, $btTones): array {
            $log    = $task['last_log'] ?? null;
            $status = $log?->status ?? 'never';
            $status = array_key_exists($status, $btTones) ? $status : 'never';

            $output = match (true) {
                $log === null        => __('No runs recorded yet'),
                $status === 'failed' => (string) ($log->error_message ?: $log->output),
                default              => (string) $log->output,
            };

            $first = strtok($output, "\n");

            return [
                'key'      => $key,
                'task'     => $task,
                'domain'   => $btDomainOf($key),
                'cadence'  => $btCadence[$key] ?? null,
                'log'      => $log,
                'status'   => $status,
                'tone'     => $btTones[$status],
                'output'   => trim($first === false ? $output : $first),
                'runCount' => (int) ($task['run_count'] ?? 0),
            ];
        });

        $btCounts = [
            'all'     => $btRows->count(),
            'success' => $btRows->where('status', 'success')->count(),
            'failed'  => $btRows->where('status', 'failed')->count(),
            'never'   => $btRows->where('status', 'never')->count(),
        ];

        $btFilters = [
            'all'     => __('All'),
            'success' => __('Healthy'),
            'failed'  => __('Failing'),
            'never'   => __('Never run'),
        ];
    @endphp

    <div class="bt-toolbar" data-bt-commands>
        <span class="bt-toolbar__summary">
            <strong>{{ __(':n managed commands', ['n' => $btRows->count()]) }}</strong>
            · @lang('every manual run is recorded in Run history')
        </span>

        <div class="bt-toolbar__tools">
            <label class="bt-search">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search"
                       class="bt-search__input"
                       data-bt-search
                       placeholder="{{ __('Search commands') }}"
                       aria-label="{{ __('Search commands') }}"
                       autocomplete="off">
            </label>

            <div class="bt-filters" role="group" aria-label="{{ __('Filter by state') }}">
                @foreach($btFilters as $btFilterKey => $btFilterLabel)
                    <button type="button"
                            class="bt-filters__btn {{ $btFilterKey === 'all' ? 'is-active' : '' }}"
                            data-bt-filter="{{ $btFilterKey }}"
                            aria-pressed="{{ $btFilterKey === 'all' ? 'true' : 'false' }}">
                        {{ $btFilterLabel }}<span class="bt-filters__count" dir="ltr">{{ $btCounts[$btFilterKey] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <div class="bt-tablewrap">
        <div class="bt-grid bt-grid--commands">
            <div class="bt-grid__head">
                <span>@lang('Command')</span>
                <span>@lang('Last run')</span>
                <span>@lang('Result')</span>
                <span class="bt-grid__num">@lang('Runs')</span>
                <span></span>
            </div>

            @foreach($btRows as $btRow)
                @php
                    $btTask = $btRow['task'];
                    $btLog  = $btRow['log'];
                @endphp
                <div class="bt-row"
                     data-bt-command
                     data-status="{{ $btRow['status'] }}"
                     data-search="{{ strtolower($btTask['label'].' '.$btTask['signature'].' '.$btTask['description']) }}">

                    <div class="bt-task">
                        <span class="bt-task__tile" aria-hidden="true"
                              style="--bt-tile-bg: {{ $btRow['domain']['bg'] }}; --bt-tile-fg: {{ $btRow['domain']['fg'] }};">
                            <i class="fa-solid {{ $btRow['domain']['icon'] }}"></i>
                        </span>

                        <div class="bt-task__copy">
                            <span class="bt-task__label" title="{{ __($btTask['label']) }}">{{ __($btTask['label']) }}</span>
                            <span class="bt-task__meta">
                                {{-- Copies the whole invocation, not the bare
                                     signature: what an operator wants in their
                                     shell is a line they can paste. --}}
                                <button type="button"
                                        class="bt-sig"
                                        data-bt-copy="php artisan {{ $btTask['signature'] }}"
                                        title="{{ __('Copy signature') }}"
                                        dir="ltr">
                                    <span class="bt-sig__text">{{ $btTask['signature'] }}</span><i class="fa-solid fa-copy" aria-hidden="true"></i>
                                </button>
                                @if($btRow['cadence'])
                                    <span class="bt-task__cadence">{{ $btRow['cadence'] }}</span>
                                @endif
                            </span>
                        </div>
                    </div>

                    <div class="bt-last">
                        <span class="bt-last__ago {{ $btLog ? '' : 'is-never' }}">
                            {{ $btLog?->finished_at?->diffForHumans() ?? __('Never') }}
                        </span>
                        <span class="bt-last__at" dir="ltr">{{ $btLog?->finished_at?->format('d M H:i') ?? '—' }}</span>
                    </div>

                    <div class="bt-result">
                        <span class="bt-state {{ $btRow['tone']['class'] }}">
                            <span class="bt-state__dot" aria-hidden="true"></span>{{ $btRow['tone']['label'] }}
                        </span>
                        <span class="bt-result__text {{ $btRow['status'] === 'failed' ? 'is-failed' : '' }} {{ $btLog ? '' : 'is-never' }}"
                              title="{{ $btRow['output'] }}">{{ $btRow['output'] }}</span>
                        @if($btLog?->duration_ms !== null)
                            <span class="bt-result__dur" dir="ltr">{{ $btDuration($btLog->duration_ms) }}</span>
                        @endif
                    </div>

                    <span class="bt-runs" dir="ltr">{{ $btRow['runCount'] }}</span>

                    <div class="bt-row__actions">
                        @can('background-task-run')
                            {{-- The options payload replaces the old
                                 data-has-limit / data-has-renewals pair. Those
                                 named two of the registry's options, so the
                                 other four — dry-run, notify, csv, repeat —
                                 could not be set from this screen at all,
                                 though RunBackgroundTaskRequest has always
                                 validated them. --}}
                            <button type="button"
                                    class="bt-run"
                                    data-coreui-toggle="modal"
                                    data-coreui-target="#run-task-modal"
                                    data-task-key="{{ $btRow['key'] }}"
                                    data-task-label="{{ __($btTask['label']) }}"
                                    data-task-desc="{{ __($btTask['description']) }}"
                                    data-task-sig="{{ $btTask['signature'] }}"
                                    data-task-tile-bg="{{ $btRow['domain']['bg'] }}"
                                    data-task-tile-fg="{{ $btRow['domain']['fg'] }}"
                                    data-task-icon="{{ $btRow['domain']['icon'] }}"
                                    data-task-options="{{ json_encode(array_map(
                                        fn (string $name, array $option): array => [
                                            'name'    => $name,
                                            'type'    => $option['type'] ?? 'boolean',
                                            'label'   => __($option['label'] ?? $name),
                                            'help'    => __($option['help'] ?? ''),
                                            'default' => $option['default'] ?? null,
                                            'min'     => $option['min'] ?? null,
                                            'max'     => $option['max'] ?? null,
                                        ],
                                        array_keys($btTask['options'] ?? []),
                                        array_values($btTask['options'] ?? []),
                                    ), JSON_UNESCAPED_UNICODE) }}">
                                <i class="fa-solid fa-play" aria-hidden="true"></i>@lang('Run')
                            </button>
                        @endcan
                    </div>
                </div>
            @endforeach

            <div class="bt-grid__empty" data-bt-empty hidden>@lang('No commands match.')</div>
        </div>
    </div>
@endsection
