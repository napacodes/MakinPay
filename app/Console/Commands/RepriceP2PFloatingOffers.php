<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\P2P\OfferStatus;
use App\Models\P2P\Offer;
use App\Services\P2P\P2PMarketPrice;
use App\Support\DecimalMoney;
use Illuminate\Console\Command;

/**
 * Move every floating ad to its margin on the current mid-price. Fixed ads
 * are never touched; a floating ad whose pair has no rate keeps its last
 * price.
 */
class RepriceP2PFloatingOffers extends Command
{
    protected $signature = 'p2p:reprice';

    protected $description = 'Re-price floating P2P ads from the current market mid-price.';

    public function handle(P2PMarketPrice $prices): int
    {
        $moved = 0;
        Offer::query()
            ->with(['wallet.currency', 'quoteCurrency'])
            ->where('price_type', Offer::PRICE_FLOAT)
            ->whereIn('status', [OfferStatus::ACTIVE->value, OfferStatus::PAUSED->value])
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($prices, &$moved): void {
                foreach ($chunk as $offer) {
                    $price = $prices->priceFor($offer);
                    if ($price === null || DecimalMoney::compare($price, (string) $offer->price) === 0) {
                        continue;
                    }
                    $offer->update(['price' => $price]);
                    $moved++;
                }
            });
        $this->components->info(sprintf('%d floating ad(s) re-priced.', $moved));

        return self::SUCCESS;
    }
}
