<?php

namespace App\Services\Payment\Bitnob;

use App\Enums\RemittanceStatus;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Enums\VirtualCard\VirtualCardStatus;
use App\Models\RemittanceTransfer;
use App\Models\Transaction;
use App\Models\VirtualCard;
use App\Services\Bitnob\BitnobDepositService;
use App\Services\Bitnob\BitnobProviderSettings;
use App\Services\MassPayment\MassPayoutReconciler;
use App\Services\Remittance\RemittanceService;
use App\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Processes authenticated Bitnob events after the global IPN dispatcher
 * resolves the Bitnob gateway driver.
 *
 * Signature verification belongs to BitnobPaymentGateway. This handler
 * dispatches the provider's versioned protocol events. ALL paths return 200 so Bitnob
 * doesn't enter its 3-retry storm — failures are logged for ops review.
 */
class BitnobWebhookHandler
{
    public function __construct(
        protected BitnobDepositService $deposits,
        protected RemittanceService $remittances,
        protected BitnobProviderSettings $settings,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $event  = (string) $request->input('event', '');
        $method = $this->settings->webhookHandler($event);

        if ($method === null) {
            // Bitnob keeps adding event names (and transaction-shaped payloads
            // carry `type` instead of `event`). Rather than silently dropping a
            // settled deposit, credit it when the payload confirms funds landed
            // on an address we generated — a wallet we own is never the
            // destination of one of our own payouts.
            if ($this->looksLikeSettledDeposit($request->all())) {
                Log::info('Bitnob webhook: crediting settled deposit from an unmapped event', [
                    'event' => $event,
                    'type'  => $request->input('type'),
                ]);

                $method = 'handleStablecoinDepositSuccess';
            } else {
                Log::info('Bitnob webhook received unknown event', [
                    'event'   => $event,
                    'type'    => $request->input('type'),
                    'payload' => $request->all(),
                ]);

                return response()->json(['ok' => true, 'ignored' => $event]);
            }
        }

        try {
            $this->{$method}($request->all());
        } catch (\Throwable $e) {
            Log::error('Bitnob webhook handler error', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['ok' => false], 503);
        }

        return response()->json(['ok' => true]);
    }

    /* ------------------------- Card events --------------------------- */

