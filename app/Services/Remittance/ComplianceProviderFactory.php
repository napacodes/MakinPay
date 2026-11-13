<?php

namespace App\Services\Remittance;

use App\Contracts\Remittance\ComplianceProvider;
use App\Services\Remittance\Providers\LocalComplianceProvider;
use App\Services\Remittance\Providers\RemoteComplianceProvider;
use InvalidArgumentException;

class ComplianceProviderFactory
{
    public function make(): ComplianceProvider
    {
        return match ((string) config('remittance.compliance.driver', 'local')) {
            'local'  => app(LocalComplianceProvider::class),
            'remote' => app(RemoteComplianceProvider::class),
            default  => throw new InvalidArgumentException(__('The remittance compliance provider is not configured.')),
        };
    }
}
