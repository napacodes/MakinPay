<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Exceptions\NotifyErrorException;
use App\Models\Transaction as TransactionModel;
use App\Models\User;
use App\Models\Wallet as WalletModel;
use App\Models\WithdrawAccount;
use App\Models\WithdrawSchedule;
use App\Services\WalletService;
use Payment;

/**
 * Taking money off the platform.
 *
 * Lifted out of WithdrawController so a second caller - the mobile API - asks
 * the same questions in the same order as the web form does. The controller
 * keeps the flash message and the redirect; everything that decides whether
 * the payout happens is here.
 *
 * The money movement itself stays where it already was, in
 * PaymentService::withdrawMoney, which locks the wallet, converts through the
 * method's own rate, checks the platform and provider limits and writes the
 * ledger row. None of that is restated here: this service is the gate in front
 * of it, not a second copy of it.
 *
 * Order matters and is preserved exactly. The PIN is checked before anything
 * is looked up, so a wrong PIN cannot be used to probe which account ids
 * exist; the schedule is checked next, because a closed day refuses everyone
 * and there is no reason to touch the database first.
 */
final class WithdrawMoneyService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly WalletPinGuard $pins,
    ) {}

    /**
     * @param array{account_id: mixed, wallet_id: mixed, amount: mixed, wallet_pin?: string|null} $validated
     *
     * @throws NotifyErrorException
     */
    public function withdraw(User $user, array $validated): TransactionModel
    {
        $this->pins->assert($user, $validated['wallet_pin'] ?? null);

        if (! WithdrawSchedule::isWithdrawEnabledToday()) {
            throw new NotifyErrorException('Withdrawals are not enabled for today.');
        }

        // Both scoped to the caller, so another customer's id is a not-found
        // rather than a refusal that confirms it exists.
        $account = WithdrawAccount::with(['withdrawMethod'])
            ->where('user_id', $user->id)
            ->findOrFail($validated['account_id']);

        $wallet = WalletModel::query()
            ->where('user_id', $user->id)
            ->findOrFail($validated['wallet_id']);

        $this->wallets->assertOperational($wallet);

        if ($account->withdrawMethod->currency !== $wallet->currency->code) {
            throw new NotifyErrorException('Withdrawal account currency does not match the selected wallet.');
        }

        // The platform limit check lives in PaymentService, next to the
        // conversion - the limits are base-denominated and the entered figure
        // is in the method's currency, so the two can only be compared after
        // the conversion has happened.
        return Payment::withdrawMoney($account, $wallet, (float) $validated['amount']);
    }
}
