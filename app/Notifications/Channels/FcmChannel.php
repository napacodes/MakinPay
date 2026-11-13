<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\UserPushToken;
use App\Notifications\Messages\FcmMessage;
use App\Services\Notifications\Fcm\FcmClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivering a notification to a customer's phones.
 *
 * One send per registered handset, because a customer with a tablet and a
 * phone expects both to light up. A token FCM says is gone is deleted here
 * rather than left to rot: the alternative is every later notification paying
 * for a request that cannot succeed, forever.
 *
 * A transport fault on ONE handset does not fail the notification. The
 * customer has other channels - database, mail, web push - and failing the
 * whole job because a single phone is unreachable would mean retrying the
 * ones that already arrived.
 */
class FcmChannel
{
    public function __construct(private readonly FcmClient $client) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm')) {
            return;
        }

        $tokens = method_exists($notifiable, 'routeNotificationForFcm')
            ? $notifiable->routeNotificationForFcm()
            : [];

        if ($tokens === []) {
            return;
        }

        $message = $notification->toFcm($notifiable);

        if (! $message instanceof FcmMessage) {
            return;
        }

        $payload = $message->toArray();

        foreach ($tokens as $token) {
            $this->deliver((string) $token, $payload);
        }
    }

    private function deliver(string $token, array $payload): void
    {
        try {
            if ($this->client->send($token, $payload)) {
                return;
            }

            // FCM says this handset is gone.
            UserPushToken::query()->where('token', $token)->delete();
        } catch (Throwable $exception) {
            Log::warning('FCM delivery failed', [
                'reason' => $exception->getMessage(),
                // Never the token itself and never the payload: one addresses
                // an install, the other is the customer's own notification.
                'token_length' => strlen($token),
            ]);
        }
    }
}
