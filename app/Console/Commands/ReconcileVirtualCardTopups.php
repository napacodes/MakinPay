<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VirtualCard\VirtualCardTopupDispatchState;
use App\Models\VirtualCardTopup;
use App\Services\VirtualCard\Drivers\SupportsTopUpLookup;
use App\Services\VirtualCard\VirtualCardManager;
use App\Services\VirtualCard\VirtualCardProviderFactory;
use Illuminate\Console\Command;
use Throwable;

/**
 * Finish the top-ups that did not finish themselves.
 *
 * Runs unattended every five minutes, so every action it can take has to be
 * safe to take twice: a run killed mid-transaction, a retried worker, and an
 * operator running it by hand while cron fires all put two passes over the
 * same row. It never writes directly — settle and compensate go through
 * VirtualCardManager, which guards both behind a row lock and a status check.
 *
 * What it will and will not do:
 *
 *   reserved, submitted_at null  compensate. The provider was never
 *                                contacted, so the money is provably ours to
 *                                give back. The only automatic refund here.
 *   succeeded                    settle. The provider already confirmed; only
 *                                the local write is missing.
 *   submitting / needs_review    ask the provider, if and only if the driver
 *                                implements SupportsTopUpLookup. Otherwise
 *                                leave it: refunding might double-pay a
 *                                funded card, retrying might fund it twice,
 *                                and neither is a cron job's call.
 *
 * It knows nothing about ledger rows with no attempt record. The historical
 * rows written before this existed are read-only and belong to
 * `virtual-card:report-stranded-topups`.
 */
class ReconcileVirtualCardTopups extends Command
{
    protected $signature = 'virtual-card:reconcile-topups
                            {--minutes=5 : Only consider attempts older than this, so a top-up still in flight is left alone.}
                            {--limit=200 : Maximum attempts to examine in one pass.}
                            {--dry-run : Report what would happen without changing anything.}';

    protected $description = 'Settle, compensate or flag virtual card top-ups whose provider outcome was never recorded';

    public function handle(VirtualCardManager $manager, VirtualCardProviderFactory $factory): int
    {
        $cutoff  = now()->subMinutes((int) $this->option('minutes'));
        $dryRun  = (bool) $this->option('dry-run');
        $actions = [];

        $attempts = VirtualCardTopup::query()
            ->unresolved()
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($attempts as $attempt) {
            try {
                $actions[] = $this->reconcile($attempt, $manager, $factory, $dryRun);
            } catch (Throwable $e) {
                report($e);
                $actions[] = [$attempt->id, (string) $attempt->dispatch_state, 'error', $e->getMessage()];
            }
        }

        if ($actions === []) {
            $this->components->info('No virtual card top-ups needed reconciliation.');

            return self::SUCCESS;
        }

        $this->table(['Attempt', 'State', 'Action', 'Detail'], $actions);

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: string}
     */
    private function reconcile(
        VirtualCardTopup $attempt,
        VirtualCardManager $manager,
        VirtualCardProviderFactory $factory,
        bool $dryRun,
    ): array {
        $state = $attempt->dispatchState();

        // Never contacted: submitted_at is the proof, and it is written and
        // committed before the request goes out.
        if ($state->isSafeToCompensate() && $attempt->submitted_at === null) {
            if (! $dryRun) {
                $manager->compensateTopup($attempt, __('The top-up was never sent to the provider.'));
            }

            return [$attempt->id, $state->value, 'compensated', __('Provider was never contacted.')];
        }

        if ($state === VirtualCardTopupDispatchState::Succeeded) {
            if (! $dryRun) {
                $manager->settleTopup($attempt, is_array($attempt->metadata) ? $attempt->metadata : []);
            }

            return [$attempt->id, $state->value, 'settled', __('Provider had already confirmed the funding.')];
        }

        if ($state === VirtualCardTopupDispatchState::Declined) {
            $reason = (string) ($attempt->failure_reason ?: __('The provider declined the top-up.'));

            if (! $dryRun) {
                $manager->compensateTopup($attempt, $reason);
            }

            return [$attempt->id, $state->value, 'compensated', __('Provider declined.')];
        }

        return $this->verifyWithProvider($attempt, $manager, $factory, $dryRun, $state);
    }

    /**
     * The ambiguous states. Only a conclusive answer from the provider may
     * move them; anything else leaves the attempt for a human.
     *
     * @return array{0: int, 1: string, 2: string, 3: string}
     */
    private function verifyWithProvider(
        VirtualCardTopup $attempt,
        VirtualCardManager $manager,
        VirtualCardProviderFactory $factory,
        bool $dryRun,
        VirtualCardTopupDispatchState $state,
    ): array {
        $driver = $factory->getProvider((string) $attempt->provider);

        if (! $driver instanceof SupportsTopUpLookup) {
            if (! $dryRun && $state !== VirtualCardTopupDispatchState::NeedsReview) {
                $manager->parkTopupForReview($attempt, __('The provider cannot be asked what happened to this top-up.'));
            }

            return [
                $attempt->id,
                $state->value,
                'needs_review',
                __('Driver has no lookup; not retried and not refunded.'),
            ];
        }

        $result = $driver->findTopUpByReference((string) $attempt->provider_reference, $attempt->card);
        $status = strtolower((string) ($result['status'] ?? ''));

        if (in_array($status, ['success', 'succeeded', 'completed', 'settled'], true)) {
            if (! $dryRun) {
                $manager->settleTopup($attempt, $result ?? []);
            }

            return [$attempt->id, $state->value, 'settled', __('Provider confirmed the funding.')];
        }

        if (in_array($status, ['failed', 'declined', 'cancelled', 'canceled', 'reversed'], true)) {
            if (! $dryRun) {
                $manager->compensateTopup($attempt, __('The provider reported the top-up as failed.'));
            }

            return [$attempt->id, $state->value, 'compensated', __('Provider confirmed the failure.')];
        }

        // Includes a null lookup. "No record" is not the same as "did not
        // happen" unless the provider guarantees it, and none of ours does.
        if (! $dryRun && $state !== VirtualCardTopupDispatchState::NeedsReview) {
            $manager->parkTopupForReview($attempt, __('The provider gave no conclusive answer for this top-up.'));
        }

        return [$attempt->id, $state->value, 'needs_review', __('Provider answer was inconclusive.')];
    }
}
