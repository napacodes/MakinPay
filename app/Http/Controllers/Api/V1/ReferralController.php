<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\ReferralContent;
use App\Models\Reward;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\ReferralNudgeNotification;
use App\Services\CurrencyService;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use App\Support\DecimalMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The referral program, from the app.
 *
 * Mirrors the website's ReferralController. Two differences, both about size:
 * the people referred are paged rather than all sent at once, and the rewards
 * are not repeated here - they are REFERRAL_REWARD transactions, which
 * GET api/v1/transactions?trx_type[]=referral_reward already lists, filtered
 * and paged, in each reward's own currency. A single "total earned" would have
 * to add up different currencies, so there is none.
 */
class ReferralController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user      = $request->user();
        $directIds = Referral::query()->where('user_id', $user->id)->pluck('id');
        $content   = ReferralContent::getContent();

        return ApiResponse::ok([
            'code'               => $user->referral_code,
            'link'               => $user->referral_link,
            'earned'             => $this->earned($user),
            'currency'           => siteCurrency(),
            'referred_count'     => $directIds->count(),
            'second_level_count' => $directIds->isEmpty()
                ? 0
                : Referral::query()->whereIn('parent_referral_id', $directIds)->count(),
            'rewards_trx_type' => TrxType::REFERRAL_REWARD->value,
            'content'          => [
                'heading'   => $content->getLocalizedHeading(),
                'dos'       => array_values($content->getLocalizedPositiveGuidelines()),
                'donts'     => array_values($content->getLocalizedNegativeGuidelines()),
                'image_url' => filled($content->image_path) ? asset($content->image_path) : null,
            ],
        ]);
    }

    /**
     * The people this customer referred, newest first, each with the people
     * THEY referred - the two levels the website's tree shows.
     *
     * A referral whose account is gone is left out rather than shown as a
     * blank row.
     */
    public function referred(Request $request): JsonResponse
    {
        $page = Referral::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('referredUser')
            ->with([
                'referredUser',
                'childReferrals' => fn ($query) => $query->whereHas('referredUser')->with('referredUser'),
            ])
            ->latest('id')
            ->paginate(20);

        $paidFor = $this->paidFor($request->user());
        $types   = Reward::query()->pluck('type')->unique()->values()->all();

        $page->through(fn (Referral $referral): array => $this->person($referral->referredUser) + [
            'bonus_status' => $this->bonusStatus($referral->referredUser, $paidFor, $types),
            'nudged_at'    => Cache::get($this->nudgeKey($request->user(), $referral->referredUser)),
            'referred'     => $referral->childReferrals
                ->map(fn (Referral $child): array => $this->person($child->referredUser))
                ->values()
                ->all(),
        ]);

        return ApiResponse::paginated($page);
    }

    /**
     * A poke to someone this customer invited who has not made the first
     * transfer that pays the bonus. Once a week per person, at most.
     */
    public function nudge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:64'],
        ]);

        $referral = Referral::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('referredUser', fn ($query) => $query->where('username', $validated['username']))
            ->with('referredUser')
            ->first();

        if ($referral === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $types = Reward::query()->pluck('type')->unique()->values()->all();
        if ($this->bonusStatus($referral->referredUser, $this->paidFor($request->user()), $types) === 'paid') {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('They have already made their first transfer.'));
        }

        $key = $this->nudgeKey($request->user(), $referral->referredUser);
        if (Cache::has($key)) {
            return ApiResponse::error(ApiErrorCode::RateLimited, __('You already nudged them this week.'));
        }

        $referral->referredUser->notify(new ReferralNudgeNotification($request->user()));
        Cache::put($key, now()->toIso8601String(), now()->addDays(7));

        return ApiResponse::ok(['nudged_at' => Cache::get($key)]);
    }

    /**
     * Referral bonuses paid to this customer, in the site currency.
     */
    private function earned(User $user): string
    {
        $total = '0';
        $site  = siteCurrency();
        $rows  = Transaction::query()
            ->where('user_id', $user->id)
            ->where('status', TrxStatus::COMPLETED)
            ->where('trx_type', TrxType::REFERRAL_REWARD)
            ->get(['payable_amount', 'payable_currency', 'amount', 'currency']);

        foreach ($rows as $row) {
            $from = (string) ($row->payable_currency ?? $row->currency);
            $rate = $site === null || strtoupper($from) === strtoupper($site) ? '1' : app(CurrencyService::class)->crossRate($from, $site);
            if ($rate === null) {
                continue;
            }
            $total = DecimalMoney::add($total, DecimalMoney::multiply((string) ($row->payable_amount ?? $row->amount), $rate, DecimalMoney::CALCULATION_SCALE), DecimalMoney::CALCULATION_SCALE);
        }

        return ApiMoney::amount($total, $site);
    }

    /**
     * The referees this customer has already been paid for, by id.
     *
     * @return list<int>
     */
    private function paidFor(User $user): array
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('trx_type', TrxType::REFERRAL_REWARD)
            ->where('status', TrxStatus::COMPLETED)
            ->get(['trx_data'])
            ->map(fn (Transaction $trx): ?int => isset($trx->trx_data['referred_user_id']) ? (int) $trx->trx_data['referred_user_id'] : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * paid once a bonus for this person was recorded, or - for bonuses
     * paid before that was recorded - once they made a rewarded kind of
     * transaction; awaiting until then.
     *
     * @param list<int>    $paidFor
     * @param list<string> $types
     */
    private function bonusStatus(User $referee, array $paidFor, array $types): string
    {
        if (in_array($referee->id, $paidFor, true)) {
            return 'paid';
        }
        if ($types !== [] && $referee->transactions()->where('status', TrxStatus::COMPLETED)->whereIn('trx_type', $types)->exists()) {
            return 'paid';
        }

        return 'awaiting';
    }

    private function nudgeKey(User $referrer, User $referee): string
    {
        return "referral_nudge:{$referrer->id}:{$referee->id}";
    }

    /**
     * What the website shows of someone referred, and nothing more - no
     * address, no phone number.
     *
     * @return array{name: string|null, username: string|null, avatar_url: string, joined_at: string|null}
     */
    private function person(User $user): array
    {
        return [
            'name'       => $user->name,
            'username'   => $user->username,
            'avatar_url' => asset($user->avatar_alt),
            'joined_at'  => $user->created_at?->toIso8601String(),
        ];
    }
}
