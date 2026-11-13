<?php

namespace App\Http\Controllers\Backend;

use App\Enums\KycRejectionReason;
use App\Enums\KycReviewAction;
use App\Enums\KycStatus;
use App\Enums\KycVerificationMethod;
use App\Enums\P2P\DisputeStatus;
use App\Exceptions\NotifyErrorException;
use App\Http\Requests\Backend\KycReviewActionRequest;
use App\Models\KycReviewLog;
use App\Models\KycSubmission;
use App\Models\P2P\Dispute;
use App\Models\User;
use App\Notifications\TemplateNotification;
use App\Services\FeatureManager;
use App\Services\Kyc\KycDuplicateDocumentFinder;
use App\Support\KycDocumentDisk;
use App\Support\KycQueueRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class KycController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'pending|index|export|review|document' => 'kyc-list',
            'requestAction'                        => 'kyc-action',
        ];
    }

    public function pending(Request $request, KycDuplicateDocumentFinder $duplicates)
    {
        $slaHours = $this->reviewSlaHours();

        $pending = fn (): Builder => KycSubmission::query()
            ->where('status', KycStatus::PENDING)
            ->filter($request);

        /*
         * Which submissions share a document with another account. Asked once
         * for the whole filtered queue rather than once per row, so the chip
         * can carry a real count and the rows can be filtered down to it.
         */
        $flaggedIds = $duplicates->flagged($pending()->select('id'));

        $stats  = $this->pendingStats($request);
        $triage = $this->triageBucket($request);

        $query = $pending()->with([
            'user',
            'kycTemplate',
            // The review modal shows the trail; loading it here keeps that
            // one query for the page instead of one per opened modal.
            'reviewLogs' => fn ($logs) => $logs->with('admin')->limit(10),
        ]);

        $this->applyTriage($query, $request, $flaggedIds);

        $kycPendingRequests = $query->latest()->paginate(10)->withQueryString();

        return view('backend.kyc.pending', [
            'kycPendingRequests' => $kycPendingRequests,
            'stats'              => $stats,
            'slaHours'           => $slaHours,
            'triage'             => $triage,
            'triageCounts'       => [
                'all'       => $stats['total'],
                'breaching' => $stats['breaching'],
                'flagged'   => $flaggedIds->count(),
                'approve'   => $pending()
                    ->where('verification_method', KycVerificationMethod::Auto)
                    ->where('auto_result->verdict', 'clear')
                    ->count(),
                'manual' => $pending()
                    ->where('verification_method', KycVerificationMethod::Manual)
                    ->count(),
            ],
            'duplicates' => $duplicates->matches($kycPendingRequests->pluck('id')),
        ]);
    }

    /**
     * Which triage chip is active. Anything unrecognised reads as "all", so a
     * hand-edited query string can never empty the queue silently.
     */
    private function triageBucket(Request $request): string
    {
        $bucket = (string) $request->query('triage', 'all');

        return in_array($bucket, ['breaching', 'flagged', 'approve', 'manual'], true)
            ? $bucket
            : 'all';
    }

    public function index(Request $request, KycDuplicateDocumentFinder $duplicates)
    {
        $records = fn (): Builder => KycSubmission::query()->filter($request);

        $outcome   = $this->outcomeBucket($request);
        $decidedBy = $this->decidedByBucket($request);

        $query = $this->applyRecordFilters($records(), $outcome, $decidedBy)->with([
            'user',
            'kycTemplate',
            // Only the newest entry: the relation is already ordered latest
            // first, and the column shows who settled it, not the whole trail.
            'reviewLogs' => fn ($logs) => $logs->with('admin')->limit(10),
        ]);

        $kycRequests = $query->latest()->paginate(10)->withQueryString();

        return view('backend.kyc.index', [
            'kycRequests' => $kycRequests,
            'stats'       => $this->recordStats($request),
            'outcome'     => $outcome,
            'decidedBy'   => $decidedBy,
            'duplicates'  => $duplicates->matches($kycRequests->pluck('id')),
        ]);
    }

    /**
     * The same rows the table is showing, as a CSV.
     *
     * Identity documents and their digests are deliberately absent: this is a
     * decision ledger, and an export that carried the evidence would be a
     * copy of the KYC file leaving the admin in an email attachment.
     */
    public function export(Request $request): StreamedResponse
    {
        $rows = $this->applyRecordFilters(
            KycSubmission::query()->filter($request),
            $this->outcomeBucket($request),
            $this->decidedByBucket($request),
        )->with(['user', 'kycTemplate', 'reviewLogs' => fn ($logs) => $logs->with('admin')->limit(1)]);

        $filename = 'kyc-records-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                __('Reference'), __('Applicant'), __('Username'), __('Template'),
                __('Outcome'), __('Decided by'), __('Decided at'), __('Reason'),
            ]);

            // Chunked: a compliance export spans the whole ledger, and the
            // whole ledger does not belong in memory at once.
            $rows->chunk(500, function ($chunk) use ($handle): void {
                foreach ($chunk as $submission) {
                    $decision = KycQueueRow::for($submission)->decision();

                    fputcsv($handle, [
                        $submission->id,
                        $submission->user?->name,
                        $submission->user?->username,
                        $submission->kycTemplate?->title,
                        $submission->status?->label(),
                        $decision['by']     ?? '',
                        $decision['at']     ?? '',
                        $decision['reason'] ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  Builder<KycSubmission> $query
     * @return Builder<KycSubmission>
     */
    private function applyRecordFilters(Builder $query, string $outcome, string $decidedBy): Builder
    {
        match ($outcome) {
            'approved' => $query->where('status', KycStatus::APPROVED),
            'rejected' => $query->where('status', KycStatus::REJECTED),
            'pending'  => $query->where('status', KycStatus::PENDING),
            default    => $query,
        };

        /*
         * Only a settled submission has a decider. Filtering on the method
         * alone would count everything still sitting in the queue as
         * "decided by an administrator", which is the opposite of true.
         */
        match ($decidedBy) {
            'provider' => $query->where('verification_method', KycVerificationMethod::Auto)
                ->whereNot('status', KycStatus::PENDING),
            'admin' => $query->where('verification_method', KycVerificationMethod::Manual)
                ->whereNot('status', KycStatus::PENDING),
            default => $query,
        };

        return $query;
    }

    /** Unrecognised values read as no filter, never as an empty table. */
    private function outcomeBucket(Request $request): string
    {
        $bucket = (string) $request->query('outcome', 'all');

        return in_array($bucket, ['approved', 'rejected', 'pending'], true) ? $bucket : 'all';
    }

    /** Unrecognised values read as no filter, never as an empty table. */
    private function decidedByBucket(Request $request): string
    {
        $bucket = (string) $request->query('decided_by', 'all');

        return in_array($bucket, ['provider', 'admin'], true) ? $bucket : 'all';
    }

    /**
     * Handle admin action to approve or reject a KYC submission.
     *
     * @return RedirectResponse
     *
     * @throws NotifyErrorException|Throwable
     */
    public function requestAction(KycReviewActionRequest $request)
    {
        $validated = $request->validated();

        $isApproval = $validated['action'] === 'approve';
        $reasonCode = $isApproval ? null : KycRejectionReason::from($validated['reason_code']);
        $remarks    = $validated['remarks'] ?? null;

        try {
            /*
             * Read the row, check it and write it inside one locked
             * transaction.
             *
             * The pending check used to run before the transaction opened, on
             * a row nobody held. Two reviewers with the same submission open —
             * which the queue makes easy and the prev/next walk makes easier —
             * could both pass it and both write: two decisions on one person,
             * two audit rows, and two emails, one approving and one rejecting.
             *
             * lockForUpdate makes the second request wait for the first to
             * commit. It then reads the settled status and is turned away by
             * the same check, which is now the last word rather than a guess
             * made a moment earlier.
             */
            $submission = DB::transaction(function () use ($validated, $isApproval, $reasonCode, $remarks): KycSubmission {
                $submission = KycSubmission::query()
                    ->whereKey($validated['submission_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($submission->status !== KycStatus::PENDING) {
                    throw new NotifyErrorException(__('KYC submission is already processed.'));
                }

                $previousStatus     = $submission->status;
                $submission->status = $isApproval ? KycStatus::APPROVED : KycStatus::REJECTED;
                $submission->notes  = $remarks;
                $submission->save();

                // Record the immutable audit trail entry for this admin decision.
                KycReviewLog::create([
                    'kyc_submission_id' => $submission->id,
                    'admin_id'          => auth('admin')->id(),
                    'action'            => $isApproval ? KycReviewAction::Approved : KycReviewAction::Rejected,
                    'reason_code'       => $reasonCode,
                    'from_status'       => $previousStatus,
                    'to_status'         => $submission->status,
                    'remarks'           => $remarks,
                ]);

                return $submission;
            });
        } catch (NotifyErrorException $e) {
            /*
             * "Already processed" is an answer, not a failure. Left to the
             * catch below it would be logged as an error and shown to the
             * reviewer as "Failed to update KYC submission: KYC submission is
             * already processed." — which reads like a fault in the panel
             * rather than the other reviewer having got there first.
             */
            throw $e;
        } catch (Throwable $e) {
            Log::error('Error updating KYC submission: '.$e->getMessage());
            throw new NotifyErrorException(__('Failed to update KYC submission: ').$e->getMessage());
        }

        $this->notifyUserOfDecision($submission, $isApproval, $reasonCode, $remarks);

        notifyEvs('success', __('KYC submission updated successfully.'));

        return redirect()->back();
    }

    /**
     * Stream one identity document to a reviewer who is allowed to see it.
     *
     * These files used to be written to the public disk and linked straight
     * off it, so a passport sat at /storage/files/… behind no session, no
     * permission and no owner check — anyone holding the URL had the
     * document, and URLs travel. This is the only way to one now.
     *
     * Addressed by position, never by path. An endpoint that takes a path
     * takes every path, and `permissions()` above puts this behind kyc-list,
     * so the guard is the same one that gates the queue it is opened from.
     *
     * `inline` rather than an attachment: the reviewer is looking, not
     * collecting. nosniff because these bytes are whatever an applicant
     * uploaded, and X-Robots-Tag because nothing here should ever be indexed
     * if a URL does leak into a referrer header.
     */
    public function document(KycSubmission $submission, int $document): StreamedResponse
    {
        $documents = KycQueueRow::for($submission)->documents();

        abort_if(! isset($documents[$document]), 404);

        $stored = $documents[$document]['path'];

        abort_if(! KycDocumentDisk::exists($stored), 404);

        [$disk, $path] = KycDocumentDisk::locate($stored);

        return $disk->response($path, basename($path), [
            'Content-Disposition'    => 'inline; filename="'.basename($path).'"',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag'           => 'noindex, nofollow',
            'Cache-Control'          => 'private, no-store, max-age=0',
            'Referrer-Policy'        => 'no-referrer',
        ]);
    }

    /**
     * The review workspace: one submission, full screen, with the queue it
     * came from still under the reviewer's feet.
     *
     * A modal could show the documents but not let anyone work: closing and
     * reopening it for each of twelve submissions is the whole cost of a
     * review session. This keeps the filter that was on screen, so prev and
     * next walk the same queue the reviewer was reading.
     */
    public function review(Request $request, KycSubmission $submission, KycDuplicateDocumentFinder $duplicates)
    {
        $submission->load(['user', 'kycTemplate', 'reviewLogs.admin']);

        $neighbours = $this->queueNeighbours($request, $submission);

        return view('backend.kyc.review', [
            'submission' => $submission,
            'slaHours'   => $this->reviewSlaHours(),
            'duplicate'  => $duplicates->matches([$submission->id])[$submission->id] ?? null,
            'previous'   => $neighbours['previous'],
            'next'       => $neighbours['next'],
            'position'   => $neighbours['position'],
            'queueTotal' => $neighbours['total'],
            'queryBack'  => $request->except(['page']),
            'account'    => $this->accountFacts($submission),
        ]);
    }

    /**
     * What the reviewer would otherwise open four other screens to learn.
     *
     * Every figure is a count, never a sum: wallets hold several currencies
     * and adding them together produces a number that means nothing. Each
     * one is a single aggregate against an existing relation, run once for
     * the one submission on screen.
     *
     * @return array{age: string, transactions: int, wallets: int, earlier_rejected: int, usual_country: ?string, country_matches: ?bool, disputes: ?int}
     */
    private function accountFacts(KycSubmission $submission): array
    {
        $user = $submission->user;

        if (! $user instanceof User) {
            return [
                'age'           => '—', 'transactions' => 0, 'wallets' => 0, 'earlier_rejected' => 0,
                'usual_country' => null, 'country_matches' => null, 'disputes' => null,
            ];
        }

        $usual = $user->loginActivities()
            ->where('succeeded', true)
            ->whereNotNull('country')
            ->selectRaw('country, COUNT(*) as total')
            ->groupBy('country')
            ->orderByDesc('total')
            ->value('country');

        return [
            'age'          => KycQueueRow::humanDuration($user->created_at),
            'transactions' => $user->transactions()->count(),
            'wallets'      => $user->wallets()->count(),
            /*
             * A second rejected attempt is the fact that changes how this
             * one reads, so the one being reviewed is excluded from it.
             */
            'earlier_rejected' => KycSubmission::query()
                ->where('user_id', $user->id)
                ->where('status', KycStatus::REJECTED)
                ->whereKeyNot($submission->id)
                ->count(),
            'usual_country' => $usual,
            /*
             * Null means unanswerable — no sign-in history, or no country on
             * the account — which is different from a mismatch and must not
             * be drawn as one.
             */
            'country_matches' => ($usual === null || blank($user->country))
                ? null
                : strcasecmp((string) $usual, (string) $user->country) === 0,
            /*
             * Null where the marketplace is switched off: "None" would be a
             * claim about a feature this installation does not run.
             */
            'disputes' => app(FeatureManager::class)->isEnabled('p2p_marketplace')
                ? Dispute::query()->where('raised_by', $user->id)->where('status', DisputeStatus::OPEN)->count()
                : null,
        ];
    }

    /**
     * The submissions either side of this one, in the queue as filtered.
     *
     * Keyset, not offset: the queue is ordered by created_at then id, so the
     * neighbour is found with a compound comparison rather than by loading
     * every id in the queue to find a position in the list.
     *
     * @return array{previous: ?KycSubmission, next: ?KycSubmission, position: int, total: int}
     */
    private function queueNeighbours(Request $request, KycSubmission $submission): array
    {
        $queue = fn (): Builder => $this->applyTriage(
            KycSubmission::query()->where('status', KycStatus::PENDING)->filter($request),
            $request
        );

        /*
         * created_at is nullable on this table, and KycQueueRow::waiting()
         * already copes with a row that has none. Without the same guard
         * here the comparison below is built against null and the whole
         * review page 500s for that one submission.
         */
        if ($submission->created_at === null) {
            return [
                'previous' => $queue()->where('id', '>', $submission->id)->orderBy('id')->first(),
                'next'     => $queue()->where('id', '<', $submission->id)->orderByDesc('id')->first(),
                'position' => $queue()->where('id', '>', $submission->id)->count() + 1,
                'total'    => $queue()->count(),
            ];
        }

        // Newer than this one, so earlier in a latest-first queue.
        $previous = $queue()
            ->where(fn (Builder $q) => $q
                ->where('created_at', '>', $submission->created_at)
                ->orWhere(fn (Builder $tie) => $tie
                    ->where('created_at', $submission->created_at)
                    ->where('id', '>', $submission->id)))
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        $next = $queue()
            ->where(fn (Builder $q) => $q
                ->where('created_at', '<', $submission->created_at)
                ->orWhere(fn (Builder $tie) => $tie
                    ->where('created_at', $submission->created_at)
                    ->where('id', '<', $submission->id)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $ahead = $queue()
            ->where(fn (Builder $q) => $q
                ->where('created_at', '>', $submission->created_at)
                ->orWhere(fn (Builder $tie) => $tie
                    ->where('created_at', $submission->created_at)
                    ->where('id', '>', $submission->id)))
            ->count();

        return [
            'previous' => $previous,
            'next'     => $next,
            'position' => $ahead + 1,
            'total'    => $queue()->count(),
        ];
    }

    /**
     * The triage chip, applied. Shared by the queue and the workspace so
     * prev/next can never walk outside the filter they were opened from.
     *
     * @param  Builder<KycSubmission> $query
     * @return Builder<KycSubmission>
     */
    private function applyTriage(Builder $query, Request $request, ?Collection $flaggedIds = null): Builder
    {
        match ($this->triageBucket($request)) {
            'breaching' => $query->where('created_at', '<', now()->subHours($this->reviewSlaHours())),
            'flagged'   => $query->whereIn('id', $flaggedIds ?? app(KycDuplicateDocumentFinder::class)->flagged(
                KycSubmission::query()->where('status', KycStatus::PENDING)->select('id')
            )),
            'approve' => $query->where('verification_method', KycVerificationMethod::Auto)
                ->where('auto_result->verdict', 'clear'),
            'manual' => $query->where('verification_method', KycVerificationMethod::Manual),
            default  => $query,
        };

        return $query;
    }

    /**
     * Whole-queue figures for the pending header strip.
     *
     * Counted under the same filters the table is showing but ahead of the
     * paginator, so page two reports the same totals as page one. The strip
     * used to count the ten rows it happened to have in hand, and its labels
     * admitted it — "Pending on page" is a defect wearing a caption.
     *
     * @return array{total: int, breaching: int, today: int, daily: list<int>, oldest: ?Carbon, sla_hours: int}
     */
    private function pendingStats(Request $request): array
    {
        $pending = fn (): Builder => KycSubmission::query()
            ->where('status', KycStatus::PENDING)
            ->filter($request);

        $arrivals = fn (): Builder => KycSubmission::query()->filter($request);
        $slaHours = $this->reviewSlaHours();
        $oldest   = $pending()->min('created_at');

        return [
            'total'     => $pending()->count(),
            'breaching' => $pending()->where('created_at', '<', now()->subHours($slaHours))->count(),
            /*
             * Arrivals, not pending arrivals. A submission that came in this
             * morning and was auto-approved by lunchtime was still submitted
             * today, and counting only the ones still waiting would make the
             * sparkline beside it slope downwards for no reason: older days
             * have had longer to be decided.
             */
            'today'     => $arrivals()->where('created_at', '>=', now()->startOfDay())->count(),
            'daily'     => $this->dailyArrivals($request),
            'oldest'    => $oldest ? Carbon::parse($oldest) : null,
            'sla_hours' => $slaHours,
        ];
    }

    /**
     * Submissions received on each of the last fourteen days.
     *
     * One grouped query, and the gaps are filled here rather than left out —
     * a sparkline that silently skips quiet days draws a busier week than
     * the one that happened.
     *
     * @return list<int>
     */
    private function dailyArrivals(Request $request): array
    {
        $from = now()->subDays(13)->startOfDay();

        $counts = KycSubmission::query()
            ->filter($request)
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $series = [];

        for ($day = 0; $day < 14; $day++) {
            $date     = $from->copy()->addDays($day)->toDateString();
            $series[] = (int) ($counts[$date] ?? 0);
        }

        return $series;
    }

    /**
     * Whole-ledger figures for the records header strip, under the same
     * filters as the table and likewise ahead of the paginator.
     *
     * @return array{total: int, approved: int, rejected: int, pending: int, provider_decided: int, top_rejection_reason: ?KycRejectionReason}
     */
    private function recordStats(Request $request): array
    {
        $all = fn (): Builder => KycSubmission::query()->filter($request);

        return [
            'total'                => $all()->count(),
            'approved'             => $all()->where('status', KycStatus::APPROVED)->count(),
            'rejected'             => $all()->where('status', KycStatus::REJECTED)->count(),
            'pending'              => $all()->where('status', KycStatus::PENDING)->count(),
            'top_rejection_reason' => $this->topRejectionReason($request),
            'provider_decided'     => $all()
                ->where('verification_method', KycVerificationMethod::Auto)
                ->whereIn('status', [KycStatus::APPROVED, KycStatus::REJECTED])
                ->count(),
        ];
    }

    /**
     * The reason rejections are given most often, under the current filter.
     *
     * Null is a real answer and not an error: reason codes only became
     * mandatory when the enum landed, so a ledger of older decisions has
     * nothing to count and the cell renders without a note rather than
     * guessing at one from free-text remarks.
     */
    private function topRejectionReason(Request $request): ?KycRejectionReason
    {
        $code = KycReviewLog::query()
            ->whereIn(
                'kyc_submission_id',
                KycSubmission::query()->filter($request)->where('status', KycStatus::REJECTED)->select('id')
            )
            ->whereNotNull('reason_code')
            ->selectRaw('reason_code, COUNT(*) as total')
            ->groupBy('reason_code')
            ->orderByDesc('total')
            ->value('reason_code');

        // value() on an Eloquent query applies the cast, so the column comes
        // back as the enum on a model query and as a string on a raw one.
        return match (true) {
            $code instanceof KycRejectionReason => $code,
            is_string($code)                    => KycRejectionReason::tryFrom($code),
            default                             => null,
        };
    }

    /**
     * Hours a submission may wait before the queue reads it as late.
     *
     * A reading, not a policy: nothing is decided when it elapses, the row
     * only sorts and tints as overdue. Floored at one hour so a misconfigured
     * zero cannot paint the entire queue as breaching.
     */
    private function reviewSlaHours(): int
    {
        return max(1, (int) config('kyc.thresholds.review_sla_hours', 48));
    }

    /**
     * Notify the user about an approval/rejection as a best-effort side effect.
     *
     * The decision is already committed; a missing or misconfigured
     * notification template must never roll back a completed KYC review.
     */
    private function notifyUserOfDecision(
        KycSubmission $submission,
        bool $isApproval,
        ?KycRejectionReason $reasonCode,
        ?string $remarks,
    ): void {
        try {
            if ($isApproval) {
                $submission->user->notify(new TemplateNotification(
                    identifier: 'kyc_user_notify_approved',
                    data: [
                        'kyc_type' => $submission->kycTemplate->title,
                    ],
                    action: route('user.settings.kyc.verify'),
                ));

                return;
            }

            /*
             * The template has always interpolated a rejection reason. Before
             * the reason code existed it could be handed null, which reached
             * the applicant as an empty sentence. The code is the part that is
             * always present; the remarks refine it when the reviewer wrote any.
             */
            $reason = $reasonCode?->label() ?? __('Your submission could not be verified.');

            if (filled($remarks)) {
                $reason .= ' — '.$remarks;
            }

            $submission->user->notify(new TemplateNotification(
                identifier: 'kyc_user_notify_rejected',
                data: [
                    'kyc_type'         => $submission->kycTemplate->title,
                    'rejection_reason' => $reason,
                ],
            ));
        } catch (Throwable $e) {
            Log::warning('KYC decision notification failed: '.$e->getMessage());
        }
    }
}
