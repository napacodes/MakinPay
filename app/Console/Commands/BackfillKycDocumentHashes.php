<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KycDocumentHash;
use App\Models\KycSubmission;
use App\Services\Kyc\KycDocumentFingerprint;
use Illuminate\Console\Command;

/**
 * Fingerprint the KYC documents submitted before the digests existed.
 *
 * The duplicate-document risk signal compares digests, so on an installation
 * that has been running for a year it reports nothing at all until the history
 * has been read once. New submissions fingerprint themselves as they arrive;
 * this is the one-off pass over everything already on disk.
 *
 * Read-only against the documents themselves — it opens each stored file to
 * digest it and writes nothing but the digest.
 */
class BackfillKycDocumentHashes extends Command
{
    protected $signature = 'kyc:backfill-document-hashes
                            {--chunk=100 : Submissions to examine per batch.}
                            {--all : Re-fingerprint submissions that already have digests.}';

    protected $description = 'Digest the stored KYC documents so duplicate uploads across accounts can be seen';

    public function handle(KycDocumentFingerprint $fingerprint): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        $query = KycSubmission::query()->orderBy('id');

        if (! $this->option('all')) {
            $query->whereNotIn(
                'id',
                KycDocumentHash::query()->select('kyc_submission_id')->distinct(),
            );
        }

        $submissions = 0;
        $documents   = 0;

        $query->chunkById($chunk, function ($rows) use ($fingerprint, &$submissions, &$documents): void {
            foreach ($rows as $submission) {
                $submissions++;
                $documents += $fingerprint->sync($submission);
            }
        });

        if ($submissions === 0) {
            $this->info('Nothing to fingerprint — every submission already has its digests.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Fingerprinted %d document(s) across %d submission(s).',
            $documents,
            $submissions,
        ));

        /*
         * A submission whose stored files have been pruned yields no digests.
         * Worth saying out loud rather than reporting a smaller number without
         * explanation.
         */
        if ($documents === 0) {
            $this->warn('No documents were readable. Check that the public disk still holds the KYC uploads.');
        }

        return self::SUCCESS;
    }
}
