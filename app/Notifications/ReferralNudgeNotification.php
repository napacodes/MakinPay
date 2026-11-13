<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use App\Services\Notifications\Fcm\FcmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A friendly poke from the person who invited someone who has not made
 * their first transfer yet. Sent at most once a week per pair; the
 * controller keeps that rule.
 */
class ReferralNudgeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly User $referrer) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (app(FcmClient::class)->isConfigured() && method_exists($notifiable, 'routeNotificationForFcm') && $notifiable->routeNotificationForFcm() !== []) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title'       => $this->title(),
            'message'     => $this->body(),
            'icon'        => 'sparkles',
            'action_type' => 'referral_nudge',
            // The website's page for it, and the app's screen.
            'action_link' => route('user.dashboard'),
            'app_link'    => '/home',
            'identifier'  => 'referral_nudge',
            'category'    => 'rewards',
            'referrer'    => $this->referrer->name,
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage($this->title(), $this->body()))->data(['identifier' => 'referral_nudge', 'action_link' => '/home']);
    }

    private function title(): string
    {
        return __(':name is waiting for you', ['name' => $this->referrer->name]);
    }

    private function body(): string
    {
        return __('Make your first transfer to unlock the welcome bonus for you both.');
    }
}
