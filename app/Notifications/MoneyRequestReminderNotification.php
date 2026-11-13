<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Transaction;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use App\Services\Notifications\Fcm\FcmClient;
use App\Support\ApiMoney;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A nudge about a request still waiting on the payer - sent by the person
 * who asked, or by the scheduler the day before it is due.
 */
class MoneyRequestReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Transaction $payerLeg, private readonly string $requesterName, private readonly bool $dueTomorrow = false) {}

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
            'icon'        => 'hand-coins',
            'action_type' => 'request_money',
            // The website's page for it, and the app's screen.
            'action_link' => route('user.transaction.index', ['search' => $this->payerLeg->trx_id]),
            'app_link'    => '/home/request',
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage($this->title(), $this->body()))->data([
            'identifier'  => 'request_money_reminder',
            'action_link' => '/home/request',
            'reference'   => (string) $this->payerLeg->trx_id,
        ]);
    }

    private function title(): string
    {
        return $this->dueTomorrow
            ? __(':name request is due tomorrow', ['name' => $this->requesterName])
            : __(':name is waiting on your payment', ['name' => $this->requesterName]);
    }

    private function body(): string
    {
        $amount = ApiMoney::amount($this->payerLeg->payable_amount, $this->payerLeg->payable_currency).' '.$this->payerLeg->payable_currency;
        $note   = trim((string) $this->payerLeg->remarks);

        return $note === '' ? $amount : $amount.' · '.$note;
    }
}
