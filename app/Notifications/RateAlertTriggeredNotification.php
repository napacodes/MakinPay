<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\RateAlert;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use App\Services\Notifications\Fcm\FcmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The rate the customer was waiting for arrived. In the inbox, and on the
 * phone when it has registered for push.
 */
class RateAlertTriggeredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param string|null $exchangeFailure why an auto-exchange the customer asked for did not run
     */
    public function __construct(private readonly RateAlert $alert, private readonly ?string $exchangedReference = null, private readonly ?string $exchangeFailure = null) {}

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
            'icon'        => 'trending-up',
            'action_type' => $this->exchangedReference === null ? 'exchange' : 'transaction',
            // The website's page for it, and the app's screen.
            'action_link' => $this->exchangedReference === null ? route('user.exchange-money.create') : route('user.transaction.index', ['search' => $this->exchangedReference]),
            'app_link'    => $this->link(),
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage($this->title(), $this->body()))->data([
            'identifier'  => 'rate_alert',
            'action_link' => $this->link(),
            'reference'   => (string) ($this->exchangedReference ?? ''),
        ]);
    }

    private function link(): string
    {
        return $this->exchangedReference === null ? '/home/exchange' : '/activity/'.$this->exchangedReference;
    }

    private function title(): string
    {
        return __(':from → :to reached your target', ['from' => $this->alert->from_currency, 'to' => $this->alert->to_currency]);
    }

    private function body(): string
    {
        $rate = self::trim((string) ($this->alert->triggered_rate ?? $this->alert->target_rate));

        // Asked for an exchange that did not happen: say so, or the customer
        // reads "target reached" and assumes their money moved.
        if ($this->exchangedReference === null && $this->alert->auto_exchange) {
            return __('1 :from = :rate :to, but the exchange of :amount :from did not go through: :reason', [
                'from'   => $this->alert->from_currency,
                'rate'   => $rate,
                'to'     => $this->alert->to_currency,
                'amount' => self::trim((string) $this->alert->amount),
                'reason' => $this->exchangeFailure ?? __('the wallets for it are no longer available.'),
            ]);
        }

        return $this->exchangedReference === null
            ? __('1 :from = :rate :to right now.', ['from' => $this->alert->from_currency, 'rate' => $rate, 'to' => $this->alert->to_currency])
            : __('1 :from = :rate :to, so we exchanged :amount :from as you asked.', ['from' => $this->alert->from_currency, 'rate' => $rate, 'to' => $this->alert->to_currency, 'amount' => self::trim((string) $this->alert->amount)]);
    }

    private static function trim(string $figure): string
    {
        return str_contains($figure, '.') ? rtrim(rtrim($figure, '0'), '.') : $figure;
    }
}
