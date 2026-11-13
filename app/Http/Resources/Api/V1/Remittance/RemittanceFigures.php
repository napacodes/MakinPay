<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Remittance;

use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * How a remittance figure crosses the wire to the app.
 *
 * Shared by the corridor, quote and transfer shapes so the three agree to the
 * digit: a quote that says "60700.00" and a transfer that says "60700" for the
 * same money is a support ticket.
 *
 * READ FROM THE STORED VALUE, never through the model's casts. The quote casts
 * its amounts and rates to float and the transfer casts its amounts to
 * `decimal:2`, both of which were chosen for the web views. A float cannot
 * hold 0.1, and `decimal:2` reports a three-decimal KWD payout as two - the
 * envelope's whole promise is that money arrives as the string the ledger
 * holds, at the currency's own precision.
 */
final class RemittanceFigures
{
    /**
     * The column as stored, before any cast.
     *
     * A freshly created row reports what the service assigned (a decimal
     * string); a loaded one, what the driver returned.
     */
    public static function raw(Model $model, string $key): mixed
    {
        $attributes = $model->getAttributes();

        return $attributes[$key] ?? null;
    }

    /**
     * A money figure at its currency's precision.
     *
     * The source leg is always a wallet currency the platform knows, and goes
     * through ApiMoney like every other amount in this API. The destination
     * leg often is not: a BDT payout needs no BDT wallet, so `currencies` may
     * have no row for it. ApiMoney would then pass the figure through at
     * ledger scale ("60700.00000000"). QuoteService rounded it with
     * currencyDecimals(), which falls back to the base currency's places, so
     * quantizing at that same fallback reproduces exactly the number that was
     * priced - nothing is lost, and nothing is invented.
     */
    public static function amount(mixed $value, ?string $currency): string
    {
        $value ??= '0';

        if (ApiMoney::decimals($currency) !== null) {
            return ApiMoney::amount($value, $currency);
        }

        return Money::quantize($value, $currency);
    }

    /**
     * An exchange rate as a decimal string, full precision, no padding.
     *
     * A rate is not money and has no currency precision to land on: "121.4"
     * and "0.0000094118" are both exact.
     */
    public static function rate(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return DecimalMoney::rate($value);
    }
}
