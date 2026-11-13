@extends('backend.layouts.app')

@push('styles')
    <link rel="stylesheet" href="{{ asset('backend/css/background-tasks.css?v=' . config('app.version') . '-' . filemtime(public_path('backend/css/background-tasks.css'))) }}">
@endpush

@php
    use App\Services\BackgroundHealthService;
    use App\Services\BackgroundTaskRegistry;
    use App\Services\Stats\BackgroundTaskStats;
    use Illuminate\Support\Facades\Gate;

    /*
     * The header belongs to the module, not to a tab: it renders on all four
     * and only two of the controllers pass a health summary. One service
     * measures once, caches for a minute and answers every figure on this
     * screen — the pill, the four cells and the attention bar — so no two of
     * them can disagree about the same queue.
     */
    $btStats = app(BackgroundTaskStats::class);

    $taskStats          = $btStats->cells();
    $workerStatus       = $btStats->status();
    $btFailedCount      = $btStats->failedCount();
    $btNeedsAttention   = $btStats->needsAttention();
    $btSchedulerStalled = $btStats->schedulerStalled();

    $btCanListTasks   = Gate::allows('background-task-list');
    $btCanRunTasks    = Gate::allows('background-task-run');
    $btCanManageQueue = Gate::allows('queue-manage');

    /*
     * The run dialog used to live on Commands, where every trigger was a row
     * and carried its own task. Opened from the header it has no row, so the
     * registry comes with it and the dialog offers the same list to pick
     * from — the same keys, labels and options the table renders.
     */
    $btRunnableTasks = $btCanRunTasks
        ? collect(app(BackgroundTaskRegistry::class)->all())
            ->map(fn (array $task, string $key): array => [
                'key'     => $key,
                'label'   => __($task['label']),
                'desc'    => __($task['description'] ?? ''),
                'sig'     => $task['signature'],
                /* The same payload the Commands rows hand the dialog, so a
                   command picked here offers the same switches as one run
                   from its row. */
                'options' => array_map(
                    fn (string $name, array $option): array => [
                        'name'    => $name,
                        'type'    => $option['type'] ?? 'boolean',
                        'label'   => __($option['label'] ?? $name),
                        'help'    => __($option['help'] ?? ''),
                        'default' => $option['default'] ?? null,
                        'min'     => $option['min'] ?? null,
                        'max'     => $option['max'] ?? null,
                    ],
                    array_keys($task['options'] ?? []),
                    array_values($task['options'] ?? []),
                ),
            ])
            ->values()
            ->all()
        : [];
@endphp

