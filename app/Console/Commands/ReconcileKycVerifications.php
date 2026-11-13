<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\KycStatus;
use App\Enums\KycVerificationMethod;
use App\Models\KycSubmission;
use App\Services\Kyc\KycDecisionEngine;
use App\Services\Kyc\KycProviderRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ask the providers about the verifications that never came back.
 *
 * Every driver implements checkStatus() — the interface even calls it "a
 * fallback for a missed webhook" — and until now nothing called any of them.
 * An automated submission whose callback was lost, refused by a signature
 * mismatch, or delivered while the queue worker was down simply stayed
 * PENDING for good, and nobody found out except the applicant.
 *
 * Every other asynchronous provider path here already has a loop like this:
 * remittance:reconcile every five minutes, virtual-card:reconcile-topups
 * beside it. KYC was the one that did not.
 *
 * Only settles what the provider has actually decided. A verdict still in
 * progress is left as it is, and anything the provider wants a human to look
 * at stays in the reviewer's queue where it already sits.
 */
class ReconcileKycVerifications extends Command
{
    protected $signature = 'kyc:reconcile-pending
                            {--limit=100 : How many submissions to ask about in one run}
                            {--dry-run : Report what would be settled without settling it}';

    protected $description = 'Poll providers for automated KYC verdicts whose webhook never arrived';

    public function handle(KycProviderRegistry $registry, KycDecisionEngine $engine): int
    {
        $after  = (int) config('kyc.thresholds.reconcile_after_minutes', 30);
        $dryRun = (bool) $this->option('dry-run');

        /*
         * Older than the window, so a submission merely in flight is left
         * alone: the webhook is the normal path and this must not race it.
         * updated_at moves whenever the provider tells us anything, so a
         * chatty journey keeps resetting its own clock rather than being
         * polled while it is still going.
         */
        $stale = KycSubmission::query()
            ->with(['kycTemplate', 'user'])
            ->where('status', KycStatus::PENDING)
            ->where('verification_method', KycVerificationMethod::Auto)
            ->whereNotNull('provider_reference')
            ->where('provider_reference', '!=', '')
            ->where('updated_at', '<', now()->subMinutes($after))
            ->orderBy('updated_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $settled = 0;
        $waiting = 0;
        $failed  = 0;

        foreach ($stale as $submission) {
            try {
                $provider = $registry->byCode((string) $submission->provider);
                $result   = $provider->checkStatus($submission);

                if (! $result->verdict->isTerminal()) {
                    $waiting++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("would settle #{$submission->id} as {$result->verdict->value}");
                    $settled++;

                    continue;
                }

                $engine->apply($submission, $result);
                $settled++;
            } catch (Throwable $e) {
                /*
                 * One unreachable provider must not stop the run: the next
                 * submission may belong to a different one that is answering
                 * perfectly well.
                 */
                $this->error("#{$submission->id}: ".$e->getMessage());
                $failed++;
            }
        }

        $this->info(sprintf(
            '%d asked, %d %s, %d still with the provider, %d errored.',
            $stale->count(),
            $settled,
            $dryRun ? 'would settle' : 'settled',
            $waiting,
            $failed,
        ));

        return self::SUCCESS;
    }
}
