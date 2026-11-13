<?php

namespace App\Notifications;

use App\Enums\NotificationChannelType;
use App\Http\Controllers\Frontend\PwaController;
use App\Jobs\Middleware\RetryTransientMailFailure;
use App\Models\NotificationSetting;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use App\Services\Notifications\Fcm\FcmClient;
use App\Support\NotificationCategory;
use App\Support\RealtimeConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TemplateNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable;

    /**
     * Read by `SendQueuedNotifications` and set on every channel job it
     * dispatches. The mail job needs all five so `RetryTransientMailFailure`
     * can walk its whole backoff; the other channels simply get two more
     * attempts than the worker's default.
     */
    public int $tries = RetryTransientMailFailure::MAX_ATTEMPTS;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        protected string $identifier,
        protected array $data = [],
        protected mixed $sender = null,
        protected mixed $action = null,
    ) {}

    /**
     * Laravel resolves the channel list inside the caller's request — and for a
     * queued notification that caller is frequently the `DB::transaction()` that
     * just credited a wallet. A missing template must therefore degrade to
     * "deliver nothing" instead of throwing, or an unseeded row rolls the money
     * back and surfaces to the user as a failed deposit.
     */
    public function via(object $notifiable): array
    {
        $template = NotificationTemplate::where('identifier', $this->identifier)
            ->with('channels')
            ->first();

        if (! $template) {
            Log::warning('Notification skipped: template identifier is not present in notification_templates.', [
                'identifier' => $this->identifier,
            ]);

            return [];
        }

        $realtimeReady = RealtimeConnection::canBroadcast();
        $twilioStatus  = pluginCredentials('twilio')['status'] ?? false;
        $pushEnabled   = ! method_exists($notifiable, 'notificationDeliveryEnabled')
            || $notifiable->notificationDeliveryEnabled();
        $webPushReady = $this->webPushIsAvailable($notifiable);
        $fcmReady     = $this->fcmIsAvailable($notifiable);

        // Read once for every channel. Kept in a local, never on the
        // notification: via() runs before it is queued, and a model on a
        // property would be serialised with it.
        $preferences = $this->preferencesFor($notifiable);

        return collect($template->channels)
            ->where('is_active', true)
            ->flatMap(function ($channel) use ($realtimeReady, $twilioStatus, $pushEnabled, $webPushReady, $fcmReady) {
                return match ($channel->channel) {
                    NotificationChannelType::EMAIL => ['mail'],
                    NotificationChannelType::SMS   => $twilioStatus ? [TwilioChannel::class] : [],
                    NotificationChannelType::PUSH  => $pushEnabled
                        ? array_merge(
                            ['database'],
                            $realtimeReady ? ['broadcast'] : [],
                            $webPushReady ? [WebPushChannel::class] : [],
                            $fcmReady ? [FcmChannel::class] : [],
                        )
                        : [],
                    default => [],
                };
            })
            ->unique()
            ->filter(fn (string $channel): bool => $this->customerAllows($notifiable, $channel, $preferences))
            ->values()
            ->toArray();
    }

    /**
     * The notification-preferences matrix, applied per channel: the database
     * row is never dropped (it is the inbox), security is never quietened,
     * quiet hours hold push back, and a money notification above the
     * customer's threshold is always pushed and mailed.
     */
    protected function customerAllows(object $notifiable, string $channel, ?NotificationSetting $settings = null): bool
    {
        // The inbox row, and the live update of the website's bell that
        // mirrors it, are not alerts: preferences and quiet hours are about
        // the phone buzzing, not about what the inbox holds.
        if ($channel === 'database' || $channel === 'broadcast' || ! $notifiable instanceof User || $settings === null) {
            return true;
        }
        $category = NotificationCategory::forIdentifier($this->identifier);
        $kind     = match ($channel) {
            'mail'               => 'email',
            TwilioChannel::class => 'sms',
            default              => 'push',
        };

        if ($category === NotificationCategory::SECURITY) {
            return true;
        }

        $amount = preg_replace('/[^0-9.]/', '', (string) ($this->data['amount'] ?? ''));
        $large  = in_array($category, [NotificationCategory::MONEY_IN, NotificationCategory::MONEY_OUT], true) && $settings->isLarge($amount === '' ? null : $amount);

        if ($large && $kind !== 'sms') {
            return true;
        }

        if ($kind === 'push' && $settings->inQuietHours(now())) {
            return false;
        }

        return $settings->allows($category, $kind);
    }

    /**
     * The customer's preferences, read once per notification rather than
     * once per channel. A store that cannot be read - an install whose
     * migration has not run yet - means the defaults, never a lost message.
     */
    private function preferencesFor(object $notifiable): ?NotificationSetting
    {
        if (! $notifiable instanceof User) {
            return null;
        }

        return rescue(
            fn (): NotificationSetting => NotificationSetting::forUser($notifiable),
            fn (): NotificationSetting => new NotificationSetting(['user_id' => $notifiable->id, 'channels' => NotificationSetting::defaultChannels()]),
        );
    }

    /**
     * A refusal from the SMTP host is worth waiting out; a push or database
     * write has no remote host to refuse it.
     *
     * @return array<int, object>
     */
    public function middleware(object $notifiable, string $channel): array
    {
        return $channel === 'mail' ? [new RetryTransientMailFailure] : [];
    }

    /**
     * Web push only reaches a closed phone when VAPID is configured and the
     * notifiable actually registered a subscription from a browser.
     */
    protected function webPushIsAvailable(object $notifiable): bool
    {
        if (blank(config('webpush.vapid.public_key')) || blank(config('webpush.vapid.private_key'))) {
            return false;
        }

        if (! method_exists($notifiable, 'webPushSubscriptionsSupported')
            || ! $notifiable->webPushSubscriptionsSupported()) {
            return false;
        }

        return $notifiable->pushSubscriptions()->exists();
    }

    /**
     * The service worker reads `action_link` from `data` to decide where a tap
     * should land, so the payload mirrors the in-app feed entry.
     */
    public function toWebPush(object $notifiable): WebPushMessage
    {
        $template = $this->getTemplate('push') ?? $this->getTemplate('email');
        $base     = $this->getBase();

        $message = (new WebPushMessage)
            ->title(str_replace_placeholders($template['title'] ?? $base?->name ?? $this->identifier, $this->data))
            ->body(str_replace_placeholders($template['message'] ?? $base?->info ?? '', $this->data))
            ->tag($this->identifier)
            ->data([
                'action_link' => (string) ($this->action ?? ''),
                'action_type' => $base?->action_type?->value ?? '',
                'identifier'  => $this->identifier,
            ]);

        $icon = app(PwaController::class)->iconUrl('icon_192');

        return blank($icon) ? $message : $message->icon($icon);
    }

    /**
     * The mail channel dereferences this return value without a null check, so a
     * template whose email channel was deactivated between queueing and delivery
     * would fatal the job. Fall back through the remaining copy instead.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $base     = $this->getBase();
        $template = $this->getTemplate('email') ?? $this->getTemplate('push');

        $subject = $template['title']   ?? $base?->name ?? $this->identifier;
        $message = $template['message'] ?? $base?->info ?? $base?->name ?? $this->identifier;

        return (new MailMessage)
            ->subject(str_replace_placeholders($subject, $this->data))
            ->line(str_replace_placeholders($message, $this->data));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $template = $this->getTemplate('push');
        if (! $template) {
            return [];
        }

        $base       = $this->getBase();
        $senderInfo = null;
        if ($this->sender) {
            $senderInfo = [
                'id'     => $this->sender->id,
                'name'   => $this->sender->name       ?? null,
                'avatar' => $this->sender->avatar_alt ?? null,
            ];
        }

        return [
            'title'       => str_replace_placeholders($template['title'] ?? '', $this->data),
            'message'     => str_replace_placeholders($template['message'] ?? '', $this->data),
            'icon'        => $base?->icon,
            'action_type' => $base?->action_type?->value ?? '',
            'action_link' => $this->action               ?? '',
            'sender'      => $senderInfo,
            'identifier'  => $this->identifier,
            'category'    => NotificationCategory::forIdentifier($this->identifier),
            // The row the notification is about, when the template was handed one.
            'reference' => isset($this->data['trx']) || isset($this->data['reference']) ? (string) ($this->data['trx'] ?? $this->data['reference']) : null,
        ];

    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->broadcastWith());
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $t    = $this->getTemplate('push');
        $base = $this->getBase();

        return [
            'title'       => str_replace_placeholders($t['title'] ?? '', $this->data),
            'message'     => str_replace_placeholders($t['message'] ?? '', $this->data),
            'icon'        => $base?->icon,
            'action_type' => $base?->action_type?->value ?? '',
            'action_link' => $this->action               ?? '',
            'timestamp'   => now()->toISOString(),
        ];
    }

    public function broadcastAs(): string
    {
        return RealtimeConnection::EVENT;
    }

    public function toTwilio(object $notifiable): TwilioSmsMessage
    {
        $sms = $this->getTemplate('sms');

        return (new TwilioSmsMessage)
            ->content(str_replace_placeholders($sms['message'] ?? $this->getBase()?->name ?? $this->identifier, $this->data));
    }

    /**
     * Null once the template is deleted between queueing and delivery; every
     * caller below falls back rather than failing the queued job.
     */
    protected function getBase(): ?NotificationTemplate
    {
        return NotificationTemplate::where('identifier', $this->identifier)->first();
    }

    protected function getTemplate(string $channel): ?array
    {
        $base = $this->getBase();

        if (! $base) {
            return null;
        }

        $template = $base->channels()->where('channel', $channel)->where('is_active', true)->first();

        return $template ? $template->toArray() : null;
    }

    /**
     * FCM only reaches a phone when the installation is configured for it and
     * the customer has actually registered a handset.
     *
     * Mirrors webPushIsAvailable() exactly, so a site with a browser audience
     * and no mobile app keeps behaving as it does today.
     */
    protected function fcmIsAvailable(object $notifiable): bool
    {
        if (! app(FcmClient::class)->isConfigured()) {
            return false;
        }

        if (! method_exists($notifiable, 'routeNotificationForFcm')) {
            return false;
        }

        return $notifiable->routeNotificationForFcm() !== [];
    }

    /**
     * What the phone shows before anyone unlocks it.
     *
     * DELIBERATELY NOT THE TEMPLATE'S MESSAGE BODY. The other push channels
     * render `$template['message']` with the placeholders substituted, and
     * that copy is full of amounts - "You have received :amount from :sender".
     * On a lock screen that is an announcement of how much money the person
     * holding the phone just received, to whoever is standing behind them.
     *
     * So the title is the template's NAME, which is static admin copy with no
     * placeholders in it, and the body is one fixed line. The reference and
     * the deep link travel in `data`, which only the app sees, and the app
     * fetches the detail over an authenticated request once it is open.
     *
     * FcmMessage refuses monetary keys in `data` as well, so this holds even
     * if a future caller passes the whole notification payload in.
     */
    public function toFcm(object $notifiable): FcmMessage
    {
        $base = $this->getBase();

        $title = $base?->name ?? __('Notification');

        return (new FcmMessage($title, __('Open the app to see the details.')))
            ->data([
                'identifier'  => $this->identifier,
                'action_link' => (string) ($this->action ?? ''),
                'action_type' => $base?->action_type?->value ?? '',
                // A transaction reference is not a monetary value: it is what
                // the app asks the server about.
                'reference' => (string) ($this->data['trx'] ?? ''),
            ]);
    }
}
