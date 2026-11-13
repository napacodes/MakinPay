<?php

declare(strict_types=1);

namespace App\Enums\MassPayment;

/**
 * When a confirmed mass payout batch waits for an admin before anything is
 * sent.
 *
 *   Always          every payout batch waits, Manual-method batches included.
 *   AboveThreshold  only a batch whose total is strictly above the threshold.
 *   Never           a confirmed batch is queued straight away.
 *
 * The mode is an admin choice, read from the site settings (layer C) and never
 * hardcoded. Waiting batches hold their money reserved, and approving or
 * rejecting one needs the `mass-payment-approve` permission.
 */
enum MassPayoutApproval: string
{
    case Always         = 'always';
    case AboveThreshold = 'above_threshold';
    case Never          = 'never';

    public const SETTING = 'mass_payout_approval';

    public const THRESHOLD_SETTING = 'mass_payout_approval_threshold';

    /**
     * A missing or unknown value is the strictest mode, so a half-upgraded or
     * mistyped installation holds every payout for a person to look at rather
     * than sending one unreviewed.
     */
    public static function fromSetting(): self
    {
        return self::tryFrom((string) setting(self::SETTING, self::Always->value)) ?? self::Always;
    }

    /**
     * The threshold in the site default currency, as a decimal string. A value
     * that is not a plain non-negative decimal reads as '0', which under
     * AboveThreshold means every batch with any amount waits.
     */
    public static function threshold(): string
    {
        $configured = trim((string) setting(self::THRESHOLD_SETTING, '0'));

        return preg_match('/^\d+(\.\d+)?$/', $configured) === 1 ? $configured : '0';
    }

    /**
     * Label => value, the shape the settings select renders from.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'Always: every payout batch waits for approval'      => self::Always->value,
            'Above threshold: only batches above the amount set' => self::AboveThreshold->value,
            'Never: payouts start as soon as they are confirmed' => self::Never->value,
        ];
    }
}
