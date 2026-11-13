<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ScheduledPayment;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use App\Services\Notifications\Fcm\FcmClient;
use App\Support\ApiMoney;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A scheduled payment speaking up: it could not be made and why, the
 * day-before reminder, or that it went through.
 *
 * @param array<string, mixed> $extra reason, shortfall, retry_at, balance
 */
class ScheduledPaymentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly ScheduledPayment $schedule, private readonly string $identifier, private readonly array $extra = []) {}

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
            'icon'        => $this->identifier === 'scheduled_payment_failed' ? 'calendar-x' : 'calendar-clock',
            'action_type' => 'scheduled_payment',
            // The website's page for it (none - this lives in the app only), and the app's screen.
            'action_link' => null,
            'app_link'    => '/home/scheduled/'.$this->schedule->id,
            'identifier'  => $this->identifier,
            'category'    => 'money_out',
            'schedule_id' => $this->schedule->id,
            'reference'   => (string) $this->schedule->id,
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage($this->title(), $this->body()))->data(['identifier' => $this->identifier, 'action_link' => '/home/scheduled/'.$this->schedule->id, 'reference' => (string) $this->schedule->id]);
    }

    private function name(): string
    {
        if ($this->schedule->type === 'mass') {
            return (string) (($this->schedule->payload ?? [])['name'] ?? '') ?: ($this->schedule->note ?: __('Mass payment'));
        }

        return $this->schedule->note ?: ($this->schedule->recipient_name ?: __('Scheduled payment'));
    }

    private function amount(): string
    {
        return ApiMoney::amount((string) $this->schedule->amount, $this->schedule->currency).' '.$this->schedule->currency;
    }

    private function title(): string
    {
        return match ($this->identifier) {
            'scheduled_payment_failed'   => __(":name didn't go through", ['name' => $this->name()]),
            'scheduled_payment_reminder' => __(':name is due tomorrow', ['name' => $this->name()]),
            default                      => __(':name was paid', ['name' => $this->name()]),
        };
    }

    private function body(): string
    {
        return match ($this->identifier) {
            'scheduled_payment_failed'   => trim(__(':reason', ['reason' => $this->extra['reason'] ?? __('Insufficient balance.')]).' '.($this->extra['retry_at'] ?? null ? __('Retrying :when, or pay now.', ['when' => $this->extra['retry_at']]) : __('Pay now or top up.'))),
            'scheduled_payment_reminder' => __(':amount from your :currency wallet · balance :balance', ['amount' => $this->amount(), 'currency' => $this->schedule->currency, 'balance' => $this->extra['balance'] ?? '']),
            default                      => __(':amount sent', ['amount' => $this->amount()]),
        };
    }
}
