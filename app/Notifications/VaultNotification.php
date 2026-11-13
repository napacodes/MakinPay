<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Vault;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use App\Services\Notifications\Fcm\FcmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * What a vault has to say for itself: a rule that could not top it up,
 * or a goal reached.
 *
 * @param array<string, string|null> $extra amount, reason
 */
class VaultNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Vault $vault, private readonly string $identifier, private readonly array $extra = []) {}

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
            'icon'        => 'piggy-bank',
            'action_type' => 'vault',
            // The website's page for it (none - this lives in the app only), and the app's screen.
            'action_link' => null,
            'app_link'    => '/home/vaults/'.$this->vault->id,
            'identifier'  => $this->identifier,
            'category'    => $this->identifier === 'vault_goal_reached' ? 'rewards' : 'money_out',
            'vault_id'    => $this->vault->id,
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage($this->title(), $this->body()))->data(['identifier' => $this->identifier, 'action_link' => '/home/vaults/'.$this->vault->id, 'reference' => (string) $this->vault->id]);
    }

    private function title(): string
    {
        return $this->identifier === 'vault_goal_reached'
            ? __('You reached your :vault goal', ['vault' => $this->vault->name])
            : __(':vault auto-save skipped', ['vault' => $this->vault->name]);
    }

    private function body(): string
    {
        $currency = (string) ($this->vault->wallet?->currency?->code ?? '');
        if ($this->identifier === 'vault_goal_reached') {
            return __(':amount :currency saved. Nice work.', ['amount' => $this->extra['amount'] ?? '', 'currency' => $currency]);
        }

        return __('Could not move :amount :currency: :reason', ['amount' => $this->extra['amount'] ?? '', 'currency' => $currency, 'reason' => $this->extra['reason'] ?? __('insufficient balance')]);
    }
}
