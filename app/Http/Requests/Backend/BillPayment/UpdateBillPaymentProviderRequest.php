<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\BillPayment;

/**
 * The same rules as a new provider, without the code and the driver.
 *
 * Both are fixed once the provider exists: payments snapshot the code, and
 * billers were configured against what the driver can do. Neither is in the
 * validated data, so a submitted value is ignored rather than applied.
 */
class UpdateBillPaymentProviderRequest extends StoreBillPaymentProviderRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function identityRules(): array
    {
        return [];
    }
}
