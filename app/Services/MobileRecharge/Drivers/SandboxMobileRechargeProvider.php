<?php

namespace App\Services\MobileRecharge\Drivers;

use App\Contracts\MobileRecharge\DetectsRechargeOperators;
use App\Contracts\MobileRecharge\ListsRechargePlans;
use App\Contracts\MobileRecharge\MobileRechargeProviderInterface;
use App\Enums\MobileRechargeStatus;
use App\Models\MobileRecharge;
use App\Services\MobileRecharge\MobileRechargeProviderResult;
use Illuminate\Support\Str;

/**
 * The sandbox provider answers everything a real one would - a detected
 * operator and a small catalogue included - so the app's recharge screens
 * can be walked end to end with nothing configured.
 */
class SandboxMobileRechargeProvider implements DetectsRechargeOperators, ListsRechargePlans, MobileRechargeProviderInterface
{
    /** @var array<string, mixed> */
    private array $credentials = [];

    /** @var array<string, mixed> */
    private array $config = [];

    /**
     * The currency the catalogue's `amount` figures are in - what a real
     * provider bills the platform in. It used to echo whatever currency
     * the caller asked with, which made a 1.24 USD bundle read as 1.24 of
     * anything.
     */
    public const SENDER_CURRENCY = 'USD';

    /**
     * Operators by dialling prefix: enough to make the sandbox feel real.
     *
     * @var array<string, array{id: string, name: string}>
     */
    private const OPERATORS = [
        '+88017' => ['id' => 'sandbox-gp', 'name' => 'Grameenphone'],
        '+88018' => ['id' => 'sandbox-robi', 'name' => 'Robi'],
        '+88019' => ['id' => 'sandbox-banglalink', 'name' => 'Banglalink'],
        '+88016' => ['id' => 'sandbox-airtel', 'name' => 'Airtel'],
        '+44'    => ['id' => 'sandbox-vodafone', 'name' => 'Vodafone'],
        '+1'     => ['id' => 'sandbox-tmobile', 'name' => 'T-Mobile'],
    ];

    /**
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $config
     */
    public function configureFromCredentials(array $credentials, array $config = []): void
    {
        $this->credentials = $credentials;
        $this->config      = $config;
    }

    public function recharge(MobileRecharge $recharge): MobileRechargeProviderResult
    {
        $statusValue = (string) ($this->credentials['sandbox_status']
            ?? $this->config['sandbox_status']
            ?? setting('mobile_recharge_sandbox_status', config('mobile_services.recharge.sandbox_status')));

        $status = MobileRechargeStatus::tryFrom($statusValue) ?? MobileRechargeStatus::COMPLETED;

        return new MobileRechargeProviderResult(
            status: $status,
            reference: 'sandbox_'.Str::lower(Str::random(16)),
            message: $status === MobileRechargeStatus::FAILED
                ? __('Sandbox provider marked this recharge as failed.')
                : __('Sandbox provider accepted the recharge.'),
            payload: [
                'provider'     => 'sandbox',
                'phone_number' => $recharge->phone_number,
                'amount'       => $recharge->amount,
                'plan_id'      => $recharge->metadata['plan_id'] ?? null,
            ],
        );
    }

    public function detectOperator(string $phoneNumber, string $country): ?array
    {
        $digits = '+'.preg_replace('/[^0-9]/', '', $phoneNumber);

        foreach (self::OPERATORS as $prefix => $operator) {
            if (str_starts_with($digits, $prefix)) {
                return $operator + ['prepaid' => true, 'logo' => null];
            }
        }

        return null;
    }

    public function plans(string $operatorId, string $country, string $currency): array
    {
        $plans = [];
        foreach ($this->catalogue() as $plan) {
            $plans[] = $plan + ['currency' => self::SENDER_CURRENCY, 'operator_id' => $operatorId];
        }

        return $plans;
    }

    public function plan(string $planId, string $country, string $currency): ?array
    {
        foreach ($this->catalogue() as $plan) {
            if ($plan['id'] === $planId) {
                return $plan + ['currency' => self::SENDER_CURRENCY, 'operator_id' => 'sandbox'];
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, kind: string, name: string, description: string|null, amount: string, local_amount: string|null, local_currency: string|null, validity: string|null, tags: list<string>}>
     */
    private function catalogue(): array
    {
        return [
            ['id' => 'sandbox-data-3gb', 'kind' => 'data', 'name' => '3 GB', 'description' => '7 days', 'amount' => '1.24', 'local_amount' => '148', 'local_currency' => 'BDT', 'validity' => '7 days', 'tags' => []],
            ['id' => 'sandbox-data-10gb', 'kind' => 'data', 'name' => '10 GB', 'description' => '30 days · 4G/5G', 'amount' => '3.35', 'local_amount' => '398', 'local_currency' => 'BDT', 'validity' => '30 days', 'tags' => ['popular']],
            ['id' => 'sandbox-combo-25gb', 'kind' => 'combo', 'name' => '25 GB', 'description' => '30 days · +100 min', 'amount' => '5.87', 'local_amount' => '699', 'local_currency' => 'BDT', 'validity' => '30 days', 'tags' => []],
            ['id' => 'sandbox-data-60gb', 'kind' => 'data', 'name' => '60 GB', 'description' => '30 days · unlimited night', 'amount' => '10.07', 'local_amount' => '1199', 'local_currency' => 'BDT', 'validity' => '30 days', 'tags' => ['best_value']],
            ['id' => 'sandbox-minutes-300', 'kind' => 'minutes', 'name' => '300 min', 'description' => '15 days', 'amount' => '2.10', 'local_amount' => '249', 'local_currency' => 'BDT', 'validity' => '15 days', 'tags' => []],
        ];
    }
}
