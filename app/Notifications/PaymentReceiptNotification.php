<?php

namespace App\Notifications;

use NotificationChannels\Twilio\TwilioChannel;

/**
 * A customer's receipt for a payment to a business, sent when the business
 * asks for it from the counter.
 *
 * Receipts go by email and SMS only: the customer's phone already had the
 * "payment completed" push when the money moved, and a second entry in the
 * app's inbox would say the same thing again. The template, its copy and
 * which of the two channels are on stay the admin's, as for every other
 * template; the customer's own email / SMS choices still apply.
 */
class PaymentReceiptNotification extends TemplateNotification
{
    public const IDENTIFIER = 'payment_receipt_customer';

    /**
     * @param array{amount: string, merchant: string, trx: string, date: string} $data
     */
    public function __construct(array $data)
    {
        parent::__construct(self::IDENTIFIER, $data);
    }

    public function via(object $notifiable): array
    {
        return array_values(array_filter(
            parent::via($notifiable),
            fn (string $channel): bool => $channel === 'mail'
                || ($channel === TwilioChannel::class && filled($notifiable->phone ?? null)),
        ));
    }
}
