<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationActionType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells an administrator a review queue has passed its SLA target.
 *
 * The dashboard already shows this, but only to someone who has the dashboard
 * open. A queue that goes late overnight is exactly the one nobody is looking
 * at, which is the case this exists for.
 */
class QueueSlaBreachAlert extends Notification
{
    use Queueable;

    /**
     * @param list<array{title: string, over_count: int, count: int, oldest_label: string}> $queues
     */
    public function __construct(
        public array $queues,
        public string $worstQueue,
        public string $worstAge,
        public string $link,
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
        $detail = collect($this->queues)
            ->map(fn (array $queue): string => __(':queue :over of :total, oldest :age', [
                'queue' => $queue['title'],
                'over'  => number_format($queue['over_count']),
                'total' => number_format($queue['count']),
                'age'   => $queue['oldest_label'],
            ]))
            ->implode(' · ');

        return [
            'title' => trans_choice(
                '{1}:count review queue is past its SLA|[2,*]:count review queues are past their SLA',
                count($this->queues),
                ['count' => count($this->queues)]
            ),
            'message'     => $detail,
            'icon'        => 'warning-2',
            'action_type' => NotificationActionType::FAILED->value,
            'action_link' => $this->link,
            'sender'      => null,
        ];
    }
}
