<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\MobileRecharge\DetectsRechargeOperators;
use App\Contracts\MobileRecharge\ListsRechargePlans;
use App\Enums\Api\ApiErrorCode;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Models\MobileRecharge;
use App\Models\SavedPhoneNumber;
use App\Services\MobileRecharge\MobileRechargeProviderManager;
use App\Services\MobileRechargeService;
use App\Services\Money\WalletPinGuard;
use App\Services\WalletService;
use App\Support\AmountEntry;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use BackedEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile recharge from the app: the terms a recharge is held to, the
 * numbers the customer keeps, what the provider knows about a number (its
 * operator, its bundles), a quote, and the recharge itself.
 *
 * The operator is still free text underneath - the website takes it that
 * way and so does the recharge - but a provider that can detect it fills
 * it in, and one with a catalogue sells bundles by id. The limits and fee
 * come from the service that enforces them, not from the website's form.
 * The quote is the server's: the app shows it and never sends a price back.
 */
class MobileRechargeController extends Controller
{
    public function show(Request $request, MobileRechargeService $service, MobileRechargeProviderManager $providers): JsonResponse
    {
        $provider = $providers->resolveActiveProvider();
        $limits   = $service->limits($provider);
        $fee      = $service->feeRule($provider);
        $driver   = $providers->activeProvider();

        $saved = $request->user()->savedPhoneNumbers()->latest('id')->get();
        $kept  = $saved->pluck('phone_number')->all();

        // The last ten, one per number, newest first: the numbers the
        // customer tops up, with the ones they saved flagged.
        $recent = $request->user()->mobileRecharges()
            ->latest()
            ->latest('id')
            ->limit(30)
            ->get()
            ->unique('phone_number')
            ->take(10)
            ->map(fn (MobileRecharge $recharge): array => $this->recharge($recharge) + ['saved' => in_array($recharge->phone_number, $kept, true)])
            ->values();

        return ApiResponse::ok([
            'provider' => $provider ? [
                'name'                 => $provider->name,
                'logo_url'             => $provider->logoUrl(),
                'supported_countries'  => array_values((array) ($provider->supported_countries ?? [])),
                'supported_currencies' => array_values((array) ($provider->supported_currencies ?? [])),
                'detects_operator'     => $driver instanceof DetectsRechargeOperators,
                'has_plans'            => $driver instanceof ListsRechargePlans,
            ] : null,
            // In the wallet's own currency, whichever that is: the service
            // applies these numbers to the amount as entered.
            'limits' => [
                'min' => $limits['min'] > 0 ? (string) $limits['min'] : null,
                'max' => $limits['max'] > 0 ? (string) $limits['max'] : null,
            ],
            'fee' => [
                'fixed'   => (string) ($fee['fixed'] ?? 0),
                'percent' => (string) ($fee['percent'] ?? 0),
            ],
            // What the active driver can do, whether or not a provider row describes it.
            'capabilities' => [
                'detects_operator' => $driver instanceof DetectsRechargeOperators,
                'has_plans'        => $driver instanceof ListsRechargePlans,
            ],
            'saved'  => $saved->map(fn (SavedPhoneNumber $number): array => $this->saved($number))->values(),
            'recent' => $recent,
        ]);
    }

