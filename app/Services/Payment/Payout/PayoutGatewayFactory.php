<?php

namespace App\Services\Payment\Payout;

use App\Services\Bitnob\BitnobProviderSettings;
use App\Services\Payment\Payout\Drivers\BitnobPayoutGateway;
use App\Services\Payment\Payout\Drivers\ManualPayoutGateway;
use App\Services\Payment\Payout\Drivers\StripeConnectPayoutGateway;
use App\Services\Payment\Stripe\StripeCredentials;
use Exception;
use Illuminate\Support\Facades\App;

class PayoutGatewayFactory
{
    /**
     * Driver codes a withdraw method may opt into for mass payouts.
     *
     * @var array<int, string>
     */
    public const MASS_PAYOUT_DRIVERS = ['manual', 'bitnob', 'stripe_connect'];

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return collect((array) config('remittance.gateways', []))
            ->filter(fn (mixed $label, string $code): bool => $this->configurationIssues($code) === [])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function availableCodes(): array
    {
        return array_keys($this->options());
    }

    public function supports(string $gatewayCode): bool
    {
        return in_array($gatewayCode, $this->availableCodes(), true);
    }

    /**
     * @return array<int, string>
     */
    public function configurationIssues(string $gatewayCode, bool $requireEnabled = true): array
    {
        if (! array_key_exists($gatewayCode, (array) config('remittance.gateways', []))) {
            return [__('The payout gateway is not registered.')];
        }

        if ($gatewayCode === 'manual') {
            return [];
        }

        if ($gatewayCode === 'stripe_connect') {
            return $this->stripeConnectIssues($requireEnabled);
        }

        if ($gatewayCode !== 'bitnob') {
            return [__('The payout gateway driver is not implemented.')];
        }

        $settings = App::make(BitnobProviderSettings::class);
        $gateway  = $settings->gateway();

        if (! $gateway || ! $settings->isGatewayEnabled()) {
            return [__('The Bitnob payment gateway is disabled or missing.')];
        }

        $credentials = (array) $gateway->credentials;
        $required    = ['client_id', 'hmac_key', 'webhook_secret'];
        $issues      = [];

        if ($requireEnabled && ! filter_var($credentials['remittance_enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            $issues[] = __('Bitnob remittance payouts are not explicitly enabled.');
        }

        foreach ($required as $key) {
            if ($this->isMissingCredential($credentials[$key] ?? null, $key)) {
                $issues[] = __('Bitnob credential :field is missing or is a placeholder.', ['field' => $key]);
            }
        }

        return $issues;
    }

    /**
     * @return array<string, array{configured: bool, issues: array<int, string>}>
     */
    public function healthReport(): array
    {
        return collect((array) config('remittance.gateways', []))
            ->mapWithKeys(function (mixed $label, string $code): array {
                $issues = $this->configurationIssues($code);

                return [$code => ['configured' => $issues === [], 'issues' => $issues]];
            })
            ->all();
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function probe(string $gatewayCode): array
    {
        $issues = $this->configurationIssues($gatewayCode, false);

        if ($issues !== []) {
            return ['ok' => false, 'message' => implode(' ', $issues)];
        }

        return $this->makeRegisteredGateway($gatewayCode)->healthCheck();
    }

    /**
     * Resolve a payout driver by code. Mirrors PaymentGatewayFactory but
     * scoped to outbound payout (remittance) drivers.
     *
     * @throws Exception
     */
    public function getGateway(string $gatewayCode): PayoutGateway
    {
        if (! $this->supports($gatewayCode)) {
            throw new Exception(sprintf('Unsupported payout gateway: %s', $gatewayCode));
        }

        return $this->makeRegisteredGateway($gatewayCode);
    }

    /**
     * Readiness of a driver for mass payouts. Bitnob is judged without the
     * remittance opt-in: the per-method mass payout driver and the Bitnob
     * credentials are the switch, not `remittance_enabled`.
     *
     * @return array<int, string>
     */
    public function massPayoutIssues(string $gatewayCode): array
    {
        return match ($gatewayCode) {
            'manual'         => [],
            'bitnob'         => $this->configurationIssues('bitnob', false),
            'stripe_connect' => $this->configurationIssues('stripe_connect', true),
            default          => [__('This payout driver cannot be used for mass payouts.')],
        };
    }

    /**
     * Resolve a mass payout driver by code. Deliberately bypasses
     * `supports()`, which follows the remittance readiness rules; the caller
     * checks `massPayoutIssues()` instead.
     *
     * @throws Exception
     */
    public function massPayoutGateway(string $gatewayCode): PayoutOrderGateway
    {
        if (! in_array($gatewayCode, self::MASS_PAYOUT_DRIVERS, true)) {
            throw new Exception(sprintf('Unsupported mass payout driver: %s', $gatewayCode));
        }

        $gateway = $this->makeRegisteredGateway($gatewayCode);

        if (! $gateway instanceof PayoutOrderGateway) {
            throw new Exception(sprintf('Payout driver %s does not implement the mass payout contract.', $gatewayCode));
        }

        return $gateway;
    }

    protected function makeRegisteredGateway(string $gatewayCode): PayoutGateway
    {
        return match ($gatewayCode) {
            'manual'         => App::make(ManualPayoutGateway::class),
            'bitnob'         => App::make(BitnobPayoutGateway::class),
            'stripe_connect' => App::make(StripeConnectPayoutGateway::class),
            default          => throw new Exception(sprintf('Unsupported payout gateway: %s', $gatewayCode)),
        };
    }

    /**
     * Stripe Connect payouts reuse the Stripe gateway credentials, so the
     * readiness check mirrors what the marketplace split driver needs.
     *
     * @return array<int, string>
     */
    protected function stripeConnectIssues(bool $requireEnabled): array
    {
        $credentials = StripeCredentials::fromGateway();
        $issues      = [];

        if ($credentials->secretKey() === '') {
            $issues[] = __('The Stripe secret key is missing or is a placeholder.');
        }

        if ($requireEnabled && ! $credentials->connectEnabled()) {
            $issues[] = __('Stripe Connect is not enabled on the Stripe payment gateway.');
        }

        return $issues;
    }

    protected function isMissingCredential(mixed $value, string $key): bool
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return true;
        }

        $normalized = strtolower(trim((string) $value));

        return $normalized === $key
            || str_starts_with($normalized, 'your_')
            || str_starts_with($normalized, 'test-')
            || str_ends_with($normalized, '_key')
            || str_ends_with($normalized, '_secret');
    }
}
