<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Constants\CurrencyRole;
use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Exceptions\NotifyErrorException;
use App\Models\Contact;
use App\Models\Transaction as TransactionModel;
use App\Models\User;
use App\Models\Wallet as WalletModel;
use App\Services\FeatureManager;
use App\Services\Handlers\RequestMoneyHandler;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Transaction;
use Wallet;

/**
 * Asking another customer for money, and answering.
 *
 * Lifted out of RequestMoneyController and TransactionController so the app
 * and the panel cannot end up disagreeing about who may approve a request -
 * which they could, because the answer used to live in a private method on a
 * controller and would have had to be written a second time for the API.
 *
 * A request is TWO pending rows: the requester's, money coming in, and the
 * payer's, money going out. Approving it debits the payer, so it is the
 * payer's consent and nobody else's. That rule is enforced here rather than
 * in either caller.
 *
 * Nothing moves until somebody approves. The credit and the debit are
 * TransactionService::completeTransaction's, which also holds the guard
 * against settling the same row twice.
 */
final class RequestMoneyService
{
    public function __construct(
        private readonly RequestMoneyHandler $handler,
        private readonly FeatureManager $features,
    ) {}

    /**
     * @param  array{recipient: string, wallet_id: mixed, amount: mixed, note?: string|null} $validated
     * @return array{requester: TransactionModel, payer: TransactionModel}
     *
     * @throws NotifyErrorException
     * @throws \Throwable
     */
    public function request(User $user, array $validated): array
    {
        // A decimal string from here to the rows (CR1): a float was bound at
        // `precision` 14 and cut a digit off any figure of fifteen or more.
        $entered = (string) $validated['amount'];
        $note    = $validated['note'] ?? null;

        $requesterWallet = $user->wallets()
            ->with(['currency.activeRoles', 'user'])
            ->findOrFail($validated['wallet_id']);

        Wallet::assertOperational($requesterWallet, CurrencyRole::REQUEST_MONEY);

        $recipientWallet = $this->resolveRecipient($user, (string) $validated['recipient'], $requesterWallet);

        if (Contact::isBlocked((int) $recipientWallet->user_id, (int) $user->id)) {
            throw new NotifyErrorException(__('This person is not accepting requests from you.'));
        }

        Wallet::assertOperational($recipientWallet);

        if (Wallet::isSelfTransaction($recipientWallet, $requesterWallet)) {
            throw new NotifyErrorException(__('You cannot request money from yourself.'));
        }

        // The customer types in the currency they hold; converting once here
        // keeps `amount` base-denominated for the ledger.
        $amount = Wallet::baseAmount($requesterWallet, $entered);

        $fee         = Wallet::calculateFeeByRole($requesterWallet, $amount, CurrencyRole::REQUEST_MONEY);
        $totalAmount = DecimalMoney::add($amount, $fee, DecimalMoney::CALCULATION_SCALE);
        $netAmount   = Wallet::conversionAmount($requesterWallet, $amount);

        $payableAmount = Wallet::conversionAmount($requesterWallet, $totalAmount);

        Wallet::validateAmountLimitByRole($requesterWallet, $amount, CurrencyRole::REQUEST_MONEY);

        return DB::transaction(fn (): array => $this->writeLegs(
            $requesterWallet,
            $recipientWallet,
            $amount,
            $entered,
            $fee,
            $netAmount,
            $payableAmount,
            $note,
        ));
    }

    /**
     * Resolve the recipient against the identifier methods the administrator
     * has enabled for this panel, not against every identifier the platform
     * understands.
     *
     * @throws NotifyErrorException
     */
    private function resolveRecipient(User $user, string $recipient, WalletModel $requesterWallet): WalletModel
    {
        $accessRule = $this->features->find('request_money')
            ?->accessRules
            ->firstWhere('panel', $this->features->currentPanel($user));

        $lookup = Wallet::resolveRecipientWallet($recipient, $requesterWallet->currency_id, $accessRule);

        if ($lookup['error'] !== null) {
            throw new NotifyErrorException($lookup['error']);
        }

        return $lookup['wallet'];
    }

