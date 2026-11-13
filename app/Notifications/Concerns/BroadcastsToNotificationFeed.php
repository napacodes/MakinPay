<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Support\RealtimeConnection;
use Illuminate\Notifications\Messages\BroadcastMessage;

/**
 * Pushes a stored notification to the bell feed in real time.
 *
 * The browser binds a single `notification.received` event, so every
 * notification that lands in the database must broadcast under that name —
 * otherwise the feed only updates on the next page load.
 */
trait BroadcastsToNotificationFeed
{
    /**
     * Add the broadcast channel next to `database` whenever realtime is configured.
     *
     * @param  array<int, string> $channels
     * @return array<int, string>
     */
    protected function withRealtimeChannel(array $channels): array
    {
        if (! in_array('database', $channels, true) || ! $this->realtimeDeliveryEnabled()) {
            return $channels;
        }

        $channels[] = 'broadcast';

        return array_values(array_unique($channels));
    }

    protected function realtimeDeliveryEnabled(): bool
    {
        return RealtimeConnection::canBroadcast();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage(array_merge(
            $this->toArray($notifiable),
            ['timestamp' => now()->toISOString()],
        ));
    }

    public function broadcastAs(): string
    {
        return RealtimeConnection::EVENT;
    }
}
