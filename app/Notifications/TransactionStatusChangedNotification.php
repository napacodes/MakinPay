<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Transaction;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use App\Services\Notifications\Fcm\FcmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * "Notify me when it lands": the customer asked to hear when a pending
 * transaction changes state. Lands in the in-app inbox (and so on the
 * phone through the same push the inbox already has).
 */
class TransactionStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Transaction $transaction) {}

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

    public function toFcm(object $notifiable): FcmMessage
    {
        $trx    = $this->transaction;
        $status = $trx->status?->value ?? '';

        return (new FcmMessage(__('Transaction :status', ['status' => $status]), __(':reference is now :status.', ['reference' => $trx->trx_id, 'status' => $status])))->data([
            'identifier'  => 'transaction_status',
            'action_link' => '/activity/'.$trx->trx_id,
            'reference'   => $trx->trx_id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $trx    = $this->transaction;
        $status = $trx->status?->value ?? '';

        return [
            'title'       => __('Transaction :status', ['status' => $status]),
            'message'     => __(':reference is now :status.', ['reference' => $trx->trx_id, 'status' => $status]),
            'icon'        => 'receipt',
            'action_type' => 'transaction',
            // The website's page for it, and the app's screen.
            'action_link' => route('user.transaction.index', ['search' => $trx->trx_id]),
            'app_link'    => '/activity/'.$trx->trx_id,
        ];
    }
}
