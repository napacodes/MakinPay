<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\NotifyErrorException;

/**
 * Check an amount against the limits the payment processor itself imposes.
 *
 * These are stated in the processor's own currency, so the figure checked here
 * is the one that will actually reach the gateway — the converted amount, not
 * the base-currency figure the customer typed. `min_deposit` / `max_deposit`
 * are a separate, base-currency policy limit and are checked elsewhere.
 *
 * Without this a customer passes every platform check, gets redirected, and is
 * rejected by the processor with an error the platform never explains. Failing
 * early with the processor's own figure is the difference between "you cannot
 * deposit that" and a dead end at someone else's checkout.
 */
class ProviderLimit
{
    /**
     * @param object           $method A deposit or withdraw method.
     * @param string|int|float $amount In the method's own currency.
     *
     * @throws NotifyErrorException
     */
    public static function assert(object $method, string|int|float $amount): void
    {
        $currency = (string) ($method->currency ?? '');
        $value    = DecimalMoney::normalize($amount);

        $min = $method->provider_min_amount ?? null;
        $max = $method->provider_max_amount ?? null;

        if ($min !== null && bccomp($value, DecimalMoney::normalize($min), DecimalMoney::CALCULATION_SCALE) < 0) {
            throw new NotifyErrorException(__('This payment method does not accept less than :amount :currency.', [
                'amount'   => Money::trimFee((string) $min),
                'currency' => $currency,
            ]));
        }

        if ($max !== null && bccomp($value, DecimalMoney::normalize($max), DecimalMoney::CALCULATION_SCALE) > 0) {
            throw new NotifyErrorException(__('This payment method does not accept more than :amount :currency.', [
                'amount'   => Money::trimFee((string) $max),
                'currency' => $currency,
            ]));
        }
    }
}
