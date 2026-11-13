<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Exceptions\NotifyErrorException;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentRow;
use App\Models\ScheduledPayment;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Money\WalletPinGuard;
use App\Services\Schedules\ScheduledPaymentService;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * A mass payment batch paid again on a cadence: payroll, rent to several
 * landlords, the weekly float to a dozen agents.
 *
 * The schedule is core's own ScheduledPayment with type `mass`: the rows
 * live in its payload as the grid posts them (recipient, amount, note,
 * reference), and the cadence, weekend rule, low-balance rule, reminders,
 * pause, resume and cancel are the same as for a single scheduled send.
 * When it is due, the runner builds a fresh draft from the rows - every
 * recipient resolved again by the strict resolver, every line priced as it
 * is that day - and confirms it under the mandate the customer gave with
 * their PIN here. Nothing is debited until a run; a run that cannot be
 * covered is a failed run the customer is told about, never a half batch.
 */
final class MassPaymentSchedules
{
    public const MAX_NAME = 100;

    public function __construct(
        private readonly MassPaymentService $massPayments,
        private readonly WalletPinGuard $pins,
    ) {}

    /**
     * The rows as the schedule keeps them: trimmed, empty lines dropped,
     * every line with a recipient and an amount above zero, no more than
     * the batch cap.
     *
     * @param  array<int|string, mixed>                                                                  $rows
     * @return list<array{recipient: string, amount: string, note: string|null, reference: string|null}>
     *
     * @throws NotifyErrorException
     */
    public function rowsFrom(array $rows): array
    {
        $kept = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $recipient = trim((string) ($row['recipient'] ?? ''));
            $amount    = trim((string) ($row['amount'] ?? ''));

            if ($recipient === '' && $amount === '') {
                continue;
            }

            if ($recipient === '' || $amount === '' || ! is_numeric($amount) || DecimalMoney::compare($amount, '0') <= 0) {
                throw new NotifyErrorException(__('Every line of a scheduled batch needs a recipient and an amount above zero.'));
            }

            $kept[] = [
                'recipient' => mb_substr($recipient, 0, 191),
                // Kept as typed: the draft parser prices it at the wallet's own precision.
                'amount'    => $amount,
                'note'      => MassPaymentCsvParser::cleanNote(isset($row['note']) ? (string) $row['note'] : null),
                'reference' => isset($row['reference']) && trim((string) $row['reference']) !== '' ? mb_substr(trim((string) $row['reference']), 0, 100) : null,
            ];
        }

        if ($kept === []) {
            throw new NotifyErrorException(__('A scheduled batch needs at least one line.'));
        }

        if (count($kept) > $this->massPayments->maxRows()) {
            throw new NotifyErrorException(__('A batch may have at most :max lines.', ['max' => $this->massPayments->maxRows()]));
        }

