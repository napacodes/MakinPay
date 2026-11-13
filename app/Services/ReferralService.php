<?php

namespace App\Services;

use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\Transaction;
use App\Notifications\TemplateNotification;
use App\Support\FeeCalculator;
use Transaction as TransactionServiceFacade;
use Wallet;

class ReferralService
{
    public function rewardReferral(string $type, int|float $baseAmount): void
    {
        $user     = auth()->user();
        $referral = Referral::where('referred_user_id', $user->id)->first();
        $maxLevel = (int) data_get($user->rank?->features, 'referral_level', 0);

        if ($referral && $maxLevel > 0) {
            $this->distributeRewards($referral, $type, (float) $baseAmount, 1, $maxLevel);
        }
    }

    protected function distributeRewards(?Referral $referral, string $type, float $amount, int $level, int $maxLevel): void
    {
        if (! $referral || $level > $maxLevel) {
            return;
        }
        $reward = Reward::where('type', $type)->where('level', $level)->first();

        $rewardPercentage = (float) ($reward->percentage ?? 0);
        $rewardFixed      = (float) ($reward->fixed_amount ?? 0);

        if ($rewardPercentage > 0 || $rewardFixed > 0) {
            $referralUser              = $referral->user;
            $referralUserDefaultWallet = $referralUser->defaultWallet;
            if (
                ! $referralUserDefaultWallet
                || ! $referralUserDefaultWallet->status
                || ! $referralUserDefaultWallet->currency->status
            ) {
                $this->distributeRewards($referral->parentReferral, $type, $amount, $level + 1, $maxLevel);

                return;
            }
            // fixed + percentage, within any configured floor and ceiling —
            // the same formula every other fee in the platform uses.
            $rewardAmount = app(FeeCalculator::class)->total(
                $amount,
                $rewardFixed,
                $rewardPercentage,
                $reward->min_reward,
                $reward->max_reward,
            );

            if ($rewardAmount <= 0) {
                $this->distributeRewards($referral->parentReferral, $type, $amount, $level + 1, $maxLevel);

                return;
            }

            $transactionData = new TransactionData(
                user_id: $referralUser->id,
                trx_type: TrxType::REFERRAL_REWARD,
                amount: $rewardAmount,
                amount_flow: AmountFlow::PLUS,
                net_amount: $rewardAmount,
                payable_amount: $rewardAmount,
                payable_currency: siteCurrency(),
                wallet_reference: $referralUserDefaultWallet->uuid,
                trx_data: [
                    'referred_user_id'   => auth()->id(),
                    'referred_user_name' => auth()->user()->name,
                    'reward_type'        => $type,
                    'level'              => $level,
                ],
                description: "Level {$level} {$type} reward from ".auth()->user()->name,
                status: TrxStatus::COMPLETED,
                exchange_rate: Wallet::fxRate($referralUserDefaultWallet),
            );

            TransactionServiceFacade::create($transactionData);
            Wallet::addMoney($referralUserDefaultWallet, $rewardAmount);
            $referralUser->notify(new TemplateNotification(
                identifier: 'reward_user_referral',
                data: [
                    'amount'        => $rewardAmount.' '.siteCurrency(),
                    'referred_user' => auth()->user()->name,
                ],
                action: route('user.transaction.index')
            ));

            $this->distributeRewards($referral->parentReferral, $type, $amount, $level + 1, $maxLevel);
        }
    }

    public function shouldApplyReferral(Transaction $transaction): bool
    {
        $referredBy = $transaction->user->referredBy;
        if (! $referredBy) {
            return false;
        }

        $rewardTypes = Reward::pluck('type')->unique()->toArray();
        if (! in_array($transaction->trx_type->value, $rewardTypes)) {
            return false;
        }

        $hasSameTypeTransaction = $transaction->user->transactions()
            ->where('trx_type', $transaction->trx_type->value)
            ->where('id', '!=', $transaction->id)
            ->exists();

        return ! $hasSameTypeTransaction;
    }
}
