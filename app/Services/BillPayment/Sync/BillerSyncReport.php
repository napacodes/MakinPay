<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Sync;

/**
 * What one catalogue sync of one provider and country did, or would do on a
 * dry run.
 *
 * Unlike the other sync value objects this one accumulates: the synchroniser
 * counts into it as it walks the feed, then stores toArray() on the provider
 * for the admin screen. A run that failed carries `error` and wrote nothing.
 */
class BillerSyncReport
{
    public const SKIP_REQUIRES_INVOICE = 'requires_invoice';

    public const SKIP_INTERNATIONAL_ONLY = 'international_only';

    public const SKIP_CURRENCY_UNKNOWN = 'currency_unknown';

    public const SKIP_COUNTRY_NOT_MAPPED = 'country_not_mapped';

    /** The provider listed a biller of another country than the one synced. */
    public const SKIP_COUNTRY_MISMATCH = 'country_mismatch';

    public const SKIP_CATEGORY_UNMAPPED = 'category_unmapped';

    public const SKIP_CATEGORY_MISSING = 'category_missing';

    public const SKIP_DUPLICATE_CODE = 'duplicate_code';

    public const SKIP_PRECISION = 'precision';

    public const CONFLICT_LIMITS = 'limits_conflict';

    public const CONFLICT_CURRENCY_CHANGED = 'currency_changed';

    public int $created = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public int $disabledMissing = 0;

    public int $itemsCreated = 0;

    public int $itemsUpdated = 0;

    public int $itemsDisabled = 0;

    /**
     * @var list<array{external_code: string, kind: string}>
     */
    public array $conflicts = [];

    /**
     * @var array<string, int> skip reason => count
     */
    public array $skipped = [];

    public ?string $error = null;

    public function skip(string $reason): void
    {
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
    }

    public function conflict(string $externalCode, string $kind): void
    {
        $this->conflicts[] = ['external_code' => $externalCode, 'kind' => $kind];
    }

    public function fail(string $error): void
    {
        $this->error = $error;
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }

    /**
     * @return array{created: int, updated: int, unchanged: int, disabled_missing: int, items_created: int, items_updated: int, items_disabled: int, conflicts: list<array{external_code: string, kind: string}>, skipped: array<string, int>, error: string|null}
     */
    public function toArray(): array
    {
        return [
            'created'          => $this->created,
            'updated'          => $this->updated,
            'unchanged'        => $this->unchanged,
            'disabled_missing' => $this->disabledMissing,
            'items_created'    => $this->itemsCreated,
            'items_updated'    => $this->itemsUpdated,
            'items_disabled'   => $this->itemsDisabled,
            'conflicts'        => $this->conflicts,
            'skipped'          => $this->skipped,
            'error'            => $this->error,
        ];
    }
}
