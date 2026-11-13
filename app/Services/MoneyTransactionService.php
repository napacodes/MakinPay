<?php

namespace App\Services;

use App\Constants\CurrencyRole;
use App\Data\TransactionData;
use App\Enums\TrxStatus;
use App\Models\User;
use App\Models\Wallet as WalletModel;
use App\Support\DecimalMoney;
use Transaction;
use Wallet;

class MoneyTransactionService
{
    /**
     * Retrieves a payer's wallet, given their identifier and currency ID.
     */
    protected function getRecipientWallet($recipient, $currencyId)
    {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $recipientUser = User::where('email', $recipient)->first();

            return $recipientUser ? WalletModel::where('user_id', $recipientUser->id)->where('currency_id', $currencyId)->first() : null;
        }

        try {
            return Wallet::getWalletByUniqueId((string) $recipient);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Determines if the payer and requester wallets are the same.
     */
    protected function isSelfTransaction($recipientWallet, $requesterWallet)
    {
        return $recipientWallet->user_id === auth()->id() || $recipientWallet->id === $requesterWallet->id;
    }

    /**
     * Calculates the fee for requesting money, based on the requester's wallet and amount.
     */
    protected function calculateFee($wallet, $amount)
    {
        // Delegates rather than re-implementing: this used to be a byte-for-byte
        // copy of WalletService::calculateFeeByRole(), which is exactly how the
        // two drifted apart.
        return app(WalletService::class)->calculateFeeByRole($wallet, $amount, CurrencyRole::REQUEST_MONEY);
    }

    /**
     * Calculates the amount of money the requester will actually receive, after deducting the fee.
     */
    protected function calculateRecipientAmount($wallet, $totalAmount, $fee)
    {
        // Converting each side and subtracting is the same figure as
        // subtracting and converting once — but only in exact arithmetic. In
        // floats the two rounded independently, and the difference landed in
        // the recipient's balance.
        //
        // The rate comes from fxRate() so this agrees with the value recorded
        // on the ledger entry rather than re-reading the currency separately.
        return (float) DecimalMoney::multiply(
            DecimalMoney::subtract($totalAmount, $fee, DecimalMoney::CALCULATION_SCALE),
            app(WalletService::class)->fxRate($wallet),
        );
    }

    /**
     * Creates a new transaction for the request money functionality.
     */
    protected function createTransaction($userId, $type, $amount, $fee, $totalAmount, $netAmount, $wallet, $description = null, $note = null, $trxReference = null)
    {
        $data = new TransactionData(
            user_id: $userId,
            trx_type: $type,
            amount: $amount,
            fee: $fee,
            currency: siteCurrency(),
            net_amount: $netAmount,
            payable_amount: $totalAmount,
            payable_currency: $wallet->currency->code,
            wallet_reference: $wallet->uuid,
            trx_reference: $trxReference,
            remarks: $note,
            description: $description,
            status: TrxStatus::PENDING,
            // The rate that produced net_amount and payable_amount.
            exchange_rate: app(WalletService::class)->fxRate($wallet),
        );

        return Transaction::create($data);
    }
}
