<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Exceptions\NotifyErrorException;
use App\Models\ScheduledPayment;
use App\Models\ScheduledPaymentRun;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\ScheduledPaymentNotification;
use App\Services\CurrencyService;
use App\Services\MassPayment\MassPaymentConfirmation;
use App\Services\MassPayment\MassPaymentService;
use App\Services\MobileRechargeService;
use App\Services\Money\ExchangeMoneyService;
use App\Services\Money\SendMoneyService;
use App\Services\Vaults\VaultService;
use App\Services\WalletService;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs what is due. Each run is written before the money moves, under a
 * unique (schedule, due moment, attempt) key, so a second worker or a
 * retry of the same tick finds the row and does nothing - the existing
 * send, vault deposit and recharge services do the moving, exactly as
 * they do from the app.
 */
class ScheduledPaymentRunner
{
    /** The reason a run carries while its money is moving. */
    public const RUNNING = 'running';

    /** A run still "running" after this long lost its worker. */
    public const STALE_AFTER_MINUTES = 30;

    public function __construct(
        private readonly SendMoneyService $sends,
        private readonly VaultService $vaults,
        private readonly MobileRechargeService $recharges,
        private readonly ExchangeMoneyService $exchanges,
        private readonly WalletService $wallets,
        private readonly CurrencyService $currencies,
        private readonly MassPaymentService $massPayments,
    ) {}

    /**
     * One due schedule, one attempt. Returns the run written, or null
     * when this tick was already taken.
     */
    public function run(ScheduledPayment $schedule, ?CarbonImmutable $now = null, bool $manual = false): ?ScheduledPaymentRun
    {
        $now = $now ?? CarbonImmutable::now();

        $run = DB::transaction(function () use ($schedule, $now, $manual): ?ScheduledPaymentRun {
            $locked = ScheduledPayment::query()->whereKey($schedule->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'active' || $locked->next_run_at === null || (! $manual && $locked->next_run_at > $now)) {
                return null;
            }
            $dueAt = $locked->next_run_at->toImmutable();
            // Attempts count every try at this due moment, the scheduler's and
            // the customer's own, so the history is whole and the key unique.
            $attempt = (int) ScheduledPaymentRun::query()->where('scheduled_payment_id', $locked->id)->where('due_at', $dueAt)->max('attempt') + 1;

            $run = $locked->runs()->create(['due_at' => $dueAt, 'attempt' => $attempt, 'status' => 'failed', 'amount' => $locked->amount, 'currency' => $locked->currency, 'reason' => self::RUNNING]);

            // Claimed while still locked. The money moves after the lock is
            // released, and until then the scheduler and the customer's own
            // "pay now" both saw the same due moment and could each pay it.
            // advance() or failed() sets the next moment when this settles.
            $locked->update(['next_run_at' => null]);

            return $run;
        }, 3);

        if ($run === null) {
            return null;
        }
        $schedule->refresh();
        $user = $schedule->user;

        try {
            $reference = $this->execute($user, $schedule);
            $run->update(['status' => 'success', 'executed_at' => now(), 'reference' => $reference['reference'], 'transaction_id' => $reference['transaction_id'], 'reason' => null]);
            $this->advance($schedule, $run->due_at->toImmutable(), 'success', null);
        } catch (NotifyErrorException $e) {
            $this->failed($user, $schedule, $run, $e->getMessage(), $manual);
        } catch (Throwable $e) {
            report($e);
            $this->failed($user, $schedule, $run, __('Something went wrong.'), $manual);
        }

        return $run->refresh();
    }

    /**
     * @return array{reference: ?string, transaction_id: ?int}
     */
    /**
     * Runs whose worker died between claiming a schedule and settling it.
     *
     * The payment may or may not have gone out, so it is never tried again:
     * the run is marked interrupted, the schedule moves on to its next
     * moment, and the customer is told to check their activity. Paying twice
     * is the one outcome this must not risk.
     *
     * @return int how many were released
     */
    public function releaseStale(?CarbonImmutable $now = null): int
    {
        $cutoff   = ($now ?? CarbonImmutable::now())->subMinutes(self::STALE_AFTER_MINUTES);
        $released = 0;

        ScheduledPaymentRun::query()
            ->where('reason', self::RUNNING)
            ->where('created_at', '<', $cutoff)
            ->with('schedule.user')
            ->orderBy('id')
            ->each(function (ScheduledPaymentRun $run) use (&$released): void {
                $claimed = ScheduledPaymentRun::query()->whereKey($run->id)->where('reason', self::RUNNING)
                    ->update(['status' => 'failed', 'executed_at' => now(), 'reason' => __('Interrupted - check your activity before paying it again.')]);
                if ($claimed !== 1 || $run->schedule === null) {
                    return;
                }

                $schedule = $run->schedule;
                if ($schedule->next_run_at === null) {
                    $this->advance($schedule, $run->due_at->toImmutable(), 'failed', __('Interrupted - check your activity before paying it again.'));
                }
                $released++;
            });

        return $released;
    }

