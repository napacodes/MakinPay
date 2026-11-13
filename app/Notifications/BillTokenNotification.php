<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\BillPayment\BillPaymentStatus;
use App\Models\BillPayment;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use App\Services\Notifications\Fcm\FcmClient;
use App\Support\Money;
use App\Support\NotificationCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;

/**
 * The prepaid token of a paid bill - a meter code, a voucher PIN - sent to
 * the customer who paid for it, when the admin has switched that on.
 *
 * A token is money, so it is handled unlike every other notice:
 *
 *  - it carries only the payment's id. The token is read, and decrypted,
 *    at the moment each channel is written, so neither the queue nor the
 *    notification table ever holds it;
 *  - it goes by email, SMS and push and never to the inbox: a database row
 *    would keep the token in plain text, and the receipt already shows it;
 *  - email, SMS and push follow the customer's own notification
 *    preferences for money going out, and SMS goes only where the site's
 *    Twilio plugin is on;
 *  - it is sent only to the payment's owner, only once the payment is
 *    completed, and never logged.
 */
class BillTokenNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const IDENTIFIER = 'bill_payment_user_token';

    public function __construct(public readonly int $billPaymentId) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $payment = $this->payment($notifiable);

        if ($payment === null || ! $notifiable instanceof User) {
            return [];
        }

        $settings = NotificationSetting::forUser($notifiable);
        $category = NotificationCategory::MONEY_OUT;
        $channels = [];

        if ($settings->allows($category, 'email') && trim((string) $notifiable->email) !== '') {
            $channels[] = 'mail';
        }

        if ((bool) (pluginCredentials('twilio')['status'] ?? false) && $settings->allows($category, 'sms') && trim((string) $notifiable->phone) !== '') {
            $channels[] = TwilioChannel::class;
        }

        if ($settings->allows($category, 'push') && ! $settings->inQuietHours(now()) && app(FcmClient::class)->isConfigured() && method_exists($notifiable, 'routeNotificationForFcm') && $notifiable->routeNotificationForFcm() !== []) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payment = $this->payment($notifiable);

        return (new MailMessage)
            ->subject($this->title($payment))
            ->line($this->line($payment))
            ->line(__(':label: :token', ['label' => $this->label($payment), 'token' => $this->token($payment)]))
            ->line(__('Enter it on your meter or device to use what you paid for.'));
    }

    public function toTwilio(object $notifiable): TwilioSmsMessage
    {
        $payment = $this->payment($notifiable);

        return (new TwilioSmsMessage)->content(__(':biller - :label: :token', [
            'biller' => (string) $payment?->biller_name,
            'label'  => $this->label($payment),
            'token'  => $this->token($payment),
        ]));
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        $payment = $this->payment($notifiable);

        return (new FcmMessage($this->title($payment), __(':label: :token', ['label' => $this->label($payment), 'token' => $this->token($payment)])))->data([
            'identifier'  => self::IDENTIFIER,
            'action_link' => '/home/bills/'.(string) $payment?->uuid,
            'reference'   => (string) $payment?->uuid,
        ]);
    }

    /**
     * The payment, as the customer it belongs to, only once it is paid and
     * holds a token. Anything else is nothing to send.
     */
    private function payment(object $notifiable): ?BillPayment
    {
        if (! $notifiable instanceof User) {
            return null;
        }

        $payment = BillPayment::query()->whereKey($this->billPaymentId)->where('user_id', $notifiable->id)->with('biller')->first();

        if ($payment === null || $payment->status !== BillPaymentStatus::Completed || $this->token($payment) === '') {
            return null;
        }

        return $payment;
    }

    private function token(?BillPayment $payment): string
    {
        return $payment === null ? '' : trim((string) rescue(fn (): ?string => $payment->token, null, report: false));
    }

    private function label(?BillPayment $payment): string
    {
        $label = trim((string) $payment?->biller?->token_label);

        return $label !== '' ? $label : __('Token');
    }

    private function title(?BillPayment $payment): string
    {
        return __('Your :label for :biller', ['label' => $this->label($payment), 'biller' => (string) $payment?->biller_name]);
    }

    private function line(?BillPayment $payment): string
    {
        $currency = (string) $payment?->currency;

        return __('Your bill of :amount to :biller for :number is paid.', [
            'amount' => trim(Money::format((string) $payment?->total_amount, $currency !== '' ? $currency : null, false).' '.$currency),
            'biller' => (string) $payment?->biller_name,
            'number' => (string) $payment?->customer_number,
        ]);
    }
}
