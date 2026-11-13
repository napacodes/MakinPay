<?php

declare(strict_types=1);

namespace App\Services\P2P;

use App\Enums\P2P\OrderSide;
use App\Models\P2P\Offer;
use App\Models\User;
use App\Services\CurrencyService;
use App\Support\DecimalMoney;
use App\Support\Money;

/**
 * Where a pair trades: the site's own cross rate as the mid-price, a
 * floating ad's price as a margin on it, and the ladder of live prices
 * an ad would sit in.
 */
class P2PMarketPrice
{
    /** How many live prices the ladder carries; enough to place any ad. */
    public const LADDER_SIZE = 50;

    public function __construct(private readonly CurrencyService $currencies, private readonly P2POfferMarketplace $marketplace) {}

    public function mid(string $asset, string $fiat): ?string
    {
        return $this->currencies->crossRate($asset, $fiat);
    }

    /**
     * The mid moved by a margin, at the fiat's decimals. A seller's positive
     * margin raises the price; a buyer's positive margin lowers it, so
     * "+1 %" always means "1 % in my favour".
     */
    public function floatingPrice(string $mid, OrderSide $side, string|float|int $marginPct, string $fiat): string
    {
        $factor = DecimalMoney::add('100', $side === OrderSide::SELL ? (string) $marginPct : DecimalMoney::multiply((string) $marginPct, '-1'), DecimalMoney::CALCULATION_SCALE);
        $price  = DecimalMoney::divide(DecimalMoney::multiply($mid, $factor), '100');

        return DecimalMoney::round($price, Money::decimalsIfKnown($fiat) ?? DecimalMoney::AMOUNT_SCALE);
    }

    /**
     * The price a floating ad should carry right now, or null when the pair
     * has no rate to float on.
     */
    public function priceFor(Offer $offer): ?string
    {
        $asset = $offer->wallet?->currency?->code;
        $fiat  = $offer->quoteCurrency?->code;
        if ($asset === null || $fiat === null || $offer->margin_pct === null) {
            return null;
        }
        $mid = $this->mid($asset, $fiat);

        return $mid === null ? null : $this->floatingPrice($mid, $offer->side, (string) $offer->margin_pct, $fiat);
    }

    /**
     * The live prices on one side of a pair, best first, as the viewer's
     * market shows them.
     *
     * @return list<string>
     */
    public function ladder(User $viewer, string $asset, string $fiat, OrderSide $side): array
    {
        $page = $this->marketplace->search($viewer, ['side' => $side->value, 'currency' => $asset, 'fiat' => $fiat, 'sort' => 'price'], self::LADDER_SIZE);

        return $page->getCollection()->map(fn (Offer $offer): string => (string) $offer->price)->values()->all();
    }

    /**
     * Where a price would rank on a ladder: 1 when it beats every live ad.
     * On the SELL side lower is better; on the BUY side higher is.
     *
     * @param list<string> $ladder
     */
    public function rank(string $price, array $ladder, OrderSide $side): int
    {
        $better = 0;
        foreach ($ladder as $other) {
            $cmp = DecimalMoney::compare($other, $price);
            if (($side === OrderSide::SELL && $cmp < 0) || ($side === OrderSide::BUY && $cmp > 0)) {
                $better++;
            }
        }

        return $better + 1;
    }
}
