<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class QueueManagementService
{
    public function getFailedJobs(int $perPage = 20): LengthAwarePaginator
    {
        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->paginate($perPage);
    }

    public function getFailedJobsCount(): int
    {
        return DB::table('failed_jobs')->count();
    }

    /**
     * `$id` is whatever the configured failer looks up — the uuid under the
     * default database-uuids driver, the row id under the plain database one.
     *
     * queue:retry reports a miss on its output and still exits 0, so a retry
     * of an id the failer cannot find — a numeric id under database-uuids, or
     * a job somebody else has just flushed — was announced as queued while
     * nothing had moved.
     *
     * `queue:retry` unserialises the payload before pushing it, only to read
     * the job's `retryUntil()`. A job that carries a model through
     * SerializesModels restores it with `firstOrFail()` at that moment, so
     * one whose record has since been deleted throws
     * ModelNotFoundException out of the command — which reached the
     * administrator as "No query results for model [App\Models\GiftCard]".
     *
     * @throws \RuntimeException when no failed job carries that id, or when
     *                           its payload can no longer be restored
     */
    public function retryJob(string $id): void
    {
        try {
            Artisan::call('queue:retry', ['id' => [$id]]);
        } catch (ModelNotFoundException) {
            throw new \RuntimeException(__('This job works on a record that no longer exists, so it can never succeed. Delete it instead of retrying it.'));
        }

        if (str_contains(Artisan::output(), 'Unable to find failed job')) {
            throw new \RuntimeException(__('No failed job matches id :id.', ['id' => $id]));
        }
    }

    /**
     * Retry every failed job, one at a time, and say how many could not be.
     *
     * `queue:retry all` takes them in one loop with nothing between the jobs,
     * so the first payload that cannot be restored throws and every job after
     * it is left where it was. The failer hands them back newest first, which
     * on the installation this was found on put an undeliverable gift-card
     * mail at the head of 595 rows: not one of them was ever retried, and the
     * screen said so in the language of a model class.
     *
     * Retrying them individually costs one command run per job and buys the
     * thing that matters — a dead payload can no longer decide the fate of
     * the 264 behind it.
     *
     * @return array{retried: int, skipped: int}
     */
    public function retryAll(): array
    {
        $retried = 0;
        $skipped = 0;

        foreach ($this->failedJobIds() as $id) {
            try {
                $this->retryJob($id);
                $retried++;
            } catch (\Throwable) {
                /*
                 * Left in the table on purpose. A job that cannot be retried
                 * is still evidence, and deleting it is the operator's call
                 * — it is what the ⋯ menu's Flush is for.
                 */
                $skipped++;
            }
        }

        return ['retried' => $retried, 'skipped' => $skipped];
    }

    /**
     * Ids as the configured failer reports them: uuids under database-uuids,
     * row ids under the plain database driver.
     *
     * Read once, up front, because a successful retry removes its row and the
     * list would shift underneath the loop.
     *
     * @return list<string>
     */
    private function failedJobIds(): array
    {
        return array_map(
            static fn (object $job): string => (string) $job->id,
            app('queue.failer')->all()
        );
    }

    /**
     * Delete only the failed jobs that can never be retried.
     *
     * The blunt Flush takes the whole table, which on the installation this
     * was written for would have thrown away 264 jobs that were still good
     * to get rid of 331 that were not. This deletes exactly the ones whose
     * payload can no longer be restored and leaves the rest on the list.
     *
     * @return array{deleted: int, kept: int}
     */
    public function flushUnrestorable(): array
    {
        $deleted = 0;
        $kept    = 0;

        foreach (app('queue.failer')->all() as $job) {
            if ($this->payloadRestores((string) $job->payload)) {
                $kept++;

                continue;
            }

            try {
                $this->forgetJob((string) $job->id);
                $deleted++;
            } catch (\Throwable) {
                /* Somebody else got there first, or the failer lost it. */
                $kept++;
            }
        }

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    /**
     * Whether `queue:retry` would be able to restore this payload.
     *
     * Deliberately a mirror of RetryCommand::refreshRetryUntil — the restore
     * happens there and nowhere else, so that is the only definition of
     * "retryable" worth holding. A class the application no longer ships
     * unserialises to __PHP_Incomplete_Class without throwing, and the
     * command copes with that, so it is not counted here either.
     */
    private function payloadRestores(string $payload): bool
    {
        $command = json_decode($payload, true)['data']['command'] ?? null;

        if (! is_string($command)) {
            return true;
        }

        try {
            if (str_starts_with($command, 'O:')) {
                unserialize($command);
            } elseif (app()->bound(Encrypter::class)) {
                unserialize(app(Encrypter::class)->decrypt($command));
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @throws \RuntimeException when no failed job carries that id
     */
    public function forgetJob(string $id): void
    {
        if (Artisan::call('queue:forget', ['id' => $id]) !== 0) {
            throw new \RuntimeException(__('No failed job matches id :id.', ['id' => $id]));
        }
    }

    public function flushAll(): void
    {
        Artisan::call('queue:flush');
    }
}
