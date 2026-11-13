<?php

declare(strict_types=1);

namespace App\Services\Kyc;

use App\Models\KycDocumentHash;
use App\Models\KycSubmission;
use App\Support\KycDocumentDisk;
use Throwable;

/**
 * Turns a submission's stored documents into digests the platform can compare.
 *
 * One person opening two accounts on the same passport is the pattern this
 * exists to surface, and it is invisible from anywhere else: the documents sit
 * in a JSON blob under different file names, so two byte-identical uploads
 * look like two unrelated rows.
 *
 * The digest is taken from the file as stored rather than as uploaded. SVGs
 * are sanitised on the way in, so the two differ — and hashing what is on disk
 * is the only definition the backfill can also compute, which keeps historic
 * submissions comparable with new ones.
 */
class KycDocumentFingerprint
{
    private const ALGORITHM = 'sha256';

    /**
     * Rewrite the digests for one submission from whatever it currently holds.
     *
     * Replaces rather than appends: a resubmission after a rejection reuses
     * the same row, and a stale digest of a document that has since been
     * swapped out would go on matching forever.
     *
     * @return int the number of documents fingerprinted
     */
    public function sync(KycSubmission $submission): int
    {
        $digests = [];

        foreach ((array) ($submission->submission_data ?? []) as $label => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $hash = $this->fingerprint($value);

            if ($hash === null) {
                continue;
            }

            $digests[] = [
                'kyc_submission_id' => $submission->id,
                'user_id'           => $submission->user_id,
                'field_label'       => (string) $label,
                'hash'              => $hash,
                'created_at'        => now(),
                'updated_at'        => now(),
            ];
        }

        KycDocumentHash::query()->where('kyc_submission_id', $submission->id)->delete();

        if ($digests !== []) {
            KycDocumentHash::query()->insert($digests);
        }

        return count($digests);
    }

    /**
     * The digest of one stored file, or null when the value is not a file this
     * platform holds.
     *
     * Free-text answers share the column with document paths, so "not a file"
     * is the ordinary case rather than an error.
     */
    public function fingerprint(string $storagePath): ?string
    {
        /*
         * Either disk. Documents taken since they were made private live on
         * the `kyc` disk; everything older is still on `public`. Asking only
         * the public one would hash every new document as missing, and the
         * duplicate flag the queue leans on would quietly stop finding
         * anything.
         */
        [$disk, $storagePath] = KycDocumentDisk::locate($storagePath);

        try {
            if (! $disk->exists($storagePath)) {
                return null;
            }

            $stream = $disk->readStream($storagePath);
        } catch (Throwable) {
            return null;
        }

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $context = hash_init(self::ALGORITHM);
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
