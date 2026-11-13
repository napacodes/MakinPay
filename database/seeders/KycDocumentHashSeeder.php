<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\KycDocumentHash;
use App\Models\KycSubmission;
use App\Services\Kyc\KycDocumentFingerprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Fingerprints the KYC documents an existing installation already holds.
 *
 * The duplicate-document risk signal compares digests. New submissions write
 * their own as they arrive, so a fresh install needs nothing — but on a site
 * that has been running for a year every document predates the digests, and
 * the signal would sit silently reporting nothing while the pattern it exists
 * to catch went on happening.
 *
 * Additive by construction: a submission that already has digests is skipped
 * entirely, so replaying this on every update is free after the first pass.
 *
 * Capped per run. Reading and digesting files is I/O, and an unattended update
 * on a site with a very large KYC history must not stall on it. Whatever is
 * left is picked up by the next update, or immediately by
 * `php artisan kyc:backfill-document-hashes`, which has no cap.
 */
class KycDocumentHashSeeder extends Seeder
{
    private const BUDGET = 2000;

    public function run(): void
    {
        if (! Schema::hasTable('kyc_document_hashes') || ! Schema::hasTable('kyc_submissions')) {
            return;
        }

        $fingerprint = app(KycDocumentFingerprint::class);

        KycSubmission::query()
            ->whereNotIn('id', KycDocumentHash::query()->select('kyc_submission_id')->distinct())
            ->orderByDesc('id')
            ->limit(self::BUDGET)
            ->get()
            ->each(fn (KycSubmission $submission) => $fingerprint->sync($submission));
    }
}
