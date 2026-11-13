<?php

namespace App\Http\Controllers\Backend;

use App\Http\Requests\Backend\RunBackgroundTaskRequest;
use App\Models\BackgroundTaskLog;
use App\Services\BackgroundHealthService;
use App\Services\BackgroundTaskRegistry;
use App\Services\BackgroundTaskRunner;
use App\Services\QueueManagementService;
use App\Services\Stats\BackgroundTaskStats;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BackgroundTaskController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'index|logs|scheduler' => 'background-task-list',
            'run'                  => 'background-task-run',
        ];
    }

    public function __construct(
        private BackgroundTaskRegistry $registry,
        private BackgroundTaskRunner $runner,
        private QueueManagementService $queueService,
        private BackgroundHealthService $health,
        private BackgroundTaskStats $stats,
    ) {}

    public function index(): View
    {
        $tasks    = $this->registry->all();
        $enriched = [];

        foreach ($tasks as $key => $task) {
            $enriched[$key] = array_merge($task, [
                'last_log'  => BackgroundTaskLog::query()->where('task_key', $key)->latest()->first(),
                'run_count' => BackgroundTaskLog::query()->where('task_key', $key)->count(),
            ]);
        }

        $stats = [
            'total_tasks'  => $this->registry->count(),
            'last_success' => BackgroundTaskLog::query()->where('status', 'success')->latest()->value('finished_at'),
            'last_failed'  => BackgroundTaskLog::query()->where('status', 'failed')->latest()->value('finished_at'),
            'failed_jobs'  => $this->queueService->getFailedJobsCount(),
        ];

        $recentFailedJobs = $this->queueService->getFailedJobs(5);

        $cadence = $this->scheduleCadence();

        /*
         * The setup guide that used to open the dashboard lives here now. It is
         * the same measurement the health chips read, so the banner and the
         * chips can never disagree about whether cron is running.
         */
        $backgroundHealth = $this->health->summary();

        return view('backend.background_tasks.index', compact('enriched', 'stats', 'recentFailedJobs', 'backgroundHealth', 'cadence'));
    }

    /**
     * How often each managed command is scheduled, read from the scheduler
     * itself so a cadence changed in bootstrap/app.php is reflected here
     * without a second list to keep in step.
     *
     * The schedule is registered through `Artisan::starting`, which an HTTP
     * request never fires — `Schedule::events()` is empty in the browser
     * until the console kernel has been asked for its commands. Booting it is
     * what makes this readable outside `php artisan`.
     *
     * @return array<string, string>
     */
    private function scheduleCadence(): array
    {
        app(ConsoleKernel::class)->all();

        $cadence = [];

        foreach (app(Schedule::class)->events() as $event) {
            $key = $this->registry->keyForCommand($event->command ?? '');

            if ($key === null || isset($cadence[$key])) {
                continue;
            }

            $parts = explode(' ', $event->expression);

            $cadence[$key] = match ($event->expression) {
                '* * * * *'    => __('Every minute'),
                '*/5 * * * *'  => __('Every 5 min'),
                '*/10 * * * *' => __('Every 10 min'),
                '*/15 * * * *' => __('Every 15 min'),
                '*/30 * * * *' => __('Every 30 min'),
                '0 * * * *'    => __('Hourly'),
                default        => count($parts) === 5 && ctype_digit($parts[0]) && ctype_digit($parts[1])
                    ? __('Daily :time', ['time' => sprintf('%02d:%02d', (int) $parts[1], (int) $parts[0])])
                    : __('Scheduled'),
            };
        }

        return $cadence;
    }

    public function run(RunBackgroundTaskRequest $request): RedirectResponse
    {
        $taskKey = $request->validated('task_key');
        $task    = $this->registry->get($taskKey);
        $options = [];

        if (isset($task['options'])) {
            foreach ($task['options'] as $name => $config) {
                if (($config['type'] ?? null) === 'boolean') {
                    if ($request->boolean($name, (bool) ($config['default'] ?? false))) {
                        $options[$name] = true;
                    }

                    continue;
                }

                if (($config['type'] ?? null) === 'integer') {
                    $options[$name] = (int) ($request->validated($name) ?? $config['default']);
                }
            }
        }

        try {
            $log = $this->runner->run($taskKey, $options, auth()->id());

            if ($log->isSuccess()) {
                notifyEvs('success', __('Task ":task" completed successfully.', ['task' => $task['label']]));
            } else {
                notifyEvs('error', __('Task ":task" failed. See logs for details.', ['task' => $task['label']]));
            }
        } catch (\RuntimeException $e) {
            notifyEvs('error', $e->getMessage());
        } catch (\Throwable $e) {
            notifyEvs('error', __('Task execution error: :message', ['message' => $e->getMessage()]));
        }

        /* A run writes a log row, which is what the throughput cell counts. */
        $this->stats->forget();

        return redirect()->route('admin.background-tasks.index');
    }

    public function scheduler(): View
    {
        return view('backend.background_tasks.scheduler', [
            'backgroundHealth' => $this->health->summary(),
        ]);
    }

    /**
     * Run history.
     *
     * `range` is a window; `date` is a single day. Both are honoured and a
     * caller may send either — the redesigned tab uses `range` because "the
     * last 24 hours" is not a date, and a run at 23:58 yesterday is the one
     * an operator is usually looking for.
     */
    public function logs(Request $request): View
    {
        $base = BackgroundTaskLog::query();

        if ($request->filled('task_key')) {
            $base->where('task_key', $request->input('task_key'));
        }

        if ($request->filled('date')) {
            $base->whereDate('created_at', $request->input('date'));
        }

        match ($request->input('range')) {
            '24h'   => $base->where('created_at', '>=', now()->subDay()),
            '7d'    => $base->where('created_at', '>=', now()->subDays(7)),
            default => null,
        };

        /*
         * Counted before the status filter is applied, so each segment says
         * how many runs it would show rather than how many are showing.
         * Grouped into one query rather than four.
         */
        $grouped = (clone $base)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $statusCounts = [
            'all'     => (int) $grouped->sum(),
            'success' => (int) $grouped->get('success', 0),
            'failed'  => (int) $grouped->get('failed', 0),
            'running' => (int) $grouped->get('running', 0),
        ];

        if ($request->filled('status')) {
            $base->where('status', $request->input('status'));
        }

        $logs  = $base->with('executor')->latest()->paginate(20)->withQueryString();
        $tasks = $this->registry->all();

        return view('backend.background_tasks.logs', compact('logs', 'tasks', 'statusCounts'));
    }
}
