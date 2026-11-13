<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Enums\WalletEarnStatus;
use App\Http\Controllers\Controller;
use App\Models\WalletEarnPlan;
use App\Models\WalletEarnStake;
use App\Services\Money\WalletPinGuard;
use App\Services\WalletEarnService;
use App\Services\WalletEarnSummary;
use App\Support\AmountEntry;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use App\Support\DecimalMoney;
use BackedEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Wallet Earn from the app: the plans on offer, the customer's stakes, one
 * stake with its payouts, and staking into a plan.
 *
 * The plan list is the website's own query - active plans for the currencies
 * the customer holds, plus plans open to every currency. A plan with no
 * currency takes the same minimum and maximum in whichever wallet funds it,
 * which is why its amounts come with currency null. Another customer's stake
 * answers 404, as on the website.
 */
class WalletEarnController extends Controller
{
    public function plans(Request $request, WalletEarnSummary $summary): JsonResponse
    {
        $user        = $request->user();
        $currencyIds = $user->activeWallets()->pluck('currency_id')->all();

        $plans = WalletEarnPlan::query()
            ->with('currency')
            ->active()
            ->where(fn ($query) => $query->whereNull('currency_id')->orWhereIn('currency_id', $currencyIds))
            ->orderByDesc('is_featured')
            ->orderByRaw("CASE WHEN plan_badge IS NOT NULL AND plan_badge <> '' THEN 1 ELSE 0 END DESC")
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::ok([
            'plans'   => $plans->map(fn (WalletEarnPlan $plan): array => $this->plan($plan))->values(),
            'summary' => $summary->for($user) + ['accrual' => $this->accrual($user)],
        ]);
    }

