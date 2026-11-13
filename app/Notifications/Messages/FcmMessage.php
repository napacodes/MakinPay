<?php

declare(strict_types=1);

namespace App\Notifications\Messages;

/**
 * What a phone is told before anyone unlocks it.
 *
 * NOTHING MONETARY GOES IN HERE, and the class is shaped so that it cannot.
 * A push notification renders on a locked screen, over the shoulder of
 * whoever is standing behind the customer on a bus. "You received 45,000 BDT"
 * is an advertisement for a mugging; "Money received - open the app" is not.
 *
 * So the title and body are plain strings the caller must choose, and
 * `data()` refuses any key that names money. The app is given a reference and
 * fetches the detail over an authenticated request, where it belongs.
 *
 * The rule is enforced here rather than trusted to each caller because the
 * templated copy on the other channels is full of amount placeholders, and
 * the obvious thing to do - reuse that copy - is exactly the mistake.
 */
final class FcmMessage
{
    /**
     * Keys whose values never leave the server on this channel.
     *
     * Matched as substrings, so `payable_amount`, `wallet_balance` and
     * `total_fee` are all caught without listing every variant a template
     * might invent.
     */
    private const MONETARY_KEYS = [
        'amount', 'balance', 'fee', 'total', 'charge', 'commission',
        'payable', 'net', 'price', 'value', 'rate', 'currency',
    ];

    /** @var array<string, string> */
    private array $data = [];

    public function __construct(
        private readonly string $title,
        private readonly string $body,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function data(array $data): self
    {
        foreach ($data as $key => $value) {
            if ($this->namesMoney((string) $key)) {
                continue;
            }

            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }

            // FCM data values must be strings; anything else is silently
            // dropped by the transport, which is worse than converting here.
            $this->data[(string) $key] = (string) $value;
        }

        return $this;
    }

    /**
     * The payload FCM's HTTP v1 API expects.
     *
     * `notification` is what the operating system draws; `data` is what the
     * app reads when it is opened. Android's own high-priority hint is set so
     * a money notification is not held back by Doze.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'notification' => [
                'title' => $this->title,
                'body'  => $this->body,
            ],
            'data'    => $this->data,
            'android' => [
                'priority'     => 'high',
                'notification' => [
                    // Collapse repeats of the same kind rather than stacking
                    // five identical lines on a lock screen.
                    'tag' => $this->data['identifier'] ?? 'digikash',
                ],
            ],
        ];
    }

    private function namesMoney(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::MONETARY_KEYS as $monetary) {
            if (str_contains($key, $monetary)) {
                return true;
            }
        }

        return false;
    }
}
