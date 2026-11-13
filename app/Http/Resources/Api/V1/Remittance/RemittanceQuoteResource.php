<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Remittance;

use App\Models\RemittanceQuote;
use App\Services\Tax\TaxPresenter;
use App\Support\DecimalMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A locked remittance quote, in the shape the app's amount and review screens
 * read.
 *
 * Separate from the integration API's RemittanceQuoteResource, which answers
 * in floats and keeps its own field names for the integrations already
 * parsing it. Nothing here is computed that the quote did not lock: the
 * figures are the stored ones, and the two derived values - the margin and
 * the seconds left - are arithmetic on stored columns, not a second pricing.
 *
 * `tax` is the same {amount, lines} shape TaxPresenter::api() gives the send
 * money quote. It comes through TaxPresenter::stored() because what the quote
 * keeps is the locked total and breakdown, not the engine's TaxQuote object -
 * and the figure shown must be the one that will be charged.
 *
 * @property-read RemittanceQuote $resource
 */
class RemittanceQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quote       = $this->resource;
        $source      = strtoupper((string) $quote->source_currency);
        $destination = strtoupper((string) $quote->destination_currency);
        $tax         = RemittanceFigures::raw($quote, 'tax_amount');

        return [
            'id'                   => $quote->quote_id,
            'corridor_id'          => $quote->corridor_id,
            'payout_method'        => $quote->payout_method?->value,
            'source_currency'      => $source,
            'destination_currency' => $destination,
            'send_amount'          => RemittanceFigures::amount(RemittanceFigures::raw($quote, 'send_amount'), $source),
            'fee'                  => RemittanceFigures::amount(RemittanceFigures::raw($quote, 'fee_amount'), $source),
            'tax'                  => TaxPresenter::stored($tax === null ? null : (string) $tax, $quote->tax_breakdown, $source),
            'total'                => RemittanceFigures::amount(RemittanceFigures::raw($quote, 'total_pay'), $source),
            'exchange_rate'        => RemittanceFigures::rate(RemittanceFigures::raw($quote, 'exchange_rate')),
            'mid_market_rate'      => RemittanceFigures::rate(RemittanceFigures::raw($quote, 'mid_market_rate')),
            'margin_percent'       => $this->marginPercent(),
            'receive_amount'       => RemittanceFigures::amount(RemittanceFigures::raw($quote, 'receive_amount'), $destination),
            'expires_at'           => $quote->expires_at?->toIso8601String(),
            'expires_in'           => $this->expiresIn(),
        ];
    }

    /**
     * How far below the market rate the customer's rate sits, in percent, to
     * two places - what the review screen calls "our margin".
     *
     * Null without a market rate to compare against, rather than a zero that
     * would claim the rate is the market's.
     */
    private function marginPercent(): ?string
    {
        $mid  = RemittanceFigures::rate(RemittanceFigures::raw($this->resource, 'mid_market_rate'));
        $rate = RemittanceFigures::rate(RemittanceFigures::raw($this->resource, 'exchange_rate'));

        if ($mid === null || $rate === null || DecimalMoney::compare($mid, '0', DecimalMoney::RATE_SCALE) <= 0) {
            return null;
        }

        $scale  = DecimalMoney::RATE_SCALE;
        $spread = DecimalMoney::subtract($mid, $rate, $scale);
        $ratio  = DecimalMoney::divide($spread, $mid, $scale);

        return DecimalMoney::round(DecimalMoney::multiply($ratio, '100', $scale), 2);
    }

    /**
     * Seconds left on the rate hold, never negative: an expired quote is
     * zero seconds from expiring, not minus forty.
     *
     * Rounded up. The column keeps whole seconds, so a five-minute hold
     * written a few hundred milliseconds into a second has 299.6 left at
     * once; the quote stays usable until the clock passes expires_at, so
     * "300" is the true answer and "299" would cut the countdown short.
     */
    private function expiresIn(): int
    {
        $expiresAt = $this->resource->expires_at;

        if ($expiresAt === null) {
            return 0;
        }

        return max(0, (int) ceil(now()->diffInSeconds($expiresAt, false)));
    }
}
