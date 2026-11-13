<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

/**
 * The review SLA targets, read from the settings layer.
 *
 * Every getter passes its own default, because an installation that upgraded
 * into the redesigned dashboard has the config entries but not necessarily the
 * settings rows yet — DashboardSettingSeeder creates them, and until it runs
 * the dashboard must still classify queues rather than divide by zero.
 */
class DashboardSla
{
    public const STATE_OVER = 'over';

    public const STATE_NEAR = 'near';

    public const STATE_IN = 'in';

    /**
     * @var array<string, array{setting: string, default: int}>
     */
    public const TARGETS = [
        'deposit'      => ['setting' => 'sla_deposit_hours', 'default' => 4],
        'withdraw'     => ['setting' => 'sla_withdraw_hours', 'default' => 2],
        'kyc'          => ['setting' => 'sla_kyc_hours', 'default' => 24],
        'merchant'     => ['setting' => 'sla_merchant_hours', 'default' => 48],
        'cardholder'   => ['setting' => 'sla_cardholder_hours', 'default' => 8],
        'vc_request'   => ['setting' => 'sla_card_request_hours', 'default' => 8],
        'ticket'       => ['setting' => 'sla_ticket_hours', 'default' => 12],
        'dispute'      => ['setting' => 'sla_dispute_hours', 'default' => 12],
        'bill_payment' => ['setting' => 'sla_bill_payment_hours', 'default' => 4],
        'mass_payment' => ['setting' => 'sla_mass_payment_hours', 'default' => 2],
    ];

    public const WARNING_SETTING = 'sla_warning_threshold_pct';

    public const WARNING_DEFAULT = 75;

    /**
     * Notifying administrators is new behaviour, so it starts off and an
     * update changes nothing until somebody asks for it.
     */
    public const ALERTS_SETTING = 'sla_alerts_enabled';

    /**
     * Target hours for one queue key. An unknown key gets the longest default
     * rather than zero, so a queue added later never renders as instantly late.
     */
    public function hoursFor(string $queue): int
    {
        $target = self::TARGETS[$queue] ?? null;

        if ($target === null) {
            return 48;
        }

        return $this->clampHours(setting($target['setting'], $target['default']), $target['default']);
    }

    /**
     * @return array<string, int>
     */
    public function all(): array
    {
        $targets = [];

        foreach (array_keys(self::TARGETS) as $queue) {
            $targets[$queue] = $this->hoursFor($queue);
        }

        return $targets;
    }

    public function alertsEnabled(): bool
    {
        return (bool) setting(self::ALERTS_SETTING, false);
    }

    public function warningThreshold(): int
    {
        $value = (int) setting(self::WARNING_SETTING, self::WARNING_DEFAULT);

        if ($value < 10 || $value > 99) {
            return self::WARNING_DEFAULT;
        }

        return $value;
    }

    /**
     * Classify one waiting item. `over` at or past target, `near` once it has
     * used the warning share of that target, `in` otherwise.
     */
    public function state(float $elapsedHours, int $targetHours): string
    {
        if ($targetHours <= 0 || $elapsedHours >= $targetHours) {
            return self::STATE_OVER;
        }

        if ($elapsedHours >= $targetHours * ($this->warningThreshold() / 100)) {
            return self::STATE_NEAR;
        }

        return self::STATE_IN;
    }

    /**
     * How much of the target the oldest item has used, capped at 100 so a
     * badly overdue item does not draw a bar wider than its track.
     */
    public function percentUsed(float $elapsedHours, int $targetHours): int
    {
        if ($targetHours <= 0) {
            return 100;
        }

        return (int) min(100, max(0, round($elapsedHours / $targetHours * 100)));
    }

    /**
     * Whether an administrator has actually saved targets, as opposed to the
     * dashboard running on this class's defaults. Drives the setup checklist.
     */
    public function isConfigured(): bool
    {
        foreach (self::TARGETS as $target) {
            if (setting($target['setting']) === null) {
                return false;
            }
        }

        return setting(self::WARNING_SETTING) !== null;
    }

    private function clampHours(mixed $value, int $default): int
    {
        $hours = (int) $value;

        if ($hours < 1 || $hours > 720) {
            return $default;
        }

        return $hours;
    }
}
