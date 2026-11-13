<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Referral;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRank;
use App\Services\CurrencyService;
use App\Services\UserRankProgress;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Everything a customer has earned from the platform, in one place:
 * cashback on card spend, referral bonuses, and rank rewards. Totals are
 * in the site currency; the history lists the underlying transactions
 * newest first, each in the currency it was paid in.
 */
final class RewardsHub
{
    public const KINDS = ['referral', 'rank', 'cashback'];

    public function __construct(
        private readonly CashbackService $cashback,
        private readonly UserRankProgress $ranks,
        private readonly CurrencyService $currencies,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $currency = siteCurrency();
        $progress = $this->ranks->for($user);
        $referral = $this->referralEarned($user, $currency);
        $rank     = $this->rankEarned($progress);
        $cashback = $this->cashback->totalClaimed($user);
        $total    = DecimalMoney::add(DecimalMoney::add($referral, $rank, DecimalMoney::CALCULATION_SCALE), $cashback, DecimalMoney::CALCULATION_SCALE);

        $directIds = Referral::query()->where('user_id', $user->id)->pluck('id');
        $next      = $progress['next'];
        $current   = $progress['current'];

        return [
            'currency' => $currency,
            'totals'   => [
                'all_time' => ApiMoney::amount($total, $currency),
                'cashback' => $cashback,
                'referral' => ApiMoney::amount($referral, $currency),
                'rank'     => ApiMoney::amount($rank, $currency),
            ],
            'cashback' => $this->cashback->describe($user),
            'rank'     => [
                'current'        => $current?->name,
                'current_id'     => $current?->id,
                'current_reward' => $current ? ApiMoney::amount($current->reward, $currency) : null,
                'next'           => $next?->name,
                'next_reward'    => $next ? ApiMoney::amount($next->reward, $currency) : null,
                'to_go'          => $next ? ApiMoney::amount(max(0, (float) $next->transaction_amount - $this->volume($user, $next)), $currency) : null,
            ],
            'invite' => [
                'referred_count'     => $directIds->count(),
                'second_level_count' => $directIds->isEmpty() ? 0 : Referral::query()->whereIn('parent_referral_id', $directIds)->count(),
                'earned'             => ApiMoney::amount($referral, $currency),
            ],
        ];
    }

    /**
     * The reward transactions, newest first; [kind] narrows to one source.
     */
    public function history(User $user, ?string $kind = null, int $perPage = 20): LengthAwarePaginator
    {
        $types = match ($kind) {
            'referral' => [TrxType::REFERRAL_REWARD->value],
            'rank'     => [TrxType::REWARD->value],
            'cashback' => [TrxType::CASHBACK->value],
            default    => [TrxType::REFERRAL_REWARD->value, TrxType::REWARD->value, TrxType::CASHBACK->value],
        };

        $page = Transaction::query()
            ->where('user_id', $user->id)
            ->where('status', TrxStatus::COMPLETED)
            ->whereIn('trx_type', $types)
            ->latest()
            ->latest('id')
            ->paginate($perPage);

        $page->through(fn (Transaction $trx): array => $this->item($trx));

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(Transaction $trx): array
    {
        $kind = match ($trx->trx_type) {
            TrxType::REFERRAL_REWARD => 'referral',
            TrxType::CASHBACK        => 'cashback',
            default                  => 'rank',
        };
        $data = (array) ($trx->trx_data ?? []);

        return [
            'id'     => $trx->id,
            'trx_id' => $trx->trx_id,
            'kind'   => $kind,
            'title'  => match ($kind) {
                'referral' => isset($data['referred_user_name']) ? __('Referral bonus · :name', ['name' => $data['referred_user_name']]) : __('Referral bonus'),
                'cashback' => isset($data['period']) ? __(':period cashback', ['period' => $this->cashback->periodLabel((string) $data['period'])]) : __('Cashback'),
                default    => isset($data['rank_name']) ? __('Reached :rank', ['rank' => $data['rank_name']]) : __('Rank reward'),
            },
            'subtitle' => match ($kind) {
                'referral' => isset($data['reward_type']) ? __('first :type completed', ['type' => str_replace('_', ' ', (string) $data['reward_type'])]) : null,
                'cashback' => isset($data['rate_pct'], $data['spend']) ? __(':rate% on :spend spend', ['rate' => rtrim(rtrim((string) $data['rate_pct'], '0'), '.'), 'spend' => trim(ApiMoney::amount((string) $data['spend'], $trx->payable_currency).' '.($trx->payable_currency ?? ''))]) : null,
                default    => null,
            },
            'amount'   => ApiMoney::amount((string) $trx->amount, $trx->currency),
            'currency' => $trx->currency,
            'at'       => $trx->created_at?->toIso8601String(),
        ];
    }

    /**
     * Referral bonuses paid, in the site currency: each carries its
     * site-currency figure as the payable amount.
     */
    private function referralEarned(User $user, ?string $currency): string
    {
        $total = '0';
        $rows  = Transaction::query()
            ->where('user_id', $user->id)
            ->where('status', TrxStatus::COMPLETED)
            ->where('trx_type', TrxType::REFERRAL_REWARD)
            ->get(['payable_amount', 'payable_currency', 'amount', 'currency']);

        foreach ($rows as $row) {
            $total = DecimalMoney::add($total, $this->inSite((string) ($row->payable_amount ?? $row->amount), (string) ($row->payable_currency ?? $row->currency), $currency), DecimalMoney::CALCULATION_SCALE);
        }

        return $total;
    }

    /**
     * Rank rewards are the ladder's own figures, in the site currency, for
     * every rank reached - the same rule that paid them.
     *
     * @param array{ranks: Collection<int, UserRank>, earned: Collection<int, int>} $progress
     */
    private function rankEarned(array $progress): string
    {
        $total = '0';
        foreach ($progress['ranks'] as $rank) {
            if ($progress['earned']->contains((int) $rank->id)) {
                $total = DecimalMoney::add($total, (string) $rank->reward, DecimalMoney::CALCULATION_SCALE);
            }
        }

        return $total;
    }

    private function inSite(string $amount, string $from, ?string $to): string
    {
        if ($to === null || $from === '' || strtoupper($from) === strtoupper($to)) {
            return $amount;
        }
        $rate = $this->currencies->crossRate($from, $to);

        return $rate === null ? '0' : DecimalMoney::multiply($amount, $rate, DecimalMoney::CALCULATION_SCALE);
    }

    /**
     * What the customer has transacted towards a rank, the listener's rule.
     */
    private function volume(User $user, UserRank $rank): float
    {
        if (empty($rank->transaction_types)) {
            return 0.0;
        }

        return (float) $user->transactions()
            ->whereIn('trx_type', (array) $rank->transaction_types)
            ->where('status', TrxStatus::COMPLETED)
            ->withoutSandbox()
            ->sum('amount');
    }
}
