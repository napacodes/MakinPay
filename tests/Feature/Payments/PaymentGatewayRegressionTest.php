<?php

declare(strict_types=1);

use App\Services\Addons\AddonLicensing;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentGatewayFactory;

/*
|--------------------------------------------------------------------------
| Payment gateway regression
|--------------------------------------------------------------------------
|
| Milestone 1 removed Envato licensing. It must not have disturbed payment
| processing: the licensing guard was woven into admin middleware and could
| plausibly have taken gateway registration with it.
|
| No network request is made here. These tests assert that every gateway class
| still exists, still satisfies the contract, and is still reachable through
| the factory that PaymentService actually uses.
|
*/

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    /*
     * Each gateway class reads its credentials from its `payment_gateways`
     * row, so the table must exist and carry rows before the factory can
     * resolve anything. Seeding the real gateway seeder keeps this test
     * faithful to what a customer installation actually contains.
     */
    $this->seed(Database\Seeders\PaymentGatewaySeeder::class);

    $this->factory = app(PaymentGatewayFactory::class);

    // coreGateways() is the private map the factory resolves from.
    $this->coreGateways = new ReflectionMethod(PaymentGatewayFactory::class, 'coreGateways');
    $this->coreGateways->setAccessible(true);

    /** @var array<string, class-string> $map */
    $this->gateways = (array) $this->coreGateways->invoke($this->factory);
});

it('registers a non-empty set of core gateways', function (): void {
    expect($this->gateways)->toBeArray()->not->toBeEmpty();
    expect(count($this->gateways))->toBeGreaterThan(20);
});

it('keeps the expected well-known gateways registered', function (string $code): void {
    expect($this->gateways)->toHaveKey($code);
})->with([
    'paypal',
    'stripe',
    'mollie',
    'paystack',
    'razorpay',
    'coinbase',
    'flutterwave',
    'manual',
]);

it('has a loadable class for every registered gateway', function (): void {
    $missing = [];

    foreach ($this->gateways as $code => $class) {
        if (! class_exists($class)) {
            $missing[] = $code.' => '.$class;
        }
    }

    expect($missing)->toBe([], 'Gateway classes that cannot be loaded: '.implode(', ', $missing));
});

it('implements the payment gateway contract for every registered gateway', function (): void {
    /*
     * `manual` is deliberately not a PaymentGateway: it is reached through
     * `?? $depositMethod->type` and only shows a "requested" notice. The
     * factory lists it so the code resolves; it is exempt from the contract.
     */
    $exempt = ['manual'];

    $violations = [];

    foreach ($this->gateways as $code => $class) {
        if (in_array($code, $exempt, true) || ! class_exists($class)) {
            continue; // the manual system is exempt; missing classes are reported elsewhere
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->implementsInterface(PaymentGateway::class)) {
            $violations[] = $code.' => '.$class;
        }
    }

    expect($violations)->toBe([], 'Gateways not implementing '.PaymentGateway::class.': '.implode(', ', $violations));
});

it('resolves every registered gateway through the factory', function (): void {
    /*
     * getGateway() is what PaymentService calls. requireActive = false skips
     * the enabled-in-database check.
     *
     * Most gateways deliberately refuse to construct without credentials
     * ("Stripe secret key is not configured."). That is the correct behaviour:
     * a half-configured gateway must fail loudly rather than charge nobody. So
     * the assertion is that every code either resolves to an object of the
     * right type OR fails with a credential/configuration error — never with a
     * class-loading, interface, or database error, which is what a licensing
     * regression would look like.
     */
    $failures = [];

    foreach (array_keys($this->gateways) as $code) {
        try {
            $gateway = $this->factory->getGateway($code, false);

            // `manual` returns a ManualPaymentSystem, not a PaymentGateway.
            if ($code !== 'manual' && ! $gateway instanceof PaymentGateway) {
                $failures[] = $code.' (wrong type)';
            }

            if ($gateway === null) {
                $failures[] = $code.' (null)';
            }
        } catch (Throwable $e) {
            $message = $e->getMessage();

            $isConfigurationError = (bool) preg_match(
                '/not configured|not set|credentials|Undefined (array key|property)|api_?key|secret/i',
                $message
            );

            if (! $isConfigurationError && ! $e instanceof App\Exceptions\NotifyErrorException) {
                $failures[] = $code.' ('.$message.')';
            }
        }
    }

    expect($failures)->toBe([], 'Gateways that failed for a non-configuration reason: '.implode(', ', $failures));
});

it('resolves gateways that need no credentials', function (): void {
    // These two construct with no configuration and must keep doing so.
    expect($this->factory->getGateway('manual', false))->not->toBeNull();
    expect($this->factory->getGateway('bsicards', false))->toBeInstanceOf(PaymentGateway::class);
});

it('reports whether a gateway exists', function (): void {
    expect($this->factory->hasGateway('stripe'))->toBeTrue();
    expect($this->factory->hasGateway('paypal'))->toBeTrue();
    expect($this->factory->hasGateway('definitely_not_a_gateway'))->toBeFalse();
});

it('rejects an unknown gateway code', function (): void {
    expect(fn () => $this->factory->getGateway('definitely_not_a_gateway', false))
        ->toThrow(Exception::class);
});
