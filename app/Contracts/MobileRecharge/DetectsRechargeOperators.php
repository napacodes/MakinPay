<?php

declare(strict_types=1);

namespace App\Contracts\MobileRecharge;

/**
 * A recharge provider that can say which operator a phone number belongs
 * to, so the customer never has to type "Grameenphone".
 */
interface DetectsRechargeOperators
{
    /**
     * @return array{id: string, name: string, prepaid?: bool, logo?: string|null}|null
     */
    public function detectOperator(string $phoneNumber, string $country): ?array;
}
