<?php

namespace App\Services\Kyc;

use App\Contracts\Kyc\HostedKycSessionInterface;
use App\Contracts\Kyc\KycVerificationProviderInterface;
use App\Models\KycProvider;
use App\Models\KycSubmission;
use App\Services\Kyc\Providers\ManualKycProvider;
use App\Services\Kyc\Providers\ShuftiProProvider;
use App\Services\Kyc\Providers\SmileIdProvider;
use App\Services\Kyc\Providers\SumsubProvider;

/**
 * Resolves which configured KYC provider handles a given user, based on the
 * admin's per-country routing. Falls back to the manual (human review) driver
 * whenever no enabled provider covers the country.
 */
class KycProviderRegistry
{
    /**
     * Pick the highest-priority enabled provider that covers the country.
     */
    public function forCountry(?string $countryCode): KycVerificationProviderInterface
    {
        $config = $this->configForCountry($countryCode);

        return $config ? $this->make($config) : $this->fallback();
    }

    /**
     * The driver covering this country when it can host the capture flow
     * itself, or null — no coverage, or a driver that only accepts documents
     * we have already collected. A null answer means the applicant belongs on
     * the template form.
     */
    public function hostedForCountry(?string $countryCode): ?HostedKycSessionInterface
    {
        $config = $this->configForCountry($countryCode);

        if (! $config) {
            return null;
        }

        $driver = $this->make($config);

        return $driver instanceof HostedKycSessionInterface ? $driver : null;
    }

    /**
     * Resolve the provider for a submission using the applicant's country.
     */
    public function forSubmission(KycSubmission $submission): KycVerificationProviderInterface
    {
        /*
         * A template set to manual review overrides country coverage. The
         * admin has said this form is read by a person; finding a provider
         * that happens to cover the applicant's country does not change that.
         */
        if ($submission->kycTemplate?->isManualOnly()) {
            return $this->fallback();
        }

        return $this->forCountry($this->countryOf($submission));
    }

    /**
     * Resolve a driver by the KycProvider code — used by the webhook route,
     * where the provider must be found even if it was later disabled.
     */
    public function byCode(string $code): KycVerificationProviderInterface
    {
        $config = KycProvider::query()->where('code', $code)->first();

        return $config ? $this->make($config) : $this->fallback();
    }

    /**
     * Instantiate the concrete driver for a configured provider row.
     */
    public function make(KycProvider $config): KycVerificationProviderInterface
    {
        return match ($config->driver) {
            'smile_id' => new SmileIdProvider($config),
            'shufti'   => new ShuftiProProvider($config),
            'sumsub'   => new SumsubProvider($config),
            default    => $this->fallback(),
        };
    }

    /**
     * The highest-priority enabled provider row covering the country.
     */
    private function configForCountry(?string $countryCode): ?KycProvider
    {
        if (blank($countryCode)) {
            return null;
        }

        return KycProvider::query()
            ->enabled()
            ->forCountry($countryCode)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->first();
    }

    private function fallback(): KycVerificationProviderInterface
    {
        return new ManualKycProvider;
    }

    private function countryOf(KycSubmission $submission): ?string
    {
        $data = $submission->submission_data ?? [];

        return $submission->user?->country
            ?? $data['country']
            ?? $data['Country']
            ?? null;
    }
}
