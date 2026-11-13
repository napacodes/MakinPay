<?php

namespace App\Services\Remittance;

use App\Enums\AmountFlow;
use App\Enums\RemittanceStatus;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\RemittanceTransfer;
use App\Support\DecimalMoney;

class RemittanceUatVerifier
{
    /**
     * @return array{passed: bool, checks: array<int, array{id: string, passed: bool, message: string}>}
     */
    public function verify(RemittanceTransfer $transfer): array
    {
        $transfer->loadMissing(['transaction', 'statusEvents']);
        $checks = [];

        $this->add($checks, 'gateway.automated', $transfer->payout_gateway !== 'manual', __('An automated payout gateway handled the transfer.'));
        $this->add($checks, 'provider.idempotency', filled($transfer->provider_idempotency_key), __('Provider idempotency key is persisted.'));
        $this->add($checks, 'provider.reference', filled($transfer->gateway_reference), __('Provider payout reference is persisted.'));
        $this->add($checks, 'dispatch.confirmed', $transfer->dispatch_state === 'confirmed', __('Payout dispatch is provider-confirmed.'));
        $this->add($checks, 'status.completed', $transfer->status === RemittanceStatus::Completed, __('Transfer reached completed status.'));
        $this->add($checks, 'status.completed_at', $transfer->completed_at !== null, __('Completion timestamp is persisted.'));

        $snapshot = (array) $transfer->recipient_snapshot;
        $this->add(
            $checks,
            'recipient.locked',
            filled($snapshot['full_name'] ?? null)
                && filled($snapshot['captured_at'] ?? null)
                && is_array($snapshot['account_details'] ?? null),
            __('Locked recipient snapshot contains identity, payout details, and capture time.'),
        );

        $compliance = (array) $transfer->compliance_result;
        $this->add(
            $checks,
            'compliance.remote',
            ($compliance['passed'] ?? false) === true && ($compliance['provider'] ?? null) === 'remote',
            __('Remote AML and sanctions screening passed.'),
        );

        $transaction = $transfer->transaction;
        $this->add($checks, 'ledger.exists', $transaction !== null, __('Linked sender ledger transaction exists.'));

        if ($transaction) {
            $this->add($checks, 'ledger.type', $transaction->trx_type === TrxType::REMITTANCE_SEND, __('Ledger transaction type is remittance send.'));
            $this->add($checks, 'ledger.flow', $transaction->amount_flow === AmountFlow::MINUS, __('Ledger amount flow is a debit.'));
            $this->add($checks, 'ledger.status', $transaction->status === TrxStatus::COMPLETED, __('Ledger transaction is completed.'));
            $this->add($checks, 'ledger.wallet', filled($transaction->wallet_reference), __('Sender wallet reference is persisted.'));
            $this->add(
                $checks,
                'ledger.total',
                DecimalMoney::compare((string) $transaction->payable_amount, (string) $transfer->getRawOriginal('total_paid')) === 0,
                __('Ledger payable amount matches the transfer total.'),
            );
        }

        $terminalAudit = $transfer->statusEvents->contains(function ($event): bool {
            return $event->to_status === RemittanceStatus::Completed->value
                && (str_starts_with($event->source, 'webhook:') || str_starts_with($event->source, 'reconciliation:'));
        });
        $this->add($checks, 'audit.terminal', $terminalAudit, __('Immutable provider-originated completion event exists.'));

        return [
            'passed' => collect($checks)->every(fn (array $check): bool => $check['passed']),
            'checks' => $checks,
        ];
    }

    /**
     * @param array<int, array{id: string, passed: bool, message: string}> $checks
     */
    protected function add(array &$checks, string $id, bool $passed, string $message): void
    {
        $checks[] = compact('id', 'passed', 'message');
    }
}
