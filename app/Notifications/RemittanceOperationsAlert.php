<?php

namespace App\Notifications;

use App\Enums\NotificationActionType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RemittanceOperationsAlert extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public int $count,
        public string $oldestReference,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title'   => __('Remittance reconciliation required'),
            'message' => __(':count payout(s) are unresolved. Oldest reference: :reference.', [
                'count'     => $this->count,
                'reference' => $this->oldestReference,
            ]),
            'icon'        => 'warning-2',
            'action_type' => NotificationActionType::FAILED->value,
            'action_link' => route('admin.remittance.transfers.index'),
            'sender'      => null,
        ];
    }
}