@section('content')
<div class="bt-page">

    <x-page-header
        :eyebrow="__('Operations')"
        :title="__('Background tasks')"
        icon="apps-1"
        tile="soft"
        :subtitle="__('Queues, scheduled jobs and workers that run behind the platform.')"
        :status="$workerStatus"
        :breadcrumbs="[['label' => __('Platform')], ['label' => __('Operations')], ['label' => __('Background tasks')]]"
        :stats="$taskStats"
    >
        @if($btCanRunTasks || $btCanManageQueue)
            <x-slot:actions>
                @if($btCanRunTasks)
                    <button type="button"
                            class="ph-btn"
                            data-coreui-toggle="modal"
                            data-coreui-target="#run-task-modal">
                        <x-icon name="check" width="14" height="14"/>{{ __('Run task') }}
                    </button>
                @endif

                @if($btCanManageQueue)
                    {{-- The form is below the header, not around this button:
                         retry-all is a POST, and a form nested in the actions
                         row would be a flex item the header's sheet knows
                         nothing about. `form=` is what HTML has for this. --}}
                    <button type="submit"
                            form="bt-retry-failed"
                            class="ph-btn ph-btn--primary"
                            @disabled($btFailedCount === 0)>
                        <x-icon name="apps-1" width="14" height="14"/>{{ __('Retry failed') }}
                    </button>
                @endif
            </x-slot:actions>
        @endif

        @if($btCanListTasks || ($btCanManageQueue && $btFailedCount > 0))
            <x-slot:menu>
                @if($btCanListTasks)
                    <a class="dropdown-item" href="{{ route('admin.background-tasks.scheduler') }}">
                        <span class="ph-menu__tile" aria-hidden="true"><x-icon name="blog" width="15" height="15"/></span>
                        <span class="ph-menu__copy">
                            <span class="ph-menu__label">{{ __('Schedule overview') }}</span>
                            <span class="ph-menu__hint">{{ __('Cron timeline for the next 24h') }}</span>
                        </span>
                    </a>

                    <a class="dropdown-item" href="{{ route('admin.background-tasks.logs') }}">
                        <span class="ph-menu__tile" aria-hidden="true"><x-icon name="layer" width="15" height="15"/></span>
                        <span class="ph-menu__copy">
                            <span class="ph-menu__label">{{ __('Worker logs') }}</span>
                            <span class="ph-menu__hint">{{ __('Every run this module recorded') }}</span>
                        </span>
                    </a>
                @endif

                {{-- The destructive path is the only thing in here, and it is
                     hidden outright when there is nothing to destroy. --}}
                @if($btCanManageQueue && $btFailedCount > 0)
                    @if($btCanListTasks)
                        <div class="dropdown-divider"></div>
                    @endif

                    {{-- The gentler destructive path first: a job whose record
                         has been deleted can never succeed, and clearing only
                         those leaves every retryable job on the list. Without
                         it the one way to be rid of 331 dead notifications was
                         a Flush that took 264 good jobs with them. --}}
                    <button type="button"
                            class="dropdown-item is-warning"
                            data-coreui-toggle="modal"
                            data-coreui-target="#bt-confirm-modal"
                            data-bt-confirm
                            data-action="{{ route('admin.queue.flush-unrestorable') }}"
                            data-method="POST"
                            data-title="{{ __('Delete the jobs whose records are gone?') }}"
                            data-body="{{ __('These work on records that no longer exist, so retrying them can never succeed. Every job that can still be retried stays on the list. This cannot be undone.') }}"
                            data-cta="{{ __('Delete them') }}">
                        <span class="ph-menu__tile" aria-hidden="true"><x-icon name="shield-ban" width="15" height="15"/></span>
                        <span class="ph-menu__copy">
                            <span class="ph-menu__label">{{ __('Delete unretryable jobs…') }}</span>
                            <span class="ph-menu__hint">{{ __('Their records are gone; the rest stay') }}</span>
                        </span>
                    </button>

                    <button type="button"
                            class="dropdown-item is-danger"
                            data-coreui-toggle="modal"
                            data-coreui-target="#bt-confirm-modal"
                            data-bt-confirm
                            data-action="{{ route('admin.queue.flush') }}"
                            data-method="POST"
                            data-title="{{ __('Flush all :n failed jobs?', ['n' => number_format($btFailedCount)]) }}"
                            data-body="{{ __('This permanently deletes every failed job. It cannot be undone.') }}"
                            data-cta="{{ __('Flush all') }}">
                        <span class="ph-menu__tile" aria-hidden="true"><x-icon name="trash" width="15" height="15"/></span>
                        <span class="ph-menu__copy">
                            <span class="ph-menu__label">{{ __('Flush failed jobs…') }}</span>
                            <span class="ph-menu__hint">{{ __('Removes :n failed entries permanently', ['n' => number_format($btFailedCount)]) }}</span>
                        </span>
                    </button>
                @endif
            </x-slot:menu>
        @endif
    </x-page-header>

    @if($btCanManageQueue)
        <form method="POST" action="{{ route('admin.queue.retry-all') }}" id="bt-retry-failed" hidden>@csrf</form>
    @endif

    @if($btNeedsAttention)
        <div class="bt-attention">
            <span class="bt-attention__tile" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
            <span class="bt-attention__text">
                @if($btFailedCount > 0)
                    <strong>{{ __(':n queue jobs failed', ['n' => number_format($btFailedCount)]) }}</strong>
                    — @lang('Retry once the cause is fixed — the exception is shown on each job.')
                @elseif($btSchedulerStalled)
                    <strong>@lang('Scheduler stalled')</strong>
                    {{-- The window is the service's own, so the bar can never
                         quote a number the measurement does not use. --}}
                    — {{ __('No scheduled run in the last :n minutes. Check the cron entry.', ['n' => BackgroundHealthService::SCHEDULER_GRACE_MINUTES]) }}
                @else
                    <strong>@lang('Queue not draining')</strong>
                    — @lang('Jobs are waiting longer than the worker should take. Check that the worker is running.')
                @endif
            </span>

            @if($btFailedCount > 0)
                @if($btCanManageQueue)
                    <a href="{{ route('admin.queue.failed') }}" class="bt-attention__cta">
                        @lang('Review failed jobs')<i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                    </a>
                @endif
            @else
                @if($btCanListTasks)
                    <a href="{{ route('admin.background-tasks.scheduler') }}" class="bt-attention__cta">
                        @lang('Open setup')<i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                    </a>
                @endif
            @endif
        </div>
    @endif

    <section class="bt-panel">
        <div class="bt-tabs" role="tablist">
            @can('background-task-list')
                <a class="bt-tab {{ isActive('admin.background-tasks.index') ? 'is-active' : '' }}"
                   role="tab"
                   aria-selected="{{ isActive('admin.background-tasks.index') ? 'true' : 'false' }}"
                   href="{{ route('admin.background-tasks.index') }}">
                    <i class="fa-solid fa-gears bt-tab__icon" aria-hidden="true"></i>@lang('Commands')
                </a>

                <a class="bt-tab {{ isActive('admin.background-tasks.logs') ? 'is-active' : '' }}"
                   role="tab"
                   aria-selected="{{ isActive('admin.background-tasks.logs') ? 'true' : 'false' }}"
                   href="{{ route('admin.background-tasks.logs') }}">
                    <i class="fa-solid fa-clock-rotate-left bt-tab__icon" aria-hidden="true"></i>@lang('Run history')
                </a>
            @endcan

            @can('queue-manage')
                <a class="bt-tab {{ isActive('admin.queue.failed') ? 'is-active' : '' }}"
                   role="tab"
                   aria-selected="{{ isActive('admin.queue.failed') ? 'true' : 'false' }}"
                   href="{{ route('admin.queue.failed') }}">
                    <i class="fa-solid fa-triangle-exclamation bt-tab__icon" aria-hidden="true"></i>@lang('Failed jobs')
                    @if($btFailedCount > 0)
                        <span class="bt-tab__badge" dir="ltr">{{ number_format($btFailedCount) }}</span>
                    @endif
                </a>
            @endcan

            @can('background-task-list')
                <a class="bt-tab {{ isActive('admin.background-tasks.scheduler') ? 'is-active' : '' }}"
                   role="tab"
                   aria-selected="{{ isActive('admin.background-tasks.scheduler') ? 'true' : 'false' }}"
                   href="{{ route('admin.background-tasks.scheduler') }}">
                    <i class="fa-solid fa-wrench bt-tab__icon" aria-hidden="true"></i>@lang('Setup')
                </a>
            @endcan
        </div>

        @yield('bt_content')
    </section>

    {{-- Both dialogs used to sit inside a single tab, because a single tab
         was the only place that opened them. The header opens them from all
         four, so they live with the header — and inside `.bt-page`, which is
         what every `.bt-dialog` rule is scoped under. --}}
    @if($btCanRunTasks)
        @include('backend.background_tasks.partials._run_modal', ['tasks' => $btRunnableTasks])
    @endif

    @if($btCanManageQueue && $btFailedCount > 0)
        @include('backend.background_tasks.partials._confirm_modal')
    @endif
</div>

{{-- One toast for the whole module: copy, run, retry, delete and flush all
     report through it. The page scripts toggle `hidden` on this node. --}}
<div class="bt-toast" id="bt-toast" role="status" aria-live="polite" hidden>
    <span class="bt-toast__icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
    <span data-bt-toast-text>@lang('Copied to clipboard')</span>
</div>
@endsection

@push('scripts')
    @include('backend.background_tasks.partials._index_scripts')
@endpush
