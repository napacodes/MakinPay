<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Withdraw;

use App\Models\WithdrawMethod;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use App\Support\Money;
use App\Support\WithdrawFieldNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One payout method, as a phone needs it.
 *
 * Two things here are easy to get wrong and are therefore stated explicitly.
 *
 * The LIMITS are stored in the platform's base currency, while the customer
 * types in the method's own. Those are the same number only when the method
 * settles in the base currency at a rate of one, which is exactly the case a
 * developer tests. `min_amount` and `max_amount` are therefore converted into
 * the currency the customer types in - the same conversion
 * PaymentService::withdrawMoney uses when it tells them the amount was out of
 * range - and the base figures the ledger actually enforces are sent
 * alongside rather than hidden, so a mismatch is visible instead of puzzling.
 *
 * The FIELDS are the administrator's own schema, normalised, with any stored
 * value stripped. A method's schema can carry a default value; sending it
 * would be harmless, but the app has no use for it and a payout schema is not
 * a place to be relaxed about what leaves the server.
 *
 * @property-read WithdrawMethod $resource
 */
class WithdrawMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $method   = $this->resource;
        $currency = (string) $method->currency;

        return [
            'id'       => $method->id,
            'name'     => $method->name,
            'logo'     => asset($method->logo_alt),
            'type'     => $method->type?->value,
            'currency' => $currency,
            'decimals' => ApiMoney::decimals($currency),

            // What the customer may type, in what they type it in.
            'min_amount' => $this->inMethodCurrency($method->effectiveMinAmount()),
            'max_amount' => $this->inMethodCurrency($method->effectiveMaxAmount()),

            // What the ledger enforces, in the currency it enforces it in.
            'base_currency'   => siteCurrency(),
            'base_min_amount' => $method->effectiveMinAmount(),
            'base_max_amount' => $method->effectiveMaxAmount(),

            'limited_by_provider' => $method->isLimitedByProvider(),
            'conversion_rate'     => DecimalMoney::rate($method->conversion_rate ?? 1),
            'processing_time'     => $method->processing_time,
            'processing'          => [
                'value' => $method->process_time_value === null ? null : (int) $method->process_time_value,
                'unit'  => $method->process_time_unit,
            ],

            // The components, not a single figure and a discriminator: the app
            // previews what it will be charged from the same parts the server
            // charges from.
            'charge' => [
                'fixed'   => DecimalMoney::normalize($method->charge_fixed ?? 0, Money::decimals()),
                'percent' => DecimalMoney::normalize($method->charge_percent ?? 0, 4),
                'min'     => $method->charge_min === null ? null : DecimalMoney::normalize($method->charge_min, Money::decimals()),
                'max'     => $method->charge_max === null ? null : DecimalMoney::normalize($method->charge_max, Money::decimals()),
            ],

            'fields' => self::fields($method),
        ];
    }

    /**
     * The credential schema, with values stripped.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fields(WithdrawMethod $method): array
    {
        return array_map(
            static function (array $field): array {
                unset($field['value']);

                return $field;
            },
            WithdrawFieldNormalizer::normalize($method->fields),
        );
    }

    /**
     * A base-currency limit as the amount the customer would have to type.
     *
     * Multiplied by the method's own rate, which is what
     * PaymentService::fromBaseAmount does when the same limit is quoted back
     * to them in a refusal. A method with no usable rate is left in base
     * rather than converted through a zero.
     */
    private function inMethodCurrency(?string $baseAmount): ?string
    {
        if ($baseAmount === null) {
            return null;
        }

        $rate = DecimalMoney::rate($this->resource->conversion_rate ?? 1);

        if (bccomp($rate, '0', DecimalMoney::RATE_SCALE) <= 0) {
            return $baseAmount;
        }

        return ApiMoney::amount(
            DecimalMoney::multiply($baseAmount, $rate),
            (string) $this->resource->currency,
        );
    }
}
