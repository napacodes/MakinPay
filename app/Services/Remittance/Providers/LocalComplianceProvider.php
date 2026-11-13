<?php

namespace App\Services\Remittance\Providers;

use App\Contracts\Remittance\ComplianceProvider;
use App\Models\Beneficiary;
use App\Models\User;
use App\Services\Remittance\ComplianceDecision;
use Illuminate\Support\Facades\App;
use RuntimeException;

class LocalComplianceProvider implements ComplianceProvider
{
    public function code(): string
    {
        return 'local';
    }

    public function healthCheck(): array
    {
        if (App::environment('production') && config('remittance.compliance.require_remote_in_production', true)) {
            return ['ok' => false, 'message' => __('Local compliance screening is not allowed in production.')];
        }

        return ['ok' => true, 'message' => __('Local non-production compliance adapter is available.')];
    }

    public function screen(
        User $sender,
        Beneficiary $beneficiary,
        string $amount,
        string $currency,
    ): ComplianceDecision {
        if (App::environment('production') && config('remittance.compliance.require_remote_in_production', true)) {
            throw new RuntimeException(__('External AML and sanctions screening is not configured.'));
        }

        return new ComplianceDecision(
            passed: true,
            decision: 'clear',
            riskScore: 0,
            provider: $this->code(),
            reason: App::environment('production') ? null : __('Local non-production screening adapter.'),
        );
    }
}
