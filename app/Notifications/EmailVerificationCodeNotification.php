<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The six-digit email verification code the app asks for at sign-up.
 */
class EmailVerificationCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $code, private readonly int $expiresMinutes) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':app verification code: :code', ['app' => config('app.name'), 'code' => $this->code]))
            ->greeting(__('Verify your email'))
            ->line(__('Enter this code in the :app app to confirm your email address:', ['app' => config('app.name')]))
            ->line('**'.$this->code.'**')
            ->line(__('It expires in :minutes minutes. If you did not create an account, you can ignore this email.', ['minutes' => $this->expiresMinutes]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
