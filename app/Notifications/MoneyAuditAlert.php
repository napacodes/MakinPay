<?php

namespace App\Notifications;

use App\Enums\NotificationActionType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells currency administrators that the nightly ledger audit found something.
 *
 * The audit itself is read-only and reports to stdout, which on a scheduled
 * run means nowhere. This is what makes the schedule worth having: the run
 * stays quiet while the ledger is clean and speaks up the moment it is not.
 *
 * The checks are named rather than the individual records — a run can surface
 * hundreds of rows, and the actionable part is which *kind* of problem
 * appeared. `money:audit-precision` prints the detail.
 */
class MoneyAuditAlert extends Notification
{
    use Queueable;

    /**
     * @param int          $count  Records needing review
     * @param list<string> $checks Distinct check names, most frequent first
     */
    public function __construct(
        public int $count,
        public array $checks,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title'   => __('Ledger audit found records to review'),
            'message' => __(':count record(s) need review (:checks). Run money:audit-precision for the detail. Nothing was modified.', [
                'count'  => $this->count,
                'checks' => implode(', ', $this->checks),
            ]),
            'icon' => 'warning-2',
            /*
             * The audit did not fail. It returns SUCCESS on purpose - see the
             * comment on that return - because the command did its job and it
             * is the ledger that needs attention. FAILED here contradicted the
             * background-task log sitting beside it and read as an outage.
             */
            'action_type' => NotificationActionType::REVIEW->value,
            'action_link' => route('admin.currency.index'),
            'sender'      => null,
        ];
    }
}
