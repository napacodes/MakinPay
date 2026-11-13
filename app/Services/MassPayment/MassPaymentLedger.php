<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\MassPayment\MassPaymentIssue;
use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentRow;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Money\SendMoneyService;
use App\Services\WalletService;
use App\Support\DecimalMoney;
use App\Support\Money;

/**
 * The ledger rows one mass payment line writes. Builders only: they return
 * attribute arrays and insert nothing, and the service inserts each inside
 * its own locked transaction.
 *
 * TransactionData types money as float, so each row is built with zeros and
 * the line's exact decimal strings are merged over its toArray(), with the
 * FX snapshot supplied too, which keeps every row off
 * Transaction::completeFxSnapshot()'s float path.
 *
 * Denomination, fixed per leg so each row is self-consistent:
 *
 *   debit   SEND_MONEY sender-leg semantics. `amount`/`fee` are the base
 *           figures in siteCurrency(); `net_amount`/`payable_amount` are in
 *           the wallet currency, with wallet_amount = net and wallet_fee =
 *           payable - net handed over. PENDING and AUTOMATIC, so the generic
 *           approve/reject screen (MANUAL PENDING only) never touches it.
 *           `trx_reference` is the batch reference, so an admin search by the
 *           MP number finds every line. A payout line's debit is the same
 *           MASS_PAYMENT row, with the method, driver, provider request id
 *           and masked label in `trx_data` and never the account itself.
 *   credit  The RECEIVE_MONEY leg send money writes, built by
 *           SendMoneyService::recipientLegData(): base amount, no fee, the
 *           recipient's net. `trx_reference` is the debit's id, which is how
 *           CounterpartyResolver names the merchant.
 *   refund  Wallet-denominated, the BillPaymentLedger::refundRow() shape: the
 *           line's whole payable, fee included, with `trx_reference` set to
 *           the debit's trx_id. That binding is what Transaction::refunds()
 *           finds and what makes a second refund detectable.
 */
class MassPaymentLedger
{
    public const SOURCE = 'mass_payment';

    public function __construct(
        private readonly WalletService $wallets,
        private readonly SendMoneyService $sendMoney,
    ) {}

    /**
     * The PENDING debit that reserves one valid line at confirm.
     *
     * @return array<string, mixed>
     */
    public function debitRow(MassPaymentBatch $batch, MassPaymentRow $row, Wallet $source): array
    {
        $code = (string) $source->currency->code;
        $base = siteCurrency();

        $context = [
            'source'          => self::SOURCE,
            'batch_id'        => (int) $batch->id,
            'batch_uuid'      => (string) $batch->uuid,
            'batch_reference' => (string) $batch->reference,
            'row_id'          => (int) $row->id,
            'line_no'         => (int) $row->line_no,
        ];

        $context += $batch->isPayout()
            ? [
                'kind'                => 'payout',
                'withdraw_method_id'  => $batch->withdraw_method_id === null ? null : (int) $batch->withdraw_method_id,
                'payout_driver'       => (string) $row->payout_driver,
                'provider_request_id' => (string) $row->provider_request_id,
                'recipient_label'     => (string) $row->recipient_label,
            ]
            : [
                'recipient_user_id'     => $row->recipient_user_id === null ? null : (int) $row->recipient_user_id,
                'recipient_wallet_uuid' => $row->recipientWallet?->uuid,
            ];

        $data = new TransactionData(
            user_id: (int) $batch->user_id,
            trx_type: TrxType::MASS_PAYMENT,
            amount: 0,
            amount_flow: AmountFlow::MINUS,
            fee: 0,
            currency: $base,
            processing_type: MethodType::AUTOMATIC,
            net_amount: 0,
            payable_amount: 0,
            payable_currency: $code,
            wallet_reference: (string) $source->uuid,
            trx_reference: (string) $batch->reference,
            trx_data: $context,
            description: $batch->isPayout()
                ? __('Mass payout :reference to :recipient', ['reference' => (string) $batch->reference, 'recipient' => (string) $row->recipient_label])
                : __('Mass payment :reference to :recipient', ['reference' => (string) $batch->reference, 'recipient' => (string) $row->recipient_label]),
            status: TrxStatus::PENDING,
            exchange_rate: $this->rate($row, $source),
            entered_currency: $code,
        );

        return array_merge($data->toArray(), [
            'amount'         => (string) $row->base_amount,
            'fee'            => (string) $row->base_fee,
            'net_amount'     => (string) $row->net_amount,
            'payable_amount' => (string) $row->payable_amount,
            'base_amount'    => (string) $row->base_amount,
            'base_fee'       => (string) $row->base_fee,
            'base_currency'  => $base,
            'wallet_amount'  => (string) $row->net_amount,
            'wallet_fee'     => (string) $row->wallet_fee,
            'entered_amount' => (string) $row->entered_amount,
        ]);
    }

