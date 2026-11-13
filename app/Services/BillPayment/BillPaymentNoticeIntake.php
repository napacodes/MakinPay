<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Jobs\CheckBillPaymentStatus;
use App\Models\BillPayment;
use App\Models\BillPaymentProvider;
use Illuminate\Support\Facades\Log;

/**
 * What happens to an authenticated provider notice once it is known which
 * provider it came from: it is bound to exactly one payment of that same
 * provider, deduplicated by its event id, and then only triggers a status
 * check after the response has gone out. A notice never moves money; only
 * the provider's status answer is applied, through BillPaymentStatusChecker.
 *
 * Shared by the bill payment webhook endpoint and by the Flutterwave deposit
 * webhook, which hands bill events over because a Flutterwave account has a
 * single dashboard webhook URL. Logs carry the provider code and ids only.
 */
class BillPaymentNoticeIntake
{
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_UNKNOWN_REFERENCE = 'unknown_reference';

    public const STATUS_MISMATCH = 'mismatch';

    public const SOURCE_WEBHOOK = 'webhook';

    public function __construct(private readonly BillPaymentService $service) {}

    /**
     * Take an authenticated notice for this provider and answer with the
     * status word the caller replies with.
     */
    public function accept(BillPaymentProvider $provider, BillPaymentWebhookNotice $notice, string $source = self::SOURCE_WEBHOOK): string
    {
        $payment = $this->bind($provider, $notice);

        if (! $payment instanceof BillPayment) {
            return $payment;
        }

        if ($this->isResolved($payment) && BillPaymentPollingSchedule::resolvedTooLongAgo($payment)) {
            return self::STATUS_RESOLVED;
        }

        if (! $this->service->recordProviderNotice((int) $payment->id, $notice->externalEventId, ['source' => $source])) {
            return self::STATUS_DUPLICATE;
        }

        CheckBillPaymentStatus::dispatchAfterResponse((int) $payment->id, BillPaymentStatusChecker::TRIGGER_WEBHOOK);

        return self::STATUS_ACCEPTED;
    }

    /**
     * The one payment of this provider the notice is about, or the reply
     * status when there is none: STATUS_UNKNOWN_REFERENCE when it names no
     * payment of this provider, STATUS_MISMATCH when its references point at
     * different payments, or at a payment holding other references. Both
     * lookups are scoped to the provider, so one provider's notice can never
     * reach another provider's payment.
     */
    private function bind(BillPaymentProvider $provider, BillPaymentWebhookNotice $notice): BillPayment|string
    {
        $requestId     = $this->reference($notice->providerRequestId);
        $transactionId = $this->reference($notice->providerTransactionId);

        $byRequest = $requestId === null ? null : BillPayment::query()
            ->where('bill_payment_provider_id', $provider->id)
            ->where('provider_request_id', $requestId)
            ->first();

        $byTransaction = $transactionId === null ? null : BillPayment::query()
            ->where('bill_payment_provider_id', $provider->id)
            ->where('provider_transaction_id', $transactionId)
            ->first();

        $payment = $byRequest ?? $byTransaction;

        if ($payment === null) {
            Log::info('Bill payment webhook names no known payment', ['provider' => $provider->code]);

            return self::STATUS_UNKNOWN_REFERENCE;
        }

        $refersElsewhere = $byRequest !== null && $byTransaction !== null && (int) $byRequest->id !== (int) $byTransaction->id;
        $heldReference   = $payment->provider_transaction_id === null ? null : (string) $payment->provider_transaction_id;
        $otherReference  = ($transactionId !== null && $heldReference !== null && $heldReference !== $transactionId)
            || ($requestId !== null && (string) $payment->provider_request_id !== $requestId);

        if ($refersElsewhere || $otherReference) {
            Log::warning('Bill payment webhook references disagree', [
                'provider'                 => $provider->code,
                'bill_payment_provider_id' => $provider->id,
                'bill_payment_id'          => $payment->id,
                'other_bill_payment_id'    => $refersElsewhere ? $byTransaction->id : null,
            ]);

            return self::STATUS_MISMATCH;
        }

        return $payment;
    }

    private function reference(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function isResolved(BillPayment $payment): bool
    {
        return ($payment->status?->isTerminal() ?? false) || $payment->dispatchState()->isTerminal();
    }
}