        return $kept;
    }

    /**
     * The lines of an existing batch, as its owner typed them, ready to be
     * kept on a schedule. Skipped and invalid lines come along too: the
     * runner resolves every line again, and a recipient who was missing
     * last month may be there next month.
     *
     * @return list<array{recipient: string, amount: string, note: string|null, reference: string|null}>
     */
    public function rowsOfBatch(MassPaymentBatch $batch): array
    {
        return $this->rowsFrom($batch->rows()->orderBy('line_no')->get()->map(fn (MassPaymentRow $row): array => [
            'recipient' => (string) $row->recipient_input,
            'amount'    => (string) $row->amount_input,
            'note'      => $row->note,
            'reference' => $row->client_reference,
        ])->all());
    }

    /**
     * What the rows add up to, before fees: the figure the schedule shows
     * and the low-balance rule reads.
     *
     * @param list<array{amount: string}> $rows
     */
    public function total(array $rows): string
    {
        $total = '0';

        foreach ($rows as $row) {
            $total = DecimalMoney::add($total, (string) $row['amount'], DecimalMoney::CALCULATION_SCALE);
        }

        return $total;
    }

    /**
     * Set the schedule up, under the customer's PIN: the mandate every run
     * pays under.
     *
     * @param list<array{recipient: string, amount: string, note: string|null, reference: string|null}>                                                                                                          $rows
     * @param array{cadence: string, weekdays?: list<int>|null, day_of_month?: int|null, starts_on: string, ends_on?: string|null, weekend_rule?: string, low_balance?: string, retry_days?: int, remind?: bool} $schedule
     *
     * @throws NotifyErrorException
     */
    public function create(User $user, Wallet $wallet, array $rows, ?string $name, array $schedule, ?string $pin): ScheduledPayment
    {
        $this->massPayments->assertFeatureAvailable($user);
        $this->pins->assert($user, $pin, context: ['flow' => 'mass_payment_schedule']);

        if ((int) $wallet->user_id !== (int) $user->id) {
            throw new NotifyErrorException(__('Choose a wallet to pay from.'));
        }

        $name    = MassPaymentService::batchName($name);
        $cadence = (string) ($schedule['cadence'] ?? 'monthly');

        $created = $user->scheduledPayments()->create([
            'type'           => 'mass',
            'wallet_id'      => $wallet->id,
            'amount'         => $this->total($rows),
            'currency'       => (string) $wallet->currency->code,
            'recipient'      => null,
            'recipient_name' => $name,
            'payload'        => ['name' => $name, 'rows' => $rows],
            'cadence'        => $cadence,
            'interval_days'  => $cadence === 'custom' ? (int) ($schedule['interval_days'] ?? 30) : null,
            'weekdays'       => $cadence === 'weekly' ? array_values(array_unique(array_map('intval', (array) ($schedule['weekdays'] ?? [])))) : null,
            'day_of_month'   => $cadence === 'monthly' ? (int) ($schedule['day_of_month'] ?? CarbonImmutable::parse((string) $schedule['starts_on'])->day) : null,
            'starts_on'      => (string) $schedule['starts_on'],
            'ends_on'        => $schedule['ends_on']                                                                              ?? null,
            'weekend_rule'   => $schedule['weekend_rule']                                                                         ?? 'none',
            'low_balance'    => in_array($schedule['low_balance'] ?? 'retry', ['skip', 'retry'], true) ? $schedule['low_balance'] ?? 'retry' : 'retry',
            'retry_days'     => (int) ($schedule['retry_days'] ?? 3),
            'remind'         => (bool) ($schedule['remind'] ?? false),
            'remind_at'      => '09:00',
            'note'           => $name,
            'status'         => 'active',
            'mandate_id'     => (string) Str::uuid(),
        ]);

        $created->update(['next_run_at' => ScheduledPaymentService::nextRunAfter($created, CarbonImmutable::now()->subSecond())]);

        if ($created->refresh()->next_run_at === null) {
            $created->update(['status' => 'completed']);
        }

        return $created->refresh();
    }

    /**
     * The customer's mass schedules, the live ones first.
     *
     * @return Collection<int, ScheduledPayment>
     */
    public function forUser(User $user): Collection
    {
        return $user->scheduledPayments()
            ->where('type', 'mass')
            ->with(['wallet.currency', 'runs' => fn ($query) => $query->latest('due_at')->limit(12)])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")
            ->orderBy('next_run_at')
            ->latest('id')
            ->get();
    }

    /** @throws NotifyErrorException */
    public function pause(User $user, ScheduledPayment $schedule): ScheduledPayment
    {
        $this->own($user, $schedule);

        if ($schedule->status !== 'active') {
            throw new NotifyErrorException(__('Only a running schedule can be paused.'));
        }

        $schedule->update(['status' => 'paused', 'paused_at' => now(), 'next_run_at' => null]);

        return $schedule->refresh();
    }

    /** @throws NotifyErrorException */
    public function resume(User $user, ScheduledPayment $schedule): ScheduledPayment
    {
        $this->own($user, $schedule);

        if ($schedule->status !== 'paused') {
            throw new NotifyErrorException(__('Only a paused schedule can be resumed.'));
        }

        $schedule->update(['status' => 'active', 'paused_at' => null, 'retry_count' => 0, 'failed_reason' => null, 'next_run_at' => ScheduledPaymentService::nextRunAfter($schedule, CarbonImmutable::now()->subSecond())]);

        if ($schedule->refresh()->next_run_at === null) {
            $schedule->update(['status' => 'completed']);
        }

        return $schedule->refresh();
    }

    /** @throws NotifyErrorException */
    public function cancel(User $user, ScheduledPayment $schedule): ScheduledPayment
    {
        $this->own($user, $schedule);

        if (! in_array($schedule->status, ['active', 'paused'], true)) {
            throw new NotifyErrorException(__('This schedule is already over.'));
        }

        $schedule->update(['status' => 'cancelled', 'next_run_at' => null]);

        return $schedule->refresh();
    }

    /** @throws NotifyErrorException */
    private function own(User $user, ScheduledPayment $schedule): void
    {
        if ((int) $schedule->user_id !== (int) $user->id || $schedule->type !== 'mass') {
            throw new NotifyErrorException(__('This schedule is not yours.'), 404);
        }
    }
}
