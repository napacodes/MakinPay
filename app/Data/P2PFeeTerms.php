<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\P2P\P2PAssetFee;
use App\Support\FeeCalculator;

/**
 * The maker or taker side of a P2P fee, resolved for one asset.
 *
 * Carrying the four components together keeps the call sites from re-reading
 * four settings each and getting one of them wrong — which is how the deposit
 * and withdraw fees drifted before they were consolidated.
 *
 * `$overridden` is presentation only: it tells the admin screen whether these
 * figures came from a per-asset row or the global default.
 */
readonly class P2PFeeTerms
{
    public function __construct(
        public string $fixed,
        public string $percent,
        public ?string $min,
        public ?string $max,
        public bool $overridden = false,
    ) {}

    public static function fromModel(P2PAssetFee $fee, string $side, bool $overridden = true): self
    {
        return new self(
            fixed: (string) $fee->{$side.'_fee_fixed'},
            percent: (string) $fee->{$side.'_fee_pct'},
            min: self::nullable($fee->{$side.'_fee_min'}),
            max: self::nullable($fee->{$side.'_fee_max'}),
            overridden: $overridden,
        );
    }

    /**
     * The global pair, read through `setting()` so the legacy key bridge on
     * P2PSetting keeps working for installs still writing those keys.
     */
    public static function fromSettings(string $side): self
    {
        return new self(
            fixed: (string) setting("p2p_{$side}_fee_fixed", 0),
            percent: (string) setting("p2p_{$side}_fee_pct", 0),
            min: self::nullable(setting("p2p_{$side}_fee_min")),
            max: self::nullable(setting("p2p_{$side}_fee_max")),
        );
    }

    /**
     * The fee on an amount, in the asset the amount is denominated in.
     */
    public function on(string|int|float $amount): float
    {
        return app(FeeCalculator::class)->total($amount, $this->fixed, $this->percent, $this->min, $this->max);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fixed'      => $this->fixed,
            'percent'    => $this->percent,
            'min'        => $this->min,
            'max'        => $this->max,
            'overridden' => $this->overridden,
        ];
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