    /**
     * Sets whether a stake rolls itself over at maturity. Only an active
     * stake has a maturity to roll over at.
     */
    public function update(Request $request, string $stake): JsonResponse
    {
        $validated = $request->validate([
            'auto_restake' => ['required', 'boolean'],
        ]);

        $found = WalletEarnStake::query()->with('currency')->where('user_id', $request->user()->id)->findOrFail($stake);
        if ($found->status !== WalletEarnStatus::Active) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('Only an active stake can be set to restake.'));
        }

        $found->update(['auto_restake' => (bool) $validated['auto_restake']]);

        return ApiResponse::ok($this->stake($found->refresh()));
    }

    /**
     * How fast the active stakes earn, per currency: each stake's expected
     * profit spread evenly over its term, so the app can tick a live
     * figure between payouts.
     *
     * @return list<array{currency: ?string, per_second: string, expected_profit: string, principal: string}>
     */
    private function accrual($user): array
    {
        $lines  = [];
        $stakes = WalletEarnStake::query()
            ->with('currency')
            ->where('user_id', $user->id)
            ->where('status', WalletEarnStatus::Active->value)
            ->get();

        foreach ($stakes as $stake) {
            $code                    = $stake->currency?->code;
            $line                    = $lines[$code] ?? ['currency' => $code, 'per_second' => '0', 'expected_profit' => '0', 'principal' => '0', 'paid' => '0'];
            $line['per_second']      = DecimalMoney::add($line['per_second'], $this->perSecond($stake), DecimalMoney::CALCULATION_SCALE);
            $line['expected_profit'] = DecimalMoney::add($line['expected_profit'], (string) $stake->expected_profit, DecimalMoney::CALCULATION_SCALE);
            $line['principal']       = DecimalMoney::add($line['principal'], (string) $stake->principal_amount, DecimalMoney::CALCULATION_SCALE);
            $line['paid']            = DecimalMoney::add($line['paid'], (string) $stake->paid_profit, DecimalMoney::CALCULATION_SCALE);
            $lines[$code]            = $line;
        }

        return array_values(array_map(fn (array $line): array => [
            'currency'        => $line['currency'],
            'per_second'      => $line['per_second'],
            'expected_profit' => ApiMoney::amount($line['expected_profit'], $line['currency']),
            'principal'       => ApiMoney::amount($line['principal'], $line['currency']),
            'paid'            => ApiMoney::amount($line['paid'], $line['currency']),
        ], $lines));
    }

    private function perSecond(WalletEarnStake $stake): string
    {
        if ($stake->status !== WalletEarnStatus::Active || ! $stake->starts_at || ! $stake->matures_at) {
            return '0';
        }
        $seconds = max(1, (int) $stake->matures_at->diffInSeconds($stake->starts_at, true));

        return DecimalMoney::divide((string) $stake->expected_profit, (string) $seconds, 12);
    }

    public function stakes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(WalletEarnStatus::class)],
        ]);

        $page = WalletEarnStake::query()
            ->with('currency')
            ->where('user_id', $request->user()->id)
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->latest('id')
            ->paginate(12);

        $page->through(fn (WalletEarnStake $stake): array => $this->stake($stake));

        return ApiResponse::paginated($page);
    }

    /**
     * Stake an amount from one of the customer's wallets into a plan.
     *
     * The customer chooses the plan, the wallet and the amount; the rate,
     * payouts and dates are the plan's, snapshotted by WalletEarnService in
     * the same transaction that debits the wallet. The wallet is found by its
     * uuid among the caller's own, the amount is refused if it is finer than
     * the wallet's currency can hold, and the wallet PIN is asked for when
     * the customer has set one - the website does not ask, the app does, as
     * for sending money.
     */
    public function store(Request $request, WalletEarnService $walletEarn, WalletPinGuard $pins): JsonResponse
    {
        $uuid   = $request->input('wallet_id');
        $wallet = is_string($uuid)
            ? $request->user()->wallets()->with('currency')->where('uuid', $uuid)->first()
            : null;

        $validated = $request->validate([
            'plan_id'    => ['required', 'integer'],
            'wallet_id'  => ['required', 'string'],
            'amount'     => AmountEntry::rules($wallet?->currency?->code ?? siteCurrency()),
            'wallet_pin' => ['nullable', 'string'],
        ]);

        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $pins->assert($request->user(), $validated['wallet_pin'] ?? null);

        $plan = WalletEarnPlan::query()->active()->findOrFail($validated['plan_id']);

        $stake = $walletEarn->createStake($request->user(), $plan, $wallet, (float) $validated['amount']);

        return ApiResponse::created($this->stake($stake->load('currency')));
    }

    public function show(Request $request, string $stake, WalletEarnService $walletEarn): JsonResponse
    {
        $stake = WalletEarnStake::query()
            ->with(['currency', 'rewards' => fn ($query) => $query->orderBy('payout_number')])
            ->where('user_id', $request->user()->id)
            ->findOrFail($stake);

        $code = $stake->currency?->code;

        return ApiResponse::ok($this->stake($stake) + [
            'payouts' => $this->schedule($stake, $walletEarn),
        ]);
    }

    /**
     * Every payout the stake will make, paid or still to come, each with
     * the date it is due: the ones made carry their own row, the rest are
     * projected from the plan's frequency out to maturity.
     *
     * @return list<array{number: int, amount: string, status: string, due_at: ?string, paid_at: ?string, final: bool}>
     */
    private function schedule(WalletEarnStake $stake, WalletEarnService $walletEarn): array
    {
        $code = $stake->currency?->code;
        $made = $stake->rewards->keyBy('payout_number');
        $rows = [];
        $each = $stake->total_payouts > 0 ? DecimalMoney::divide((string) $stake->expected_profit, (string) $stake->total_payouts, DecimalMoney::CALCULATION_SCALE) : '0';
        $due  = $stake->starts_at;

        for ($n = 1; $n <= max($stake->total_payouts, $made->count()); $n++) {
            $reward = $made->get($n);
            if ($due && $stake->matures_at) {
                $due = $walletEarn->calculateNextPayoutDate($due, $stake->payout_frequency, $stake->matures_at);
            }
            $rows[] = [
                'number'  => $n,
                'amount'  => ApiMoney::amount((string) ($reward?->amount ?? $each), $code),
                'status'  => $reward ? self::value($reward->status) : 'scheduled',
                'due_at'  => $reward?->paid_at?->toIso8601String() ?? $due?->toIso8601String(),
                'paid_at' => $reward?->paid_at?->toIso8601String(),
                'final'   => $n === max($stake->total_payouts, $made->count()),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(WalletEarnPlan $plan): array
    {
        // Not $plan->currency->code: the relation defaults to a translated "ALL"
        // for a plan open to every currency - a label, not a currency.
        $code = $plan->currency_id ? $plan->currency?->code : null;

        return [
            'id'                => $plan->id,
            'name'              => $plan->name,
            'description'       => $plan->description,
            'icon_url'          => filled($plan->icon) ? asset($plan->icon) : null,
            'badge'             => $plan->plan_badge ?: null,
            'featured'          => (bool) $plan->is_featured,
            'currency'          => $code,
            'minimum_amount'    => ApiMoney::amount((string) $plan->minimum_amount, $code),
            'maximum_amount'    => $plan->maximum_amount !== null ? ApiMoney::amount((string) $plan->maximum_amount, $code) : null,
            'profit_type'       => self::value($plan->profit_type),
            'profit_rate'       => (string) $plan->profit_rate,
            'duration_value'    => (int) $plan->duration_value,
            'duration_unit'     => $plan->duration_unit,
            'payout_frequency'  => self::value($plan->payout_frequency),
            'returns_principal' => (bool) $plan->return_principal,
            'auto_approve'      => (bool) $plan->auto_approve,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stake(WalletEarnStake $stake): array
    {
        $code = $stake->currency?->code;

        return [
            'id'                 => $stake->id,
            'plan_name'          => $stake->plan_name,
            'currency'           => $code,
            'principal'          => ApiMoney::amount((string) $stake->principal_amount, $code),
            'expected_profit'    => ApiMoney::amount((string) $stake->expected_profit, $code),
            'paid_profit'        => ApiMoney::amount((string) $stake->paid_profit, $code),
            'payouts_made'       => (int) $stake->payouts_made,
            'total_payouts'      => (int) $stake->total_payouts,
            'status'             => self::value($stake->status),
            'starts_at'          => $stake->starts_at?->toIso8601String(),
            'next_payout_at'     => $stake->next_payout_at?->toIso8601String(),
            'matures_at'         => $stake->matures_at?->toIso8601String(),
            'created_at'         => $stake->created_at?->toIso8601String(),
            'plan_id'            => $stake->wallet_earn_plan_id,
            'reference'          => $stake->trx_id,
            'profit_type'        => self::value($stake->profit_type),
            'profit_rate'        => (string) $stake->profit_rate,
            'payout_frequency'   => self::value($stake->payout_frequency),
            'duration_value'     => (int) $stake->duration_value,
            'duration_unit'      => $stake->duration_unit,
            'returns_principal'  => (bool) $stake->return_principal,
            'auto_restake'       => (bool) $stake->auto_restake,
            'restaked_into_id'   => $stake->restaked_into_id,
            'review_note'        => $stake->review_note,
            'accrual_per_second' => $this->perSecond($stake),
        ];
    }

    private static function value(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
