<?php

namespace App\Enums\VirtualCard;

enum VirtualCardStatus: string
{
    case Active   = 'active';
    case Pending  = 'pending';
    case Inactive = 'inactive';
    case Blocked  = 'blocked';
    case Expired  = 'expired';
    // `Failed` is for async-issuing providers (Bitnob in particular)
    // that return a card id but mark it failed milliseconds later.
    // We persist these rows with status=failed so the dashboard has a
    // record the user can investigate / retry instead of losing the
    // attempt to a SQL crash.
    case Failed = 'failed';

    // Optional: human-friendly label method
    public function label(): string
    {
        return match ($this) {
            self::Active   => __('Active'),
            self::Pending  => __('Pending'),
            self::Inactive => __('Inactive'),
            self::Blocked  => __('Blocked'),
            self::Expired  => __('Expired'),
            self::Failed   => __('Failed'),
        };
    }

    /**
     * Whether money may be loaded onto a card in this state.
     *
     * Only an active card can be funded. A pending card has no provider-side
     * balance to load, a frozen or blocked one refuses authorisations, an
     * expired one cannot spend what it receives, and a failed issuance never
     * became a card at all — funding any of them moves real money at the
     * provider for nothing.
     */
    public function isFundable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Translate a provider's own status wording into a local status.
     *
     * Every gateway describes a card in its own words — StroWallet says
     * `processing` and `frozen`, Bitnob says `active` and `terminated` — and
     * a sync that wrote those words straight into the enum-cast column lost
     * the row to a cast error. Null means the wording is unknown and the
     * caller should leave the local status alone rather than guess.
     *
     * `frozen` maps to Inactive on purpose: that is the status our own freeze
     * action writes, so a sync after a customer freezes their card must not
     * turn it into a provider-imposed Blocked.
     */
    public static function fromProvider(?string $status): ?self
    {
        return match (strtolower(trim((string) $status))) {
            'active', 'approved', 'success', 'successful', 'live', 'enabled' => self::Active,
            'pending', 'processing', 'in_progress', 'creating', 'created', 'initiated', 'queued' => self::Pending,
            'inactive', 'disabled', 'frozen', 'freeze', 'paused' => self::Inactive,
            'blocked', 'suspended', 'terminated' => self::Blocked,
            'expired' => self::Expired,
            'failed', 'failure', 'declined', 'rejected', 'deleted', 'cancelled', 'canceled' => self::Failed,
            default => null,
        };
    }

    public static function options(): array
    {
        return array_combine(
            array_map(fn ($case) => $case->value, self::cases()),
            array_map(fn ($case) => __(str_replace('_', ' ', ucfirst($case->value))), self::cases())
        );
    }

    // Optional: badge color for blade/view
    public function badgeColor(): string
    {
        return match ($this) {
            self::Active   => 'success',
            self::Pending  => 'info',
            self::Inactive => 'warning',
            self::Blocked  => 'danger',
            self::Expired  => 'secondary',
            self::Failed   => 'danger',
        };
    }
}
