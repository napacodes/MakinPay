<?php

declare(strict_types=1);

namespace App\Contracts\MobileRecharge;

/**
 * A recharge provider with a catalogue: the bundles an operator sells, each
 * bought by id rather than by a typed amount.
 */
interface ListsRechargePlans
{
    /**
     * Every plan the operator offers, priced in [$currency] where the
     * provider can, with the operator's own local price beside it.
     *
     * @return list<array{id: string, kind: string, name: string, description: string|null, amount: string, currency: string, local_amount: string|null, local_currency: string|null, validity: string|null, tags: list<string>}>
     */
    public function plans(string $operatorId, string $country, string $currency): array;

    /**
     * One plan by the id [plans] gave it, or null when it is not on offer.
     *
     * @return array{id: string, kind: string, name: string, description: string|null, amount: string, currency: string, local_amount: string|null, local_currency: string|null, validity: string|null, tags: list<string>, operator_id: string}|null
     */
    public function plan(string $planId, string $country, string $currency): ?array;
}