    /**
     * The COMPLETED RECEIVE_MONEY credit written when a line is paid, in
     * exactly the shape send money's own credit has.
     *
     * @return array<string, mixed>
     */
    public function creditRow(MassPaymentBatch $batch, MassPaymentRow $row, Transaction $debit, Wallet $source, Wallet $recipient): array
    {
        $data = $this->sendMoney->recipientLegData(
            $source,
            $recipient,
            (string) $row->base_amount,
            (string) $row->net_amount,
            $row->note,
            (string) $debit->id,
            $this->rate($row, $source),
        );

        return array_merge($data->toArray(), [
            'amount'         => (string) $row->base_amount,
            'fee'            => '0',
            'net_amount'     => (string) $row->net_amount,
            'payable_amount' => (string) $row->net_amount,
            'base_amount'    => (string) $row->base_amount,
            'base_fee'       => '0',
            'base_currency'  => siteCurrency(),
            'wallet_amount'  => (string) $row->net_amount,
            'wallet_fee'     => '0',
            'trx_data'       => [
                'source'          => self::SOURCE,
                'batch_reference' => (string) $batch->reference,
                'line_no'         => (int) $row->line_no,
            ],
        ]);
    }

    /**
     * The COMPLETED REFUND that releases a failed or stopped line: its whole
     * payable, fee included, back to the source wallet.
     *
     * @return array<string, mixed>
     */
    public function refundRow(MassPaymentBatch $batch, MassPaymentRow $row, Transaction $debit, Wallet $source, MassPaymentIssue $reason): array
    {
        $code    = (string) $source->currency->code;
        $rate    = $this->rate($row, $source);
        $payable = (string) $row->payable_amount;
        $fee     = '0';

        $data = new TransactionData(
            user_id: (int) $batch->user_id,
            trx_type: TrxType::REFUND,
            amount: 0,
            amount_flow: AmountFlow::PLUS,
            fee: 0,
            currency: $code,
            processing_type: MethodType::AUTOMATIC,
            net_amount: 0,
            payable_amount: 0,
            payable_currency: $code,
            wallet_reference: (string) $source->uuid,
            trx_reference: (string) $debit->trx_id,
            trx_data: [
                'source'          => self::SOURCE,
                'batch_reference' => (string) $batch->reference,
                'line_no'         => (int) $row->line_no,
                'refunded_trx_id' => (string) $debit->trx_id,
                'reason'          => $reason->value,
            ],
            remarks: $reason->value,
            description: __('Refund for mass payment :reference', ['reference' => (string) $batch->reference]),
            status: TrxStatus::COMPLETED,
            exchange_rate: $rate,
        );

        return array_merge($data->toArray(), [
            'amount'         => $payable,
            'fee'            => $fee,
            'net_amount'     => $payable,
            'payable_amount' => $payable,
        ], Money::fxSnapshot($payable, $fee, $rate));
    }

    /** The rate frozen on the line, or the wallet's own when the line has none. */
    private function rate(MassPaymentRow $row, Wallet $source): string
    {
        $rate = $row->exchange_rate;

        return $rate === null || DecimalMoney::compare((string) $rate, '0') <= 0
            ? $this->wallets->fxRate($source)
            : DecimalMoney::rate((string) $rate);
    }
}
