<?php

declare(strict_types=1);

namespace App\Services\VirtualCard;

use App\Enums\VirtualCard\VirtualCardStatus;
use App\Exceptions\NotifyErrorException;
use App\Models\VirtualCard;
use Illuminate\Support\Carbon;

/**
 * What a cardholder can change about their own card - freezing it, and the
 * spend limits and usage controls they set - for the website and the app
 * alike.
 *
 * Freezing and unfreezing go to the provider first and are written locally
 * only once it answers, so the card is never marked frozen here while it can
 * still spend there; a provider with no freeze API answers "soft" and the
 * status flip is local only. The limits and controls are the cardholder's own
 * settings, kept on the card: advisory - no provider enforces them yet.
 */
class VirtualCardControls
{
    /** meta.freeze.by for a freeze set from the admin panel. */
    public const FROZEN_BY_ADMIN = 'admin';

    public function __construct(private readonly VirtualCardProviderFactory $providers) {}

    /**
     * @return array{soft: bool}
     *
     * @throws NotifyErrorException
     */
    public function freeze(VirtualCard $card, ?string $reason = null, ?string $duration = null): array
    {
        $result = $this->providers->getProvider($card->provider->code)->freezeCard($card);

        $meta           = $card->meta ?? [];
        $meta['freeze'] = array_filter([
            'reason'    => $reason,
            'duration'  => $duration ?? 'indefinite',
            'frozen_at' => now()->toIso8601String(),
            'soft'      => (bool) ($result['soft'] ?? false),
        ], fn ($v) => $v !== null && $v !== '');

        $card->update([
            'status'       => VirtualCardStatus::Inactive->value,
            'meta'         => $meta,
            'frozen_until' => self::frozenUntil($duration),
        ]);

        return ['soft' => (bool) ($result['soft'] ?? false)];
    }

    /**
     * Whether the card's current freeze was set from the admin panel.
     */
    public static function frozenByAdmin(VirtualCard $card): bool
    {
        return $card->status                      === VirtualCardStatus::Inactive
            && data_get($card->meta, 'freeze.by') === self::FROZEN_BY_ADMIN;
    }

    /**
     * When a freeze of this duration lifts on its own; null for one that
     * waits for the customer.
     */
    public static function frozenUntil(?string $duration): ?Carbon
    {
        return match ($duration) {
            '1h'    => now()->addHour(),
            '24h'   => now()->addDay(),
            '7d'    => now()->addDays(7),
            default => null,
        };
    }

    /**
     * @return array{soft: bool}
     *
     * @throws NotifyErrorException
     */
    public function unfreeze(VirtualCard $card): array
    {
        // A card the administrator froze - suspected fraud, a dispute - is
        // theirs to release. Neither the cardholder nor a freeze timer the
        // cardholder set earlier may lift it.
        if (self::frozenByAdmin($card)) {
            throw new NotifyErrorException(__('This card was frozen by our support team. Please contact support to unfreeze it.'));
        }

        $result = $this->providers->getProvider($card->provider->code)->unfreezeCard($card);

        $meta = $card->meta ?? [];
        unset($meta['freeze']);

        $card->update(['status' => VirtualCardStatus::Active->value, 'frozen_until' => null, 'meta' => $meta]);

        return ['soft' => (bool) ($result['soft'] ?? false)];
    }

    /**
     * @param array{per_transaction?: mixed, daily?: mixed, monthly?: mixed, alert_at?: mixed} $limits
     */
    public function saveLimits(VirtualCard $card, array $limits, bool $autoFreeze): void
    {
        $meta           = $card->meta ?? [];
        $meta['limits'] = array_filter([
            'per_transaction' => $limits['per_transaction'] ?? null,
            'daily'           => $limits['daily']           ?? null,
            'monthly'         => $limits['monthly']         ?? null,
            'alert_at'        => $limits['alert_at']        ?? null,
            'auto_freeze'     => $autoFreeze,
        ], fn ($v) => $v !== null);

        $card->update(['meta' => $meta]);
    }

    public function saveControls(VirtualCard $card, bool $online, bool $atm, bool $intl, bool $contactless): void
    {
        $meta             = $card->meta ?? [];
        $meta['controls'] = [
            'online'      => $online,
            'atm'         => $atm,
            'intl'        => $intl,
            'contactless' => $contactless,
        ];

        $card->update(['meta' => $meta]);
    }
}
