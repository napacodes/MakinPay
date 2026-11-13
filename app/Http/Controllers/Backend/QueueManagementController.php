<?php

namespace App\Http\Controllers\Backend;

use App\Services\QueueManagementService;
use App\Services\Stats\BackgroundTaskStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class QueueManagementController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'failed|retry|retryAll|forget|flush|flushUnrestorable' => 'queue-manage',
        ];
    }

    public function __construct(
        private QueueManagementService $service,
        private BackgroundTaskStats $stats,
    ) {}

    public function failed(): View
    {
        $failedJobs = $this->service->getFailedJobs(20);
        $count      = $this->service->getFailedJobsCount();

        return view('backend.background_tasks.queue', compact('failedJobs', 'count'));
    }

    public function retry(string $id): RedirectResponse
    {
        try {
            $this->service->retryJob($id);
            notifyEvs('success', __('Job #:id has been queued for retry.', ['id' => $id]));
        } catch (\Throwable $e) {
            notifyEvs('error', __('Failed to retry job: :message', ['message' => $e->getMessage()]));
        }

        $this->stats->forget();

        return redirect()->back();
    }

    /**
     * Retry every failed job, and say plainly what happened to each group.
     *
     * A job whose model has been deleted can never succeed, and there is no
     * point announcing "all jobs queued" over the top of it. Naming the two
     * counts is also what tells an administrator that the remaining rows are
     * not a queue problem at all — they are records that have gone.
     */
    public function retryAll(): RedirectResponse
    {
        try {
            ['retried' => $retried, 'skipped' => $skipped] = $this->service->retryAll();

            match (true) {
                $retried === 0 && $skipped === 0 => notifyEvs('success', __('There were no failed jobs to retry.')),
                $skipped === 0                   => notifyEvs('success', __(':n failed jobs have been queued for retry.', ['n' => number_format($retried)])),
                $retried === 0                   => notifyEvs('warning', __('None of the :n failed jobs could be retried: each one works on a record that no longer exists. Delete them instead.', ['n' => number_format($skipped)])),
                default                          => notifyEvs('warning', __(':retried failed jobs queued for retry. :skipped skipped — they work on records that no longer exist.', [
                    'retried' => number_format($retried),
                    'skipped' => number_format($skipped),
                ])),
            };
        } catch (\Throwable $e) {
            notifyEvs('error', __('Failed to retry all jobs: :message', ['message' => $e->getMessage()]));
        }

        $this->stats->forget();

        return redirect()->back();
    }

    public function forget(string $id): RedirectResponse
    {
        try {
            $this->service->forgetJob($id);
            notifyEvs('success', __('Failed job #:id has been deleted.', ['id' => $id]));
        } catch (\Throwable $e) {
            notifyEvs('error', __('Failed to delete job: :message', ['message' => $e->getMessage()]));
        }

        $this->stats->forget();

        return redirect()->back();
    }

    /**
     * Delete the jobs that can never succeed, and keep the ones that can.
     *
     * Reported as two counts for the same reason the retry is: what is left
     * on the list afterwards is the part an operator still has a decision to
     * make about.
     */
    public function flushUnrestorable(): RedirectResponse
    {
        try {
            ['deleted' => $deleted, 'kept' => $kept] = $this->service->flushUnrestorable();

            match (true) {
                $deleted === 0 => notifyEvs('success', __('Nothing to clear — every failed job still has the record it works on.')),
                default        => notifyEvs('success', __(':deleted failed jobs deleted, because the records they work on are gone. :kept left to retry.', [
                    'deleted' => number_format($deleted),
                    'kept'    => number_format($kept),
                ])),
            };
        } catch (\Throwable $e) {
            notifyEvs('error', __('Failed to clear unretryable jobs: :message', ['message' => $e->getMessage()]));
        }

        $this->stats->forget();

        return redirect()->back();
    }

    public function flush(): RedirectResponse
    {
        try {
            $this->service->flushAll();
            notifyEvs('success', __('All failed jobs have been flushed.'));
        } catch (\Throwable $e) {
            notifyEvs('error', __('Failed to flush jobs: :message', ['message' => $e->getMessage()]));
        }

        $this->stats->forget();

        return redirect()->back();
    }
}
