<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LoginActivity;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use App\Services\Notifications\Fcm\FcmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A sign-in from a device or place this account has not used before. The
 * row carries what the inbox needs for "That was me" and "Secure account".
 */
class NewDeviceLoginNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly LoginActivity $activity) {}

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
            'icon'        => 'shield-alert',
            'action_type' => 'new_device_login',
            // The website's page for it, and the app's screen.
            'action_link'       => route('user.settings.security.index'),
            'app_link'          => '/more/security',
            'identifier'        => 'new_device_login',
            'category'          => 'security',
            'login_activity_id' => $this->activity->id,
            'device'            => $this->device(),
            'location'          => $this->activity->location(),
            'signed_in_at'      => $this->activity->login_at?->toIso8601String(),
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage($this->title(), $this->body()))->data(['identifier' => 'new_device_login', 'action_link' => '/more/security', 'reference' => (string) $this->activity->id]);
    }

    private function device(): string
    {
        $parts = array_filter([
            $this->activity->device   !== 'Unknown' ? $this->activity->device : null,
            $this->activity->platform !== 'Unknown' ? $this->activity->platform : null,
            $this->activity->browser  !== 'Unknown' ? $this->activity->browser : null,
        ]);

        return implode(' · ', $parts) ?: __('an unrecognised device');
    }

    private function title(): string
    {
        return __('New sign-in from :device', ['device' => $this->device()]);
    }

    private function body(): string
    {
        return __(':location · :time. If this was not you, secure your account now.', [
            'location' => $this->activity->location(),
            'time'     => $this->activity->login_at?->format('d M H:i') ?? '',
        ]);
    }
}