    private function execute(User $user, ScheduledPayment $schedule): array
    {
        $wallet = $schedule->wallet()->with('currency')->firstOrFail();
        $this->topUpIfShort($user, $schedule, $wallet->uuid);

        switch ($schedule->type) {
            case 'send':
                $legs = $this->sends->sendUnderMandate($user, ['wallet_id' => $wallet->id, 'recipient' => (string) $schedule->recipient, 'amount' => (string) $schedule->amount, 'note' => $schedule->note]);

                return ['reference' => $legs['sender']->trx_id, 'transaction_id' => $legs['sender']->id];
            case 'mass':
                return $this->runMassPayment($user, $schedule, $wallet);

            case 'vault':
                $vault = $schedule->vault;
                if ($vault === null || $vault->status === 'closed') {
                    throw new NotifyErrorException(__('That vault is no longer open.'));
                }
                $entry = $this->vaults->deposit($user, $vault, (string) $schedule->amount, 'auto_save', $schedule->note);

                return ['reference' => $entry->transaction?->trx_id, 'transaction_id' => $entry->transaction_id];
            default:
                $payload  = (array) ($schedule->payload ?? []);
                $recharge = $this->recharges->recharge(
                    user: $user,
                    wallet: $wallet,
                    phoneNumber: (string) ($payload['phone_number'] ?? ''),
                    amount: (float) $schedule->amount,
                    operator: $payload['operator'] ?? null,
                    country: $payload['country']   ?? null,
                    metadata: array_filter(['operator_id' => $payload['operator_id'] ?? null, 'plan_id' => $payload['plan_id'] ?? null, 'plan_name' => $payload['plan_name'] ?? null], fn ($v) => $v !== null && $v !== ''),
                );
                if ($recharge->status->value === 'failed') {
                    throw new NotifyErrorException((string) ($recharge->failure_reason ?? __('Mobile recharge failed.')));
                }

                return ['reference' => $recharge->transaction?->trx_id, 'transaction_id' => $recharge->transaction_id];
        }
    }

    /**
     * A mass payment batch, rung up again: a fresh draft from the rows the
     * schedule kept - every recipient resolved again, every line priced as
     * it is today - confirmed under the mandate with the invalid lines
     * skipped. The batch then pays out exactly as one the customer confirmed
     * by hand. A draft that cannot be confirmed is discarded, so a failed
     * run leaves no half-built batch behind, and the reason reaches the
     * customer through the run.
     *
     * @return array{reference: ?string, transaction_id: ?int}
     *
     * @throws NotifyErrorException
     */
    private function runMassPayment(User $user, ScheduledPayment $schedule, Wallet $wallet): array
    {
        $payload = (array) ($schedule->payload ?? []);
        $rows    = array_values(array_filter((array) ($payload['rows'] ?? []), 'is_array'));
        $name    = isset($payload['name']) && is_string($payload['name']) ? $payload['name'] : null;

        if ($rows === []) {
            throw new NotifyErrorException(__('This schedule has no lines left to pay.'));
        }

        try {
            $draft = $this->massPayments->createDraft($user, $wallet->uuid, $rows, MassPaymentService::SOURCE_SCHEDULE, MassPaymentService::CHANNEL_SCHEDULE, $name, (int) $schedule->id);
        } catch (ValidationException $invalid) {
            throw new NotifyErrorException((string) collect($invalid->errors())->flatten()->first());
        }

        try {
            $confirmed = $this->massPayments->confirmUnderMandate($user, (string) $draft->uuid, new MassPaymentConfirmation(
                pin: null,
                fingerprint: (string) $draft->preview_fingerprint,
                expectedTotal: (string) $draft->total_payable,
                skipInvalid: true,
                acknowledgeWarnings: true,
                channel: MassPaymentService::CHANNEL_SCHEDULE,
            ));
        } catch (Throwable $failed) {
            try {
                $this->massPayments->discard($user, (string) $draft->uuid);
            } catch (Throwable) {
                // The draft expires on its own; the failure below is what matters.
            }

            throw $failed instanceof NotifyErrorException ? $failed : new NotifyErrorException($failed->getMessage());
        }

        return ['reference' => (string) $confirmed->batch->reference, 'transaction_id' => null];
    }

