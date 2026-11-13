<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AmountFlow;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Transaction;
use App\Notifications\MoneyRequestReminderNotification;
use App\Services\Api\CounterpartyResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The day before a request falls due, nudge the payer - when the person
 * who asked ticked "remind". Once per request: the nudge is stamped on the
 * row so a rerun of the same day sends nothing twice.
 */
class RemindDueRequests extends Command
{
    protected $signature = 'requests:remind-due';

    protected $description = 'Remind payers about money requests due tomorrow.';

    public function handle(CounterpartyResolver $counterparties): int
    {
        $tomorrow = CarbonImmutable::tomorrow()->toDateString();
        $sent     = 0;

        Transaction::query()
            ->where('trx_type', TrxType::REQUEST_MONEY)
            ->where('amount_flow', AmountFlow::MINUS)
            ->where('status', TrxStatus::PENDING)
            ->with('user')
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($tomorrow, $counterparties, &$sent): void {
                foreach ($chunk as $leg) {
                    $data = $leg->trx_data ?? [];
                    if (($data['remind'] ?? false) !== true || substr((string) ($data['due_at'] ?? ''), 0, 10) !== $tomorrow || ! empty($data['due_reminder_sent_at'])) {
                        continue;
                    }

                    $requester = $counterparties->for($leg);
                    $leg->update(['trx_data' => array_merge($data, ['due_reminder_sent_at' => now()->toIso8601String()])]);
                    $leg->user?->notify(new MoneyRequestReminderNotification($leg, $requester['name'] ?? __('Someone'), dueTomorrow: true));
                    $sent++;
                }
            });

        $this->components->info(sprintf('%d reminder(s) sent.', $sent));

        return self::SUCCESS;
    }
}
