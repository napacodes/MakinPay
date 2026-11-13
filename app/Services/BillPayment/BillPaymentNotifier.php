<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Enums\BillPayment\BillPaymentStatus;
use App\Models\BillPayment;
use App\Models\BillPaymentProvider;
use App\Models\Transaction;
use App\Notifications\BillTokenNotification;
use App\Services\TransactionNotifierService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * The customer and admin notifications a bill payment sends.
 *
 * Every send waits for the surrounding database transaction to commit, so a
 * rolled-back payment never announces itself, and each one runs inside
 * rescue(), so a notification that fails is reported and never undoes the
 * money movement it describes.
 *
 * The prepaid token is never handed to a notification. TemplateNotification
 * is queued, which stores its data in plain text in `jobs`, and its push copy
 * is persisted in `notifications`; the completed notice points the customer
 * at the receipt instead, which is the one place the token is shown.
 */
class BillPaymentNotifier
{
    /** Admins holding this permission hear about every new bill payment. */
    public const ADMIN_PERMISSION = 'bill-payment-notification';

    public function __construct(protected TransactionNotifierService $notifier) {}

    /**
     * The customer's "submitted" notice and the admins' "new bill payment"
     * alert. Each is a separate send, so one failing does not cost the other.
     */
    public function submitted(BillPayment $payment): void
    {
        $this->afterCommit(function () use ($payment): void {
            $debit = $this->debitFor($payment, 'bill_payment_user_submitted');

            if ($debit === null) {
                return;
            }

            $this->notifier->toUser($debit, 'bill_payment_user_submitted', [
                'biller'          => (string) $payment->biller_name,
                'customer_number' => (string) $payment->customer_number,
                'amount'          => $this->amount($payment),
                'bill_reference'  => (string) $payment->reference,
                'trx'             => (string) $debit->trx_id,
            ], $this->link('user.bill-payment.show', $payment));
        });

        $this->afterCommit(function () use ($payment): void {
            $debit = $this->debitFor($payment, 'bill_payment_admin_requested');

            if ($debit === null) {
                return;
            }

            $this->notifier->toAdmins(self::ADMIN_PERMISSION, 'bill_payment_admin_requested', [
                'user'           => (string) $payment->user?->name,
                'biller'         => (string) $payment->biller_name,
                'amount'         => $this->amount($payment),
                'bill_reference' => (string) $payment->reference,
                'trx'            => (string) $debit->trx_id,
            ], sender: $payment->user, action: $this->link('admin.bill-payment.show', $payment));
        });
    }

    /**
     * BP1: the breaker paused a provider. Sent once per pause, to the same
     * admins who hear about new bill payments, pointing at the provider
     * screen where a passing connection test resumes it.
     */
    public function providerPaused(BillPaymentProvider $provider): void
    {
        $this->afterCommit(function () use ($provider): void {
            $this->notifier->toAdmins(self::ADMIN_PERMISSION, 'bill_payment_admin_provider_paused', [
                'provider' => (string) $provider->name,
                'reason'   => (string) ($provider->pause_reason?->label() ?? ''),
                'message'  => (string) ($provider->pause_message ?? ''),
            ], action: Route::has('admin.bill-payment.providers.edit') ? route('admin.bill-payment.providers.edit', $provider) : null);
        });
    }

    /**
     * BP4: what the daily catalogue sync changed, to the same admins, after
     * the run. Sent only when a country failed or something changed: a run
     * that found the catalogue as it was says nothing. New billers arrive
     * switched off, which the notice says, since switching them on is the
     * operator's next step. Each country's full report stays on the
     * provider screen; this carries the totals and the link there.
     *
     * @param array<string, array{created: int, updated: int, unchanged: int, disabled_missing: int, items_created: int, items_updated: int, items_disabled: int, conflicts: list<array{external_code: string, kind: string}>, skipped: array<string, int>, error: string|null}> $reports country => report
     */
    public function billersSynced(BillPaymentProvider $provider, array $reports): void
    {
        $summary = self::syncSummary($reports);

        if ($summary === null) {
            return;
        }

        $this->afterCommit(function () use ($provider, $reports, $summary): void {
            $this->notifier->toAdmins(self::ADMIN_PERMISSION, 'bill_payment_admin_billers_synced', [
                'provider'  => (string) $provider->name,
                'countries' => implode(', ', array_map('strval', array_keys($reports))),
                'summary'   => $summary,
            ], action: Route::has('admin.bill-payment.providers.edit') ? route('admin.bill-payment.providers.edit', $provider) : null);
        });
    }

