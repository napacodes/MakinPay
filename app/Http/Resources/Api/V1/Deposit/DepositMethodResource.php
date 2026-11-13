<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Deposit;

use App\Enums\MethodType;
use App\Enums\Tax\TaxableType;
use App\Models\DepositMethod;
use App\Services\Tax\TaxEngine;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use App\Support\Money;
use App\Support\WithdrawFieldNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One way of putting money in, as a phone needs it.
 *
 * The same denomination care as the payout side: `min_deposit`/`max_deposit`
 * are stored in the platform's base currency while the customer types in the
 * method's own, and those are the same number only at a rate of one.
 * `min_amount`/`max_amount` are converted the way
 * PaymentService::depositWithPaymentMethod quotes them back in a refusal, and
 * the base figures it actually compares against are sent alongside.
 *
 * `fields` are what the CUSTOMER fills in for a manual deposit - a sender
 * account number, a reference - and `instructions` is what the ADMINISTRATOR
 * tells them to pay into. They are different things and are named
 * differently, because the withdraw side's `fields` mean the opposite (where
 * money goes, not where it came from) and one word for both invites an app to
 * render the wrong one.
 *
 * Neither is sent for an automatic method: there is nothing to fill in and
 * nowhere to pay by hand, and the gateway's own page collects everything.
 *
 * @property-read DepositMethod $resource
 */
class DepositMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $method   = $this->resource;
        $currency = (string) $method->currency;
        $manual   = $method->type === MethodType::MANUAL;

        return [
            'id'       => $method->id,
            'name'     => $method->name,
            'logo'     => asset($method->logo_alt),
            'type'     => $method->type?->value,
            'currency' => $currency,
            'decimals' => ApiMoney::decimals($currency),

            'min_amount' => $this->inMethodCurrency($method->effectiveMinAmount()),
            'max_amount' => $this->inMethodCurrency($method->effectiveMaxAmount()),

            'base_currency'   => siteCurrency(),
            'base_min_amount' => $method->effectiveMinAmount(),
            'base_max_amount' => $method->effectiveMaxAmount(),

            'limited_by_provider' => $method->isLimitedByProvider(),

            // A gateway credits on its callback; a manual claim waits for an
            // administrator. The app words each from this rather than guessing.
            'arrival'         => $manual ? 'manual' : 'instant',
            'conversion_rate' => DecimalMoney::rate($method->conversion_rate ?? 1),

            'charge' => [
                'fixed'   => DecimalMoney::normalize($method->charge_fixed ?? 0, Money::decimals()),
                'percent' => DecimalMoney::normalize($method->charge_percent ?? 0, 4),
                'min'     => $method->charge_min === null ? null : DecimalMoney::normalize($method->charge_min, Money::decimals()),
                'max'     => $method->charge_max === null ? null : DecimalMoney::normalize($method->charge_max, Money::decimals()),
            ],

            // GF6: the deposit tax rules for the signed-in customer, the fixed
            // part already in the method's currency. Added on top of the
            // amount and the fee, a line per rule; the store's payable is the
            // server's own figure.
            'tax_rules' => $request->user() === null
                ? []
                : app(TaxEngine::class)->previewRules(TaxableType::Deposit, $request->user(), DecimalMoney::rate($method->conversion_rate ?? 1)),

            // none | optional | required: whether to ask for the payer's phone
            // and send it back as `customer_phone`. A mobile-money gateway
            // charges that number, so it is the one the customer approves on.
            'customer_phone' => $method->customerPhoneMode()->value,

            // What the customer has to tell us, and where they have to pay.
            'fields'       => $manual ? self::fields($method) : [],
            'instructions' => $manual ? $method->receive_payment_details : null,
        ];
    }

    /**
     * The customer's own fields, with any stored value stripped.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fields(DepositMethod $method): array
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
