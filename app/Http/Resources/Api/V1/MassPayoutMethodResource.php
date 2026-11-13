<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\FeeMode;
use App\Models\User;
use App\Models\WithdrawMethod;
use App\Services\MassPayment\MassPayoutAccount;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One withdraw method the caller may send a mass payout through from the
 * wallet asked about: what to call it, the account fields each line needs,
 * and the charge as the caller's own role pays it.
 *
 * The charge components are what the draft prices with; the app shows them
 * as guidance and never computes a figure it sends back. Limits are the
 * platform's per-payout bounds in the site currency.
 *
 * @property-read WithdrawMethod $resource
 */
class MassPayoutMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $method   = $this->resource;
        $user     = $request->user();
        $charges  = $user instanceof User ? $method->chargeComponentsFor($user) : null;
        $accounts = app(MassPayoutAccount::class);
        $currency = strtoupper(trim((string) $method->currency));

        return [
            'id'              => (int) $method->id,
            'name'            => (string) $method->name,
            'currency'        => $currency,
            'logo'            => asset($method->logo_alt),
            'processing_time' => $method->processing_time,
            'fee_mode'        => FeeMode::withdraw()->value,

            'charge' => $charges === null ? null : [
                'fixed'   => ApiMoney::amount((string) $charges['fixed'], $currency),
                'percent' => (string) $charges['percent'],
                'min'     => is_numeric($charges['min']) ? ApiMoney::amount((string) $charges['min'], $currency) : null,
                'max'     => is_numeric($charges['max']) ? ApiMoney::amount((string) $charges['max'], $currency) : null,
            ],

            'limits' => [
                'min'      => is_numeric($method->getAttributes()['min_withdraw'] ?? null) ? (string) $method->getAttributes()['min_withdraw'] : null,
                'max'      => is_numeric($method->getAttributes()['max_withdraw'] ?? null) ? (string) $method->getAttributes()['max_withdraw'] : null,
                'currency' => siteCurrency(),
            ],

            'fields' => array_map(static fn (array $field): array => [
                'name'     => (string) $field['name'],
                'label'    => is_scalar($field['label'] ?? null) && trim((string) $field['label']) !== '' ? (string) $field['label'] : (string) $field['name'],
                'type'     => (string) $field['type'],
                'required' => $field['validation'] === 'required',
            ], $accounts->fields($method)),
        ];
    }
}
