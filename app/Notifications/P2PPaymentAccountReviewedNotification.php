<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\P2P\PaymentAccount;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use App\Services\Notifications\Fcm\FcmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An administrator's verdict on the ownership proof a customer attached to
 * a P2P payment account.
 */
class P2PPaymentAccountReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly PaymentAccount $account,
        private readonly bool $approved,
        private readonly ?string $reason = null,
    ) {}

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
            'icon'        => $this->approved ? 'check' : 'not-verified',
            'action_type' => 'p2p_account_review',
            // The web page for the web bell; the app screen for the app.
            'action_link' => route('user.p2p.payment-accounts.index'),
            'app_link'    => '/more/p2p-accounts',
            'identifier'  => $this->approved ? 'p2p_account_verified' : 'p2p_account_declined',
            'category'    => 'updates',
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage($this->title(), $this->body()))->data([
            'identifier'  => $this->approved ? 'p2p_account_verified' : 'p2p_account_declined',
            'action_link' => '/more/p2p-accounts',
        ]);
    }

    private function title(): string
    {
        return $this->approved
            ? __('Payment account verified')
            : __('Payment account proof declined');
    }

    private function body(): string
    {
        $name = $this->account->effective_label ?? $this->account->display_name ?? __('your payment account');

        if ($this->approved) {
            return __('Your proof for :account was accepted. Buyers now see it as verified.', ['account' => $name]);
        }

        return filled($this->reason)
            ? __('Your proof for :account was not accepted: :reason Upload a clearer one to try again.', ['account' => $name, 'reason' => rtrim((string) $this->reason, '.').'.'])
            : __('Your proof for :account was not accepted. Upload a clearer one to try again.', ['account' => $name]);
    }
}
