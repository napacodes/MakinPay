<?php

namespace App\Services\MassPayment\Concerns;

use App\Enums\MassPayment\MassPaymentBatchStatus;
use App\Enums\MassPayment\MassPaymentKind;
use App\Enums\MassPayment\MassPaymentRowStatus;
use App\Exceptions\MassPaymentNotConfirmableException;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentEvent;
use App\Models\MassPaymentRow;
use App\Models\User;
use App\Services\MassPayment\ConfirmedMassPayment;
use App\Services\MassPayment\MassPaymentActor;
use App\Services\MassPayment\MassPaymentConfirmation;
use App\Support\DecimalMoney;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The draft and confirm steps a wallet batch and a payout batch share
 * word for word: expiry, the replay answer, the preview match, the totals,
 * the audit trail and the upload's file name. Kept in one place so a fix to
 * one kind cannot miss the other. The using class names its kind through
 * {@see self::batchKind()} and declares FILENAME_MAX_LENGTH.
 */
trait SharesBatchSteps
{
    /** The kind of batch the using service drafts and confirms. */
    abstract protected function batchKind(): MassPaymentKind;

    /**
     * Mark a draft past its expiry as Expired, under its lock, and refuse it.
     * Should another request have moved it on meanwhile, it is answered as
     * that state instead.
     *
     * @throws MassPaymentNotConfirmableException
     */
    private function expire(User $user, MassPaymentBatch $batch): ConfirmedMassPayment
    {
        $expired = DB::transaction(function () use ($user, $batch): ?MassPaymentBatch {
            $draft = MassPaymentBatch::query()
                ->whereKey($batch->id)
                ->where('user_id', $user->id)
                ->ofKind($this->batchKind())
                ->lockForUpdate()
                ->firstOrFail();

            if ($draft->status() !== MassPaymentBatchStatus::Draft) {
                return null;
            }

            $draft->forceFill(['status' => MassPaymentBatchStatus::Expired->value])->save();

            $this->recordEvent($draft, MassPaymentBatchStatus::Draft->value, MassPaymentActor::system(), 'expired');

            return $draft;
        }, 3);

        if ($expired === null) {
            return $this->replayOrRefuse($batch->refresh());
        }

        throw new MassPaymentNotConfirmableException(MassPaymentNotConfirmableException::EXPIRED, $expired);
    }

    /**
     * A batch found past Draft: a confirmed one is a replay that debits
     * nothing; a discarded or expired one is refused.
     *
     * @throws MassPaymentNotConfirmableException
     */
    private function replayOrRefuse(MassPaymentBatch $batch): ConfirmedMassPayment
    {
        $this->refuseUnlessConfirmed($batch);

        return new ConfirmedMassPayment($batch, true);
    }

    /**
     * Refuse a batch that never reached Queued: still a draft (the caller
     * should not be here), discarded or expired.
     *
     * @throws MassPaymentNotConfirmableException
     */
    private function refuseUnlessConfirmed(MassPaymentBatch $batch): void
    {
        $reason = match ($batch->status()) {
            MassPaymentBatchStatus::Expired => MassPaymentNotConfirmableException::EXPIRED,
            MassPaymentBatchStatus::Discarded, MassPaymentBatchStatus::Draft => MassPaymentNotConfirmableException::NOT_DRAFT,
            default => null,
        };

        if ($reason !== null) {
            throw new MassPaymentNotConfirmableException($reason, $batch);
        }
    }

    /** The fingerprint and total the client reviewed equal these. */
    private function matchesPreview(MassPaymentConfirmation $confirmation, string $fingerprint, string $total): bool
    {
        return hash_equals($fingerprint, $confirmation->fingerprint)
            && is_numeric($confirmation->expectedTotal)
            && DecimalMoney::compare($confirmation->expectedTotal, $total) === 0;
    }

    /**
     * The stored rows in the shape {@see self::totals()} reads.
     *
     * @param  Collection<int, MassPaymentRow> $rows
     * @return list<array<string, mixed>>
     */
    private function figuresOf(Collection $rows): array
    {
        return $rows->map(fn (MassPaymentRow $row): array => [
            'status'         => (string) $row->getAttribute('status'),
            'warnings'       => $row->warnings === [] ? null : $row->warnings,
            'net_amount'     => $row->net_amount,
            'wallet_fee'     => $row->wallet_fee,
            'payable_amount' => $row->payable_amount,
            'base_amount'    => $row->base_amount,
            'base_fee'       => $row->base_fee,
        ])->values()->all();
    }

    /**
     * The batch counts and totals over its valid lines, in the same columns
     * for both kinds.
     *
     * @param  list<array<string, mixed>> $rows
     * @return array<string, int|string>
     */
    private function totals(array $rows, string $code): array
    {
        $decimals     = Money::decimals($code);
        $baseDecimals = DecimalMoney::CALCULATION_SCALE;

        $totals = [
            'row_count'         => count($rows),
            'valid_count'       => 0,
            'invalid_count'     => 0,
            'warning_count'     => 0,
            'total_amount'      => DecimalMoney::normalize('0', $decimals),
            'total_fee'         => DecimalMoney::normalize('0', $decimals),
            'total_payable'     => DecimalMoney::normalize('0', $decimals),
            'total_base_amount' => DecimalMoney::normalize('0', $baseDecimals),
            'total_base_fee'    => DecimalMoney::normalize('0', $baseDecimals),
        ];

        foreach ($rows as $row) {
            if ($row['warnings'] !== null) {
                $totals['warning_count']++;
            }

            if ($row['status'] !== MassPaymentRowStatus::Valid->value) {
                $totals['invalid_count']++;

                continue;
            }

            $totals['valid_count']++;
            $totals['total_amount']      = DecimalMoney::add($totals['total_amount'], $row['net_amount'], $decimals);
            $totals['total_fee']         = DecimalMoney::add($totals['total_fee'], $row['wallet_fee'], $decimals);
            $totals['total_payable']     = DecimalMoney::add($totals['total_payable'], $row['payable_amount'], $decimals);
            $totals['total_base_amount'] = DecimalMoney::add($totals['total_base_amount'], $row['base_amount'], $baseDecimals);
            $totals['total_base_fee']    = DecimalMoney::add($totals['total_base_fee'], $row['base_fee'], $baseDecimals);
        }

        return $totals;
    }

    /**
     * Append one row to the batch's audit trail, inside the caller's
     * transaction. The `to_` side is read from the batch as it now stands,
     * unless a line event names its own.
     *
     * @param array<string, mixed> $payload
     */
    private function recordEvent(
        MassPaymentBatch $batch,
        ?string $fromStatus,
        MassPaymentActor $actor,
        ?string $note = null,
        array $payload = [],
        ?int $rowId = null,
        ?string $toStatus = null,
    ): MassPaymentEvent {
        $note = trim((string) $note);

        return MassPaymentEvent::query()->create([
            'mass_payment_batch_id' => $batch->id,
            'row_id'                => $rowId,
            'from_status'           => $fromStatus,
            'to_status'             => $toStatus ?? (string) $batch->getAttribute('status'),
            'actor_type'            => $actor->type,
            'actor_id'              => $actor->id,
            'note'                  => $note    === '' ? null : mb_substr($note, 0, 500),
            'payload'               => $payload === [] ? null : $payload,
        ]);
    }

    /** The uploaded file's base name with control characters removed, or null. */
    private function safeFilename(UploadedFile $file): ?string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));
        $name = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $name));

        return $name === '' ? null : mb_substr($name, 0, self::FILENAME_MAX_LENGTH);
    }
}
