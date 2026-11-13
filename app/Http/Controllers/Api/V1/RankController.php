<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserRank;
use App\Models\UserRankAchievement;
use App\Services\UserRankProgress;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The rank ladder and the customer's place on it - the website's rank
 * showcase, from the app. The reading is UserRankProgress's, shared with that
 * page, so the two cannot disagree about which rank is current.
 */
class RankController extends Controller
{
    public function show(Request $request, UserRankProgress $ranks): JsonResponse
    {
        $progress = $ranks->for($request->user());
        $earned   = $progress['earned'];
        $currency = siteCurrency();
        $dates    = UserRankAchievement::query()->where('user_id', $request->user()->id)->pluck('earned_at', 'user_rank_id');
        $current  = $progress['current']?->id;

        $describe = fn (UserRank $rank): array => $this->describe($rank, $earned, $currency) + [
            'earned_at'  => $dates->get($rank->id)?->toIso8601String(),
            'is_current' => $current === (int) $rank->id,
            'perks'      => $this->perks($rank),
        ];

        return ApiResponse::ok([
            'current'            => $progress['current'] ? $describe($progress['current']) : null,
            'next'               => $progress['next'] ? $describe($progress['next']) : null,
            'completion_percent' => $progress['completion_percent'],
            'currency'           => $currency,
            'ranks'              => $progress['ranks']->map($describe)->values(),
        ]);
    }

    /**
     * Amounts are in the site's base currency, as strings at its precision -
     * reward is a double column, and a phone rounding it is how "5.00" becomes
     * "4.99".
     *
     * @param  Collection<int, int> $earned
     * @return array<string, mixed>
     */
    private function describe(UserRank $rank, Collection $earned, ?string $currency): array
    {
        return [
            'id'                 => $rank->id,
            'name'               => $rank->name,
            'description'        => $rank->description,
            'icon_url'           => filled($rank->icon) ? asset($rank->icon) : null,
            'transaction_amount' => ApiMoney::amount($rank->transaction_amount, $currency),
            'reward'             => ApiMoney::amount($rank->reward, $currency),
            'features'           => (array) ($rank->features ?? []),
            'earned'             => $earned->contains((int) $rank->id),
        ];
    }

    /**
     * The rank's features as sentences the ladder can print.
     *
     * @return list<string>
     */
    private function perks(UserRank $rank): array
    {
        $features = (array) ($rank->features ?? []);
        $perks    = [];

        if (($wallets = (int) ($features['wallet_create'] ?? 0)) > 0) {
            $perks[] = trans_choice('Up to :count wallet|Up to :count wallets', $wallets, ['count' => $wallets]);
        }
        if (($levels = (int) ($features['referral_level'] ?? 0)) > 0) {
            $perks[] = trans_choice('Referral rewards :count level deep|Referral rewards :count levels deep', $levels, ['count' => $levels]);
        }
        if (filled($rank->description)) {
            $perks[] = (string) $rank->description;
        }

        return $perks;
    }
}
