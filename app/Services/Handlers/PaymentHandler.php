<?php

namespace App\Services\Handlers;

use App\Enums\AmountFlow;
use App\Models\Transaction;
use App\Notifications\TemplateNotification;
use App\Services\Handlers\Interfaces\SuccessHandlerInterface;
use App\Services\TransactionNotifierService;
use Illuminate\Support\Facades\Log;
use Wallet;

class PaymentHandler implements SuccessHandlerInterface
{
    protected TransactionNotifierService $notifier;

    public function __construct(TransactionNotifierService $notifier)
    {
        $this->notifier = $notifier;
    }

    public function handleSuccess(Transaction $transaction): void
    {
        // Detect if this is a sandbox/test transaction
        $isSandbox = $this->isSandboxTransaction($transaction);

        if ($transaction->amount_flow === AmountFlow::MINUS) {
            // Skip wallet operations for sandbox transactions
            if (! $isSandbox) {
                Wallet::subtractMoneyByWalletUuid($transaction->wallet_reference, $transaction->payable_amount);
            } else {
                // Log sandbox wallet operation skip
                Log::info('Sandbox transaction: Skipped wallet subtraction', [
                    'transaction_id'   => $transaction->trx_id,
                    'amount'           => $transaction->payable_amount,
                    'wallet_reference' => $transaction->wallet_reference,
                ]);
            }

            $merchantName = $transaction->trx_data['merchant_name'] ?? 'Merchant';

            // Add sandbox prefix to notification data for test transactions
            $notificationData = [
                'amount'   => $transaction->notificationAmount(),
                'merchant' => $isSandbox ? __('SANDBOX: :merchant', ['merchant' => $merchantName]) : $merchantName,
                'trx'      => $transaction->trx_id,
            ];

            $this->notifier->toUser($transaction, 'payment_user_made', $notificationData);
        }

        if ($transaction->amount_flow === AmountFlow::PLUS) {
            // Skip wallet operations for sandbox transactions
            if (! $isSandbox) {
                Wallet::creditSettlementByWalletUuid($transaction->wallet_reference, $transaction->net_amount);
            } else {
                // Log sandbox wallet operation skip
                Log::info('Sandbox transaction: Skipped wallet addition', [
                    'transaction_id'   => $transaction->trx_id,
                    'amount'           => $transaction->net_amount,
                    'wallet_reference' => $transaction->wallet_reference,
                ]);
            }

            $payerTrx  = $this->resolvePayerTransaction($transaction);
            $payerName = $payerTrx?->user?->name
                ?? $transaction->trx_data['customer_name']
                ?? __('Payer');

            // Add sandbox prefix to notification data for test transactions
            $notificationData = [
                'amount' => $transaction->notificationAmount(),
                'payer'  => $isSandbox ? __('SANDBOX: :payer', ['payer' => $payerName]) : $payerName,
                'trx'    => $transaction->trx_id,
            ];

            $transaction->user->notify(new TemplateNotification(
                identifier: 'payment_user_received',
                data: $notificationData
            ));

            $this->notifier->toAdmins(
                'payment-notification',
                'payment_admin_notify_received',
                [
                    'payer'    => $notificationData['payer'],
                    'receiver' => $transaction->user->name,
                    'amount'   => $notificationData['amount'],
                    'trx'      => $notificationData['trx'],
                ],
                $payerTrx?->user,
                route('admin.transaction')
            );
        }

        // Log successful payment handling with environment context
        Log::info('Payment handled successfully', [
            'transaction_id'            => $transaction->trx_id,
            'amount_flow'               => $transaction->amount_flow->value,
            'amount'                    => $transaction->amount,
            'currency'                  => $transaction->currency,
            'is_sandbox'                => $isSandbox,
            'wallet_operations_skipped' => $isSandbox,
        ]);
    }

    /**
     * Writers across this app store either a `trx_id` or a primary key in
     * `trx_reference`, so resolving only by key silently loses the payer.
     */
    private function resolvePayerTransaction(Transaction $transaction): ?Transaction
    {
        $reference = $transaction->trx_reference;

        if (blank($reference)) {
            return null;
        }

        return Transaction::query()->where('trx_id', $reference)->first()
            ?? Transaction::query()->whereKey($reference)->first();
    }

    /**
     * Detect if transaction is a sandbox/test transaction. The model holds
     * the markers, so the totals that leave these rows out
     * (Transaction::scopeWithoutSandbox) read the same ones.
     */
    private function isSandboxTransaction(Transaction $transaction): bool
    {
        return $transaction->isSandbox();
    }
}
