<?php

namespace App\Services;

use App\Enums\BillingCycle;
use App\Enums\EnvironmentMode;
use App\Enums\MerchantSubscriptionInvoiceStatus;
use App\Enums\MerchantSubscriptionStatus;
use App\Enums\TrxStatus;
use App\Models\Merchant;
use App\Models\MerchantSubscription;
use App\Models\MerchantSubscriptionInvoice;
use App\Models\Transaction;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MerchantSubscriptionService
{
    public function __construct(private readonly MerchantCheckoutService $checkouts) {}

    /**
     * @param array<string, mixed> $data
     */
    public function create(Merchant $merchant, EnvironmentMode $environment, array $data): MerchantSubscription
    {
        $existing = MerchantSubscription::query()
            ->forMerchantEnvironment($merchant, $environment)
            ->where('external_reference', $data['external_reference'])
            ->first();

        if ($existing) {
            throw new DomainException(__('A subscription with this external_reference already exists.'));
        }

        return DB::transaction(function () use ($merchant, $environment, $data): MerchantSubscription {
            $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : now();

            $subscription = MerchantSubscription::query()->create([
                'merchant_id'        => $merchant->id,
                'environment'        => $environment,
                'external_reference' => $data['external_reference'],
                'status'             => MerchantSubscriptionStatus::Pending,
                'billing_cycle'      => BillingCycle::from($data['billing_cycle']),
                'amount'             => $data['payment_amount'],
                'currency_code'      => strtoupper(trim((string) $data['currency_code'])),
                'customer_name'      => $data['customer_name'] ?? null,
                'customer_email'     => $data['customer_email'],
                'description'        => $data['description'] ?? null,
                'success_redirect'   => $data['success_redirect'],
                'cancel_redirect'    => $data['cancel_redirect'],
                'ipn_url'            => $data['ipn_url'],
                'starts_at'          => $startsAt,
                'next_billing_at'    => $startsAt,
                'metadata'           => $data['metadata'] ?? [],
            ]);

            if ($startsAt->lessThanOrEqualTo(now())) {
                $this->createInvoiceCheckout(
                    subscription: $subscription,
                    allowPaymentMethods: $data['allow_payment_methods'] ?? null,
                );
            }

            return $subscription->refresh()->load('latestInvoice');
        }, 3);
    }

    public function cancel(MerchantSubscription $subscription, bool $atPeriodEnd = true): MerchantSubscription
    {
        return DB::transaction(function () use ($subscription, $atPeriodEnd): MerchantSubscription {
            $locked = MerchantSubscription::query()->lockForUpdate()->findOrFail($subscription->id);

            if ($locked->status === MerchantSubscriptionStatus::Cancelled) {
                return $locked->load('latestInvoice');
            }

            if ($atPeriodEnd && $locked->status === MerchantSubscriptionStatus::Active && $locked->current_period_end?->isFuture()) {
                $locked->update(['cancel_at_period_end' => true]);

                return $locked->refresh()->load('latestInvoice');
            }

            $this->cancelOpenInvoices($locked);
            $locked->update([
                'status'               => MerchantSubscriptionStatus::Cancelled,
                'cancel_at_period_end' => false,
                'cancelled_at'         => now(),
                'next_billing_at'      => null,
            ]);

            return $locked->refresh()->load('latestInvoice');
        }, 3);
    }

    public function renew(MerchantSubscription $subscription, bool $forceNew = false): MerchantSubscription
    {
        return DB::transaction(function () use ($subscription, $forceNew): MerchantSubscription {
            $locked = MerchantSubscription::query()->lockForUpdate()->findOrFail($subscription->id);

            if ($locked->status === MerchantSubscriptionStatus::Cancelled) {
                throw new DomainException(__('Cancelled subscriptions cannot be renewed. Create a new subscription instead.'));
            }

            if ($locked->status === MerchantSubscriptionStatus::Active && $locked->next_billing_at?->isFuture()) {
                throw new DomainException(__('This subscription is not due for renewal yet.'));
            }

            $pendingInvoice = $locked->invoices()
                ->where('status', MerchantSubscriptionInvoiceStatus::Pending->value)
                ->latest('sequence')
                ->first();

            if ($pendingInvoice && ! $forceNew && $pendingInvoice->checkout_expires_at?->isFuture()) {
                return $locked->load('latestInvoice');
            }

            if ($pendingInvoice) {
                $this->cancelInvoice($pendingInvoice);
            }

            $this->createInvoiceCheckout($locked);

            if ($locked->status === MerchantSubscriptionStatus::Active && $locked->current_period_end?->lessThanOrEqualTo(now())) {
                $locked->update(['status' => MerchantSubscriptionStatus::PastDue]);
            }

            return $locked->refresh()->load('latestInvoice');
        }, 3);
    }

    public function processDue(int $limit = 100): int
    {
        $processed = 0;

        $ids = MerchantSubscription::query()
            ->whereIn('status', [MerchantSubscriptionStatus::Pending->value, MerchantSubscriptionStatus::Active->value])
            ->whereNotNull('next_billing_at')
            ->where('next_billing_at', '<=', now())
            ->orderBy('next_billing_at')
            ->limit(max(1, min(1000, $limit)))
            ->pluck('id');

        foreach ($ids as $id) {
            try {
                $invoice = $this->processDueSubscription((int) $id);
                if ($invoice) {
                    $processed++;
                }
            } catch (Throwable $exception) {
                report($exception);
                Log::error('Merchant subscription renewal invoice creation failed.', [
                    'subscription_id' => $id,
                    'error'           => $exception->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    public function markTransactionPaid(Transaction $transaction): void
    {
        $invoiceId = (int) ($transaction->trx_data['merchant_subscription_invoice_id'] ?? 0);
        if ($invoiceId === 0) {
            return;
        }

        DB::transaction(function () use ($invoiceId, $transaction): void {
            $invoice = MerchantSubscriptionInvoice::query()->lockForUpdate()->find($invoiceId);
            if (! $invoice || $invoice->status === MerchantSubscriptionInvoiceStatus::Paid) {
                return;
            }

            $subscription = MerchantSubscription::query()->lockForUpdate()->findOrFail($invoice->merchant_subscription_id);

            $invoice->update([
                'transaction_id' => $transaction->trx_id,
                'status'         => MerchantSubscriptionInvoiceStatus::Paid,
                'paid_at'        => now(),
                'failed_at'      => null,
            ]);

            if ($subscription->status === MerchantSubscriptionStatus::Cancelled) {
                return;
            }

            $subscription->update([
                'status'               => MerchantSubscriptionStatus::Active,
                'current_period_start' => $invoice->period_start,
                'current_period_end'   => $invoice->period_end,
                'next_billing_at'      => $invoice->period_end,
            ]);
        }, 3);
    }

    public function markTransactionFailed(Transaction $transaction): void
    {
        $invoiceId = (int) ($transaction->trx_data['merchant_subscription_invoice_id'] ?? 0);
        if ($invoiceId === 0) {
            return;
        }

        DB::transaction(function () use ($invoiceId, $transaction): void {
            $invoice = MerchantSubscriptionInvoice::query()->lockForUpdate()->find($invoiceId);
            if (! $invoice || $invoice->status !== MerchantSubscriptionInvoiceStatus::Pending) {
                return;
            }

            $invoice->update([
                'transaction_id' => $transaction->trx_id,
                'status'         => MerchantSubscriptionInvoiceStatus::Failed,
                'failed_at'      => now(),
            ]);

            $subscription = MerchantSubscription::query()->lockForUpdate()->findOrFail($invoice->merchant_subscription_id);
            if ($subscription->status !== MerchantSubscriptionStatus::Cancelled) {
                $subscription->update(['status' => MerchantSubscriptionStatus::PastDue]);
            }
        }, 3);
    }

    private function processDueSubscription(int $subscriptionId): ?MerchantSubscriptionInvoice
    {
        return DB::transaction(function () use ($subscriptionId): ?MerchantSubscriptionInvoice {
            $subscription = MerchantSubscription::query()->lockForUpdate()->find($subscriptionId);
            if (! $subscription || ! $subscription->next_billing_at?->lessThanOrEqualTo(now())) {
                return null;
            }

            if ($subscription->cancel_at_period_end && $subscription->status === MerchantSubscriptionStatus::Active) {
                $subscription->update([
                    'status'               => MerchantSubscriptionStatus::Cancelled,
                    'cancel_at_period_end' => false,
                    'cancelled_at'         => now(),
                    'next_billing_at'      => null,
                ]);

                return null;
            }

            $hasOpenInvoice = $subscription->invoices()
                ->where('status', MerchantSubscriptionInvoiceStatus::Pending->value)
                ->exists();

            if ($hasOpenInvoice) {
                return null;
            }

            $invoice = $this->createInvoiceCheckout($subscription);

            if ($subscription->status === MerchantSubscriptionStatus::Active && $subscription->current_period_end?->lessThanOrEqualTo(now())) {
                $subscription->update(['status' => MerchantSubscriptionStatus::PastDue]);
            }

            return $invoice;
        }, 3);
    }

    private function createInvoiceCheckout(MerchantSubscription $subscription, null|string|array $allowPaymentMethods = null): MerchantSubscriptionInvoice
    {
        $subscription->loadMissing('merchant.user', 'merchant.currency', 'merchant.supportedCurrencies');

        $periodStart = $subscription->next_billing_at ?? $subscription->starts_at ?? now();
        $periodEnd   = $subscription->billing_cycle->calculateEndDate($periodStart->copy());
        if (! $periodEnd) {
            throw new DomainException(__('Recurring subscriptions require a renewable billing cycle.'));
        }

        $sequence  = ((int) $subscription->invoices()->max('sequence')) + 1;
        $reference = 'MSI-'.Str::upper(substr(str_replace('-', '', $subscription->uuid), 0, 16)).'-'.$sequence;

        $invoice = $subscription->invoices()->create([
            'sequence'            => $sequence,
            'reference'           => $reference,
            'status'              => MerchantSubscriptionInvoiceStatus::Pending,
            'amount'              => $subscription->amount,
            'currency_code'       => $subscription->currency_code,
            'period_start'        => $periodStart,
            'period_end'          => $periodEnd,
            'due_at'              => now(),
            'checkout_expires_at' => now()->addMinutes(900),
        ]);

        $checkout = $this->checkouts->create(
            merchant: $subscription->merchant,
            environment: $subscription->environment,
            data: [
                'ref_trx'               => $reference,
                'currency_code'         => $subscription->currency_code,
                'payment_amount'        => (float) $subscription->amount,
                'description'           => $subscription->description ?: __('Subscription renewal :reference', ['reference' => $subscription->external_reference]),
                'ipn_url'               => $subscription->ipn_url,
                'cancel_redirect'       => $subscription->cancel_redirect,
                'success_redirect'      => $subscription->success_redirect,
                'customer_name'         => $subscription->customer_name,
                'customer_email'        => $subscription->customer_email,
                'allow_payment_methods' => $allowPaymentMethods,
            ],
            context: [
                'merchant_subscription_invoice_id' => $invoice->id,
                'subscription_id'                  => $subscription->uuid,
                'subscription_reference'           => $subscription->external_reference,
                'subscription_invoice_id'          => $invoice->uuid,
                'subscription_invoice_reference'   => $invoice->reference,
                'billing_cycle'                    => $subscription->billing_cycle->value,
                'period_start'                     => $periodStart->toIso8601String(),
                'period_end'                       => $periodEnd->toIso8601String(),
            ],
        );

        $invoice->update([
            'transaction_id' => $checkout['transaction']->trx_id,
            'payment_url'    => $checkout['payment_url'],
        ]);

        return $invoice->refresh();
    }

    private function cancelOpenInvoices(MerchantSubscription $subscription): void
    {
        $subscription->invoices()
            ->where('status', MerchantSubscriptionInvoiceStatus::Pending->value)
            ->get()
            ->each(fn (MerchantSubscriptionInvoice $invoice) => $this->cancelInvoice($invoice));
    }

    private function cancelInvoice(MerchantSubscriptionInvoice $invoice): void
    {
        $invoice->update(['status' => MerchantSubscriptionInvoiceStatus::Cancelled]);

        if ($invoice->transaction_id) {
            Transaction::query()
                ->where('trx_id', $invoice->transaction_id)
                ->where('status', TrxStatus::PENDING->value)
                ->update(['status' => TrxStatus::CANCELED->value]);
        }
    }
}
