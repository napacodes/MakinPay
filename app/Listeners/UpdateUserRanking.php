<?php

namespace App\Listeners;

use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Events\TransactionUpdated;
use App\Models\User;
use App\Models\UserRank;
use App\Models\UserRankAchievement;
use App\Notifications\TemplateNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Transaction;
use Wallet;

class UpdateUserRanking
{
    /**
     * Handle the event.
     */
    public function handle(TransactionUpdated $event): void
    {
        $user    = $event->user;
        $lockKey = "user_ranking_update_{$user->id}";

        // Prevent concurrent updates using a cache lock
        $lock = Cache::lock($lockKey, 5);

        if ($lock->get()) {
            try {
                $eligibleRank = $this->getHighestEligibleRank($user);

                if ($eligibleRank && $this->shouldPromote($user, $eligibleRank)) {
                    $this->promoteUserRank($user, $eligibleRank);
                }
            } catch (Throwable $e) {
                /*
                 * Best effort. Ranking is a side effect of whatever fired this
                 * event - a sign-up, a completed payment - and it must not
                 * become that flow's error: registration fires this after its
                 * own commit, so the customer would see a failure for an
                 * account that exists. The promotion runs in a transaction, so
                 * a failure here has already been undone whole.
                 */
                Log::warning('Rank promotion failed', [
                    'user_id'   => $user->id,
                    'exception' => $e::class,
                ]);
            } finally {
                $lock->release();
            }
        }
    }

    /**
     * Determine the highest eligible rank based on transaction sums.
     */
    protected function getHighestEligibleRank(User $user): ?UserRank
    {
        return UserRank::active()
            ->orderByDesc('transaction_amount')
            ->get()
            ->filter(function ($rank) use ($user) {
                if (empty($rank->transaction_types)) {
                    return false;
                }

                // Only money that moved buys a rank: not a pending, failed
                // or cancelled row, and not a sandbox payment, which costs
                // nothing.
                $sum = $user->transactions()
                    ->whereIn('trx_type', $rank->transaction_types)
                    ->where('status', TrxStatus::COMPLETED)
                    ->withoutSandbox()
                    ->sum('amount');

                return $sum >= $rank->transaction_amount;
            })
            ->first(); // Return the top-most eligible rank
    }

    /**
     * Determine if the user should be promoted.
     */
    protected function shouldPromote(User $user, UserRank $rank): bool
    {
        return ! $user->rank || $rank->transaction_amount > $user->rank->transaction_amount;
    }

    /**
     * Promote the user to the specified rank and apply rewards.
     */
    protected function promoteUserRank(User $user, UserRank $newRank): void
    {
        DB::transaction(function () use ($user, $newRank) {
            // Get all lower rank IDs
            $lowerRankIds = UserRank::active()
                ->where('transaction_amount', '<', $newRank->transaction_amount)
                ->pluck('id')
                ->toArray();

            $oldRanks = array_unique(array_merge($user->old_ranks ?? [], $lowerRankIds));

            $user->update([
                'rank_id'   => $newRank->id,
                'old_ranks' => $oldRanks,
            ]);

            $trxId = null;
            if ($newRank->reward > 0) {
                $wallet = $user->default_Wallet;

                if ($wallet && $wallet->status && $wallet->currency->status) {
                    Wallet::addMoney($wallet, $newRank->reward);
                    $trxId = $this->createTransaction($user, $wallet, $newRank->reward, $newRank);
                }
            }

            // The date the rank was reached, once; the ranks skipped over on
            // the way are recorded as earned without a date.
            UserRankAchievement::record($user, $newRank, $trxId);
            foreach ($lowerRankIds as $lowerId) {
                UserRankAchievement::query()->firstOrCreate(['user_id' => $user->id, 'user_rank_id' => $lowerId], ['earned_at' => null]);
            }
        });
    }

    /**
     * Log the reward transaction and notify the user.
     *
     * @param \App\Models\Wallet $wallet
     */
    protected function createTransaction(User $user, $wallet, float $amount, ?UserRank $rank = null): ?string
    {
        $transactionData = new TransactionData(
            user_id: $user->id,
            trx_type: TrxType::REWARD,
            amount: $amount,
            amount_flow: AmountFlow::PLUS,
            currency: $wallet->currency->code,
            net_amount: $amount,
            payable_amount: $amount,
            payable_currency: $wallet->currency->code,
            wallet_reference: $wallet->uuid,
            trx_reference: $rank ? 'rank_'.$rank->id : null,
            trx_data: $rank ? ['rank_id' => $rank->id, 'rank_name' => $rank->name] : null,
            description: __('Reward for reaching a new rank'),
            status: TrxStatus::COMPLETED,
            // Denominated in the wallet currency already.
            exchange_rate: Wallet::fxRate($wallet),
        );

        $transaction = Transaction::create($transactionData);

        $user->notify(new TemplateNotification(
            identifier: 'reward_user_system',
            data: [
                'amount'        => $amount.' '.$wallet->currency->code,
                'reward_reason' => __('You have reached a new rank and received a reward'),
            ],
            action: route('user.transaction.index')
        ));

        return $transaction?->trx_id;
    }
}
