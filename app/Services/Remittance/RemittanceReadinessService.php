<?php

namespace App\Services\Remittance;

use App\Models\RemittanceCorridor;
use App\Services\CurrencyConversionService;
use App\Services\Payment\Payout\PayoutGatewayFactory;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RemittanceReadinessService
{
    public function __construct(
        protected PayoutGatewayFactory $gatewayFactory,
        protected ComplianceProviderFactory $complianceFactory,
        protected CurrencyConversionService $currencyConverter,
    ) {}

    /**
     * @param  array<int, string>                                                                                                                        $requiredGateways
     * @return array{ready: bool, production: bool, probed: bool, checks: array<int, array{id: string, label: string, status: string, message: string}>}
     */
    public function report(bool $production = false, bool $probe = false, array $requiredGateways = []): array
    {
        $production = $production || app()->environment('production');
        $checks     = [];

        $this->checkCoreTables($checks);
        $this->checkQueue($checks, $production);
        $this->checkApplicationUrl($checks, $production);

        $activeCorridors = RemittanceCorridor::query()->active();
        $corridorCount   = (clone $activeCorridors)->count();
        $currencyPairs   = (clone $activeCorridors)
            ->get(['source_currency', 'destination_currency'])
            ->map(fn (RemittanceCorridor $corridor): array => [
                'source'      => strtoupper($corridor->source_currency),
                'destination' => strtoupper($corridor->destination_currency),
            ])
            ->unique(fn (array $pair): string => $pair['source'].'_'.$pair['destination'])
            ->values()
            ->all();
        $activeGatewayCodes = (clone $activeCorridors)
            ->where('payout_gateway', '!=', 'manual')
            ->distinct()
            ->orderBy('payout_gateway')
            ->pluck('payout_gateway')
            ->all();
        $gatewayCodes = collect($activeGatewayCodes)
            ->merge($requiredGateways)
            ->map(fn (mixed $gateway): string => strtolower(trim((string) $gateway)))
            ->filter(fn (string $gateway): bool => $gateway !== '' && $gateway !== 'manual')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->add(
            $checks,
            'corridors.active',
            __('Active corridors'),
            $corridorCount > 0 ? 'pass' : ($production ? 'fail' : 'warn'),
            $corridorCount > 0
                ? __(':count active remittance corridor(s) are available.', ['count' => $corridorCount])
                : __('No active remittance corridor is configured.'),
        );

        foreach ($gatewayCodes as $gatewayCode) {
            $issues = $this->gatewayFactory->configurationIssues((string) $gatewayCode);
            $this->add(
                $checks,
                'gateway.'.(string) $gatewayCode,
                __('Payout gateway: :gateway', ['gateway' => strtoupper((string) $gatewayCode)]),
                $issues === [] ? 'pass' : 'fail',
                $issues === [] ? __('Configuration is complete and explicitly enabled.') : implode(' ', $issues),
            );
        }

        if ($gatewayCodes === []) {
            $this->add(
                $checks,
                'gateway.external',
                __('External payout gateway'),
                'warn',
                __('No active corridor currently uses an automated payout gateway.'),
            );
        }

        $this->checkWebhookUrl($checks, $gatewayCodes, $production);
        $this->checkCompliance($checks, $production, $probe);
        $this->checkFxCoverage($checks, $currencyPairs, $probe);

        if ($probe) {
            foreach ($gatewayCodes as $gatewayCode) {
                $result = $this->gatewayFactory->probe((string) $gatewayCode);
                $this->add(
                    $checks,
                    'probe.gateway.'.(string) $gatewayCode,
                    __('Live payout probe: :gateway', ['gateway' => strtoupper((string) $gatewayCode)]),
                    $result['ok'] ? 'pass' : 'fail',
                    $result['message'],
                );
            }
        } elseif ($gatewayCodes !== []) {
            $this->add(
                $checks,
                'probe.gateway',
                __('Live payout probe'),
                'warn',
                __('Not executed. Re-run with --probe during staging UAT.'),
            );
        }

        return [
            'ready'      => collect($checks)->doesntContain(fn (array $check): bool => $check['status'] === 'fail'),
            'production' => $production,
            'probed'     => $probe,
            'checks'     => $checks,
        ];
    }

    /**
     * @param array<int, array{id: string, label: string, status: string, message: string}> $checks
     */
    protected function checkCoreTables(array &$checks): void
    {
        foreach (['remittance_corridors', 'remittance_quotes', 'remittance_transfers', 'remittance_status_events'] as $table) {
            $exists = Schema::hasTable($table);
            $this->add(
                $checks,
                'database.'.$table,
                __('Database table: :table', ['table' => $table]),
                $exists ? 'pass' : 'fail',
                $exists ? __('Table is available.') : __('Required table is missing.'),
            );
        }
    }

    /**
     * @param array<int, array{id: string, label: string, status: string, message: string}> $checks
     */
    protected function checkQueue(array &$checks, bool $production): void
    {
        $connection = (string) config('queue.default');
        $driver     = (string) config('queue.connections.'.$connection.'.driver');
        $async      = ! in_array($driver, ['sync', 'null', ''], true);

        $this->add(
            $checks,
            'queue.connection',
            __('Queue connection'),
            $async ? 'pass' : ($production ? 'fail' : 'warn'),
            $async
                ? __('Asynchronous :driver queue is configured.', ['driver' => $driver])
                : __('A persistent asynchronous queue is required for production payouts.'),
        );

        if ($driver === 'database') {
            foreach (['jobs', 'failed_jobs'] as $table) {
                $exists = Schema::hasTable($table);
                $this->add(
                    $checks,
                    'queue.table.'.$table,
                    __('Queue table: :table', ['table' => $table]),
                    $exists ? 'pass' : 'fail',
                    $exists ? __('Table is available.') : __('Required queue table is missing.'),
                );
            }
        }

        $failedDriver = (string) config('queue.failed.driver');
        $this->add(
            $checks,
            'queue.failed_driver',
            __('Failed-job storage'),
            ! in_array($failedDriver, ['null', ''], true) ? 'pass' : ($production ? 'fail' : 'warn'),
            ! in_array($failedDriver, ['null', ''], true)
                ? __('Failed jobs are retained using :driver.', ['driver' => $failedDriver])
                : __('Failed-job persistence is disabled.'),
        );
    }

    /**
     * @param array<int, array{id: string, label: string, status: string, message: string}> $checks
     */
    protected function checkApplicationUrl(array &$checks, bool $production): void
    {
        $url    = trim((string) config('app.url'));
        $secure = $this->isHttpsUrl($url);

        $this->add(
            $checks,
            'application.url',
            __('Application URL'),
            $secure ? 'pass' : ($production ? 'fail' : 'warn'),
            $secure ? __('HTTPS application URL is configured.') : __('Production remittance callbacks require an HTTPS APP_URL.'),
        );
    }

    /**
     * @param array<int, array{id: string, label: string, status: string, message: string}> $checks
     * @param array<int, string>                                                            $gatewayCodes
     */
    protected function checkWebhookUrl(array &$checks, array $gatewayCodes, bool $production): void
    {
        if ($gatewayCodes === []) {
            return;
        }

        $url    = route('ipn.handle', ['gateway' => $gatewayCodes[0]]);
        $secure = $this->isHttpsUrl($url);

        $this->add(
            $checks,
            'webhook.url',
            __('Provider webhook URL'),
            $secure ? 'pass' : ($production ? 'fail' : 'warn'),
            $secure ? __('Provider callback route resolves over HTTPS.') : __('Provider callback route does not resolve over HTTPS.'),
        );
    }

    /**
     * @param array<int, array{id: string, label: string, status: string, message: string}> $checks
     */
    protected function checkCompliance(array &$checks, bool $production, bool $probe): void
    {
        $driver         = (string) config('remittance.compliance.driver', 'local');
        $requiresRemote = $production && (bool) config('remittance.compliance.require_remote_in_production', true);
        $driverReady    = ! $requiresRemote || $driver === 'remote';

        $this->add(
            $checks,
            'compliance.driver',
            __('Compliance provider'),
            $driverReady ? 'pass' : 'fail',
            $driverReady
                ? __('Compliance driver :driver is selected.', ['driver' => $driver])
                : __('Production requires a remote AML and sanctions provider.'),
        );

        if ($driver !== 'remote') {
            return;
        }

        foreach (['endpoint', 'health_endpoint'] as $key) {
            $url    = trim((string) config('remittance.compliance.remote.'.$key));
            $secure = $this->isHttpsUrl($url);
            $this->add(
                $checks,
                'compliance.'.$key,
                __('Compliance :field', ['field' => str_replace('_', ' ', $key)]),
                $secure ? 'pass' : 'fail',
                $secure ? __('Secure endpoint is configured.') : __('A valid HTTPS endpoint is required.'),
            );
        }

        $tokenReady = $this->isConfiguredSecret(config('remittance.compliance.remote.token'));
        $this->add(
            $checks,
            'compliance.token',
            __('Compliance API token'),
            $tokenReady ? 'pass' : 'fail',
            $tokenReady ? __('A non-placeholder token is configured.') : __('The API token is missing or is a placeholder.'),
        );

        if ($probe && $tokenReady) {
            $result = $this->complianceFactory->make()->healthCheck();
            $this->add(
                $checks,
                'probe.compliance',
                __('Live compliance probe'),
                $result['ok'] ? 'pass' : 'fail',
                $result['message'],
            );
        } elseif (! $probe) {
            $this->add(
                $checks,
                'probe.compliance',
                __('Live compliance probe'),
                'warn',
                __('Not executed. Re-run with --probe during staging UAT.'),
            );
        }
    }

    /**
     * @param array<int, array{id: string, label: string, status: string, message: string}> $checks
     * @param array<int, array{source: string, destination: string}>                        $currencyPairs
     */
    protected function checkFxCoverage(array &$checks, array $currencyPairs, bool $probe): void
    {
        $provider = $this->currencyConverter->fiatRateProvider();

        $this->add(
            $checks,
            'fx.provider',
            __('FX rate provider'),
            'pass',
            __('Fiat rates are configured through :provider.', ['provider' => $provider]),
        );

        if ($currencyPairs === []) {
            return;
        }

        if (! $probe) {
            $this->add(
                $checks,
                'probe.fx',
                __('Live FX coverage probe'),
                'warn',
                __('Not executed. Re-run with --probe during staging UAT.'),
            );

            return;
        }

        foreach ($currencyPairs as $pair) {
            $source      = $pair['source'];
            $destination = $pair['destination'];

            try {
                $rate = $source === $destination
                    ? 1.0
                    : $this->currencyConverter->convertCurrency(1.0, $source, $destination);
                $ready   = is_numeric($rate) && (float) $rate > 0;
                $message = $ready
                    ? __('A positive live rate is available from :source to :destination.', compact('source', 'destination'))
                    : __('No valid live rate is available from :source to :destination.', compact('source', 'destination'));
            } catch (Throwable) {
                $ready   = false;
                $message = __('The FX provider could not quote :source to :destination.', compact('source', 'destination'));
            }

            $this->add(
                $checks,
                'probe.fx.'.strtolower($source.'_'.$destination),
                __('Live FX probe: :source/:destination', compact('source', 'destination')),
                $ready ? 'pass' : 'fail',
                $message,
            );
        }
    }

    /**
     * @param array<int, array{id: string, label: string, status: string, message: string}> $checks
     */
    protected function add(array &$checks, string $id, string $label, string $status, string $message): void
    {
        $checks[] = compact('id', 'label', 'status', 'message');
    }

    protected function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    protected function isConfiguredSecret(mixed $value): bool
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return false;
        }

        $value = strtolower(trim((string) $value));

        return ! str_starts_with($value, 'your_')
            && ! str_starts_with($value, 'test-')
            && ! in_array($value, ['token', 'secret', 'api-token', 'api-secret'], true);
    }
}
