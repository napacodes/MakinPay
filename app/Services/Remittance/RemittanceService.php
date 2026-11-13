<?php

namespace App\Services\Remittance;

use App\Constants\CurrencyRole;
use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\MethodType;
use App\Enums\RemittanceStatus;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Exceptions\IdempotencyConflictException;
use App\Jobs\DispatchRemittancePayout;
use App\Models\Beneficiary;
use App\Models\RemittanceQuote;
use App\Models\RemittanceStatusEvent;
use App\Models\RemittanceTransfer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet as WalletModel;
use App\Services\Handlers\RemittanceHandler;
use App\Services\Payment\Payout\PayoutGatewayFactory;
use App\Services\Payment\Payout\PayoutResult;
use App\Services\WalletService;
use App\Support\DecimalMoney;
use Exception;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Orchestrates the full Tap-Tap-Send style international transfer:
 * validate quote → screen → debit wallet → record transaction → call
 * payout gateway → update transfer with outcome.
 */
class RemittanceService
{
    public function __construct(
        protected ScreeningService $screening,
        protected PayoutGatewayFactory $gatewayFactory,
        protected RemittanceHandler $handler,
        protected WalletService $wallets,
    ) {}

    /**
     * Confirm a remittance against a previously generated quote.
     *
     * @param array{purpose?: ?string, source_of_funds?: ?string, wallet_id: int, idempotency_key?: ?string} $context
     *
     * @throws Throwable
     */
    public function send(User $user, RemittanceQuote $quote, Beneficiary $beneficiary, array $context): RemittanceTransfer
    {
        $clientKey   = isset($context['idempotency_key']) ? trim((string) $context['idempotency_key']) : null;
        $requestHash = $clientKey ? $this->clientRequestHash($quote, $beneficiary, $context) : null;
        $wasReplay   = false;

        if ($clientKey) {
            $existing = RemittanceTransfer::query()
                ->where('user_id', $user->id)
                ->where('client_idempotency_key', $clientKey)
                ->first();

            if ($existing) {
                if (! $existing->client_request_hash || ! hash_equals($existing->client_request_hash, (string) $requestHash)) {
                    throw new IdempotencyConflictException(__('This Idempotency-Key was already used for a different request.'));
                }

                $existing->setAttribute('idempotent_replay', true);

                return $existing;
            }
        }

        $this->assertQuoteUsable($quote, $user, $beneficiary);

        $senderWallet = $this->resolveSenderWallet($user, (int) $context['wallet_id'], $quote->source_currency);

        /** @var RemittanceTransfer $transfer */
        $transfer = DB::transaction(function () use ($user, $quote, $beneficiary, $senderWallet, $context, $clientKey, $requestHash, &$wasReplay) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($clientKey) {
                $existing = RemittanceTransfer::query()
                    ->where('user_id', $user->id)
                    ->where('client_idempotency_key', $clientKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if (! $existing->client_request_hash || ! hash_equals($existing->client_request_hash, (string) $requestHash)) {
                        throw new IdempotencyConflictException(__('This Idempotency-Key was already used for a different request.'));
                    }

                    $wasReplay = true;

                    return $existing;
                }
            }

            $lockedQuote = RemittanceQuote::query()
                ->with('corridor')
                ->lockForUpdate()
                ->find($quote->id);

            if (! $lockedQuote || ! $lockedQuote->isUsable()) {
                throw new RuntimeException(__('This quote has expired or has already been used. Please get a fresh quote.'));
            }

            $lockedBeneficiary = Beneficiary::query()->lockForUpdate()->find($beneficiary->id);

            if (! $lockedBeneficiary) {
                throw new RuntimeException(__('Selected beneficiary was not found.'));
            }

            $this->assertQuoteUsable($lockedQuote, $user, $lockedBeneficiary);

            $this->gatewayFactory
                ->getGateway($lockedQuote->corridor->payout_gateway)
                ->validateBeneficiary($lockedBeneficiary);

            $compliance = $this->screening->screen(
                sender: $lockedUser,
                beneficiary: $lockedBeneficiary,
                sendAmountSourceCurrency: $lockedQuote->send_amount,
                requiredKycTier: (int) ($lockedQuote->corridor->required_kyc_tier ?? 0),
                sourceCurrency: $lockedQuote->source_currency,
            );

            if (! $compliance['passed']) {
                throw new RuntimeException($compliance['reason'] ?? __('Compliance screening failed.'));
            }

            $lockedWallet = WalletModel::query()
                ->with(['currency.roles'])
                ->lockForUpdate()
                ->find($senderWallet->id);

            if (! $lockedWallet || DecimalMoney::compare($lockedWallet->balance, $lockedQuote->total_pay) < 0) {
                throw new RuntimeException(__('Insufficient balance.'));
            }

            $this->wallets->assertOperational($lockedWallet, CurrencyRole::SENDER);

            // Locked above, so the check cannot go stale — the shared primitive
            // is used for the delta: `decrement()` interpolates the float, and
            // a small enough payout reaches SQL in exponent notation.
            if ($this->wallets->tryDebit($lockedWallet, $lockedQuote->total_pay) !== 1) {
                throw new RuntimeException(__('Insufficient balance.'));
            }

            $lockedTax = $lockedQuote->tax_amount === null ? null : (string) $lockedQuote->tax_amount;

            $transactionData = new TransactionData(
                user_id: $user->id,
                trx_type: TrxType::REMITTANCE_SEND,
                amount: (float) $lockedQuote->send_amount,
                amount_flow: AmountFlow::MINUS,
                fee: (float) $lockedQuote->fee_amount,
                currency: $lockedQuote->source_currency,
                provider: $lockedQuote->corridor->payout_gateway,
                processing_type: MethodType::AUTOMATIC,
                net_amount: (float) $lockedQuote->send_amount,
                payable_amount: (float) $lockedQuote->total_pay,
                payable_currency: $lockedWallet->currency->code,
                wallet_reference: $lockedWallet->uuid,
                trx_data: [
                    'quote_id'             => $lockedQuote->quote_id,
                    'beneficiary_id'       => $lockedBeneficiary->id,
                    'beneficiary_name'     => $lockedBeneficiary->full_name,
                    'destination_country'  => $lockedBeneficiary->country_code,
                    'destination_currency' => $lockedQuote->destination_currency,
                    'receive_amount'       => $lockedQuote->receive_amount,
                    'exchange_rate'        => $lockedQuote->exchange_rate,
                ],
                description: __('Sending :amount :currency to :name (:country)', [
                    'amount'   => $lockedQuote->send_amount,
                    'currency' => $lockedQuote->source_currency,
                    'name'     => $lockedBeneficiary->full_name,
                    'country'  => $lockedBeneficiary->country_code,
                ]),
                status: TrxStatus::PENDING,
                exchange_rate: \Wallet::fxRate($lockedWallet),
                // GF6: the tax the quote locked, inside payable_amount and
                // apart from the fee; restated to the site currency at the
                // rate this row records.
                wallet_tax: $lockedTax,
                base_tax: $lockedTax === null ? null : DecimalMoney::divide($lockedTax, \Wallet::fxRate($lockedWallet), DecimalMoney::CALCULATION_SCALE),
                tax_breakdown: $lockedQuote->tax_breakdown,
            );

            $transaction = Transaction::create($transactionData->toArray());

            $transfer = RemittanceTransfer::create([
                'user_id'                  => $user->id,
                'quote_id'                 => $lockedQuote->id,
                'beneficiary_id'           => $lockedBeneficiary->id,
                'corridor_id'              => $lockedQuote->corridor_id,
                'transaction_id'           => $transaction->id,
                'source_currency'          => $lockedQuote->source_currency,
                'destination_currency'     => $lockedQuote->destination_currency,
                'send_amount'              => $lockedQuote->send_amount,
                'fee_amount'               => $lockedQuote->fee_amount,
                'tax_amount'               => $lockedQuote->tax_amount,
                'tax_breakdown'            => $lockedQuote->tax_breakdown,
                'total_paid'               => $lockedQuote->total_pay,
                'exchange_rate'            => $lockedQuote->exchange_rate,
                'receive_amount'           => $lockedQuote->receive_amount,
                'payout_method'            => $lockedQuote->payout_method,
                'payout_gateway'           => $lockedQuote->corridor->payout_gateway,
                'provider_idempotency_key' => hash('sha256', $lockedQuote->quote_id),
                'client_idempotency_key'   => $clientKey,
                'client_request_hash'      => $requestHash,
                'recipient_snapshot'       => $this->recipientSnapshot($lockedBeneficiary),
                'dispatch_state'           => 'scheduled',
                'status'                   => RemittanceStatus::Pending,
                'compliance_result'        => $compliance,
                'purpose'                  => $context['purpose']         ?? null,
                'source_of_funds'          => $context['source_of_funds'] ?? null,
            ]);

            $transfer->recordStatus(RemittanceStatus::Pending, __('Wallet debited; awaiting payout submission.'));

            $lockedQuote->update(['is_consumed' => true]);
            $lockedBeneficiary->update(['last_used_at' => now()]);

            return $transfer;
        });

