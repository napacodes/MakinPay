<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The broadcast channel, made unable to fail anything but itself.
 *
 * A live push is a hint on top of the stored notification, never the record.
 * On a sync queue the broadcast runs inline, inside whatever sent the
 * notification - often a money flow still in its database transaction - and
 * an unreachable Pusher or Reverb server threw straight through it, rolling
 * back a deposit that had been captured. Here a failed push is logged and
 * the notification carries on: the database row, the mail and the phone
 * push are separate channels and still arrive.
 */
class ResilientBroadcastChannel extends BroadcastChannel
{
    /**
     * @param mixed $notifiable
     */
    public function send($notifiable, Notification $notification): ?array
    {
        try {
            return parent::send($notifiable, $notification);
        } catch (Throwable $exception) {
            Log::warning('Realtime broadcast failed; the notification itself was still delivered.', [
                'notification' => $notification::class,
                'notifiable'   => is_object($notifiable) && method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null,
                // The broadcaster's own words: a host, a status. Never the
                // payload, which is the customer's notification.
                'reason' => Str::limit($exception->getMessage(), 300),
            ]);

            return null;
        }
    }
}
