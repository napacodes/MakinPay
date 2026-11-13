<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Remittance;

use App\Enums\PayoutMethod;
use App\Models\RemittanceCorridor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One corridor as the app's "Send abroad" screen draws it: where from, where
 * to, how the money can be collected, and the window an amount must fall in.
 *
 * The fee and the FX spread are deliberately absent, unlike the integration
 * API's corridor list. The app never prices a transfer itself - it asks for a
 * quote - so sending the inputs would only invite a client to compute a figure
 * that disagrees with the one it is charged.
 *
 * Each payout method carries the account fields its recipient form needs,
 * from PayoutMethod::requiredFields(), which is the same list the recipient
 * POST enforces. One field is filtered by corridor: `stripe_account_id` only
 * means anything to the Stripe Connect driver, so a customer sending through
 * any other partner is never asked for it.
 *
 * @property-read RemittanceCorridor $resource
 */
class RemittanceCorridorResource extends JsonResource
{
    /**
     * The payout driver code that reads `stripe_account_id`.
     */
    private const STRIPE_CONNECT_GATEWAY = 'stripe_connect';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $corridor    = $this->resource;
        $source      = strtoupper((string) $corridor->source_currency);
        $destination = strtoupper((string) $corridor->destination_country);

        return [
            'id'     => $corridor->id,
            'name'   => $corridor->name,
            'source' => [
                'country'  => strtoupper((string) $corridor->source_country),
                'currency' => $source,
            ],
            'destination' => [
                'country' => $destination,
                // Null rather than the code when the country list does not
                // know it, so the app can tell "no name" from a name.
                'country_name' => getCountryByCode($destination)['name'] ?? null,
                'currency'     => strtoupper((string) $corridor->destination_currency),
            ],
            'payout_methods' => array_map(
                fn (PayoutMethod $method): array => [
                    'key'    => $method->value,
                    'label'  => $method->label(),
                    'fields' => $this->fields($method, (string) $corridor->payout_gateway),
                ],
                $corridor->payoutMethodEnums(),
            ),
            // In the currency the customer types in, which is the source.
            'limits' => [
                'min' => RemittanceFigures::amount(RemittanceFigures::raw($corridor, 'min_amount'), $source),
                'max' => RemittanceFigures::amount(RemittanceFigures::raw($corridor, 'max_amount'), $source),
            ],
            'quote_ttl_minutes' => (int) $corridor->quote_ttl_minutes,
        ];
    }

    /**
     * @return list<array{name: string, label: string, required: bool}>
     */
    private function fields(PayoutMethod $method, string $gateway): array
    {
        return array_values(array_filter(
            $method->requiredFields(),
            static fn (array $field): bool => $field['name'] !== 'stripe_account_id'
                || $gateway === self::STRIPE_CONNECT_GATEWAY,
        ));
    }
}