    /**
     * The "top up" rule: when the paying wallet is short, exchange the
     * shortfall (and a little room for the fee) from the wallet the
     * customer named. Any other rule leaves the balance alone.
     */
    private function topUpIfShort(User $user, ScheduledPayment $schedule, string $walletUuid): void
    {
        if ($schedule->low_balance !== 'top_up' || $schedule->top_up_wallet_id === null) {
            return;
        }
        $wallet = $schedule->wallet()->with('currency')->first();
        $source = $schedule->topUpWallet()->with('currency')->first();
        if ($wallet === null || $source === null) {
            return;
        }
        $needed = $this->neededFor($user, $schedule, $wallet);
        $short  = DecimalMoney::subtract($needed, (string) $wallet->balance, DecimalMoney::CALCULATION_SCALE);
        if (DecimalMoney::compare($short, '0') <= 0) {
            return;
        }
        $rate = $this->currencies->crossRate((string) $source->currency->code, (string) $wallet->currency->code);
        if ($rate === null || DecimalMoney::compare($rate, '0') <= 0) {
            return;
        }
        $entered = DecimalMoney::normalize(DecimalMoney::multiply(DecimalMoney::divide($short, $rate), '1.03'), ApiMoney::decimals((string) $source->currency->code));
        try {
            $this->exchanges->exchange($user, ['source_wallet_id' => $source->uuid, 'destination_wallet_id' => $wallet->uuid, 'amount' => $entered]);
        } catch (NotifyErrorException) {
            // The payment itself will say what was short.
        }
    }

    /**
     * What the paying wallet has to hold for this run. A send is priced the
     * way it will be charged - amount, fee and, since GF6, its tax - so the
     * top-up covers all of it; anything else keeps the old 2% of room. A send
     * that cannot be quoted falls back to the same room and fails on its own.
     */
    private function neededFor(User $user, ScheduledPayment $schedule, Wallet $wallet): string
    {
        $room = DecimalMoney::multiply((string) $schedule->amount, '1.02');

        if ($schedule->type !== 'send') {
            return $room;
        }

        try {
            $quote = $this->sends->quote($user, ['wallet_id' => $wallet->id, 'recipient' => (string) $schedule->recipient, 'amount' => (string) $schedule->amount]);
        } catch (NotifyErrorException) {
            return $room;
        }

        return DecimalMoney::normalize((string) $quote['payable'], DecimalMoney::CALCULATION_SCALE);
    }

    private function failed(User $user, ScheduledPayment $schedule, ScheduledPaymentRun $run, string $reason, bool $manual = false): void
    {
        // The customer's own try that failed is history, not a used-up retry:
        // the schedule stays where it was and they already saw the reason.
        if ($manual) {
            $run->update(['status' => 'failed', 'executed_at' => now(), 'reason' => $reason]);
            // Back to the moment it had before this run claimed it.
            ScheduledPayment::query()->whereKey($schedule->id)->whereNull('next_run_at')->update(['next_run_at' => $run->due_at]);

            return;
        }
        $insufficient = str_contains(strtolower($reason), 'insufficient');
        $rule         = $insufficient ? $schedule->low_balance : 'retry';
        $attempts     = $schedule->retry_count + 1;
        $retryAt      = null;

        if ($rule === 'skip' || $attempts >= ScheduledPayment::MAX_ATTEMPTS) {
            $run->update(['status' => $rule === 'skip' ? 'skipped' : 'failed', 'executed_at' => now(), 'reason' => $reason]);
            $this->advance($schedule, $run->due_at->toImmutable(), $rule === 'skip' ? 'skipped' : 'failed', $reason);
        } else {
            $retryAt = CarbonImmutable::now()->addDays(max(1, (int) $schedule->retry_days))->setHour(ScheduledPaymentService::RUN_HOUR)->startOfHour();
            $run->update(['status' => 'failed', 'executed_at' => now(), 'reason' => $reason]);
            // The retry keeps the run's due moment: the next attempt is the
            // same payment tried again, not a new one.
            $schedule->update(['retry_count' => $attempts, 'last_run_at' => now(), 'last_status' => 'failed', 'failed_reason' => $reason, 'next_run_at' => $retryAt]);
        }

        $wallet = $schedule->wallet()->with('currency')->first();
        $user->notify(new ScheduledPaymentNotification($schedule->refresh(), 'scheduled_payment_failed', [
            'reason'   => $reason,
            'retry_at' => $retryAt?->format('D j M'),
            'balance'  => $wallet === null ? null : ApiMoney::amount((string) $wallet->balance, (string) $wallet->currency->code).' '.$wallet->currency->code,
        ]));
    }

    /**
     * After a run has settled one way or the other: the next moment, or
     * done for a one-off.
     */
    private function advance(ScheduledPayment $schedule, CarbonImmutable $dueAt, string $status, ?string $reason): void
    {
        // Paused or cancelled while this run was in flight: the outcome is
        // recorded, and the customer's decision stands.
        $schedule->refresh();
        if ($schedule->status !== 'active') {
            $schedule->update(['retry_count' => 0, 'last_run_at' => now(), 'last_status' => $status, 'failed_reason' => $reason]);

            return;
        }

        $next = $schedule->isRecurring() ? ScheduledPaymentService::nextRunAfter($schedule, $dueAt) : null;
        $schedule->update([
            'retry_count'   => 0,
            'last_run_at'   => now(),
            'last_status'   => $status,
            'failed_reason' => $reason,
            'next_run_at'   => $next,
            'reminded_for'  => null,
            'status'        => $next === null ? 'completed' : $schedule->status,
        ]);
    }
}