    /**
     * Keeps a number to hand. Saving one already kept updates its label.
     */
    public function saveNumber(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9\s\-\(\)]{8,32}$/'],
            'label'        => ['nullable', 'string', 'max:60'],
            'operator'     => ['nullable', 'string', 'max:64'],
            'country'      => ['nullable', 'string', 'size:2'],
        ]);

        $number = SavedPhoneNumber::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'phone_number' => SavedPhoneNumber::normalize($validated['phone_number'])],
            ['label' => $validated['label'] ?? null, 'operator' => $validated['operator'] ?? null, 'country' => isset($validated['country']) ? strtoupper($validated['country']) : null],
        );

        return ApiResponse::created(['number' => $this->saved($number)]);
    }

    public function forgetNumber(Request $request, int $number): JsonResponse
    {
        $request->user()->savedPhoneNumbers()->whereKey($number)->delete();

        return ApiResponse::ok(['deleted' => true]);
    }

    /**
     * Who a number belongs to, when the provider can tell.
     */
    public function operator(Request $request, MobileRechargeProviderManager $providers): JsonResponse
    {
        $validated = $request->validate([
            'phone'   => ['required', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'size:2'],
        ]);

        $driver   = $providers->activeProvider();
        $operator = $driver instanceof DetectsRechargeOperators
            ? $driver->detectOperator($validated['phone'], strtoupper($validated['country'] ?? $this->defaultCountry($providers)))
            : null;

        return ApiResponse::ok(['operator' => $operator]);
    }

    /**
     * The operator's bundles, priced for the wallet the customer would pay
     * from. Empty when the provider has no catalogue - the app hides the tab.
     */
    public function plans(Request $request, MobileRechargeProviderManager $providers, MobileRechargeService $service): JsonResponse
    {
        $validated = $request->validate([
            'operator' => ['required', 'string', 'max:64'],
            'country'  => ['nullable', 'string', 'size:2'],
            'currency' => ['nullable', 'string', 'max:10'],
        ]);

        $currency = strtoupper($validated['currency'] ?? siteCurrency());
        $driver   = $providers->activeProvider();
        $plans    = $driver instanceof ListsRechargePlans
            ? $driver->plans($validated['operator'], strtoupper($validated['country'] ?? $this->defaultCountry($providers)), $currency)
            : [];

        // What each plan costs from a wallet in [$currency] - the figure the
        // quote and the recharge will charge. Null when it cannot be paid
        // from such a wallet at all.
        $plans = array_map(function (array $plan) use ($service, $currency): array {
            try {
                $charge         = $service->planCharge($plan, $currency);
                $plan['charge'] = ['amount' => ApiMoney::amount($charge['amount'], $currency), 'currency' => $currency];
            } catch (NotifyErrorException) {
                $plan['charge'] = null;
            }

            return $plan;
        }, $plans);

        return ApiResponse::ok(['plans' => $plans]);
    }

    public function quote(Request $request, MobileRechargeService $service, WalletService $wallets, MobileRechargeProviderManager $providers): JsonResponse
    {
        $validated = $request->validate([
            'wallet_id' => ['required', 'string'],
            'amount'    => ['required_without:plan_id', 'nullable', 'numeric', 'min:0.01'],
            'plan_id'   => ['nullable', 'string', 'max:120'],
            'country'   => ['nullable', 'string', 'size:2'],
        ]);

        // By uuid - the wallet id the rest of the API speaks - among the caller's own.
        $wallet = $request->user()->wallets()
            ->with('currency')
            ->where('uuid', $validated['wallet_id'])
            ->firstOrFail();

        $code = $wallet->currency->code;

        $service->assertSupported($code, $validated['country'] ?? null);

        $amount = isset($validated['amount']) ? (float) $validated['amount'] : null;
        if (isset($validated['plan_id'])) {
            $driver = $providers->activeProvider();
            $plan   = $driver instanceof ListsRechargePlans
                ? $driver->plan($validated['plan_id'], strtoupper($validated['country'] ?? $this->defaultCountry($providers)), $code)
                : null;

            if ($plan === null) {
                return ApiResponse::error(ApiErrorCode::ValidationFailed, __('That bundle is no longer on offer.'));
            }
            $amount = (float) $service->planCharge($plan, $code)['amount'];
        }

        $quote = $service->quote((float) $amount, $code, fromPlan: isset($validated['plan_id']));

        return ApiResponse::ok([
            'wallet_id'          => $wallet->uuid,
            'currency'           => $code,
            'amount'             => ApiMoney::amount((string) $quote->amount, $code),
            'fee'                => ApiMoney::amount((string) $quote->fee, $code),
            'total'              => ApiMoney::amount((string) $quote->total, $code),
            'sufficient_balance' => $wallets->isWalletBalanceSufficient($wallet->uuid, $quote->total),
        ]);
    }

    /**
     * Recharge a phone from one of the customer's wallets, by an amount or
     * by a plan from the provider's catalogue.
     *
     * MobileRechargeService debits the wallet and writes the recharge in one
     * transaction, then calls the provider; a provider that refuses has the
     * debit refunded before this answers. Every outcome - completed,
     * processing, or failed and refunded - comes back as the recharge that
     * was recorded, with its status, so the replay key can hand a retry the
     * first answer: the provider is called once per key, never again.
     *
     * The amount is in the wallet's own currency, as the quote prices it; a
     * plan's price is read from the catalogue again here, never from the
     * app. The wallet PIN is asked for when one is set.
     */
    public function store(Request $request, MobileRechargeService $service, WalletPinGuard $pins, MobileRechargeProviderManager $providers): JsonResponse
    {
        $uuid   = $request->input('wallet_id');
        $wallet = is_string($uuid)
            ? $request->user()->wallets()->with('currency')->where('uuid', $uuid)->first()
            : null;

        $validated = $request->validate([
            'wallet_id'    => ['required', 'string'],
            'phone_number' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9\s\-\(\)]{8,32}$/'],
            'operator'     => ['nullable', 'string', 'max:64'],
            'operator_id'  => ['nullable', 'string', 'max:64'],
            'country'      => ['nullable', 'string', 'size:2'],
            'plan_id'      => ['nullable', 'string', 'max:120'],
            'amount'       => array_merge(['required_without:plan_id'], AmountEntry::rules($wallet?->currency?->code ?? siteCurrency(), required: false)),
            'wallet_pin'   => ['nullable', 'string'],
        ]);

        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $pins->assert($request->user(), $validated['wallet_pin'] ?? null);

        $metadata = array_filter(['operator_id' => $validated['operator_id'] ?? null]);
        $amount   = isset($validated['amount']) ? (float) $validated['amount'] : null;
        $operator = $validated['operator'] ?? null;

        if (isset($validated['plan_id'])) {
            $driver = $providers->activeProvider();
            $plan   = $driver instanceof ListsRechargePlans
                ? $driver->plan($validated['plan_id'], strtoupper($validated['country'] ?? $this->defaultCountry($providers)), (string) $wallet->currency->code)
                : null;

            if ($plan === null) {
                return ApiResponse::error(ApiErrorCode::ValidationFailed, __('That bundle is no longer on offer.'));
            }

            // Charged in the wallet's currency; the provider is asked for the
            // plan's own sender amount, which is what it bills the platform.
            $charge                        = $service->planCharge($plan, (string) $wallet->currency->code);
            $amount                        = (float) $charge['amount'];
            $metadata['plan_id']           = $plan['id'];
            $metadata['plan_name']         = $plan['name'];
            $metadata['operator_id']       = $metadata['operator_id'] ?? ($plan['operator_id'] ?? null);
            $metadata['provider_amount']   = $charge['provider_amount'];
            $metadata['provider_currency'] = $charge['provider_currency'];
        }

        $recharge = $service->recharge(
            user: $request->user(),
            wallet: $wallet,
            phoneNumber: $validated['phone_number'],
            amount: (float) $amount,
            operator: $operator,
            country: $validated['country'] ?? null,
            metadata: array_filter($metadata, fn ($v) => $v !== null && $v !== ''),
        );

        return ApiResponse::created($this->recharge($recharge->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function recharge(MobileRecharge $recharge): array
    {
        return [
            'id'           => $recharge->id,
            'phone_number' => $recharge->phone_number,
            'operator'     => $recharge->operator,
            'country'      => $recharge->country,
            'amount'       => ApiMoney::amount((string) $recharge->amount, $recharge->currency),
            'fee'          => ApiMoney::amount((string) $recharge->fee, $recharge->currency),
            'total'        => ApiMoney::amount((string) $recharge->total_amount, $recharge->currency),
            'currency'     => $recharge->currency,
            'status'       => $recharge->status instanceof BackedEnum ? $recharge->status->value : $recharge->status,
            'reason'       => $recharge->failure_reason,
            'plan'         => data_get($recharge->metadata, 'plan_name'),
            'created_at'   => $recharge->created_at?->toIso8601String(),
            'processed_at' => $recharge->processed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saved(SavedPhoneNumber $number): array
    {
        return [
            'id'           => $number->id,
            'phone_number' => $number->phone_number,
            'label'        => $number->label,
            'operator'     => $number->operator,
            'country'      => $number->country,
        ];
    }

    private function defaultCountry(MobileRechargeProviderManager $providers): string
    {
        $provider = $providers->resolveActiveProvider();
        $config   = is_array($provider?->config) ? $provider->config : [];

        return strtoupper((string) ($config['default_country'] ?? ((array) ($provider?->supported_countries ?? []))[0] ?? 'BD'));
    }
}
