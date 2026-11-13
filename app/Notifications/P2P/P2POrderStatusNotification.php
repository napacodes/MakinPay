<?php

declare(strict_types=1);

namespace App\Notifications\P2P;

use App\Enums\NotificationActionType;
use App\Jobs\Middleware\RetryTransientMailFailure;
use App\Models\P2P\Order;
use App\Notifications\Concerns\BroadcastsToNotificationFeed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class P2POrderStatusNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use BroadcastsToNotificationFeed;
    use Queueable;

    /**
     * Read by `SendQueuedNotifications`, which sets it on every channel job it
     * dispatches for this notification. The mail job needs all five so
     * `RetryTransientMailFailure` can walk its whole backoff; the database and
     * broadcast jobs simply get two more attempts than the worker's default,
     * which costs nothing because neither talks to a remote host.
     */
    public int $tries = RetryTransientMailFailure::MAX_ATTEMPTS;

    public function __construct(
        public readonly int $orderId,
        public readonly string $title,
        public readonly string $message,
        public readonly array $data = []
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->withRealtimeChannel(
            ($this->data['send_email'] ?? false) ? ['database', 'mail'] : ['database']
        );
    }

    /**
     * Only the mail job can be refused by a remote host, and only its retry is
     * worth delaying — a released database or broadcast job would just rewrite
     * a row that is already correct.
     *
     * @return array<int, object>
     */
    public function middleware(object $notifiable, string $channel): array
    {
        return $channel === 'mail' ? [new RetryTransientMailFailure] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->line($this->message)
            ->action(__('Open Order'), $this->orderUrl())
            ->line(__('Sign in to review the order and respond securely.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'p2p_order',
            'orderId'     => $this->orderId,
            'title'       => $this->title,
            'message'     => $this->message,
            'data'        => $this->data,
            'icon'        => 'p2p_trading',
            'action_type' => NotificationActionType::CREATED->value,
            'action_link' => $this->orderUrl(),
        ];
    }

    /**
     * The link carries the order number, not the id this notification was
     * built with. An order that has since gone falls back to the id, which
     * the route still resolves.
     */
    private function orderUrl(): string
    {
        return route('user.p2p.orders.show', Order::query()->find($this->orderId) ?? $this->orderId);
    }
}
