<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Models\MassPaymentBatch;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Notifications\TemplateNotification;
use App\Services\TransactionNotifierService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The notifications a mass payment sends: these three, and for a payout
 * batch the two admin alerts below them ({@see self::approvalRequired()},
 * {@see self::operatorRequired()}):
 *
 *   recipient  `send_money_user_received` for each paid line, with the same
 *              variables send money gives it, so a payout from a batch reads
 *              exactly like a transfer sent alone;
 *   owner      `mass_payment_user_completed`, once, when the batch reaches a
 *              terminal state. There is no per-line "sent" message;
 *   admins     `mass_payment_admin_needs_review`, to holders of
 *              {@see self::ADMIN_PERMISSION}, when a batch is held.
 *
 * Every send waits for the surrounding transaction to commit, so a line or a
 * batch move that rolls back never announces itself, and runs inside
 * rescue(), so a channel that fails is reported and never undoes the money
 * it describes. Recipients reach a notification only by their masked label
 * or not at all: TemplateNotification is queued and its data is stored in
 * plain text in `jobs`.
 */
class MassPaymentNotifier
{
    public const ADMIN_PERMISSION = 'mass-payment-notification';

    public const RECIPIENT_TEMPLATE = 'send_money_user_received';

    public const OWNER_TEMPLATE = 'mass_payment_user_completed';

    public const ADMIN_TEMPLATE = 'mass_payment_admin_needs_review';

    /** Who is told a payout batch waits for approval: those who may approve it. */
    public const APPROVE_PERMISSION = 'mass-payment-approve';

    public const APPROVAL_TEMPLATE = 'mass_payout_admin_approval_required';

    public const OPERATOR_TEMPLATE = 'mass_payout_admin_operator_required';

    public function __construct(protected TransactionNotifierService $notifier) {}

    /**
     * Tell a recipient their line was paid, against the RECEIVE_MONEY credit
     * the line wrote. `$source` is the batch's wallet, whose owner is named
     * as the sender.
     */
    public function paid(Transaction $credit, Wallet $source): void
    {
        $sender = (string) ($source->user?->name ?: __('Sender'));

        $this->afterCommit(function () use ($credit, $sender): void {
            $this->notifier->toUser($credit, self::RECIPIENT_TEMPLATE, [
                'amount' => $credit->notificationAmount(),
                'sender' => $sender,
                'trx'    => (string) $credit->trx_id,
            ]);
        });
    }

    /**
     * Tell the admins who handle mass payments that a batch was held, and
     * why. Nothing more is paid until one of them resumes it or cancels its
     * remaining lines.
     */
    public function needsReview(MassPaymentBatch $batch, string $reason): void
    {
        $this->afterCommit(function () use ($batch, $reason): void {
            $this->notifier->toAdmins(self::ADMIN_PERMISSION, self::ADMIN_TEMPLATE, [
                'user'      => (string) $batch->user?->name,
                'reference' => (string) $batch->reference,
                'reason'    => $this->reason($reason),
                'amount'    => $this->amount((string) $batch->total_payable, $batch),
            ], sender: $batch->user, action: $this->link('admin.mass-payment.show', $batch));
        });
    }

    /**
     * Tell the admins who may approve payout batches that one is waiting,
     * with its money reserved and nothing sent.
     */
    public function approvalRequired(MassPaymentBatch $batch): void
    {
        $this->afterCommit(function () use ($batch): void {
            $link = $this->link('admin.mass-payment.show', $batch);

            $this->notifier->toAdmins(self::APPROVE_PERMISSION, self::APPROVAL_TEMPLATE, $this->payoutData($batch, (int) $batch->valid_count, $link), sender: $batch->user, action: $link);
        });
    }

    /**
     * Tell the admins who handle mass payments that a payout batch has lines
     * waiting to be paid by hand and recorded. Sent once per batch.
     */
    public function operatorRequired(MassPaymentBatch $batch, int $rows): void
    {
        $this->afterCommit(function () use ($batch, $rows): void {
            $link = $this->link('admin.mass-payment.show', $batch);

            $this->notifier->toAdmins(self::ADMIN_PERMISSION, self::OPERATOR_TEMPLATE, $this->payoutData($batch, $rows, $link), sender: $batch->user, action: $link);
        });
    }

    /**
     * The owner's one summary, sent on the batch's move to a terminal state:
     * how many lines were paid and for how much, how many were not, and what
     * went back to the wallet.
     */
    public function completed(MassPaymentBatch $batch): void
    {
        $this->afterCommit(function () use ($batch): void {
            $owner = $batch->user;

            if ($owner === null) {
                return;
            }

            $link = $this->link('user.mass-payment.show', $batch);

            $owner->notify(new TemplateNotification(
                identifier: self::OWNER_TEMPLATE,
                data: [
                    'reference'    => (string) $batch->reference,
                    'paid_count'   => (string) (int) $batch->paid_count,
                    'failed_count' => (string) (int) $batch->failed_count,
                    'paid_total'   => $this->amount((string) $batch->paid_payable, $batch),
                    'released'     => $this->amount((string) $batch->released_amount, $batch),
                    'link'         => (string) $link,
                ],
                action: $link,
            ));
        });
    }

    /**
     * Runs immediately when no transaction is open, and on commit otherwise.
     * A rollback discards the callback, so nothing is sent for it.
     */
    private function afterCommit(callable $send): void
    {
        DB::afterCommit(static fn () => rescue($send, report: true));
    }

    /**
     * A figure in the batch currency, written the way
     * Transaction::notificationAmount() writes every other money notice: the
     * code rather than the symbol, at the currency's own decimals.
     */
    private function amount(string $amount, MassPaymentBatch $batch): string
    {
        $currency = (string) $batch->currency_code;

        return trim(Money::format($amount, $currency !== '' ? $currency : null, false).' '.$currency);
    }

    /**
     * The variables both payout admin templates take. The method is the name
     * frozen into the batch, so a method renamed since reads as it was.
     *
     * @return array{user: string, reference: string, amount: string, rows: string, method: string, link: string}
     */
    private function payoutData(MassPaymentBatch $batch, int $rows, ?string $link): array
    {
        $metadata = is_array($batch->metadata) ? $batch->metadata : [];

        return [
            'user'      => (string) $batch->user?->name,
            'reference' => (string) $batch->reference,
            'amount'    => $this->amount((string) $batch->total_payable, $batch),
            'rows'      => (string) $rows,
            'method'    => (string) ($metadata['payout']['method_name'] ?? $batch->payout_driver),
            'link'      => (string) $link,
        ];
    }

    /** The hold reason as an admin reads it. */
    private function reason(string $reason): string
    {
        return match ($reason) {
            MassPaymentProcessor::HOLD_SENDER_UNAVAILABLE  => __('The sender account cannot make payments right now'),
            MassPaymentProcessor::HOLD_LEDGER_ANOMALY      => __('The ledger does not match the batch'),
            MassPayoutProcessor::HOLD_PROVIDER_UNAVAILABLE => __('The payout provider refused our credentials; nothing was sent'),
            default                                        => $reason,
        };
    }

    /** A link to the batch's page, or none while that page is not routed. */
    private function link(string $routeName, MassPaymentBatch $batch): ?string
    {
        return Route::has($routeName) ? route($routeName, $batch) : null;
    }
}
