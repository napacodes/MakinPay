<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\MassPayment\MassPaymentBatchStatus;
use App\Enums\MassPayment\MassPaymentRowStatus;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentRow;
use App\Support\ApiMoney;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One mass payment, as its owner's app shows it: the preview's summary, the
 * progress of a payout, and the outcome.
 *
 * Identified by its uuid; the primary key never leaves the server. Every
 * figure is an ApiMoney string at the batch currency's precision, never a
 * JSON number.
 *
 * `counts.rows`, `valid`, `invalid` and `warnings` are the draft's own
 * figures. `skipped`, `pending`, `paid`, `failed` and `reviewed` are the
 * lines in each status now, so a payout can be followed while it runs; load
 * the batch through {@see self::withRowCounts()} to read them in the same
 * query as the batch. `totals.paid` and `totals.released` are written when
 * the batch finishes, and read 0 until then.
 *
 * `fingerprint` is sent only while the batch is a draft: it is what the app
 * hands back to confirm, so the service can refuse a batch that changed
 * after the customer reviewed it. `can` is a hint for which buttons to show
 * and decides nothing; the service checks again under the batch lock.
 *
 * `ledger.trx_reference` is the reference every one of the batch's debits
 * carries, which the transactions endpoints can search by. No per-row
 * transaction id appears at batch level; those are on the rows.
 *
 * Load with {@see self::RELATIONS}.
 *
 * @property-read MassPaymentBatch $resource
 */
class MassPaymentBatchResource extends JsonResource
{
    /** What toArray() reads, to eager-load on any list of batches. */
    public const RELATIONS = ['wallet:id,uuid'];

    /** The live row statuses counted alongside the batch. */
    private const COUNTED_STATUSES = [
        MassPaymentRowStatus::Skipped,
        MassPaymentRowStatus::Pending,
        MassPaymentRowStatus::Paid,
        MassPaymentRowStatus::Failed,
        MassPaymentRowStatus::Reviewed,
    ];

    /**
     * Count each live row status alongside the batches this query loads, so
     * a page of batches is two queries rather than one per batch.
     *
     * @template TQuery of Builder
     *
     * @param  TQuery $query
     * @return TQuery
     */
    public static function withRowCounts(Builder $query): Builder
    {
        $counts = [];

        foreach (self::COUNTED_STATUSES as $status) {
            $counts['rows as '.self::countAttribute($status)] = fn (Builder $rows): Builder => $rows->where('status', $status->value);
        }

        return $query->with(self::RELATIONS)->withCount($counts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $batch    = $this->resource;
        $status   = $batch->status();
        $currency = (string) $batch->currency_code;
        $isDraft  = $status === MassPaymentBatchStatus::Draft;
        $live     = $this->liveCounts();

        return [
            'id'           => $batch->uuid,
            'reference'    => $batch->reference,
            'name'         => $batch->name,
            'status'       => $status->value,
            'status_label' => $status->customerLabel(),
            'terminal'     => $status->isTerminal(),

            'wallet' => [
                'id'       => $batch->wallet?->uuid,
                'currency' => $currency,
            ],
            'currency' => $currency,
            'decimals' => ApiMoney::decimals($currency),
            'source'   => $batch->source,
            'channel'  => $batch->channel,

            'counts' => [
                'rows'     => (int) $batch->row_count,
                'valid'    => (int) $batch->valid_count,
                'invalid'  => (int) $batch->invalid_count,
                'warnings' => (int) $batch->warning_count,
                'skipped'  => $live[MassPaymentRowStatus::Skipped->value],
                'pending'  => $live[MassPaymentRowStatus::Pending->value],
                'paid'     => $live[MassPaymentRowStatus::Paid->value],
                'failed'   => $live[MassPaymentRowStatus::Failed->value],
                'reviewed' => $live[MassPaymentRowStatus::Reviewed->value],
            ],

            'totals' => [
                'amount'   => ApiMoney::amount((string) $batch->total_amount, $currency),
                'fee'      => ApiMoney::amount((string) $batch->total_fee, $currency),
                'payable'  => ApiMoney::amount((string) $batch->total_payable, $currency),
                'paid'     => ApiMoney::amount((string) $batch->paid_payable, $currency),
                'released' => ApiMoney::amount((string) $batch->released_amount, $currency),
            ],

            'fingerprint'           => $isDraft ? $batch->preview_fingerprint : null,
            'skip_invalid'          => (bool) $batch->skip_invalid,
            'warnings_acknowledged' => (bool) $batch->warnings_acknowledged,
            'stop_requested'        => $batch->stop_requested_at !== null,

            'can' => [
                'confirm' => $isDraft
                    && (int) $batch->valid_count > 0
                    && ($batch->expires_at === null || $batch->expires_at->isFuture()),
                'discard' => $isDraft,
                'stop'    => in_array($status, [MassPaymentBatchStatus::Queued, MassPaymentBatchStatus::Processing], true)
                    && $batch->stop_requested_at === null,
            ],

            'ledger' => [
                'trx_reference' => $batch->reference,
            ],

            'created_at'            => $batch->created_at?->toIso8601String(),
            'expires_at'            => $batch->expires_at?->toIso8601String(),
            'confirmed_at'          => $batch->confirmed_at?->toIso8601String(),
            'processing_started_at' => $batch->processing_started_at?->toIso8601String(),
            'last_progress_at'      => $batch->last_progress_at?->toIso8601String(),
            'completed_at'          => $batch->completed_at?->toIso8601String(),
        ];
    }

    /**
     * The lines in each live status: from the counts loaded with the batch,
     * or one grouped query when it was loaded without them.
     *
     * @return array<string, int>
     */
    private function liveCounts(): array
    {
        $batch  = $this->resource;
        $counts = [];

        $loaded = array_key_exists(self::countAttribute(MassPaymentRowStatus::Pending), $batch->getAttributes());

        $byStatus = $loaded ? null : MassPaymentRow::query()
            ->where('mass_payment_batch_id', $batch->id)
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        foreach (self::COUNTED_STATUSES as $status) {
            $counts[$status->value] = (int) ($loaded
                ? $batch->getAttribute(self::countAttribute($status))
                : ($byStatus[$status->value] ?? 0));
        }

        return $counts;
    }

    private static function countAttribute(MassPaymentRowStatus $status): string
    {
        return $status->value.'_rows_count';
    }
}
