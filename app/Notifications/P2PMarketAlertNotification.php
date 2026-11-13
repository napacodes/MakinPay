<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\P2P\MarketAlert;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use App\Services\Notifications\Fcm\FcmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The ad the customer was waiting for has appeared.
 */
class P2PMarketAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly MarketAlert $alert, private readonly int $offers, private readonly string $bestPrice) {}

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
            'icon'        => 'users',
            'action_type' => 'p2p_market',
            // The website's page for it, and the app's screen.
            'action_link' => route('user.p2p.offers.index'),
            'app_link'    => $this->link(),
            'identifier'  => 'p2p_market_alert',
            'category'    => 'updates',
            'alert_id'    => $this->alert->id,
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage($this->title(), $this->body()))->data(['identifier' => 'p2p_market_alert', 'action_link' => $this->link()]);
    }

    private function link(): string
    {
        return '/more/p2p?side='.$this->alert->side.'&asset='.$this->alert->asset.'&fiat='.$this->alert->fiat;
    }

    private function title(): string
    {
        return $this->alert->side === 'SELL'
            ? __(':asset ads in :fiat are back', ['asset' => $this->alert->asset, 'fiat' => $this->alert->fiat])
            : __('Someone is buying :asset for :fiat', ['asset' => $this->alert->asset, 'fiat' => $this->alert->fiat]);
    }

    private function body(): string
    {
        return __(':count offer(s) · best :price :fiat per :asset', ['count' => $this->offers, 'price' => $this->bestPrice, 'fiat' => $this->alert->fiat, 'asset' => $this->alert->asset]);
    }
}
