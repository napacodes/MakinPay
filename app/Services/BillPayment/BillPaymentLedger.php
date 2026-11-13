<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\BillPayment\BillPaymentFulfilment;
use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\BillPayment;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\WalletService;
use App\Support\DecimalMoney;
use App\Support\Money;

/**
 * The ledger rows a bill payment writes: one debit when the money is
 * reserved, and one refund if the bill is not paid. Builders only — the
 * service inserts each row inside its own locked transaction.
 *
 * Shaped like VirtualCardManager::topupLedgerRow(). TransactionData types its
 * money as float, so it is built with zeros and the decimal strings are merged
 * over the top; the FX snapshot is supplied too, which keeps the row off
 * Transaction::completeFxSnapshot()'s float path.
 *
 * The token never reaches a ledger row: not trx_data, not the remarks, not the
 * description. The PDF receipt is fetched by trx_id alone and prints from
 * this row.
 */
class BillPaymentLedger
{
    public function __construct(private readonly WalletService $wallets) {}

    /**
     * The PENDING wallet debit for a payment that has just been reserved.
     *
     * `trx_reference` is the payment's own BP reference, so support and the
     * admin ledger search find the row by the number the customer quotes.
     * `payable_amount` carries the fee, because the money audit reconciles a
     * debit against it. The biller's category goes under `bill_category`, not
     * `category`, so insights never trust it as the spending category.
     *
     * @return array<string, mixed>
     */
    public function debitRow(BillPayment $payment, Wallet $wallet): array
    {
        $currencyCode = $wallet->currency?->code;
        $rate         = $this->wallets->fxRate($wallet);
        $amount       = (string) $payment->amount;
        $fee          = (string) $payment->fee;
        $total        = (string) $payment->total_amount;

        $data = new TransactionData(
            user_id: (int) $payment->user_id,
            trx_type: TrxType::BILL_PAYMENT,
            amount: 0,
            amount_flow: AmountFlow::MINUS,
            fee: 0,
            currency: $currencyCode,
            provider: (string) $payment->provider_code,
            processing_type: $payment->fulfilment === BillPaymentFulfilment::Operator
                ? MethodType::MANUAL
                : MethodType::AUTOMATIC,
            net_amount: 0,
            payable_amount: 0,
            payable_currency: $currencyCode,
            wallet_reference: $wallet->uuid,
            trx_reference: (string) $payment->reference,
            trx_data: $this->debitData($payment),
            description: __('Bill payment: :biller (:number)', [
                'biller' => (string) $payment->biller_name,
                'number' => (string) $payment->customer_number,
            ]),
            status: TrxStatus::PENDING,
            exchange_rate: $rate,
        );

        return array_merge($data->toArray(), [
            'amount'         => $amount,
            'fee'            => $fee,
            'net_amount'     => $amount,
            'payable_amount' => $total,
        ], Money::fxSnapshot($amount, $fee, $rate), $this->taxColumns($payment, $rate));
    }

    /**
     * GF6: the tax inside payable_amount, apart from the fee, so payable =
     * net + wallet_fee + wallet_tax; restated to the site currency at the
     * row's rate the way the fee is.
     *
     * @return array{wallet_tax: string|null, base_tax: string|null, tax_breakdown: array<int, mixed>|null}
     */
    private function taxColumns(BillPayment $payment, string $rate): array
    {
        if ($payment->tax_amount === null) {
            return ['wallet_tax' => null, 'base_tax' => null, 'tax_breakdown' => null];
        }

        $tax = (string) $payment->tax_amount;

        return [
            'wallet_tax'    => DecimalMoney::normalize($tax, DecimalMoney::CALCULATION_SCALE),
            'base_tax'      => DecimalMoney::toBase($tax, $rate),
            'tax_breakdown' => $payment->tax_breakdown,
        ];
    }

    /**
     * The COMPLETED wallet credit that gives a refunded payment's money back.
     *
     * Mirrors VirtualCardManager::topupRefundLedgerRow(): the full total, fee
     * included, with `trx_reference` set to the debit's trx_id, which is how
     * Transaction::refunds() finds it and how a second refund is detected.
     * It carries the reason as its remarks, and never the token.
     *
     * @return array<string, mixed>
     */
    public function refundRow(BillPayment $payment, Transaction $debit, Wallet $wallet, string $reason): array
    {
        $currencyCode = $debit->payable_currency ?: ($debit->currency ?: $wallet->currency?->code);
        $rate         = $this->wallets->fxRate($wallet);
        $total        = (string) $payment->total_amount;
        $fee          = '0';

        $data = new TransactionData(
            user_id: (int) $payment->user_id,
            trx_type: TrxType::REFUND,
            amount: 0,
            amount_flow: AmountFlow::PLUS,
            fee: 0,
            currency: $currencyCode,
            provider: (string) $payment->provider_code,
            processing_type: $debit->processing_type ?? MethodType::AUTOMATIC,
            net_amount: 0,
            payable_amount: 0,
            payable_currency: $currencyCode,
            wallet_reference: $wallet->uuid,
            trx_reference: (string) $debit->trx_id,
            trx_data: [
                'source'          => 'bill_payment',
                'bill_payment_id' => (int) $payment->id,
                'bill_reference'  => (string) $payment->reference,
                'refunded_trx_id' => (string) $debit->trx_id,
            ],
            remarks: $reason,
            description: __('Refund for bill payment :reference', ['reference' => (string) $payment->reference]),
            status: TrxStatus::COMPLETED,
            exchange_rate: $rate,
        );

        return array_merge($data->toArray(), [
            'amount'         => $total,
            'fee'            => $fee,
            'net_amount'     => $total,
            'payable_amount' => $total,
        ], Money::fxSnapshot($total, $fee, $rate));
    }

    /**
     * What the debit row remembers about the bill. Never the token.
     *
     * @return array<string, int|string>
     */
    private function debitData(BillPayment $payment): array
    {
        return array_filter([
            'bill_payment_id'       => (int) $payment->id,
            'bill_payment_uuid'     => (string) $payment->uuid,
            'bill_reference'        => (string) $payment->reference,
            'biller_id'             => (int) $payment->biller_id,
            'biller_name'           => (string) $payment->biller_name,
            'bill_category'         => (string) $payment->category_code,
            'customer_number'       => (string) $payment->customer_number,
            'customer_number_label' => (string) $payment->customer_number_label,
            'item_name'             => $payment->item_name,
            'bill_amount'           => (string) $payment->bill_amount,
            'bill_currency'         => (string) $payment->bill_currency,
            'provider_request_id'   => (string) $payment->provider_request_id,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
