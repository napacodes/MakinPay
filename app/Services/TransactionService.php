<?php

namespace App\Services;

use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\EnvironmentMode;
use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Events\TransactionUpdated;
use App\Exceptions\GatewayBindingViolationException;
use App\Models\Merchant;
use App\Models\PaymentLink;
use App\Models\Transaction;
use App\Services\Handlers\DepositHandler;
use App\Services\Handlers\Interfaces\FailHandlerInterface;
use App\Services\Handlers\Interfaces\SuccessHandlerInterface;
use App\Services\Handlers\PaymentHandler;
use App\Services\Handlers\RequestMoneyHandler;
use App\Services\Handlers\WithdrawHandler;
use App\Services\Payment\GatewayOriginResolver;
use App\Support\GatewayCallbackContext;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wallet;

class TransactionService
{
    /*
    |--------------------------------------------------------------------------
    | Public Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new transaction record.
     */
    public function create(TransactionData $data): Transaction
    {
        return Transaction::create($this->prepareTransactionData($data));
    }

    /**
     * Fetch transactions based on filters.
     */
    public function getTransactions(
        ?int $user_id = null,
        TrxType|string|array|null $trx_type = null,
        ?string $provider = null,
        TrxStatus|string|null $status = null,
        int $per_page = 10,
        string $sort_by = 'created_at',
        string $order = 'desc',
        ?string $search = null,
        ?string $dateRange = null,
        ?MethodType $processing_type = null
    ): LengthAwarePaginator {
        $filters = compact('user_id', 'trx_type', 'provider', 'status', 'search', 'dateRange', 'processing_type');

        return Transaction::with('user')
            ->applyFilters($filters)
            ->orderBy($sort_by, $order)
            ->paginate($per_page)
            ->withQueryString();
    }

    /**
     * Calculate current and previous stats for a specific transaction type.
     */
    public function calculateTransactionTypeStatistics(TrxType $trxType, $trxStatuses, ?int $userId = null, int $days = 7): array
    {
        $trxStatuses  = (array) $trxStatuses;
        $statusValues = array_map(fn ($s) => $s instanceof TrxStatus ? $s->value : $s, $trxStatuses);

        $range         = now();
        $currentRange  = [$range->copy()->subDays($days), $range];
        $previousRange = [$range->copy()->subDays($days * 2), $range->copy()->subDays($days)];

        $baseQuery = Transaction::where('trx_type', $trxType->value);
        if ($userId) {
            $baseQuery->where('user_id', $userId);
        }

        $current  = (clone $baseQuery)->whereBetween('created_at', $currentRange)->whereIn('status', $statusValues)->sum('amount');
        $previous = (clone $baseQuery)->whereBetween('created_at', $previousRange)->whereIn('status', $statusValues)->sum('amount');

        return [
            'current_value'   => $current,
            'previous_value'  => $previous,
            'current_percent' => $previous === 0 ? 0 : ($current / $previous) * 100,
        ];
    }

    /**
     * Complete a transaction and trigger success handler.
     */
    public function completeTransaction(string $trxId, ?string $remarks = null, ?string $description = null): void
    {
        $completedTransaction = null;

        DB::transaction(function () use ($trxId, $remarks, $description, &$completedTransaction) {
            $transaction = Transaction::query()
                ->where('trx_id', $trxId)
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                throw new \Exception("Transaction not found for ID: {$trxId}");
            }

            $this->assertCallbackOwnsTransaction($transaction);

            if ($transaction->status === TrxStatus::COMPLETED) {
                return;
            }

            if ($transaction->status !== TrxStatus::PENDING) {
                Log::info('Transaction completion skipped because transaction is not pending.', [
                    'transaction_id' => $trxId,
                    'status'         => $transaction->status->value,
                ]);

                return;
            }

            $this->updateTransactionStatusWithRemarks($transaction, TrxStatus::COMPLETED, $remarks, $description);

            if (($handler = $this->resolveHandler($transaction)) instanceof SuccessHandlerInterface) {
                $handler->handleSuccess($transaction);
            }

            $this->recordPaymentLinkSuccessfulPayment($transaction);
            app(MerchantSubscriptionService::class)->markTransactionPaid($transaction);

            $completedTransaction = $transaction->refresh();
        }, 3); // Retry transaction up to 3 times on failure

