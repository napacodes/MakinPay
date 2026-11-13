<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\BillPayment\BillPaymentStatus;
use App\Enums\MassPayment\MassPaymentBatchStatus;
use App\Enums\MobileRechargeStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an admin's hard delete of a customer has to respect, in one place, so
 * a module that holds money outside the wallets registers here once instead
 * of growing UserController::destroy() (CR4).
 *
 * Two halves, read before and inside the delete:
 *
 * - refusal(): a module row that still holds the customer's money - an open
 *   bill payment, a recharge being processed, a mass payment paying out or
 *   held - refuses the delete. Deleting would delete the row with it, so
 *   nobody would pay the counterparty or refund the customer. The forfeit
 *   acknowledgement does not cover these; holdsBalance() is the part it does.
 * - purgeResolved(): the module rows that hold nothing are deleted before the
 *   wallets, because their wallet_id is RESTRICT. A row that opened since the
 *   refusal check still holds its wallet, so the wallet delete fails and the
 *   whole delete rolls back.
 *
 * A customer closing their own account (AccountClosureService) does not ask
 * this: closing deletes no rows, and every open module row above holds a
 * PENDING transaction, which that service already refuses on.
 */
class AccountDeletionGuard
{
    /** @var array<string, bool> */
    private array $tables = [];

    /**
     * The first reason this account may not be deleted yet, worded for the
     * admin, or null when nothing holds its money.
     */
    public function refusal(User $user): ?string
    {
        if ($this->has('bill_payments') && $user->billPayments()->whereNotIn('status', $this->resolvedBillStatuses())->exists()) {
            return __('This account has bill payments awaiting processing. Mark them paid or failed first.');
        }

        // US1: a pending or processing recharge - one whose outcome is
        // unknown (MR1) included - has its money held out of the wallet.
        if ($this->has('mobile_recharges') && $user->mobileRecharges()->whereNotIn('status', $this->resolvedRechargeStatuses())->exists()) {
            return __('This account has mobile recharges still being processed. Let them complete or fail first.');
        }

        if ($this->has('mass_payment_batches') && $user->massPaymentBatches()->whereIn('status', $this->openMassStatuses())->exists()) {
            return __('This account has mass payments still paying out or held for review. Let them finish, or cancel their remaining rows, first.');
        }

        return null;
    }

    /**
     * Whether money would be destroyed with the account: a wallet or a
     * savings vault with a balance. The admin has to acknowledge it.
     */
    public function holdsBalance(User $user): bool
    {
        return $user->wallets()->where('balance', '>', 0)->exists()
            || ($this->has('vaults') && $user->vaults()->where('balance', '>', 0)->exists());
    }

    /**
     * Delete the module rows that hold nothing and would block the wallet
     * delete. Runs inside the caller's transaction.
     */
    public function purgeResolved(int $userId): void
    {
        if ($this->has('bill_payments')) {
            DB::table('bill_payments')
                ->where('user_id', $userId)
                ->whereIn('status', $this->resolvedBillStatuses())
                ->delete();
        }

        // Every batch that holds no money, drafts included: rows first (their
        // batch key is RESTRICT too), then the batch; its events cascade.
        if ($this->has('mass_payment_batches')) {
            $batchIds = DB::table('mass_payment_batches')
                ->where('user_id', $userId)
                ->whereNotIn('status', $this->openMassStatuses())
                ->pluck('id');

            foreach ($batchIds->chunk(500) as $chunk) {
                DB::table('mass_payment_rows')->whereIn('mass_payment_batch_id', $chunk->all())->delete();
                DB::table('mass_payment_batches')->whereIn('id', $chunk->all())->delete();
            }
        }

        if ($this->has('mobile_recharges')) {
            DB::table('mobile_recharges')
                ->where('user_id', $userId)
                ->whereIn('status', $this->resolvedRechargeStatuses())
                ->delete();
        }
    }

    /** Asked once per table, so a check inside the transaction reads the same answer. */
    private function has(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
    }

    /** @return list<string> */
    private function resolvedBillStatuses(): array
    {
        return [BillPaymentStatus::Completed->value, BillPaymentStatus::Failed->value];
    }

    /** @return list<string> */
    private function resolvedRechargeStatuses(): array
    {
        return [MobileRechargeStatus::COMPLETED->value, MobileRechargeStatus::FAILED->value];
    }

    /** @return list<string> */
    private function openMassStatuses(): array
    {
        return array_values(array_map(
            static fn (MassPaymentBatchStatus $status): string => $status->value,
            array_filter(MassPaymentBatchStatus::cases(), static fn (MassPaymentBatchStatus $status): bool => $status->holdsMoney()),
        ));
    }
}
