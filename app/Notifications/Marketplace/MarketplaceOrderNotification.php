<?php

declare(strict_types=1);

namespace App\Notifications\Marketplace;

use App\Enums\NotificationActionType;
use App\Notifications\Concerns\BroadcastsToNotificationFeed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class MarketplaceOrderNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use BroadcastsToNotificationFeed;
    use Queueable;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly int $orderId,
        public readonly string $title,
        public readonly string $message,
        public readonly array $data = [],
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->withRealtimeChannel(['database']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'marketplace_order',
            'orderId'     => $this->orderId,
            'title'       => $this->title,
            'message'     => $this->message,
            'data'        => $this->data,
            'icon'        => 'shop',
            'action_type' => NotificationActionType::CREATED->value,
        ];
    }
}