    /**
     * The sync's totals in one sentence, or null when nothing changed and
     * nothing failed - the case nobody needs to hear about.
     *
     * @param array<string, array<string, mixed>> $reports country => report
     */
    public static function syncSummary(array $reports): ?string
    {
        $created = $updated = $disabled = $conflicts = 0;
        $failed  = [];

        foreach ($reports as $country => $report) {
            if (($report['error'] ?? null) !== null) {
                $failed[] = sprintf('%s: %s', $country, (string) $report['error']);

                continue;
            }

            $created   += (int) ($report['created'] ?? 0)          + (int) ($report['items_created'] ?? 0);
            $updated   += (int) ($report['updated'] ?? 0)          + (int) ($report['items_updated'] ?? 0);
            $disabled  += (int) ($report['disabled_missing'] ?? 0) + (int) ($report['items_disabled'] ?? 0);
            $conflicts += count((array) ($report['conflicts'] ?? []));
        }

        if ($created + $updated + $disabled + $conflicts === 0 && $failed === []) {
            return null;
        }

        $parts = [];

        if ($created > 0) {
            $parts[] = __(':count new, switched off until you enable them', ['count' => $created]);
        }

        if ($updated > 0) {
            $parts[] = __(':count updated', ['count' => $updated]);
        }

        if ($disabled > 0) {
            $parts[] = __(':count no longer listed, switched off', ['count' => $disabled]);
        }

        if ($conflicts > 0) {
            $parts[] = __(':count with a conflict to review', ['count' => $conflicts]);
        }

        if ($failed !== []) {
            $parts[] = __('failed - :countries', ['countries' => implode('; ', $failed)]);
        }

        return rtrim(implode(', ', $parts), '.').'.';
    }

    public function completed(BillPayment $payment): void
    {
        $this->afterCommit(function () use ($payment): void {
            $debit = $this->debitFor($payment, 'bill_payment_user_completed');

            if ($debit === null) {
                return;
            }

            $this->notifier->toUser($debit, 'bill_payment_user_completed', [
                'biller'            => (string) $payment->biller_name,
                'customer_number'   => (string) $payment->customer_number,
                'amount'            => $this->amount($payment),
                'bill_reference'    => (string) $payment->reference,
                'receipt_reference' => (string) ($payment->receipt_reference ?: $payment->reference),
                'token_label'       => (string) ($payment->biller?->token_label ?: __('payment details')),
                'trx'               => (string) $debit->trx_id,
            ], $this->link('user.bill-payment.show', $payment));
        });

        $this->afterCommit(function () use ($payment): void {
            $this->sendToken($payment);
        });
    }

    /**
     * Whether the admin lets a prepaid token leave the receipt: by email,
     * SMS and push, as the customer's own preferences allow. Off is today's
     * behaviour, so an upgrade changes nothing.
     */
    public static function tokenDeliveryOn(): bool
    {
        return setting('bill_payment_token_delivery', 'receipt') === 'notify';
    }

    /**
     * The prepaid token to the customer who paid, when the admin lets it
     * leave the receipt and the payment holds one. BillTokenNotification
     * carries the payment's id and nothing else; the token is read as each
     * channel is written, so the queue never holds it. Called when the bill
     * completes, and again when the customer asks for it from the receipt.
     *
     * @return bool whether there was anything to send
     */
    public function sendToken(BillPayment $payment): bool
    {
        $user = $payment->user()->first();

        if (! self::tokenDeliveryOn() || $user === null || $payment->status !== BillPaymentStatus::Completed) {
            return false;
        }

        $token = rescue(fn (): ?string => $payment->token, null, report: false);

        if ($token === null || trim($token) === '') {
            return false;
        }

        $user->notify(new BillTokenNotification((int) $payment->id));

        return true;
    }

    public function failed(BillPayment $payment): void
    {
        $this->afterCommit(function () use ($payment): void {
            $debit = $this->debitFor($payment, 'bill_payment_user_failed');

            if ($debit === null) {
                return;
            }

            $this->notifier->toUser($debit, 'bill_payment_user_failed', [
                'biller'         => (string) $payment->biller_name,
                'amount'         => $this->amount($payment),
                'reason'         => (string) ($payment->failure_reason ?: __('The biller did not accept the payment.')),
                'bill_reference' => (string) $payment->reference,
                'trx'            => (string) $debit->trx_id,
            ], $this->link('user.bill-payment.show', $payment));
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
     * The wallet debit the payment reserved. Read through the relation query
     * rather than the cached relation, because the caller may have loaded the
     * relation before `transaction_id` was set.
     */
    private function debitFor(BillPayment $payment, string $identifier): ?Transaction
    {
        $debit = $payment->transaction()->first();

        if ($debit === null) {
            Log::warning('Bill payment notification skipped: the payment has no debit row.', [
                'bill_payment_id' => $payment->id,
                'identifier'      => $identifier,
            ]);
        }

        return $debit;
    }

    /**
     * What the wallet paid, fee included, written the way
     * Transaction::notificationAmount() writes every other money notice. The
     * code rather than the symbol keeps the figure readable for the
     * large-amount rule in TemplateNotification, which keeps only digits
     * and dots.
     */
    private function amount(BillPayment $payment): string
    {
        $currency = (string) $payment->currency;

        return trim(Money::format((string) $payment->total_amount, $currency !== '' ? $currency : null, false).' '.$currency);
    }

    /**
     * A link to the payment's page, or none while that page is not routed.
     */
    private function link(string $routeName, BillPayment $payment): ?string
    {
        return Route::has($routeName) ? route($routeName, $payment) : null;
    }
}
