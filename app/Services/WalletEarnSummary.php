<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WalletEarnStatus;
use App\Models\Currency;
use App\Models\User;
use App\Models\WalletEarnReward;
use App\Models\WalletEarnStake;
use App\Support\ApiMoney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Wallet Earn positions, one line per currency.
 *
 * Stakes and rewards each carry their own currency, so they are summed per
 * currency and never across. The website used to add them all into one
 * figure printed with no currency - 10 USD and 500 BDT of rewards read "510" -
 * on the customer's stakes page and on the admin's KPIs alike. Both read this
 * now, as does the app.
 */
final class WalletEarnSummary
{
    /**
     * @return array{counts: array<string, int>, active_principal: list<array{currency: ?string, amount: string}>, rewards_paid: list<array{currency: ?string, amount: string}>, next_payout_at: ?string}
     */
    public function for(User $user): array
    {
        $counts = WalletEarnStake::query()
            ->where('user_id', $user->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $nextPayout = WalletEarnStake::query()
            ->where('user_id', $user->id)
            ->where('status', WalletEarnStatus::Active->value)
            ->whereNotNull('next_payout_at')
            ->min('next_payout_at');

        return [
            'counts' => [
                'active'    => (int) ($counts[WalletEarnStatus::Active->value] ?? 0),
                'pending'   => (int) ($counts[WalletEarnStatus::Pending->value] ?? 0),
                'completed' => (int) ($counts[WalletEarnStatus::Completed->value] ?? 0),
                'total'     => (int) $counts->sum(),
            ],
            'active_principal' => $this->principal($user, [WalletEarnStatus::Active]),
            'rewards_paid'     => $this->rewardsPaid($user),
            'next_payout_at'   => $nextPayout ? Carbon::parse($nextPayout)->toIso8601String() : null,
        ];
    }

    /**
     * Principal staked, one line per currency; everyone's when $user is null.
     *
     * @param  list<WalletEarnStatus>                         $statuses
     * @return list<array{currency: ?string, amount: string}>
     */
    public function principal(?User $user, array $statuses): array
    {
        return $this->perCurrency(WalletEarnStake::query()
            ->when($user, fn ($query) => $query->where('user_id', $user->id))
            ->whereIn('status', array_map(fn (WalletEarnStatus $status): string => $status->value, $statuses))
            ->selectRaw('currency_id, SUM(principal_amount) as total')
            ->groupBy('currency_id')
            ->pluck('total', 'currency_id'));
    }

    /**
     * Rewards paid, one line per currency; everyone's when $user is null.
     *
     * @return list<array{currency: ?string, amount: string}>
     */
    public function rewardsPaid(?User $user = null): array
    {
        return $this->perCurrency(WalletEarnReward::query()
            ->when($user, fn ($query) => $query->where('user_id', $user->id))
            ->selectRaw('currency_id, SUM(amount) as total')
            ->groupBy('currency_id')
            ->pluck('total', 'currency_id'));
    }

    /**
     * @param  Collection<int|string, mixed>                  $totals Keyed by currency id.
     * @return list<array{currency: ?string, amount: string}>
     */
    private function perCurrency(Collection $totals): array
    {
        $codes = Currency::query()->whereIn('id', $totals->keys()->filter())->pluck('code', 'id');

        return $totals
            ->map(fn ($total, $currencyId): array => [
                'currency' => $codes[$currencyId] ?? null,
                'amount'   => ApiMoney::amount((string) $total, $codes[$currencyId] ?? null),
            ])
            ->values()
            ->all();
    }
}