        if ($completedTransaction instanceof Transaction) {
            $this->sendMerchantPaymentIPN($completedTransaction, __('Payment Completed'), TrxStatus::COMPLETED);
            event(new TransactionUpdated($completedTransaction->user));
        }
    }

    /**
     * Fail a transaction and trigger failure handler.
     */
    public function failTransaction(string $trxId, ?string $remarks = null, ?string $description = null): void
    {
        $failedTransaction = null;

        DB::transaction(function () use ($trxId, $remarks, $description, &$failedTransaction): void {
            $transaction = Transaction::query()
                ->where('trx_id', $trxId)
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                throw new \Exception("Transaction not found for ID: {$trxId}");
            }

            $this->assertCallbackOwnsTransaction($transaction);

            if ($transaction->status !== TrxStatus::PENDING) {
                Log::info('Transaction failure skipped because transaction is not pending.', [
                    'transaction_id' => $trxId,
                    'status'         => $transaction->status->value,
                ]);

                return;
            }

            $this->updateTransactionStatusWithRemarks($transaction, TrxStatus::FAILED, $remarks, $description);

            if (($handler = $this->resolveHandler($transaction)) instanceof FailHandlerInterface) {
                $handler->handleFail($transaction);
            }

            app(MerchantSubscriptionService::class)->markTransactionFailed($transaction);

            $failedTransaction = $transaction->refresh();
        }, 3);

        if ($failedTransaction instanceof Transaction) {
            $this->sendMerchantPaymentIPN($failedTransaction, 'Payment Failed', TrxStatus::FAILED);
        }
    }

    /**
     * Cancel a transaction, optionally refund amount.
     *
     * WD1: ONCE, AND ONLY WHILE PENDING. The row is locked, its status read
     * under the lock, and the refund, the CANCELED status and the fail
     * handler all happen in that one transaction. It used to read the row
     * without a lock and never look at its status, so a reject posted twice
     * (a double click, a replay, the back button), a duplicate "payout
     * failed" webhook, or a reject of a withdrawal already approved and paid
     * out each credited the wallet again and wrote another REFUND row.
     *
     * A transaction that is no longer pending is left exactly as it is and
     * the call is logged and skipped - the same answer completeTransaction()
     * and failTransaction() give - so a replay is harmless rather than an
     * error, and approving after a reject (or rejecting after an approval)
     * moves nothing.
     */
    public function cancelTransaction(string $trxId, ?string $remarks = null, bool $refund = false): void
    {
        $cancelled = null;

        DB::transaction(function () use ($trxId, $remarks, $refund, &$cancelled): void {
            $transaction = Transaction::query()
                ->where('trx_id', $trxId)
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                throw new \Exception("Transaction not found for ID: {$trxId}");
            }

            // Before anything moves. With `$refund` set this method credits a
            // wallet and writes a REFUND entry, so a callback cancelling
            // someone else's transaction is as costly as one completing it.
            $this->assertCallbackOwnsTransaction($transaction);

            if ($transaction->status !== TrxStatus::PENDING) {
                Log::info('Transaction cancellation skipped because transaction is not pending.', [
                    'transaction_id' => $trxId,
                    'status'         => $transaction->status->value,
                ]);

                return;
            }

            if ($refund) {
                // 1) Credit back the wallet
                Wallet::creditSettlementByWalletUuid($transaction->wallet_reference, $transaction->payable_amount);

                // 2) Create a dedicated REFUND transaction entry
                $refundData = new TransactionData(
                    user_id: (int) $transaction->user_id,
                    trx_type: TrxType::REFUND,
                    amount: (float) $transaction->payable_amount,
                    amount_flow: AmountFlow::PLUS,
                    fee: 0,
                    currency: $transaction->payable_currency ?? $transaction->currency,
                    provider: 'system',
                    processing_type: MethodType::AUTOMATIC,
                    net_amount: (float) $transaction->payable_amount,
                    payable_amount: (float) $transaction->payable_amount,
                    payable_currency: $transaction->payable_currency ?? $transaction->currency,
                    wallet_reference: $transaction->wallet_reference,
                    trx_reference: $transaction->trx_id,
                    trx_data: [
                        'refunded_trx_id'   => $transaction->trx_id,
                        'original_trx_type' => $transaction->trx_type->value,
                    ],
                    remarks: $remarks,
                    description: 'Refund for '.$transaction->trx_id,
                    status: TrxStatus::COMPLETED,
                    exchange_rate: Wallet::fxRate($transaction->payable_currency ?? $transaction->currency),
                    trx_token: null,
                    expires_at: null,
                );

                $this->create($refundData);
            }

            $this->updateTransactionStatusWithRemarks($transaction, TrxStatus::CANCELED, $remarks);

            if (($handler = $this->resolveHandler($transaction)) instanceof FailHandlerInterface) {
                $handler->handleFail($transaction);
            }

            $cancelled = $transaction;
        }, 3);

        // Notify dashboards/widgets once the refund is committed.
        if ($refund && $cancelled instanceof Transaction) {
            event(new TransactionUpdated($cancelled->user));
        }
    }

    /**
     * Retrieve statistics for different transaction groups.
     *
     * Totals are restated into the base currency from the stored rates, the
     * same source every wallet conversion reads. This used to ask
     * CurrencyConversionService for a live quote *per ledger row*: the cache
     * key includes the amount, so nearly every row missed and fired its own
     * CoinMarketCap / CurrencyLayer request, and one unreachable provider threw
     * out of the middle of the loop and returned a 500 for the whole admin
     * dashboard. It also meant this page priced money differently from the
     * ledger it was summarising.
     */
    public function getTransactionStatistics(?int $userId = null): Collection
    {
        $trxGroups = [
            'deposit'        => [TrxType::DEPOSIT],
            'send_money'     => [TrxType::SEND_MONEY],
            'request_money'  => [TrxType::REQUEST_MONEY],
            'exchange_money' => [TrxType::EXCHANGE_MONEY],
            'payment'        => [TrxType::PAYMENT],
            'withdraw'       => [TrxType::WITHDRAW],
            'voucher'        => [TrxType::VOUCHER],
            'rewards'        => [TrxType::REWARD, TrxType::REFERRAL_REWARD, TrxType::WALLET_EARN_REWARD, TrxType::CASHBACK],
        ];

        $transactions = Transaction::select('trx_type', 'amount', 'currency')
            ->where('status', TrxStatus::COMPLETED)
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->get();

        $currencies = app(CurrencyService::class);
        $rates      = $currencies->ratesByCode($transactions->pluck('currency'));

        return collect($trxGroups)->mapWithKeys(function ($types, $key) use ($transactions, $currencies, $rates) {
            $values = array_map(fn ($t) => $t->value, $types);
            $sum    = $currencies->sumInBaseCurrency(
                $transactions->filter(fn ($trx): bool => in_array($trx->trx_type->value, $values, true))->values(),
                'amount',
                $rates,
            );
            $type = $types[0];

            return [$key => [
                'title'       => $type->label(),
                'value'       => Money::format($sum, siteCurrency()),
                'icon'        => $type->icon(),
                'color_class' => $type->kebabCase(),
                'link'        => $key == 'rewards' || $key == 'voucher' ? null : route('admin.transaction', ['type' => $key]),
            ]];
        });
    }

    /**
     * Find transaction by trx ID.
     */
    public function findTransaction(string $trxId): ?Transaction
    {
        return Transaction::where('trx_id', $trxId)->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Protected Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Prepare transaction data array from DTO.
     */
    protected function prepareTransactionData(TransactionData $data): array
    {
        return [
            'user_id'          => $data->user_id,
            'trx_type'         => $data->trx_type->value,
            'amount'           => $data->amount,
            'amount_flow'      => $data->amount_flow?->value,
            'fee'              => $data->fee,
            'currency'         => $data->currency ?? siteCurrency(),
            'provider'         => $data->provider,
            'processing_type'  => $data->processing_type->value,
            'net_amount'       => $data->net_amount,
            'payable_amount'   => $data->payable_amount,
            'payable_currency' => $data->payable_currency,
            'wallet_reference' => $data->wallet_reference,
            'trx_data'         => $data->trx_data,
            'remarks'          => $data->remarks,
            'description'      => ucfirst($data->description),
            'trx_reference'    => $data->trx_reference,
            'status'           => $data->status->value,
            'trx_token'        => $data->trx_token,
            'expires_at'       => $data->expires_at,
            // FX snapshot. Left null by callers that perform no conversion;
            // Transaction::completeFxSnapshot() fills what it safely can.
            'exchange_rate' => $data->exchange_rate,
            'base_amount'   => $data->base_amount,
            'base_fee'      => $data->base_fee,
            'base_currency' => $data->base_currency,
            'wallet_amount' => $data->wallet_amount,
            'wallet_fee'    => $data->wallet_fee,
            // What the customer typed, in the unit they typed it in. Null for
            // flows with no customer-entered amount — a reward, a referral
            // bonus, an admin adjustment.
            'entered_amount'   => $data->entered_amount,
            'entered_currency' => $data->entered_currency,
            // GF6: the tax inside payable_amount, apart from the fee.
            'wallet_tax'    => $data->wallet_tax,
            'base_tax'      => $data->base_tax,
            'tax_breakdown' => $data->tax_breakdown,
        ];
    }

    /**
     * Update transaction status and metadata.
     */
    protected function updateTransactionStatusWithRemarks(Transaction $transaction, TrxStatus $status, ?string $remarks = null, ?string $description = null): void
    {
        $transaction->update(array_filter([
            'status'      => $status,
            'remarks'     => $remarks,
            'description' => $description,
        ]));

        // Sync local object with updated values
        $transaction->status      = $status;
        $transaction->remarks     = $remarks;
        $transaction->description = $description;
    }

    /**
     * Queue the merchant's IPN for this payment.
     *
     * Recorded and delivered by MerchantWebhookDispatcher, which retries on a
     * schedule and keeps a row per delivery. This used to be a synchronous
     * POST inside the settling request — three tries on a timeout only,
     * nothing on a 5xx, and no record afterwards of whether the merchant
     * ever heard.
     */
    protected function sendMerchantPaymentIPN(Transaction $transaction, string $message, TrxStatus $status): void
    {
        if ($transaction->trx_type !== TrxType::RECEIVE_PAYMENT) {
            return;
        }

        try {
            $trxData  = $transaction->trx_data ?? [];
            $merchant = Merchant::query()->find($trxData['merchant_id'] ?? null);

            if (! $merchant instanceof Merchant) {
                Log::warning('IPN skipped because the merchant no longer exists.', ['transaction_id' => $transaction->trx_id]);

                return;
            }

            $environment = ($trxData['environment'] ?? EnvironmentMode::PRODUCTION->value) === EnvironmentMode::SANDBOX->value
                || (bool) ($trxData['is_sandbox'] ?? false)
                ? EnvironmentMode::SANDBOX
                : EnvironmentMode::PRODUCTION;

            unset($trxData['merchant_id']);
            $trxData['trx_id'] = $transaction->trx_id;

            $payload = [
                'data'      => $trxData,
                'message'   => $message,
                'status'    => $status->value,
                'timestamp' => now()->timestamp,
            ];

            app(MerchantWebhookDispatcher::class)->queue(
                $merchant,
                $transaction,
                'payment.'.$status->value,
                $payload,
                $environment,
            );
        } catch (\Exception $e) {
            Log::error('IPN error: '.$e->getMessage(), ['transaction_id' => $transaction->trx_id]);
        }
    }

    /**
     * Refuse a callback that is settling someone else's transaction.
     *
     * Only applies when the request actually came through /ipn/{gateway}. An
     * admin approval, a reconciliation command or a queued job has no callback
     * context and passes straight through, exactly as before.
     *
     * The gateway comes from a request-scoped object established once at the
     * edge, never from the request, the route or a URL segment — this class
     * has no business knowing it is inside an HTTP request at all.
     *
     * A legacy row with no bound code is traced back through the method that
     * created it. When that cannot name exactly one gateway the transaction
     * fails closed and waits for manual reconciliation: an unverifiable origin
     * is not permission to settle.
     *
     * @throws GatewayBindingViolationException
     */
    private function assertCallbackOwnsTransaction(Transaction $transaction): void
    {
        $context = app(GatewayCallbackContext::class);

        if (! $context->isInitialized()) {
            return;
        }

        $callbackGateway = (string) $context->gatewayCode();
        $origin          = app(GatewayOriginResolver::class)->resolve($transaction);

        if ($origin === null) {
            Log::warning('Callback refused: transaction origin could not be established', [
                'trx_id'           => $transaction->trx_id,
                'transaction_id'   => $transaction->id,
                'callback_gateway' => $callbackGateway,
                'reason'           => 'unresolved_origin',
            ]);

            throw new GatewayBindingViolationException(sprintf(
                'Transaction %s has no resolvable originating gateway; %s may not settle it.',
                $transaction->trx_id,
                $callbackGateway,
            ));
        }

        if (! hash_equals($origin, $callbackGateway)) {
            Log::warning('Callback refused: gateway is not bound to this transaction', [
                'trx_id'           => $transaction->trx_id,
                'transaction_id'   => $transaction->id,
                'callback_gateway' => $callbackGateway,
                'reason'           => 'gateway_mismatch',
            ]);

            throw new GatewayBindingViolationException(sprintf(
                'Transaction %s belongs to %s and cannot be settled by %s.',
                $transaction->trx_id,
                $origin,
                $callbackGateway,
            ));
        }
    }

    /**
     * Resolve transaction handler based on type.
     */
    protected function resolveHandler(Transaction $transaction): mixed
    {
        return match ($transaction->trx_type) {
            TrxType::DEPOSIT         => app(DepositHandler::class),
            TrxType::RECEIVE_PAYMENT => app(PaymentHandler::class),
            TrxType::REQUEST_MONEY   => app(RequestMoneyHandler::class),
            TrxType::WITHDRAW        => app(WithdrawHandler::class),
            default                  => null,
        };
    }

    /**
     * Increment payment-link usage counters when a gateway completes the
     * pending receiver transaction.
     */
    protected function recordPaymentLinkSuccessfulPayment(Transaction $transaction): void
    {
        if (($transaction->trx_data['source'] ?? null) !== 'payment_link') {
            return;
        }

        $paymentLinkId = $transaction->trx_data['payment_link_id'] ?? null;
        if ($paymentLinkId === null) {
            return;
        }

        PaymentLink::query()->find($paymentLinkId)?->recordSuccessfulPayment();
    }
}
