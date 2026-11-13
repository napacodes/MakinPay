<?php

declare(strict_types=1);

namespace App\Services\Kyc;

use App\Enums\KycStatus;
use App\Models\KycDocumentHash;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reads the document fingerprints back.
 *
 * Every uploaded KYC document already has a one-way digest stored against it,
 * and a backfill command exists to fill them in for old submissions — but
 * nothing in the admin has ever asked the obvious question those digests were
 * written to answer: is this exact file already on file under someone else's
 * account? A reviewer approving the second one had no way to know.
 *
 * The self-join runs on the (hash, user_id) index the table was given for
 * precisely this lookup, and is issued once per page rather than once a row.
 */
class KycDuplicateDocumentFinder
{
    /**
     * Ids, among those given, whose documents also appear under another account.
     *
     * @param  Collection<int, int>|list<int>|Builder $submissionIds
     * @return Collection<int, int>
     */
    public function flagged(mixed $submissionIds): Collection
    {
        return $this->sharedHashQuery($submissionIds)
            ->distinct()
            ->pluck('kyc_document_hashes.kyc_submission_id')
            ->map(fn ($id): int => (int) $id)
            ->values();
    }

    /**
     * The same question, but carrying who the other account is and what
     * became of their submission, for the rows actually on screen.
     *
     * The digest itself is never surfaced: it answers whether two files match
     * and nothing else, and putting it on a page would invite someone to
     * treat it as an id. What is surfaced is what changes the decision — an
     * already-approved twin is a different finding from one still queued.
     *
     * @param  Collection<int, int>|list<int>                                                                                                      $submissionIds
     * @return array<int, array{username: string, field_label: string, user_id: int, submission_id: int, status: ?KycStatus, decided_at: ?Carbon}>
     */
    public function matches(mixed $submissionIds): array
    {
        $rows = $this->sharedHashQuery($submissionIds)
            ->join('users as other_user', 'other_user.id', '=', 'other.user_id')
            ->leftJoin('kyc_submissions as other_submission', 'other_submission.id', '=', 'other.kyc_submission_id')
            ->select([
                'kyc_document_hashes.kyc_submission_id as submission_id',
                'kyc_document_hashes.field_label as field_label',
                'other_user.id as user_id',
                'other_user.username as username',
                'other.kyc_submission_id as other_submission_id',
                'other_submission.status as other_status',
                'other_submission.verified_at as other_verified_at',
                'other_submission.updated_at as other_updated_at',
            ])
            ->get();

        $matches = [];

        foreach ($rows as $row) {
            // A backed int enum: the raw column comes back as "1", not as a case.
            $status = $row->other_status === null ? null : KycStatus::tryFrom((int) $row->other_status);

            $candidate = [
                'username'      => (string) $row->username,
                'field_label'   => (string) $row->field_label,
                'user_id'       => (int) $row->user_id,
                'submission_id' => (int) $row->other_submission_id,
                'status'        => $status,
                'decided_at'    => $this->decidedAt($status, $row->other_verified_at, $row->other_updated_at),
            ];

            $key = (int) $row->submission_id;

            /*
             * One finding per row, and a settled twin outranks a queued one:
             * "the same passport is already approved elsewhere" is the
             * sentence that stops a reviewer, and picking whichever match the
             * database returned first would sometimes hide it behind another
             * pending submission.
             */
            if (! isset($matches[$key]) || $this->outranks($candidate['status'], $matches[$key]['status'])) {
                $matches[$key] = $candidate;
            }
        }

        return $matches;
    }

    /**
     * When the other submission was settled, or null while it still waits.
     */
    private function decidedAt(?KycStatus $status, mixed $verifiedAt, mixed $updatedAt): ?Carbon
    {
        if ($status === null || $status === KycStatus::PENDING) {
            return null;
        }

        $stamp = $verifiedAt ?? $updatedAt;

        return $stamp === null ? null : Carbon::parse($stamp);
    }

    /**
     * Approved beats rejected beats everything unsettled.
     */
    private function outranks(?KycStatus $candidate, ?KycStatus $incumbent): bool
    {
        $rank = static fn (?KycStatus $status): int => match ($status) {
            KycStatus::APPROVED => 3,
            KycStatus::REJECTED => 2,
            KycStatus::PENDING  => 1,
            default             => 0,
        };

        return $rank($candidate) > $rank($incumbent);
    }

    /**
     * @param Collection<int, int>|list<int>|Builder $submissionIds
     */
    private function sharedHashQuery(mixed $submissionIds): Builder
    {
        return KycDocumentHash::query()
            ->join('kyc_document_hashes as other', function ($join): void {
                $join->on('other.hash', '=', 'kyc_document_hashes.hash')
                    ->whereColumn('other.user_id', '!=', 'kyc_document_hashes.user_id');
            })
            ->whereIn('kyc_document_hashes.kyc_submission_id', $submissionIds);
    }
}
