<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Models\ScheduledPayment;
use App\Models\Vault;
use App\Models\Wallet;
use App\Services\MassPayment\MassPaymentSchedules;
use App\Services\MassPayment\MassPaymentService;
use App\Services\Money\SendMoneyService;
use App\Services\Money\WalletPinGuard;
use App\Services\Schedules\ScheduledPaymentRunner;
use App\Services\Schedules\ScheduledPaymentService;
use App\Support\AmountEntry;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Scheduled payments. Creating one takes the wallet PIN once and issues
 * a standing mandate; the scheduler then runs it through the same
 * services the app uses, with a per-run key so nothing pays twice.
 */
class ScheduledPaymentController extends Controller
{
    public function __construct(
        private readonly ScheduledPaymentService $schedules,
        private readonly ScheduledPaymentRunner $runner,
        private readonly SendMoneyService $sends,
        private readonly WalletPinGuard $pins,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->schedules->overview($request->user()));
    }

    public function show(Request $request, ScheduledPayment $schedule): JsonResponse
    {
        $this->own($request, $schedule);

        return ApiResponse::ok(['schedule' => $this->schedules->describe($schedule->load(['wallet', 'vault', 'topUpWallet']), $request->user()->displayCurrency(), withRuns: true)]);
    }

    /**
     * What a schedule would do before it is created: the next five dates
     * and, for a send, who and what it costs per payment.
     */
    public function preview(Request $request): JsonResponse
    {
        $user                 = $request->user();
        [$wallet, $validated] = $this->validated($request);
        $draft                = new ScheduledPayment($validated + ['user_id' => $user->id, 'wallet_id' => $wallet->id, 'currency' => $wallet->currency->code, 'mandate_id' => '']);
        $first                = ScheduledPaymentService::nextRunAfter($draft, CarbonImmutable::now()->subSecond());
        $dates                = [];
        $cursor               = $first;
        for ($i = 0; $i < 5 && $cursor !== null; $i++) {
            $dates[] = $cursor->toDateString();
            $cursor  = $draft->isRecurring() ? ScheduledPaymentService::nextRunAfter($draft, $cursor) : null;
        }
        $fee       = '0';
        $tax       = '0';
        $recipient = null;
        // The fee in the wallet's currency, beside the tax: `fee` on the quote
        // is the site-currency figure.
        if ($validated['type'] === 'send') {
            $quote     = $this->sends->quote($user, ['wallet_id' => $wallet->id, 'recipient' => $validated['recipient'], 'amount' => $validated['amount']]);
            $fee       = DecimalMoney::subtract((string) $quote['payable_before_tax'], (string) $quote['net'], DecimalMoney::CALCULATION_SCALE);
            $tax       = $quote['tax']->total;
            $recipient = ['name' => $quote['recipient_wallet']->user?->name, 'username' => $quote['recipient_wallet']->user?->username];
        }

        return ApiResponse::ok([
            'dates' => $dates,
            'first' => $first?->toDateString(),
            'fee'   => ApiMoney::amount($fee, $wallet->currency->code),
            // GF6: the tax each run is charged at today's rules, on top of the fee.
            'tax'       => ApiMoney::amount($tax, $wallet->currency->code),
            'currency'  => $wallet->currency->code,
            'recipient' => $recipient,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user                 = $request->user();
        [$wallet, $validated] = $this->validated($request);
        $pin                  = $request->validate(['wallet_pin' => ['nullable', 'string']])['wallet_pin'] ?? null;
        $this->pins->assert($user, $pin);

        if ($validated['type'] === 'send') {
            $quote                       = $this->sends->quote($user, ['wallet_id' => $wallet->id, 'recipient' => $validated['recipient'], 'amount' => $validated['amount']]);
            $validated['recipient_name'] = $quote['recipient_wallet']->user?->name;
        }

        // A whole batch on a cadence: the mass payment module's own rules,
        // and the same service the merchant's web screen uses.
        if ($validated['type'] === 'mass') {
            $schedules = app(MassPaymentSchedules::class);
            $schedule  = $schedules->create($user, $wallet, $schedules->rowsFrom((array) ($validated['payload']['rows'] ?? [])), $validated['payload']['name'] ?? null, $validated, $pin);

            return ApiResponse::created(['schedule' => $this->schedules->describe($schedule->load(['wallet', 'vault', 'topUpWallet']), $user->displayCurrency(), withRuns: true)]);
        }

        $schedule = $user->scheduledPayments()->create($validated + ['wallet_id' => $wallet->id, 'currency' => $wallet->currency->code, 'mandate_id' => (string) Str::uuid(), 'status' => 'active']);
        $schedule->update(['next_run_at' => ScheduledPaymentService::nextRunAfter($schedule, CarbonImmutable::now()->subSecond())]);

        return ApiResponse::created(['schedule' => $this->schedules->describe($schedule->refresh()->load(['wallet', 'vault', 'topUpWallet']), $user->displayCurrency(), withRuns: true)]);
    }

    /**
     * Edits that change what is paid or to whom take the PIN again; the
     * rest - dates, rules, reminder, note - do not.
     */
    public function update(Request $request, ScheduledPayment $schedule): JsonResponse
    {
        $this->own($request, $schedule);
        if (! in_array($schedule->status, ['active', 'paused'], true)) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('This schedule has finished.'));
        }
        $user                 = $request->user();
        [$wallet, $validated] = $this->validated($request, $schedule);

        // A mass schedule's lines are its money: normalised and added up
        // here, so a changed line is a change of money and asks for the PIN.
        if ($validated['type'] === 'mass') {
            $schedules                   = app(MassPaymentSchedules::class);
            $rows                        = $schedules->rowsFrom((array) ($validated['payload']['rows'] ?? []));
            $name                        = MassPaymentService::batchName($validated['payload']['name'] ?? null);
            $validated['payload']        = ['name' => $name, 'rows' => $rows];
            $validated['amount']         = $schedules->total($rows);
            $validated['recipient_name'] = $name;
            $validated['note']           = $name;
        }

        $moneyChanged = $validated['amount'] !== (string) $schedule->amount || $validated['recipient'] !== $schedule->recipient || $wallet->id !== $schedule->wallet_id || $validated['type'] !== $schedule->type || ($validated['type'] === 'mass' && $validated['payload'] !== $schedule->payload);
        if ($moneyChanged) {
            $this->pins->assert($user, $request->validate(['wallet_pin' => ['nullable', 'string']])['wallet_pin'] ?? null);
            if ($validated['type'] === 'send') {
                $validated['recipient_name'] = $this->sends->quote($user, ['wallet_id' => $wallet->id, 'recipient' => $validated['recipient'], 'amount' => $validated['amount']])['recipient_wallet']->user?->name;
            }
        }
        $schedule->fill($validated + ['wallet_id' => $wallet->id, 'currency' => $wallet->currency->code, 'retry_count' => 0, 'failed_reason' => null]);
        $schedule->next_run_at = $schedule->status === 'active' ? ScheduledPaymentService::nextRunAfter($schedule, CarbonImmutable::now()->subSecond()) : null;
        $schedule->save();

        return ApiResponse::ok(['schedule' => $this->schedules->describe($schedule->refresh()->load(['wallet', 'vault', 'topUpWallet']), $user->displayCurrency(), withRuns: true)]);
    }

    public function pause(Request $request, ScheduledPayment $schedule): JsonResponse
    {
        $this->own($request, $schedule);
        $schedule->update(['status' => 'paused', 'paused_at' => now(), 'next_run_at' => null]);

        return $this->show($request, $schedule->refresh());
    }

    public function resume(Request $request, ScheduledPayment $schedule): JsonResponse
    {
        $this->own($request, $schedule);
        $schedule->update(['status' => 'active', 'paused_at' => null, 'retry_count' => 0, 'failed_reason' => null, 'next_run_at' => ScheduledPaymentService::nextRunAfter($schedule, CarbonImmutable::now()->subSecond())]);
        if ($schedule->refresh()->next_run_at === null) {
            $schedule->update(['status' => 'completed']);
        }

        return $this->show($request, $schedule->refresh());
    }

    public function skipNext(Request $request, ScheduledPayment $schedule): JsonResponse
    {
        $this->own($request, $schedule);
        if ($schedule->status !== 'active' || $schedule->next_run_at === null) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('There is nothing to skip.'));
        }
        $next = $schedule->isRecurring() ? ScheduledPaymentService::nextRunAfter($schedule, $schedule->next_run_at->toImmutable()) : null;
        $schedule->update(['next_run_at' => $next, 'retry_count' => 0, 'failed_reason' => null, 'reminded_for' => null, 'status' => $next === null ? 'completed' : 'active']);

        return $this->show($request, $schedule->refresh());
    }

    /**
     * The failed (or simply due) payment, made now with the PIN: the run
     * goes into the same history as the scheduler's.
     */
    public function payNow(Request $request, ScheduledPayment $schedule): JsonResponse
    {
        $this->own($request, $schedule);
        $this->pins->assert($request->user(), $request->validate(['wallet_pin' => ['nullable', 'string']])['wallet_pin'] ?? null);
        if ($schedule->status !== 'active' || $schedule->next_run_at === null) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('There is nothing due on this schedule.'));
        }
        $run = $this->runner->run($schedule, manual: true);
        if ($run === null) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('That payment is already being made.'));
        }
        if ($run->status !== 'success') {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, (string) ($run->reason ?? __('The payment could not be made.')));
        }

        return ApiResponse::created(['run' => ['reference' => $run->reference, 'status' => $run->status], 'schedule' => $this->schedules->describe($schedule->refresh()->load(['wallet', 'vault', 'topUpWallet']), $request->user()->displayCurrency(), withRuns: true)]);
    }

    public function destroy(Request $request, ScheduledPayment $schedule): JsonResponse
    {
        $this->own($request, $schedule);
        $schedule->update(['status' => 'cancelled', 'next_run_at' => null]);

        return ApiResponse::ok(['deleted' => true]);
    }

    /**
     * @return array{0: Wallet, 1: array<string, mixed>}
     */
    private function validated(Request $request, ?ScheduledPayment $schedule = null): array
    {
        $user   = $request->user();
        $uuid   = $request->input('wallet_id', $schedule?->wallet?->uuid);
        $wallet = is_string($uuid) ? $user->wallets()->with('currency')->where('uuid', $uuid)->first() : null;
        abort_if($wallet === null, 422, __('Choose a wallet to pay from.'));
        $code = (string) $wallet->currency->code;

        $isMass = $request->input('type') === 'mass';

        $validated = $request->validate([
            'type'      => ['required', Rule::in(ScheduledPayment::TYPES)],
            'wallet_id' => ['nullable', 'string'],
            // A mass schedule's amount is what its lines add up to, worked out here.
            'amount'                   => $isMass ? ['nullable'] : AmountEntry::rules($code),
            'recipient'                => ['required_if:type,send', 'nullable', 'string', 'max:255'],
            'vault_id'                 => ['required_if:type,vault', 'nullable', 'integer'],
            'payload'                  => ['required_if:type,recharge,mass', 'nullable', 'array'],
            'payload.name'             => ['nullable', 'string', 'max:'.MassPaymentSchedules::MAX_NAME],
            'payload.rows'             => ['required_if:type,mass', 'nullable', 'array', 'min:1', 'max:1000'],
            'payload.rows.*'           => ['array'],
            'payload.rows.*.recipient' => ['nullable', 'string', 'max:191'],
            'payload.rows.*.amount'    => ['nullable', 'string', 'max:64'],
            'payload.rows.*.note'      => ['nullable', 'string', 'max:255'],
            'payload.rows.*.reference' => ['nullable', 'string', 'max:100'],
            'payload.phone_number'     => ['required_if:type,recharge', 'nullable', 'string', 'max:32'],
            'payload.operator'         => ['nullable', 'string', 'max:64'],
            'payload.operator_id'      => ['nullable', 'string', 'max:64'],
            'payload.country'          => ['nullable', 'string', 'size:2'],
            'payload.plan_id'          => ['nullable', 'string', 'max:120'],
            'payload.plan_name'        => ['nullable', 'string', 'max:120'],
            'cadence'                  => ['required', Rule::in(ScheduledPayment::CADENCES)],
            'interval_days'            => ['required_if:cadence,custom', 'nullable', 'integer', 'min:1', 'max:365'],
            'weekdays'                 => ['nullable', 'array', 'max:7'],
            'weekdays.*'               => ['integer', 'min:1', 'max:7'],
            'day_of_month'             => ['nullable', 'integer', 'min:1', 'max:31'],
            'starts_on'                => ['required', 'date', 'after_or_equal:today'],
            'ends_on'                  => ['nullable', 'date', 'after_or_equal:starts_on'],
            'weekend_rule'             => ['nullable', Rule::in(ScheduledPayment::WEEKEND_RULES)],
            'low_balance'              => ['nullable', Rule::in(ScheduledPayment::LOW_BALANCE)],
            'retry_days'               => ['nullable', 'integer', 'min:1', 'max:14'],
            'top_up_wallet_id'         => ['required_if:low_balance,top_up', 'nullable', 'string'],
            'remind'                   => ['nullable', 'boolean'],
            'remind_at'                => ['nullable', 'date_format:H:i'],
            'note'                     => ['nullable', 'string', 'max:255'],
        ]);

        $vault = null;
        if ($validated['type'] === 'vault') {
            $vault = Vault::query()->where('user_id', $user->id)->where('status', '!=', 'closed')->find($validated['vault_id']);
            abort_if($vault === null, 422, __('Choose one of your open vaults.'));
            abort_if((int) $vault->wallet_id !== (int) $wallet->id, 422, __('A vault is topped up from its own wallet.'));
        }
        $topUp = null;
        if (($validated['low_balance'] ?? 'retry') === 'top_up') {
            $topUp = $user->wallets()->where('uuid', $validated['top_up_wallet_id'])->first();
            abort_if($topUp === null || $topUp->id === $wallet->id, 422, __('Choose a different wallet to top up from.'));
        }

        return [$wallet, [
            'type'             => $validated['type'],
            'amount'           => $isMass ? '0' : (string) $validated['amount'],
            'recipient'        => $validated['type'] === 'send' ? $validated['recipient'] : null,
            'vault_id'         => $vault?->id,
            'payload'          => in_array($validated['type'], ['recharge', 'mass'], true) ? $validated['payload'] : null,
            'cadence'          => $validated['cadence'],
            'interval_days'    => $validated['cadence'] === 'custom' ? (int) $validated['interval_days'] : null,
            'weekdays'         => $validated['cadence'] === 'weekly' ? array_values(array_unique(array_map('intval', $validated['weekdays'] ?? []))) : null,
            'day_of_month'     => $validated['cadence'] === 'monthly' ? (int) ($validated['day_of_month'] ?? CarbonImmutable::parse($validated['starts_on'])->day) : null,
            'starts_on'        => $validated['starts_on'],
            'ends_on'          => $validated['ends_on']      ?? null,
            'weekend_rule'     => $validated['weekend_rule'] ?? 'none',
            'low_balance'      => $validated['low_balance']  ?? 'retry',
            'retry_days'       => (int) ($validated['retry_days'] ?? 3),
            'top_up_wallet_id' => $topUp?->id,
            'remind'           => (bool) ($validated['remind'] ?? false),
            'remind_at'        => $validated['remind_at'] ?? '09:00',
            'note'             => $validated['note']      ?? null,
        ]];
    }

    private function own(Request $request, ScheduledPayment $schedule): void
    {
        abort_unless((int) $schedule->user_id === (int) $request->user()->id, 404);
    }
}
