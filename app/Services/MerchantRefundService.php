<?php

namespace App\Services;

use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\EnvironmentMode;
use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Exceptions\NotifyErrorException;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Support\DecimalMoney;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processes refunds of merchant payments.
 *
 * A refund reverses the wallet credit the merchant received for a completed
 * RECEIVE_PAYMENT: the merchant's wallet is debited and a dedicated REFUND
 * transaction is recorded. The REFUND rows are the single source of truth —
 * the refunded total is derived by summing them (see Transaction::refunds()),
 * so partial refunds add up and over-refunds are blocked without any
 * denormalized counter. The merchant's IPN endpoint is notified to reconcile.
 *
 * Scope note: this reverses the platform-side ledger. Returning funds to the
 * buyer's card is completed by the platform through the original payment
 * gateway — DigiKash never holds the buyer's card, so it cannot move card
 * money itself. See the Merchant API docs for the full boundary.
 */
class MerchantRefundService
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly WalletService $wallets,
    ) {}

    /**
     * Refund all or part of a completed merchant payment.
     *
     * @throws NotifyErrorException on any business-rule violation
     */
    public function refund(
        Merchant $merchant,
        string $trxId,
        ?float $amount = null,
        ?string $reason = null,
        ?string $refRefund = null,
        string $environment = EnvironmentMode::PRODUCTION->value,
    ): Transaction {
        return DB::transaction(function () use ($merchant, $trxId, $amount, $reason, $refRefund, $environment): Transaction {
            /** @var Transaction|null $original */
            $original = Transaction::query()
                ->where('trx_id', $trxId)
                ->lockForUpdate()
                ->first();

            // Idempotency: the same merchant refund reference never runs twice -
            // and a repeat of a refund that emptied the payment is that refund,
            // not "already fully refunded".
            if ($original !== null && $refRefund !== null && $refRefund !== '') {
                $existing = $this->findExistingRefund($trxId, $refRefund);
                if ($existing) {
                    return $existing;
                }
            }

            $this->assertRefundable($original, $merchant, $environment);

            $refundable = $original->refundableAmount();

            // Quantized at the payment's own currency. Rounding a requested
            // refund to two decimals turned every sub-cent crypto amount into
            // zero — rejected below as "must be greater than zero" — and
            // rounded a 0.005 BTC request up to 0.01, refunding twice what the
            // merchant asked for.
            $amount = $amount !== null
                ? (float) $original->quantizeToOwnCurrency($amount)
                : $refundable;

            if ($amount <= 0) {
                throw new NotifyErrorException(__('Refund amount must be greater than zero.'));
            }

            if ($amount > $refundable) {
                throw new NotifyErrorException(__('Refund amount exceeds the refundable balance (:remaining :currency remaining).', [
                    'remaining' => Money::format($refundable, $original->currency, withSymbol: false),
                    'currency'  => $original->currency,
                ]));
            }

            $isSandbox = $this->isSandbox($original);

            // Move the money back out of the merchant wallet (live mode only).
            if (! $isSandbox) {
                $wallet = Wallet::query()->where('uuid', $original->wallet_reference)->lockForUpdate()->first();

                if (! $wallet) {
                    throw new NotifyErrorException(__('Merchant wallet for this payment could not be found.'));
                }

                // The row is already locked above, so the sufficiency check
                // cannot go stale here — but the debit still goes through the
                // shared primitive so the delta reaches SQL as an exact
                // decimal. `decrement()` interpolates the float, and a
                // sub-cent crypto refund arrives as `balance - 1.0E-8`.
                if ($this->wallets->tryDebit($wallet, $amount) !== 1) {
                    throw new NotifyErrorException(__('Merchant wallet balance is insufficient to process this refund.'));
                }
            }

            // Record the refund as its own transaction against the merchant.
            $refund = $this->transactions->create(new TransactionData(
                user_id: (int) $original->user_id,
                trx_type: TrxType::REFUND,
                amount: $amount,
                amount_flow: AmountFlow::MINUS,
                fee: 0,
                currency: $original->currency,
                provider: 'merchant_api',
                processing_type: MethodType::AUTOMATIC,
                net_amount: $amount,
                payable_amount: $amount,
                payable_currency: $original->currency,
                wallet_reference: $original->wallet_reference,
                trx_reference: $original->trx_id,
                trx_data: [
                    'original_trx_id' => $original->trx_id,
                    'merchant_id'     => $merchant->id,
                    'merchant_name'   => $merchant->business_name,
                    'ref_trx'         => $original->trx_data['ref_trx'] ?? null,
                    'ref_refund'      => $refRefund,
                    'reason'          => $reason,
                    'customer_name'   => $original->trx_data['customer_name']  ?? null,
                    'customer_email'  => $original->trx_data['customer_email'] ?? null,
                    'ipn_url'         => $original->trx_data['ipn_url']        ?? null,
                    'environment'     => $environment,
                    'is_sandbox'      => $isSandbox,
                ],
                remarks: $isSandbox ? 'SANDBOX_TRANSACTION' : null,
                description: $reason ?: __('Refund for :trx', ['trx' => $original->trx_id]),
                status: TrxStatus::COMPLETED,
                // Denominated in the wallet currency already.
                exchange_rate: \Wallet::fxRate($original->currency),
            ));

            $this->returnToPayer($original, $refund, $amount, $isSandbox);

            // No denormalized counter to update — refundedAmount() now derives
            // straight from the REFUND rows, so the freshly created refund is
            // already reflected in the original's totals below.
            $this->notifyMerchant($refund, $original, $merchant, $environment);

            return $refund;
        }, 3);
    }

    /**
     * The customer's side of the refund.
     *
     * A payment made from a DigiKash wallet names its payer on the checkout
     * (`payer_user_id`, written by MerchantWalletPaymentService). The money
     * left that wallet, so it goes back there: credited under a row lock in
     * the same unit as the merchant's debit, with a REFUND row of the
     * payer's own, referencing the merchant's refund row rather than the
     * original so the original's refunded total counts each refund once. A
     * checkout paid any other way names no payer and gets nothing here, as
     * before - its money went back through its gateway, or never left a
     * wallet of ours.
     *
     * The payer paid `payable_amount` in `payable_currency` for the
     * original's `amount`; a partial refund returns the same share of it,
     * quantized to that currency.
     *
     * @throws NotifyErrorException
     */
    private function returnToPayer(Transaction $original, Transaction $refund, float $amount, bool $isSandbox): void
    {
        $payerId = (int) (($original->trx_data ?? [])['payer_user_id'] ?? 0);

        if ($payerId <= 0) {
            return;
        }

        $paidCurrency = strtoupper((string) ($original->payable_currency ?: $original->currency));
        $paidAmount   = DecimalMoney::normalize((string) ($original->payable_amount ?: $original->amount), DecimalMoney::CALCULATION_SCALE);
        $share        = DecimalMoney::compare((string) $original->amount, '0') > 0
            ? DecimalMoney::divide(DecimalMoney::multiply($paidAmount, (string) $amount, DecimalMoney::CALCULATION_SCALE), (string) $original->amount, DecimalMoney::CALCULATION_SCALE)
            : '0';
        $credit = Money::quantize($share, $paidCurrency);

        // What the merchant gave back is its net; the customer paid the gross.
        // The difference - the platform's fee on the refunded share - goes back
        // to the customer too, and the row says how much, so a reconciler can
        // tell returned fees from money the merchant returned.
        $feeReturned = strtoupper((string) $original->currency) === $paidCurrency
            ? Money::quantize(DecimalMoney::subtract($credit, (string) $amount, DecimalMoney::CALCULATION_SCALE), $paidCurrency)
            : null;

        if (DecimalMoney::compare($credit, '0') <= 0) {
            return;
        }

        $wallet = Wallet::query()
            ->where('user_id', $payerId)
            ->whereHas('currency', fn ($query) => $query->where('code', $paidCurrency))
            ->lockForUpdate()
            ->first();

        if ($wallet === null) {
            throw new NotifyErrorException(__('The customer\'s wallet for this refund could not be found.'));
        }

        if (! $isSandbox) {
            $this->wallets->creditSettlement($wallet, $credit);
        }

        $this->transactions->create(new TransactionData(
            user_id: $payerId,
            trx_type: TrxType::REFUND,
            amount: $credit,
            amount_flow: AmountFlow::PLUS,
            fee: 0,
            currency: $paidCurrency,
            provider: 'merchant_api',
            processing_type: MethodType::AUTOMATIC,
            net_amount: $credit,
            payable_amount: $credit,
            payable_currency: $paidCurrency,
            wallet_reference: $wallet->uuid,
            trx_reference: $refund->trx_id,
            trx_data: [
                'original_trx_id' => $original->trx_id,
                'refund_trx_id'   => $refund->trx_id,
                'merchant_id'     => $refund->trx_data['merchant_id']   ?? null,
                'merchant_name'   => $refund->trx_data['merchant_name'] ?? null,
                'reason'          => $refund->trx_data['reason']        ?? null,
                'environment'     => $refund->trx_data['environment']   ?? null,
                'is_sandbox'      => $isSandbox,
                'fee_returned'    => $feeReturned,
            ],
            remarks: $isSandbox ? 'SANDBOX_TRANSACTION' : null,
            description: __('Refund from :merchant', ['merchant' => (string) ($refund->trx_data['merchant_name'] ?? '')]),
            status: TrxStatus::COMPLETED,
            exchange_rate: \Wallet::fxRate($paidCurrency),
        ));
    }

    /**
     * @throws NotifyErrorException
     */
    private function assertRefundable(?Transaction $original, Merchant $merchant, string $environment): void
    {
        if (! $original) {
            throw new NotifyErrorException(__('Transaction not found.'));
        }

        $trxData = $original->trx_data ?? [];

        if ($original->trx_type !== TrxType::RECEIVE_PAYMENT || (int) ($trxData['merchant_id'] ?? 0) !== (int) $merchant->id) {
            throw new NotifyErrorException(__('Transaction not found.'));
        }

        if (($trxData['environment'] ?? null) !== null && $trxData['environment'] !== $environment) {
            throw new NotifyErrorException(__('Transaction not found.'));
        }

        if ($original->status !== TrxStatus::COMPLETED) {
            throw new NotifyErrorException(__('Only completed payments can be refunded.'));
        }

        if ($original->refundableAmount() <= 0) {
            throw new NotifyErrorException(__('This payment has already been fully refunded.'));
        }
    }

    private function findExistingRefund(string $originalTrxId, string $refRefund): ?Transaction
    {
        return Transaction::query()
            ->where('trx_type', TrxType::REFUND->value)
            ->where('trx_reference', $originalTrxId)
            ->where('trx_data->ref_refund', $refRefund)
            ->first();
    }

    private function isSandbox(Transaction $transaction): bool
    {
        $trxData = $transaction->trx_data ?? [];

        return str_contains($transaction->remarks ?? '', 'SANDBOX_TRANSACTION')
            || (bool) ($trxData['is_sandbox'] ?? false)
            || ($trxData['environment'] ?? null) === EnvironmentMode::SANDBOX->value;
    }

    /**
     * Signed IPN so the merchant's system learns about the refund, queued and
     * retried through the same delivery log as the payment's own webhook.
     * Linked to the original payment, which is the row the merchant knows.
     */
    private function notifyMerchant(Transaction $refund, Transaction $original, Merchant $merchant, string $environment): void
    {
        $payload = [
            'event'  => 'payment.refunded',
            'status' => 'refunded',
            'data'   => [
                'original_trx_id'  => $original->trx_id,
                'refund_trx_id'    => $refund->trx_id,
                'ref_trx'          => $original->trx_data['ref_trx']  ?? null,
                'ref_refund'       => $refund->trx_data['ref_refund'] ?? null,
                'refunded_amount'  => (float) $refund->amount,
                'total_refunded'   => $original->refundedAmount(),
                'remaining_refund' => $original->refundableAmount(),
                'fully_refunded'   => $original->isFullyRefunded(),
                'currency'         => $original->currency,
                'reason'           => $refund->trx_data['reason'] ?? null,
            ],
            'message'   => __('Payment refunded.'),
            'timestamp' => now()->timestamp,
        ];

        app(MerchantWebhookDispatcher::class)->queue(
            $merchant,
            $original,
            'payment.refunded',
            $payload,
            EnvironmentMode::tryFrom($environment) ?? EnvironmentMode::PRODUCTION,
        );
    }
}
