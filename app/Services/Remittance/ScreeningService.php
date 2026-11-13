<?php

namespace App\Services\Remittance;

use App\Models\Beneficiary;
use App\Models\RemittanceTransfer;
use App\Models\User;
use App\Support\DecimalMoney;

class ScreeningService
{
    public function __construct(protected ?ComplianceProviderFactory $providers = null) {}

    /**
     * Hard-coded high-risk country list. Replace with a config-driven list
     * or live OFAC/UN feed in production.
     *
     * @var array<int, string>
     */
    /**
     * @return array<string, mixed>
     */
    public function screen(
        User $sender,
        Beneficiary $beneficiary,
        string|int|float $sendAmountSourceCurrency,
        int $requiredKycTier = 0,
        string $sourceCurrency = 'USD',
    ): array {
        $reasons = [];
        $score   = 0;

        $blockedCountries = (array) config('remittance.compliance.blocked_countries', []);

        if (in_array(strtoupper($beneficiary->country_code), $blockedCountries, true)) {
            $reasons[] = __('Beneficiary country is on the restricted list.');
            $score += 100;
        }

        // Treat KYC verification as "tier 1". A corridor requiring tier >= 1
        // must have a verified sender; tier 0 corridors skip KYC entirely.
        if ($requiredKycTier >= 1 && ! $sender->isKycVerified()) {
            $reasons[] = __('Sender KYC is not approved for this corridor (requires tier :tier).', ['tier' => $requiredKycTier]);
            $score += 80;
        }

        $limits = config('remittance.compliance.tiers.'.$requiredKycTier);

        if (! is_array($limits)) {
            $reasons[] = __('The required compliance tier is not configured.');
            $score += 100;

            return $this->result($reasons, $score, $requiredKycTier);
        }

        if (DecimalMoney::compare($sendAmountSourceCurrency, $limits['per_transaction']) > 0) {
            $reasons[] = __('Amount exceeds the :cap limit for tier :tier.', [
                'cap'  => number_format((float) $limits['per_transaction'], 2),
                'tier' => $requiredKycTier,
            ]);
            $score += 60;
        }

        $activeStatuses = ['pending', 'submitted', 'processing', 'completed'];
        $baseQuery      = RemittanceTransfer::query()
            ->where('user_id', $sender->id)
            ->where('source_currency', strtoupper($sourceCurrency))
            ->whereIn('status', $activeStatuses);
        $dailyAmount   = (string) (clone $baseQuery)->where('created_at', '>=', now()->startOfDay())->sum('send_amount');
        $monthlyAmount = (string) (clone $baseQuery)->where('created_at', '>=', now()->startOfMonth())->sum('send_amount');
        $dailyCount    = (clone $baseQuery)->where('created_at', '>=', now()->startOfDay())->count();
        $monthlyCount  = (clone $baseQuery)->where('created_at', '>=', now()->startOfMonth())->count();

        if (DecimalMoney::compare(DecimalMoney::add($dailyAmount, $sendAmountSourceCurrency), $limits['daily']) > 0
            || $dailyCount + 1                                                                                  > (int) $limits['daily_count']) {
            $reasons[] = __('Daily remittance limit exceeded.');
            $score += 80;
        }

        if (DecimalMoney::compare(DecimalMoney::add($monthlyAmount, $sendAmountSourceCurrency), $limits['monthly']) > 0
            || $monthlyCount + 1                                                                                    > (int) $limits['monthly_count']) {
            $reasons[] = __('Monthly remittance limit exceeded.');
            $score += 80;
        }

        if ($reasons !== [] || $score >= 80) {
            return $this->result($reasons, $score, $requiredKycTier);
        }

        $external = ($this->providers ?? new ComplianceProviderFactory)->make()->screen(
            sender: $sender,
            beneficiary: $beneficiary,
            amount: (string) $sendAmountSourceCurrency,
            currency: $sourceCurrency,
        );

        if (! $external->passed) {
            $reasons[] = $external->reason ?? __('External compliance screening did not clear this transfer.');
            $score     = max($score, $external->riskScore);
        }

        return $this->result($reasons, $score, $requiredKycTier, $external);
    }

    /**
     * @param  array<int, string>   $reasons
     * @return array<string, mixed>
     */
    protected function result(array $reasons, int $score, int $requiredKycTier, ?ComplianceDecision $external = null): array
    {
        return [
            'passed'        => $reasons === [] && $score < 80,
            'reason'        => $reasons === [] ? null : implode(' ', $reasons),
            'risk_score'    => $score,
            'required_tier' => $requiredKycTier,
            'checked_at'    => now()->toIso8601String(),
            'external'      => $external?->toArray(),
        ];
    }
}
