<?php

namespace App\Http\Controllers\Common;

use App\Http\Controllers\Controller;
use App\Services\CurrencyService;
use Illuminate\Http\JsonResponse;

class AppController extends Controller
{
    /**
     * The stored rate between two currencies.
     *
     * This used to call CurrencyConversionService, which meant an endpoint
     * with no authentication and no throttle reached straight into a metered
     * CoinMarketCap / CurrencyLayer account: each currency pair is its own
     * cache entry, so walking the pair space burned one paid request per hit.
     * Nothing in the product calls this route — it is kept only because a
     * customer theme might — which made it a cost and abuse surface with no
     * corresponding benefit.
     *
     * It now answers from the same stored rates every wallet conversion reads,
     * so it costs a query, cannot disagree with the ledger, and refuses a pair
     * it cannot price rather than returning null and letting the caller treat
     * that as parity. `currency:refresh-rates` keeps those rates current.
     */
    public function getCurrencyRate(string $fromCurrency, string $toCurrency, CurrencyService $currencies): JsonResponse
    {
        $rate = $currencies->crossRate($fromCurrency, $toCurrency);

        if ($rate === null) {
            return response()->json([
                'message' => __('The exchange rate is not available for this currency pair.'),
            ], 422);
        }

        return response()->json([
            'rate' => $rate,
            'from' => strtoupper(trim($fromCurrency)),
            'to'   => strtoupper(trim($toCurrency)),
        ]);
    }
}
