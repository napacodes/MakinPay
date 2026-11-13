<?php

namespace App\Services\Remittance\Providers;

use App\Contracts\Remittance\ComplianceProvider;
use App\Models\Beneficiary;
use App\Models\User;
use App\Services\Remittance\ComplianceDecision;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RemoteComplianceProvider implements ComplianceProvider
{
    public function code(): string
    {
        return 'remote';
    }

    public function healthCheck(): array
    {
        $endpoint = trim((string) config('remittance.compliance.remote.health_endpoint'));
        $token    = trim((string) config('remittance.compliance.remote.token'));

        if ($endpoint === '' || $token === '') {
            return ['ok' => false, 'message' => __('External compliance health-check credentials are missing.')];
        }

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->connectTimeout((int) config('remittance.compliance.remote.connect_timeout_seconds', 3))
                ->timeout((int) config('remittance.compliance.remote.timeout_seconds', 8))
                ->retry(
                    (int) config('remittance.compliance.remote.retry_attempts', 2),
                    (int) config('remittance.compliance.remote.retry_delay_ms', 250),
                    throw: false,
                )
                ->get($endpoint);
        } catch (Throwable) {
            return ['ok' => false, 'message' => __('External compliance health check could not connect.')];
        }

        return $response->successful()
            ? ['ok' => true, 'message' => __('External compliance provider is reachable and authenticated.')]
            : ['ok' => false, 'message' => __('External compliance health check was rejected.')];
    }

    /**
     * @throws ConnectionException
     */
    public function screen(
        User $sender,
        Beneficiary $beneficiary,
        string $amount,
        string $currency,
    ): ComplianceDecision {
        $endpoint = trim((string) config('remittance.compliance.remote.endpoint'));
        $token    = trim((string) config('remittance.compliance.remote.token'));

        if ($endpoint === '' || $token === '') {
            throw new RuntimeException(__('External AML and sanctions screening credentials are missing.'));
        }

        $response = Http::acceptJson()
            ->withToken($token)
            ->connectTimeout((int) config('remittance.compliance.remote.connect_timeout_seconds', 3))
            ->timeout((int) config('remittance.compliance.remote.timeout_seconds', 8))
            ->retry(
                (int) config('remittance.compliance.remote.retry_attempts', 2),
                (int) config('remittance.compliance.remote.retry_delay_ms', 250),
                throw: false,
            )
            ->post($endpoint, [
                'sender' => [
                    'reference'     => (string) $sender->id,
                    'first_name'    => (string) $sender->first_name,
                    'last_name'     => (string) $sender->last_name,
                    'country_code'  => strtoupper((string) $sender->country),
                    'date_of_birth' => $sender->birthday ? (string) $sender->birthday : null,
                ],
                'beneficiary' => [
                    'reference'    => (string) $beneficiary->id,
                    'full_name'    => (string) $beneficiary->full_name,
                    'country_code' => strtoupper((string) $beneficiary->country_code),
                ],
                'transaction' => [
                    'amount'   => $amount,
                    'currency' => strtoupper($currency),
                ],
                'checks' => ['sanctions', 'pep', 'adverse_media', 'name_screening'],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(__('External AML and sanctions screening is temporarily unavailable.'));
        }

        $decision = strtolower(trim((string) $response->json('decision')));

        if (! in_array($decision, ['clear', 'review', 'blocked'], true)) {
            throw new RuntimeException(__('External AML and sanctions screening returned an invalid decision.'));
        }

        $reason = $response->json('reason');
        $reason = is_scalar($reason) && trim((string) $reason) !== ''
            ? Str::limit(strip_tags(trim((string) $reason)), 240)
            : null;

        return new ComplianceDecision(
            passed: $decision === 'clear',
            decision: $decision,
            riskScore: min(100, max(0, (int) $response->json('risk_score', $decision === 'clear' ? 0 : 100))),
            provider: $this->code(),
            providerReference: is_scalar($response->json('reference')) ? (string) $response->json('reference') : null,
            reason: $reason ?? ($decision === 'clear' ? null : __('External compliance screening requires review.')),
        );
    }
}
