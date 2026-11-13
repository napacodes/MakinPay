<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BillPaymentProvider;
use App\Services\BillPayment\BillPaymentNotifier;
use App\Services\BillPayment\Sync\BillerSynchronizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The admin "Sync billers" request, run on the queue: one provider, the
 * chosen countries and categories, optionally as a preview.
 *
 * One at a time per provider (unique on the provider id), tried once: a sync
 * the operator wants again is queued again from the screen, and a failed run
 * has already recorded its reason on the provider. Each country's report
 * lands on the provider's sync_state, which the provider screen shows.
 *
 * BP4: the daily automatic sync queues the same job with `scheduled` set.
 * Nobody is watching that one, so it tells the bill payment admins what it
 * changed - and only when it changed something or a country failed. A sync
 * the operator queued from the screen is read from the screen instead.
 */
class SyncBillPaymentBillers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /** A lock left by a worker that died is released after this long. */
    public int $uniqueFor = 900;

    /**
     * @param list<string> $countries     two-letter country codes
     * @param list<string> $categoryCodes bill_categories codes; empty means every mapped category
     * @param bool         $scheduled     queued by the daily sync, so the admins are told what changed
     */
    public function __construct(
        public int $providerId,
        public array $countries,
        public array $categoryCodes = [],
        public bool $dryRun = false,
        public bool $scheduled = false,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->providerId;
    }

    public function handle(BillerSynchronizer $synchronizer, BillPaymentNotifier $notifier): void
    {
        $provider = BillPaymentProvider::query()->find($this->providerId);

        if ($provider === null) {
            return;
        }

        $reports = [];

        foreach ($this->countries as $country) {
            $reports[$country] = $synchronizer->sync($provider, $country, $this->categoryCodes, $this->dryRun)->toArray();
        }

        if ($this->scheduled && ! $this->dryRun) {
            $notifier->billersSynced($provider, $reports);
        }
    }
}
