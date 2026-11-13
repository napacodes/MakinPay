<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Sync;

use App\Enums\BillPayment\BillerAmountType;
use InvalidArgumentException;

/**
 * One biller as a provider's catalogue lists it, already mapped to local
 * terms by the driver.
 *
 *   externalCode           the provider's code, unique per provider; the
 *                          synchroniser matches the local biller on it
 *   currency               the biller's currency, when the provider names it
 *   providerCategory       the provider's own category code, mapped to a
 *                          local bill category through config
 *   amountType             'fixed' (pick an item) or 'open' (type an amount)
 *   minAmount, maxAmount   the provider's limits for an open biller, as
 *                          exact decimal strings
 *   items                  the packages of a fixed biller
 *   supportsLookup         whether the provider can look up customers here
 *   skipReason             set when this biller must not be imported (one of
 *                          the BillerSyncReport::SKIP_* reasons); the biller
 *                          is still yielded so the report can count it
 *   meta                   provider facts kept on the biller's external meta
 *
 * Money is never a float here: every amount is a decimal string.
 */
readonly class SyncedBiller
{
    /**
     * @param list<SyncedBillerItem> $items
     * @param array<string, mixed>   $meta
     */
    public function __construct(
        public string $externalCode,
        public string $name,
        public string $country,
        public ?string $currency,
        public string $providerCategory,
        public string $amountType,
        public ?string $minAmount = null,
        public ?string $maxAmount = null,
        public array $items = [],
        public ?string $customerNumberLabel = null,
        public ?string $customerNumberPattern = null,
        public ?string $tokenLabel = null,
        public bool $supportsLookup = false,
        public ?string $skipReason = null,
        public array $meta = [],
    ) {
        if (trim($externalCode) === '') {
            throw new InvalidArgumentException('A synced biller needs an external code.');
        }

        if (BillerAmountType::tryFrom($amountType) === null) {
            throw new InvalidArgumentException('A synced biller amount type must be "fixed" or "open".');
        }

        foreach ([$minAmount, $maxAmount] as $limit) {
            if ($limit !== null && ! is_numeric($limit)) {
                throw new InvalidArgumentException('A synced biller limit must be a decimal string.');
            }
        }

        foreach ($items as $item) {
            if (! $item instanceof SyncedBillerItem) {
                throw new InvalidArgumentException('Synced biller items must be SyncedBillerItem instances.');
            }
        }
    }

    public function amountType(): BillerAmountType
    {
        return BillerAmountType::from($this->amountType);
    }

    public function isSkipped(): bool
    {
        return $this->skipReason !== null;
    }
}
