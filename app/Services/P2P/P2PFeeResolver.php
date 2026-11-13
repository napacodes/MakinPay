<?php

declare(strict_types=1);

namespace App\Services\P2P;

use App\Data\P2PFeeTerms;
use App\Models\P2P\P2PAssetFee;
use Illuminate\Support\Facades\Schema;

/**
 * Which maker/taker fee applies to a trade.
 *
 * P2P fees are charged in the asset being traded, so the global pair on
 * `p2p_settings` cannot be right for every offer at once: a flat 0.0005 is
 * about thirty dollars of BTC and a twentieth of a cent of USDT. An operator
 * configures an override per asset and this resolves it, falling back to the
 * global pair for anything they have not overridden.
 *
 * One place decides, so the order service, the offer preview and the admin
 * screen cannot disagree about what a trade will cost.
 */
class P2PFeeResolver
{
    /** @var array<int|string, array{maker: P2PFeeTerms, taker: P2PFeeTerms}> */
    private array $resolved = [];

    /**
     * The terms for a currency, or the global pair when `$currencyId` is null
     * or has no active override.
     *
     * @return array{maker: P2PFeeTerms, taker: P2PFeeTerms}
     */
    public function forCurrency(?int $currencyId): array
    {
        $key = $currencyId ?? 'global';

        return $this->resolved[$key] ??= $this->resolve($currencyId);
    }

    public function maker(?int $currencyId): P2PFeeTerms
    {
        return $this->forCurrency($currencyId)['maker'];
    }

    public function taker(?int $currencyId): P2PFeeTerms
    {
        return $this->forCurrency($currencyId)['taker'];
    }

    /**
     * Whether this currency is priced by an override rather than the default.
     * The admin list reads this to show which assets are actually customised.
     */
    public function isOverridden(?int $currencyId): bool
    {
        return $currencyId !== null && $this->override($currencyId) !== null;
    }

    /**
     * @return array{maker: P2PFeeTerms, taker: P2PFeeTerms}
     */
    private function resolve(?int $currencyId): array
    {
        $override = $currencyId === null ? null : $this->override($currencyId);

        if ($override !== null) {
            return [
                'maker' => P2PFeeTerms::fromModel($override, 'maker', overridden: true),
                'taker' => P2PFeeTerms::fromModel($override, 'taker', overridden: true),
            ];
        }

        return [
            'maker' => P2PFeeTerms::fromSettings('maker'),
            'taker' => P2PFeeTerms::fromSettings('taker'),
        ];
    }

    private function override(int $currencyId): ?P2PAssetFee
    {
        // Tolerate the table being absent: this resolver runs inside the order
        // flow, and a deploy that has not migrated yet must fall back to the
        // global pair rather than fail the trade.
        if (! Schema::hasTable('p2p_asset_fees')) {
            return null;
        }

        return P2PAssetFee::query()
            ->active()
            ->where('currency_id', $currencyId)
            ->first();
    }
}