    /**
     * @param array<string, mixed> $event
     */
    protected function handleCardCreated(array $event): void
    {
        $cardId = $event['cardId'] ?? $event['id'] ?? null;
        $card   = $this->findCard($cardId, $event['reference'] ?? null);
        if (! $card) {
            return;
        }
        $card->update(['status' => VirtualCardStatus::Active->value]);
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function handleCardCreationFailed(array $event): void
    {
        $card = $this->findCard($event['cardId'] ?? null, $event['reference'] ?? null);
        if (! $card) {
            return;
        }
        $card->update(['status' => VirtualCardStatus::Blocked->value]);
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function handleCardTopupSuccess(array $event): void
    {
        $this->markCardTransactionStatus($event, TrxStatus::COMPLETED);
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function handleCardTopupFailed(array $event): void
    {
        $this->markCardTransactionStatus($event, TrxStatus::FAILED);
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function handleCardWithdrawalSuccess(array $event): void
    {
        $this->markCardTransactionStatus($event, TrxStatus::COMPLETED);
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function handleCardWithdrawalFailed(array $event): void
    {
        $this->markCardTransactionStatus($event, TrxStatus::FAILED);
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function handleCardDebit(array $event): void
    {
        // Authorization debit — append to the card's meta for audit. We don't
        // own the wallet ledger for card debits (Bitnob holds the float).
        $card = $this->findCard($event['cardId'] ?? null);
        if (! $card) {
            return;
        }
        $meta                  = $card->meta ?? [];
        $meta['last_debit']    = $event;
        $meta['last_debit_at'] = now()->toIso8601String();
        $card->update(['meta' => $meta]);
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function handleCardReversal(array $event): void
    {
        $card = $this->findCard($event['cardId'] ?? null);
        if (! $card) {
            return;
        }
        $meta                  = $card->meta ?? [];
        $meta['last_reversal'] = $event;
        $card->update(['meta' => $meta]);
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function handleCardDeclined(array $event): void
    {
        $card = $this->findCard($event['cardId'] ?? null);
        if (! $card) {
            return;
        }
        $meta                  = $card->meta ?? [];
        $meta['last_declined'] = $event;
        $card->update(['meta' => $meta]);
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function handleCardTerminated(array $event): void
    {
        $card = $this->findCard($event['cardId'] ?? null);
        if (! $card) {
            return;
        }
        $card->update(['status' => VirtualCardStatus::Blocked->value]);
    }

    /* ------------------------- Deposit events ------------------------ */

    /**
     * @param array<string, mixed> $event
     */
    protected function handleStablecoinDepositSuccess(array $event): void
    {
        $this->deposits->applyDepositSuccess($event);
    }

    /**
     * The current Bitnob stablecoins API emits a single `transfer.success` /
     * `transfer.failed` event for BOTH incoming deposits and outgoing sends.
     * Route by the reference so an outgoing payout is never mistaken for a
     * deposit (which would wrongly credit the wallet). The legacy
     * `stablecoin.deposit.success` / `payouts.withdrawal.*` events keep their
     * dedicated handlers.
     *
     * @param array<string, mixed> $event
     */
    protected function handleStablecoinTransfer(array $event): void
    {
        if ($this->transferMatchesDeposit($event)) {
            $this->handleStablecoinDepositSuccess($event);

            return;
        }

        $this->handlePayoutSuccess($event);
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function handleStablecoinTransferFailed(array $event): void
    {
        if ($this->transferMatchesDeposit($event)) {
            // A failed inbound deposit needs no wallet action — the pending
            // deposit simply never completes. Log it for reconciliation.
            Log::warning('Bitnob stablecoin deposit transfer failed', [
                'reference' => $this->transferReference($event),
            ]);

            return;
        }

        $this->handlePayoutFailed($event);
    }

    /**
     * Whether the transfer belongs to one of our pending crypto deposits
     * (vs an outgoing payout/withdrawal). Legacy events echo our reference;
     * the current stablecoins API only carries chain + address, so the
     * generated deposit address is matched too.
     *
     * @param array<string, mixed> $event
     */
    private function transferMatchesDeposit(array $event): bool
    {
        $reference = $this->transferReference($event);

        if ($reference !== '' && Transaction::query()
            ->where('trx_type', TrxType::DEPOSIT)
            ->where(function ($query) use ($reference): void {
                $query->where('trx_id', $reference)
                    ->orWhere('trx_data->bitnob_reference', $reference);
            })
            ->exists()) {
            return true;
        }

        $address = BitnobDepositService::eventAddress($event);

        return $address !== '' && Transaction::query()
            ->where('trx_type', TrxType::DEPOSIT)
            ->where('trx_data->bitnob_deposit_wallet', $address)
            ->exists();
    }

    /**
     * @param array<string, mixed> $event
     */
    private function transferReference(array $event): string
    {
        return BitnobDepositService::eventReference($event);
    }

    /**
     * Whether an unmapped payload is a confirmed incoming deposit for one of
     * our generated addresses. Both a success-like status and a matching
     * pending deposit are required, so pending/failed notices and outgoing
     * transfers never credit a wallet.
     *
     * @param array<string, mixed> $event
     */
    private function looksLikeSettledDeposit(array $event): bool
    {
        // Deposit payloads carry no status field — the event name is the
        // outcome ("deposit.success") — so it is part of the haystack.
        $status = strtolower(implode(' ', array_filter([
            (string) (data_get($event, 'event') ?? ''),
            (string) (data_get($event, 'status') ?? ''),
            (string) (data_get($event, 'state') ?? ''),
            (string) (data_get($event, 'type') ?? ''),
            (string) (data_get($event, 'data.status') ?? ''),
            (string) (data_get($event, 'data.state') ?? ''),
            (string) (data_get($event, 'data.type') ?? ''),
        ])));

        if (str_contains($status, 'fail') || str_contains($status, 'pending') || str_contains($status, 'reject')) {
            return false;
        }

        $settled = collect(['settled', 'success', 'confirmed', 'completed', 'complete'])
            ->contains(fn (string $needle): bool => str_contains($status, $needle));

        if (! $settled) {
            return false;
        }

        $address = BitnobDepositService::eventAddress($event);

        return $address !== '' && Transaction::query()
            ->where('trx_type', TrxType::DEPOSIT)
            ->where('status', TrxStatus::PENDING)
            ->where('trx_data->bitnob_deposit_wallet', $address)
            ->exists();
    }

    /* ------------------------- Payout events ------------------------- */

    /**
     * @param array<string, mixed> $event
     */
    protected function handlePayoutSuccess(array $event): void
    {
        if ($this->noteMassPayoutEvent($event)) {
            return;
        }

        if ($this->applyRemittancePayoutEvent($event, RemittanceStatus::Completed)) {
            return;
        }

        $this->markPayoutTransactionStatus($event, TrxStatus::COMPLETED);
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function handlePayoutProcessing(array $event): void
    {
        if ($this->noteMassPayoutEvent($event)) {
            return;
        }

        $this->applyRemittancePayoutEvent($event, RemittanceStatus::Processing);
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function handlePayoutFailed(array $event): void
    {
        if ($this->noteMassPayoutEvent($event)) {
            return;
        }

        if ($this->applyRemittancePayoutEvent($event, RemittanceStatus::Failed)) {
            return;
        }

        $this->markPayoutTransactionStatus($event, TrxStatus::FAILED);
    }

    /* --------------------------- Helpers ----------------------------- */

    protected function findCard(?string $providerCardId, ?string $reference = null): ?VirtualCard
    {
        if ($providerCardId) {
            $byMeta = VirtualCard::query()->whereJsonContains('meta->card_id', $providerCardId)->first();
            if ($byMeta) {
                return $byMeta;
            }
            $byColumn = VirtualCard::query()->where('provider_card_id', $providerCardId)->first();
            if ($byColumn) {
                return $byColumn;
            }
        }
        if ($reference) {
            return VirtualCard::query()->whereJsonContains('meta->reference', $reference)->first();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function markCardTransactionStatus(array $event, TrxStatus $status): void
    {
        $reference = (string) ($event['reference'] ?? '');
        if ($reference === '') {
            return;
        }
        $tx = Transaction::query()->where('trx_id', $reference)->first()
            ?? Transaction::query()->whereJsonContains('meta->bitnob_reference', $reference)->first();
        if (! $tx) {
            return;
        }
        $meta                 = $tx->meta ?? [];
        $meta['bitnob_event'] = $event;
        $tx->status           = $status;
        $tx->meta             = $meta;
        $tx->save();
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function markPayoutTransactionStatus(array $event, TrxStatus $status): void
    {
        $reference = $this->payoutReference($event);

        if ($reference === '') {
            return;
        }

        $tx = Transaction::query()
            ->where(function ($query) use ($reference) {
                $query->where('trx_id', $reference)
                    ->orWhere('trx_reference', $reference)
                    ->orWhere('trx_data->bitnob_withdraw->reference', $reference)
                    ->orWhere('trx_data->bitnob_withdraw->data->reference', $reference)
                    ->orWhere('trx_data->bitnob_withdraw->raw->data->reference', $reference);
            })
            ->latest('id')
            ->first();

        if (! $tx) {
            return;
        }

        $trxData                         = $tx->trx_data ?? [];
        $trxData['bitnob_payout_event']  = $event;
        $trxData['bitnob_payout_status'] = $status->value;

        $tx->update(['trx_data' => $trxData]);

        if ($tx->status !== TrxStatus::PENDING) {
            return;
        }

        if ($status === TrxStatus::COMPLETED) {
            app(TransactionService::class)->completeTransaction($tx->trx_id, __('Bitnob payout completed.'));
        } elseif ($status === TrxStatus::FAILED) {
            app(TransactionService::class)->cancelTransaction($tx->trx_id, __('Bitnob payout failed.'), true);
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function applyRemittancePayoutEvent(array $event, RemittanceStatus $status): bool
    {
        $reference = $this->payoutReference($event);

        if ($reference === '') {
            return false;
        }

        $transferExists = RemittanceTransfer::query()
            ->where('payout_gateway', 'bitnob')
            ->where(function ($query) use ($reference): void {
                $query->where('reference', $reference)
                    ->orWhere('provider_idempotency_key', $reference)
                    ->orWhere('gateway_reference', $reference);
            })
            ->exists();

        if (! $transferExists) {
            return false;
        }

        $eventId    = $this->payoutEventId($event);
        $occurredAt = data_get($event, 'data.occurred_at')
            ?? data_get($event, 'data.created_at')
            ?? data_get($event, 'timestamp')
            ?? data_get($event, 'created_at')
            ?? now()->toIso8601String();

        try {
            $occurredAt = CarbonImmutable::parse((string) $occurredAt)->toIso8601String();
        } catch (\Throwable) {
            $occurredAt = now()->toIso8601String();
        }

        $gatewayReference = data_get($event, 'data.id');
        $message          = data_get($event, 'message');

        $this->remittances->applyWebhookStatus('bitnob', [
            'event_id'           => $eventId,
            'transfer_reference' => $reference,
            'status'             => $status->value,
            'occurred_at'        => $occurredAt,
            'gateway_reference'  => is_scalar($gatewayReference) ? (string) $gatewayReference : null,
            'message'            => is_scalar($message) ? (string) $message : null,
        ], hash('sha256', (string) json_encode($event)));

        return true;
    }

    /**
     * A payout notice about a mass payout line: recorded once and turned into
     * an early status check of that line, never into a settlement or a
     * refund. The lookup it prompts settles the line on Bitnob's own answer.
     * Returns whether the reference named a mass payout line.
     *
     * @param array<string, mixed> $event
     */
    protected function noteMassPayoutEvent(array $event): bool
    {
        return app(MassPayoutReconciler::class)->notice(
            'bitnob',
            $this->payoutReference($event),
            $this->payoutEventId($event),
            (string) ($event['event'] ?? 'payout.status'),
        );
    }

    /**
     * A stable id for one delivery of a payout event: the event name and
     * Bitnob's own id, or a hash of the body when it carries none.
     *
     * @param array<string, mixed> $event
     */
    protected function payoutEventId(array $event): string
    {
        $eventName  = (string) ($event['event'] ?? 'payout.status');
        $providerId = data_get($event, 'event_id')
            ?? data_get($event, 'data.event_id')
            ?? data_get($event, 'data.id')
            ?? $event['id']
            ?? null;

        return is_scalar($providerId)
            ? $eventName.':'.(string) $providerId
            : $eventName.':'.hash('sha256', (string) json_encode($event));
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function payoutReference(array $event): string
    {
        return (string) (
            data_get($event, 'data.reference')
            ?? data_get($event, 'data.transaction.reference')
            ?? data_get($event, 'data.paymentReference')
            ?? data_get($event, 'data.transactionReference')
            ?? data_get($event, 'reference')
            ?? ''
        );
    }
}
