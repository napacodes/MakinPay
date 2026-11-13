<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BillPayment\BillPaymentStatus;
use App\Models\SavedBill;
use App\Notifications\TemplateNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * BP3: on the day a customer asked for, say "Pay <bill>" and point at the
 * one-tap pay page. Nothing is paid by itself.
 *
 * Once a month per bill: the day it went out is stamped on the row, so a
 * rerun of the same day sends nothing twice. A bill already paid this month
 * (any payment that did not fail) is not nagged about, and a bill whose
 * biller can no longer be paid gets no reminder it could not act on. A day
 * the month does not have (the 31st in April) falls on the month's last day.
 */
class RemindSavedBills extends Command
{
    protected $signature = 'bill-payment:remind-saved';

    protected $description = 'Remind customers to pay the saved bills whose reminder day is today.';

    public function handle(): int
    {
        $today = CarbonImmutable::today();
        $sent  = 0;

        SavedBill::query()
            ->whereNotNull('remind_day')
            ->where(fn (Builder $due): Builder => $due
                ->where('remind_day', $today->day)
                ->when($today->isLastOfMonth(), fn (Builder $last): Builder => $last->orWhere('remind_day', '>', $today->day)))
            ->where(fn (Builder $fresh): Builder => $fresh->whereNull('reminded_for')->orWhere('reminded_for', '<', $today->startOfMonth()->toDateString()))
            ->whereHas('biller', fn (Builder $billers): Builder => $billers->payable())
            ->with(['user', 'biller'])
            ->orderBy('id')
            ->chunkById(200, function (Collection $chunk) use ($today, &$sent): void {
                foreach ($chunk as $bill) {
                    if ($bill->user === null || $bill->biller === null || ! $bill->remindsOn($today) || $this->paidThisMonth($bill, $today)) {
                        continue;
                    }

                    $bill->forceFill(['reminded_for' => $today->toDateString()])->save();

                    $bill->user->notify(new TemplateNotification('bill_payment_user_reminder', [
                        'title'           => $bill->title(),
                        'biller'          => (string) $bill->biller->name,
                        'customer_number' => (string) $bill->customer_number,
                        'day'             => $today->format('jS'),
                    ], null, route('user.bill-payment.create', ['biller' => $bill->biller_id, 'saved' => $bill->id])));

                    $sent++;
                }
            });

        $this->components->info(sprintf('%d reminder(s) sent.', $sent));

        return self::SUCCESS;
    }

    /** A payment to this bill this month that did not fail: nothing to remind. */
    private function paidThisMonth(SavedBill $bill, CarbonImmutable $today): bool
    {
        return $bill->user->billPayments()
            ->where('biller_id', $bill->biller_id)
            ->where('customer_number', (string) $bill->customer_number)
            ->where('status', '!=', BillPaymentStatus::Failed->value)
            ->where('created_at', '>=', $today->startOfMonth())
            ->exists();
    }
}
