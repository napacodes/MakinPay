<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Contracts\BillPayment\BillPaymentProviderInterface;
use App\Models\BillPaymentProvider;
use InvalidArgumentException;

/**
 * Builds the driver for one bill payment provider.
 *
 * Resolution is per payment, from the provider that payment was pinned to
 * when its money was reserved. There is deliberately no site-wide "active
 * provider", so changing providers never redirects a payment already in
 * flight.
 *
 * The class comes from config('bill_payments.drivers') and is built through
 * the container. The provider's plugin credentials (secrets revealed) and its
 * `config` column reach the driver through configure(), the one hydration
 * path every driver shares.
 */
class BillPaymentProviderManager
{
    /**
     * @throws InvalidArgumentException when the provider's driver is not registered, or does not implement the contract
     */
    public function driverFor(BillPaymentProvider $provider): BillPaymentProviderInterface
    {
        $driver  = (string) $provider->driver;
        $drivers = (array) config('bill_payments.drivers', []);
        $class   = $drivers[$driver] ?? null;

        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            throw new InvalidArgumentException("Bill payment driver [{$driver}] is not registered.");
        }

        $instance = app($class);

        if (! $instance instanceof BillPaymentProviderInterface) {
            throw new InvalidArgumentException("Bill payment driver [{$driver}] must implement ".BillPaymentProviderInterface::class.'.');
        }

        $instance->configure(
            $provider,
            $provider->plugin?->credentialsArray() ?? [],
            is_array($provider->config) ? $provider->config : [],
        );

        return $instance;
    }
}