        if ($wasReplay) {
            $transfer->setAttribute('idempotent_replay', true);

            return $transfer;
        }

        DispatchRemittancePayout::dispatch($transfer->id)->afterCommit();

        try {
            $this->handler->handleSubmitted($transfer->transaction);
        } catch (Throwable $exception) {
            report($exception);
        }

        $transfer = $transfer->fresh();
        $transfer->setAttribute('idempotent_replay', false);

        return $transfer;
    }

    /**
     * @param array{purpose?: ?string, source_of_funds?: ?string, wallet_id: int, idempotency_key?: ?string} $context
     */
    protected function clientRequestHash(RemittanceQuote $quote, Beneficiary $beneficiary, array $context): string
    {
        return hash('sha256', (string) json_encode([
            'quote_id'        => $quote->quote_id,
            'beneficiary_id'  => $beneficiary->id,
            'wallet_id'       => (int) $context['wallet_id'],
            'purpose'         => $context['purpose']         ?? null,
            'source_of_funds' => $context['source_of_funds'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Hand off a pending transfer to its payout driver and react to the result.
     */
    public function dispatchToGateway(RemittanceTransfer $transfer): RemittanceTransfer
    {
        return $this->dispatchToGatewayById($transfer->id);
    }

    public function dispatchToGatewayById(int $transferId): RemittanceTransfer
    {
        $operation = null;
        $transfer  = DB::transaction(function () use ($transferId, &$operation): RemittanceTransfer {
            $lockedTransfer = RemittanceTransfer::query()->lockForUpdate()->findOrFail($transferId);

            if ($lockedTransfer->status !== RemittanceStatus::Pending) {
                return $lockedTransfer;
            }

            if (! in_array($lockedTransfer->dispatch_state, ['scheduled', 'ambiguous'], true)) {
                return $lockedTransfer;
            }

            if ($lockedTransfer->dispatch_state === 'ambiguous') {
                if (blank($lockedTransfer->gateway_reference)) {
                    return $lockedTransfer;
                }

                $operation = 'reconcile';
                $lockedTransfer->update(['last_dispatch_at' => now()]);

                return $lockedTransfer->fresh(['beneficiary', 'corridor']);
            }

            $operation = 'payout';
            $lockedTransfer->update([
                'dispatch_state'    => 'attempting',
                'dispatch_attempts' => $lockedTransfer->dispatch_attempts + 1,
                'last_dispatch_at'  => now(),
                'failure_reason'    => null,
            ]);

            return $lockedTransfer->fresh(['beneficiary', 'corridor']);
        }, 3);

        if ($transfer->status !== RemittanceStatus::Pending || $operation === null) {
            return $transfer;
        }

        try {
            $gateway = $this->gatewayFactory->getGateway($transfer->payout_gateway);
            $result  = $operation === 'reconcile'
                ? $gateway->checkStatus($transfer)
                : $gateway->payout($transfer);
        } catch (Throwable $exception) {
            $this->recordAmbiguousDispatchFailure($transfer->id, $exception->getMessage());

            throw $exception;
        }

        DB::transaction(function () use ($transfer, $result): void {
            $lockedTransfer = RemittanceTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($lockedTransfer->status !== RemittanceStatus::Pending) {
                return;
            }

            $lockedTransfer->update([
                'gateway_reference' => $result->gatewayReference ?? $lockedTransfer->gateway_reference,
                'gateway_payload'   => $result->payload,
                'dispatch_state'    => match ($result->status) {
                    RemittanceStatus::Failed  => 'definitively_failed',
                    RemittanceStatus::Pending => 'ambiguous',
                    default                   => 'confirmed',
                },
            ]);

            $lockedTransfer->recordStatus($result->status, $result->message);
            $this->reconcileTransaction($lockedTransfer, $result);
        }, 3);

        $transfer = $transfer->fresh();

        if ($result->isFailed()) {
            return $this->refund($transfer, $result->message ?? __('Payout provider rejected the transfer.'));
        }

        return $transfer;
    }

    /**
     * @param array{event_id: string, transfer_reference: string, status: string, occurred_at: string, gateway_reference?: ?string, message?: ?string} $payload
     */
    public function applyWebhookStatus(string $gateway, array $payload, string $payloadHash): RemittanceTransfer
    {
        $source       = 'webhook:'.$gateway;
        $shouldRefund = false;

        $transfer = DB::transaction(function () use ($gateway, $payload, $payloadHash, $source, &$shouldRefund): RemittanceTransfer {
            $duplicate = RemittanceStatusEvent::query()
                ->where('source', $source)
                ->where('external_event_id', $payload['event_id'])
                ->first();

            if ($duplicate) {
                $existing     = RemittanceTransfer::query()->lockForUpdate()->findOrFail($duplicate->transfer_id);
                $shouldRefund = $existing->status === RemittanceStatus::Failed
                    && $existing->dispatch_state  === 'definitively_failed';

                return $existing;
            }

            $lockedTransfer = RemittanceTransfer::query()
                ->where('payout_gateway', $gateway)
                ->where(function ($query) use ($payload): void {
                    $query->where('reference', $payload['transfer_reference'])
                        ->orWhere('provider_idempotency_key', $payload['transfer_reference'])
                        ->orWhere('gateway_reference', $payload['transfer_reference']);
                })
                ->lockForUpdate()
                ->first();

            if (! $lockedTransfer) {
                throw new RuntimeException(__('Webhook transfer reference was not found.'));
            }

            $requestedStatus = RemittanceStatus::from($payload['status']);
            $latestWebhook   = $lockedTransfer->statusEvents()
                ->where('source', $source)
                ->latest('occurred_at')
                ->first();
            $isStale = $latestWebhook
                && strtotime($payload['occurred_at']) < $latestWebhook->occurred_at->timestamp;

            if ($isStale || ! $this->isWebhookTransitionAllowed($lockedTransfer->status, $requestedStatus)) {
                $lockedTransfer->statusEvents()->create([
                    'from_status'       => $lockedTransfer->status->value,
                    'to_status'         => $lockedTransfer->status->value,
                    'note'              => __('Ignored stale or invalid provider status callback.'),
                    'actor_type'        => 'provider',
                    'source'            => $source,
                    'external_event_id' => $payload['event_id'],
                    'payload_hash'      => $payloadHash,
                    'context'           => [
                        'requested_status' => $requestedStatus->value,
                        'occurred_at'      => $payload['occurred_at'],
                        'ignored'          => true,
                    ],
                    'occurred_at' => $payload['occurred_at'],
                ]);

                return $lockedTransfer;
            }

            $result = new PayoutResult(
                status: $requestedStatus,
                gatewayReference: $payload['gateway_reference'] ?? $lockedTransfer->gateway_reference,
                message: $payload['message']                    ?? __('Payout provider status update.'),
                payload: [
                    'source'       => $source,
                    'event_id'     => $payload['event_id'],
                    'payload_hash' => $payloadHash,
                ],
            );

            $lockedTransfer->update([
                'gateway_reference' => $result->gatewayReference,
                'gateway_payload'   => $result->payload,
                'dispatch_state'    => $requestedStatus === RemittanceStatus::Failed
                    ? 'definitively_failed'
                    : 'confirmed',
            ]);
            $lockedTransfer->recordStatus($requestedStatus, $result->message, [
                'actor_type'        => 'provider',
                'source'            => $source,
                'external_event_id' => $payload['event_id'],
                'payload_hash'      => $payloadHash,
                'occurred_at'       => $payload['occurred_at'],
            ]);
            $this->reconcileTransaction($lockedTransfer, $result);
            $shouldRefund = $requestedStatus === RemittanceStatus::Failed;

            return $lockedTransfer;
        }, 3);

        if ($shouldRefund) {
            return $this->refund($transfer->fresh(), $payload['message'] ?? __('Payout provider rejected the transfer.'));
        }

        return $transfer->fresh();
    }

    protected function isWebhookTransitionAllowed(RemittanceStatus $current, RemittanceStatus $requested): bool
    {
        if ($current === $requested) {
            return true;
        }

        return match ($current) {
            RemittanceStatus::Pending => in_array($requested, [
                RemittanceStatus::Submitted,
                RemittanceStatus::Processing,
                RemittanceStatus::Completed,
                RemittanceStatus::Failed,
            ], true),
            RemittanceStatus::Submitted => in_array($requested, [
                RemittanceStatus::Processing,
                RemittanceStatus::Completed,
                RemittanceStatus::Failed,
            ], true),
            RemittanceStatus::Processing => in_array($requested, [
                RemittanceStatus::Completed,
                RemittanceStatus::Failed,
            ], true),
            default => false,
        };
    }

    public function recordAmbiguousDispatchFailure(int $transferId, string $message): void
    {
        RemittanceTransfer::query()
            ->whereKey($transferId)
            ->where('status', RemittanceStatus::Pending->value)
            ->update([
                'dispatch_state'  => 'ambiguous',
                'failure_reason'  => __('Payout status is uncertain and requires reconciliation.'),
                'gateway_payload' => ['dispatch_error' => mb_substr($message, 0, 500)],
            ]);
    }

    public function reconcileProviderStatusById(int $transferId): RemittanceTransfer
    {
        $inFlightStatuses = [
            RemittanceStatus::Submitted->value,
            RemittanceStatus::Processing->value,
        ];

        $transfer = DB::transaction(function () use ($transferId, $inFlightStatuses): RemittanceTransfer {
            $lockedTransfer = RemittanceTransfer::query()->lockForUpdate()->findOrFail($transferId);

            if (! in_array($lockedTransfer->status->value, $inFlightStatuses, true) || $lockedTransfer->payout_gateway === 'manual') {
                return $lockedTransfer;
            }

            $updates = [
                'last_reconciled_at'      => now(),
                'reconciliation_attempts' => $lockedTransfer->reconciliation_attempts + 1,
                'reconciliation_error'    => null,
            ];

            if (blank($lockedTransfer->gateway_reference) || blank($lockedTransfer->provider_idempotency_key)) {
                $updates['reconciliation_error'] = __('Provider references are missing; automatic reconciliation is unsafe.');
            }

            $lockedTransfer->update($updates);

            return $lockedTransfer->fresh(['beneficiary', 'corridor']);
        }, 3);

        if (! in_array($transfer->status->value, $inFlightStatuses, true)
            || $transfer->payout_gateway === 'manual'
            || filled($transfer->reconciliation_error)) {
            return $transfer;
        }

        try {
            $result = $this->gatewayFactory
                ->getGateway($transfer->payout_gateway)
                ->checkStatus($transfer);
        } catch (Throwable $exception) {
            RemittanceTransfer::query()
                ->whereKey($transfer->id)
                ->whereIn('status', $inFlightStatuses)
                ->update([
                    'reconciliation_error' => mb_substr(strip_tags($exception->getMessage()), 0, 500),
                ]);

            throw $exception;
        }

        $shouldRefund = false;

        $transfer = DB::transaction(function () use ($transfer, $result, $inFlightStatuses, &$shouldRefund): RemittanceTransfer {
            $lockedTransfer = RemittanceTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if (! in_array($lockedTransfer->status->value, $inFlightStatuses, true)) {
                return $lockedTransfer;
            }

            $source = 'reconciliation:'.$lockedTransfer->payout_gateway;

            if ($result->status === $lockedTransfer->status) {
                $lockedTransfer->update([
                    'gateway_reference'    => $result->gatewayReference ?? $lockedTransfer->gateway_reference,
                    'gateway_payload'      => $result->payload,
                    'dispatch_state'       => 'confirmed',
                    'reconciliation_error' => null,
                ]);

                return $lockedTransfer;
            }

            if (! $this->isWebhookTransitionAllowed($lockedTransfer->status, $result->status)) {
                $message = __('Ignored non-forward provider status during reconciliation.');
                $lockedTransfer->update(['reconciliation_error' => $message]);
                $lockedTransfer->statusEvents()->create([
                    'from_status' => $lockedTransfer->status->value,
                    'to_status'   => $lockedTransfer->status->value,
                    'note'        => $message,
                    'actor_type'  => 'system',
                    'source'      => $source,
                    'context'     => [
                        'requested_status' => $result->status->value,
                        'ignored'          => true,
                    ],
                    'occurred_at' => now(),
                ]);

                return $lockedTransfer;
            }

            $lockedTransfer->update([
                'gateway_reference'    => $result->gatewayReference ?? $lockedTransfer->gateway_reference,
                'gateway_payload'      => $result->payload,
                'dispatch_state'       => $result->status === RemittanceStatus::Failed ? 'definitively_failed' : 'confirmed',
                'reconciliation_error' => null,
            ]);
            $lockedTransfer->recordStatus($result->status, $result->message, [
                'actor_type' => 'system',
                'source'     => $source,
            ]);
            $this->reconcileTransaction($lockedTransfer, $result);
            $shouldRefund = $result->isFailed();

            return $lockedTransfer;
        }, 3);

        if ($shouldRefund) {
            return $this->refund($transfer->fresh(), $result->message ?? __('Payout provider rejected the transfer.'));
        }

        return $transfer->fresh();
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function completeManually(RemittanceTransfer $transfer, array $actor = []): RemittanceTransfer
    {
        DB::transaction(function () use ($transfer, $actor): void {
            $lockedTransfer = RemittanceTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($lockedTransfer->payout_gateway !== 'manual') {
                throw new RuntimeException(__('Only manual payouts can be completed from this action.'));
            }

            if (! in_array($lockedTransfer->status, [
                RemittanceStatus::Pending,
                RemittanceStatus::Submitted,
                RemittanceStatus::Processing,
            ], true)) {
                throw new RuntimeException(__('This transfer can no longer be approved.'));
            }

            $result = new PayoutResult(
                status: RemittanceStatus::Completed,
                gatewayReference: $lockedTransfer->gateway_reference ?? 'MAN-'.strtoupper(substr((string) ($lockedTransfer->provider_idempotency_key ?? $lockedTransfer->reference), 0, 16)),
                message: __('Manually approved by admin.'),
                payload: ['driver' => 'manual', 'action' => 'approved'],
            );

            $lockedTransfer->update([
                'gateway_reference' => $result->gatewayReference,
                'gateway_payload'   => $result->payload,
                'dispatch_state'    => 'confirmed',
            ]);
            $lockedTransfer->recordStatus(RemittanceStatus::Completed, $result->message, $actor);
            $this->reconcileTransaction($lockedTransfer, $result);
        }, 3);

        return $transfer->fresh();
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function failManually(RemittanceTransfer $transfer, string $reason, array $actor = []): RemittanceTransfer
    {
        /*
         * RM1: ONE TRANSACTION for the failure and its refund. They used to
         * commit separately, so a crash, a deadlock or a refusal from
         * refund() in between left a transfer marked failed - its sender
         * told so - with the money still held and no way back through this
         * method, which only fails a transfer that is not failed yet. Now
         * either both happen or neither does; refund() keeps its own locks
         * and checks, run as a savepoint inside this one.
         */
        return DB::transaction(function () use ($transfer, $reason, $actor): RemittanceTransfer {
            $lockedTransfer = RemittanceTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($lockedTransfer->payout_gateway !== 'manual') {
                throw new RuntimeException(__('Automated payouts must be reconciled with their provider before failure.'));
            }

            if (! in_array($lockedTransfer->status, [
                RemittanceStatus::Pending,
                RemittanceStatus::Submitted,
                RemittanceStatus::Processing,
            ], true)) {
                throw new RuntimeException(__('This transfer can no longer be marked as failed.'));
            }

            $result = new PayoutResult(
                status: RemittanceStatus::Failed,
                message: $reason,
                payload: ['driver' => 'manual', 'action' => 'failed'],
            );

            $lockedTransfer->update([
                'gateway_payload' => $result->payload,
                'dispatch_state'  => 'definitively_failed',
            ]);
            $lockedTransfer->recordStatus(RemittanceStatus::Failed, $reason, $actor);
            $this->reconcileTransaction($lockedTransfer, $result);

            return $this->refund($lockedTransfer->fresh(), $reason);
        }, 3);
    }

    /**
     * Refund a pending/failed transfer back to the sender wallet.
     *
     * @throws Throwable
     */
    public function refund(RemittanceTransfer $transfer, ?string $reason = null): RemittanceTransfer
    {
        if ($transfer->status === RemittanceStatus::Refunded) {
            throw new RuntimeException(__('This transfer has already been refunded.'));
        }

        if ($transfer->status === RemittanceStatus::Completed) {
            throw new RuntimeException(__('Completed transfers cannot be auto-refunded.'));
        }

        DB::transaction(function () use ($transfer, $reason): void {
            // Re-fetch with row lock to defend against concurrent refunds.
            $lockedTransfer = RemittanceTransfer::lockForUpdate()->find($transfer->id);

            if (! $lockedTransfer || $lockedTransfer->status === RemittanceStatus::Refunded) {
                throw new RuntimeException(__('This transfer has already been refunded.'));
            }

            $safeBeforeDispatch = $lockedTransfer->status === RemittanceStatus::Pending
                && in_array($lockedTransfer->dispatch_state, ['not_started', 'scheduled'], true)
                && $lockedTransfer->dispatch_attempts     === 0;
            $definitivelyFailed = $lockedTransfer->status === RemittanceStatus::Failed
                && $lockedTransfer->dispatch_state        === 'definitively_failed';

            if (! $safeBeforeDispatch && ! $definitivelyFailed) {
                throw new RuntimeException(__('This transfer cannot be refunded until the payout status is definitively resolved.'));
            }

            $originalTrx = Transaction::query()->lockForUpdate()->find($lockedTransfer->transaction_id);

            if (! $originalTrx) {
                throw new RuntimeException(__('Original remittance transaction was not found. Refund requires reconciliation.'));
            }

            $wallet = WalletModel::query()
                ->where('uuid', $originalTrx->wallet_reference)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                throw new RuntimeException(__('Sender wallet was not found. Refund requires reconciliation.'));
            }

            /*
             * Read as stored, not through the model's decimal:2 cast: a
             * 3-decimal or crypto wallet was debited every digit of
             * total_paid, and the cast would refund it rounded.
             */
            $refundAmount = DecimalMoney::normalize((string) $lockedTransfer->getRawOriginal('total_paid'), DecimalMoney::CALCULATION_SCALE);

            $this->wallets->creditSettlement($wallet, $refundAmount);

            $refundData = new TransactionData(
                user_id: $lockedTransfer->user_id,
                trx_type: TrxType::REMITTANCE_REFUND,
                amount: $refundAmount,
                amount_flow: AmountFlow::PLUS,
                fee: 0,
                currency: $lockedTransfer->source_currency,
                provider: $lockedTransfer->payout_gateway,
                processing_type: MethodType::AUTOMATIC,
                net_amount: $refundAmount,
                payable_amount: $refundAmount,
                payable_currency: $lockedTransfer->source_currency,
                wallet_reference: $originalTrx->wallet_reference,
                trx_reference: (string) $originalTrx->id,
                trx_data: [
                    'transfer_reference' => $lockedTransfer->reference,
                    'reason'             => $reason,
                ],
                description: __('Refund for remittance :ref', ['ref' => $lockedTransfer->reference]),
                status: TrxStatus::COMPLETED,
                exchange_rate: \Wallet::fxRate($lockedTransfer->source_currency),
            );

            $refundTransaction = Transaction::create($refundData->toArray());

            $originalTrx->update(['status' => TrxStatus::CANCELED]);
            $lockedTransfer->update(['refund_transaction_id' => $refundTransaction->id]);

            $lockedTransfer->recordStatus(RemittanceStatus::Refunded, $reason ?? __('Refunded to sender wallet.'));
        });

        return $transfer->fresh();
    }

    protected function reconcileTransaction(RemittanceTransfer $transfer, PayoutResult $result): void
    {
        $transaction = $transfer->transaction?->fresh();

        if (! $transaction) {
            return;
        }

        if ($result->status === RemittanceStatus::Completed) {
            $transaction->update(['status' => TrxStatus::COMPLETED]);
            $this->handler->handleSuccess($transaction);

            return;
        }

        if ($result->isFailed()) {
            $transaction->update([
                'status'  => TrxStatus::FAILED,
                'remarks' => $result->message,
            ]);
            $this->handler->handleFail($transaction);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function assertQuoteUsable(RemittanceQuote $quote, User $user, Beneficiary $beneficiary): void
    {
        if ($quote->user_id !== $user->id) {
            throw new InvalidArgumentException(__('Quote does not belong to current user.'));
        }

        if (! $quote->isUsable()) {
            throw new InvalidArgumentException(__('This quote has expired or has already been used. Please get a fresh quote.'));
        }

        if ($beneficiary->user_id !== $user->id) {
            throw new InvalidArgumentException(__('Beneficiary does not belong to current user.'));
        }

        if (strtoupper($beneficiary->currency) !== strtoupper($quote->destination_currency)) {
            throw new InvalidArgumentException(__('Beneficiary currency does not match the quote destination currency.'));
        }

        if (strtoupper($beneficiary->country_code) !== strtoupper($quote->corridor->destination_country)) {
            throw new InvalidArgumentException(__('Beneficiary country does not match the quote destination country.'));
        }

        if ($beneficiary->payout_method !== $quote->payout_method) {
            throw new InvalidArgumentException(__('Beneficiary payout method does not match the quote.'));
        }

        if (! $this->gatewayFactory->supports($quote->corridor->payout_gateway)) {
            throw new InvalidArgumentException(__('The payout provider for this quote is unavailable.'));
        }
    }

    /**
     * @throws Exception
     */
    protected function resolveSenderWallet(User $user, int $walletId, string $sourceCurrency): WalletModel
    {
        $wallet = $user->wallets()
            ->with(['currency.roles'])
            ->find($walletId);

        if (! $wallet) {
            throw new InvalidArgumentException(__('Selected wallet was not found.'));
        }

        $this->wallets->assertOperational($wallet, CurrencyRole::SENDER);

        if (strtoupper($wallet->currency->code) !== strtoupper($sourceCurrency)) {
            throw new InvalidArgumentException(__('Wallet currency does not match the quote source currency.'));
        }

        return $wallet;
    }

    /**
     * @return array<string, mixed>
     */
    protected function recipientSnapshot(Beneficiary $beneficiary): array
    {
        return [
            'full_name'       => $beneficiary->full_name,
            'country_code'    => strtoupper($beneficiary->country_code),
            'currency'        => strtoupper($beneficiary->currency),
            'payout_method'   => $beneficiary->payout_method->value,
            'phone_number'    => $beneficiary->phone_number,
            'email'           => $beneficiary->email,
            'account_details' => $beneficiary->account_details,
            'captured_at'     => now()->toIso8601String(),
        ];
    }
}
