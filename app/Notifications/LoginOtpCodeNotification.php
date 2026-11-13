<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;

/**
 * The SMS carrying a one-time sign-in code (GF9). Sent now, never queued,
 * so the code is not written into a queue payload; and toArray() leaves the
 * code out, so no database or log channel can ever record it.
 */
class LoginOtpCodeNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $code, private readonly int $minutes) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [TwilioChannel::class];
    }

    public function toTwilio(object $notifiable): TwilioSmsMessage
    {
        return (new TwilioSmsMessage)->content(__(':app sign-in code: :code. It expires in :minutes minutes. Never share it - we will never ask you for it.', [
            'app'     => config('app.name'),
            'code'    => $this->code,
            'minutes' => $this->minutes,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['minutes' => $this->minutes];
    }
}