    /**
     * Both pending rows, and the reference each holds to the other.
     *
     * @return array{requester: TransactionModel, payer: TransactionModel}
     */
    private function writeLegs(
        WalletModel $requesterWallet,
        WalletModel $recipientWallet,
        string $amount,
        string $entered,
        string $fee,
        string $netAmount,
        string $payableAmount,
        ?string $note,
    ): array {
        $requesterData = new TransactionData(
            user_id: $requesterWallet->user_id,
            trx_type: TrxType::REQUEST_MONEY,
            amount: $amount,
            amount_flow: AmountFlow::PLUS,
            fee: $fee,
            currency: siteCurrency(),
            net_amount: $netAmount,
            payable_amount: $payableAmount,
            payable_currency: $requesterWallet->currency->code,
            wallet_reference: $requesterWallet->uuid,
            description: __('You have requested money from :recipient.', ['recipient' => $recipientWallet->user->name]),
            status: TrxStatus::PENDING,
            // The rate that produced net_amount and payable_amount.
            exchange_rate: Wallet::fxRate($requesterWallet),
            entered_amount: $entered,
            entered_currency: $requesterWallet->currency->code,
        );

        $requesterTransaction = Transaction::create($requesterData);

        $recipientData = $requesterData->copy([
            'user_id'          => $recipientWallet->user_id,
            'trx_type'         => TrxType::REQUEST_MONEY,
            'amount_flow'      => AmountFlow::MINUS,
            'fee'              => 0, // No fee for recipient
            'wallet_reference' => $recipientWallet->uuid,
            'description'      => __(':requester has requested money from you.', ['requester' => $requesterWallet->user->name]),
            'trx_reference'    => $requesterTransaction->trx_id,
            'remarks'          => $note,
        ]);

        $recipientTransaction = Transaction::create($recipientData);

        $requesterTransaction->update(['trx_reference' => $recipientTransaction->trx_id]);

        $this->handler->handleSubmitted($requesterTransaction);

        return ['requester' => $requesterTransaction, 'payer' => $recipientTransaction];
    }

    /**
     * Pay it.
     *
     * Only the payer may, and only their own outgoing leg counts. The
     * requester's incoming leg carries the same reference and is not consent:
     * approving from there let the requester help themselves, blocked in
     * practice only when their own wallet happened to be empty.
     *
     * @throws NotifyErrorException
     * @throws \Throwable
     */
    public function approve(User $user, string $trxId, ?string $remarks = null): TransactionModel
    {
        $transaction = $this->payersLeg($user, $trxId);

        if (! Wallet::isWalletBalanceSufficient($transaction->wallet_reference, $transaction->payable_amount)) {
            throw new NotifyErrorException(__('Not enough balance in your wallet.'));
        }

        DB::transaction(function () use ($transaction, $remarks): void {
            Transaction::completeTransaction($transaction->trx_id);
            Transaction::completeTransaction($transaction->trx_reference, $remarks);
        });

        return $transaction->refresh();
    }

    /**
     * Say no, or take it back.
     *
     * Either party's to do - the person being asked can refuse, and the
     * person who asked can withdraw it - so this wants only a pending money
     * request belonging to the caller, on either side.
     *
     * @throws NotifyErrorException
     * @throws \Throwable
     */
    public function decline(User $user, string $trxId, ?string $remarks = null): TransactionModel
    {
        $transaction = $this->pendingLeg($user, $trxId);

        DB::transaction(function () use ($transaction, $remarks): void {
            Transaction::cancelTransaction($transaction->trx_id);
            Transaction::cancelTransaction($transaction->trx_reference, $remarks);
        });

        return $transaction->refresh();
    }

    /**
     * The caller's own outgoing leg of a pending request.
     *
     * @throws NotifyErrorException
     */
    private function payersLeg(User $user, string $trxId): TransactionModel
    {
        $transaction = $this->pendingLeg($user, $trxId);

        if ($transaction->amount_flow !== AmountFlow::MINUS) {
            throw new NotifyErrorException(__('Transaction cannot be approved.'));
        }

        return $transaction;
    }

    /**
     * Either of the caller's own legs of a pending request.
     *
     * Scoped to the caller, and reported as not found rather than refused when
     * it is somebody else's - a refusal confirms it exists. This used to be an
     * unscoped lookup by trx_id with no owner check at all.
     *
     * @throws NotifyErrorException
     */
    private function pendingLeg(User $user, string $trxId): TransactionModel
    {
        $transaction = TransactionModel::query()
            ->where('trx_id', $trxId)
            ->where('user_id', $user->id)
            ->where('trx_type', TrxType::REQUEST_MONEY)
            ->where('status', TrxStatus::PENDING)
            ->first();

        if ($transaction === null) {
            throw new NotifyErrorException(__('Transaction not found.'));
        }

        return $transaction;
    }
}
