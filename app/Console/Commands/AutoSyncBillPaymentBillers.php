<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\BillPayment\SyncsBillers;
use App\Jobs\SyncBillPaymentBillers;
use App\Models\BillPaymentProvider;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * BP4: queue the daily catalogue sync for every provider whose admin asked
 * for one.
 *
 * A provider opts in on its screen (config.auto_sync, off by default, so an
 * upgrade syncs nothing). Each run covers the sync countries saved there and
 * every mapped category, as a real sync, never a preview; it is skipped
 * while the provider is switched off, while the breaker holds it paused, and
 * while a sync for it is already queued or running. The job it queues is
 * the one the screen queues, with `scheduled` set so the admins are told
 * what changed. What a sync writes, keeps and never deletes is decided by
 * BillerSynchronizer, exactly as for a sync queued by hand.
 */
class AutoSyncBillPaymentBillers extends Command
{
    protected $signature = 'bill-payment:auto-sync-billers';

    protected $description = 'Queue the daily biller catalogue sync for every provider that has it switched on';

    public function handle(Dispatcher $dispatcher, CacheRepository $cache): int
    {
        $drivers = (array) config('bill_payments.drivers', []);
        $queued  = 0;

        foreach (BillPaymentProvider::query()->orderBy('id')->get() as $provider) {
            $config    = is_array($provider->config) ? $provider->config : [];
            $countries = array_values(array_filter((array) ($config['sync_countries'] ?? []), 'is_string'));
            $class     = $drivers[(string) $provider->driver] ?? null;

            if (! (bool) ($config['auto_sync'] ?? false)) {
                continue;
            }

            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, SyncsBillers::class)) {
                $this->line(sprintf('%s: skipped, its driver cannot sync billers.', $provider->code));

                continue;
            }

            if (! $provider->status || $provider->isPaused()) {
                $this->line(sprintf('%s: skipped, the provider is %s.', $provider->code, $provider->isPaused() ? 'paused' : 'switched off'));

                continue;
            }

            if ($countries === []) {
                $this->line(sprintf('%s: skipped, no sync countries are saved.', $provider->code));

                continue;
            }

            $job = new SyncBillPaymentBillers((int) $provider->id, $countries, [], false, true);

            // The lock ShouldBeUnique takes: a sync already queued or running
            // for this provider is left to finish, and today's is not queued.
            if (! (new UniqueLock($cache))->acquire($job)) {
                $this->line(sprintf('%s: skipped, a sync is already queued or running.', $provider->code));

                continue;
            }

            $dispatcher->dispatch($job);
            $queued++;

            $this->info(sprintf('%s: sync queued for %s.', $provider->code, implode(', ', $countries)));
        }

        $this->info(sprintf('%d sync%s queued.', $queued, $queued === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
