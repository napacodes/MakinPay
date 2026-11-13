<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Sync;

use App\Contracts\BillPayment\SyncsBillers;
use App\Enums\BillPayment\BillerAmountType;
use App\Exceptions\BillerSyncException;
use App\Models\BillCategory;
use App\Models\Biller;
use App\Models\BillerItem;
use App\Models\BillPaymentProvider;
use App\Services\BillPayment\BillerRules;
use App\Services\BillPayment\BillPaymentProviderManager;
use App\Support\DecimalMoney;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Brings one provider's billers for one country in line with the provider's
 * catalogue, without ever deleting anything and without overriding what an
 * admin has edited.
 *
 *   - The whole feed is read before anything is written. A provider error
 *     aborts the run with nothing written and nothing disabled.
 *   - A new biller is created switched off, with the provider's name, label,
 *     pattern and limits. From then on those belong to the admin.
 *   - On every run the provider owns only the amount type and the external
 *     meta. Admin limits are narrowed into the provider's range, never
 *     widened; a range left upside down disables the biller. A currency the
 *     provider changed is never rewritten: the biller is disabled and the
 *     conflict reported.
 *   - A package's name and price follow the provider. A new package under an
 *     existing biller arrives switched off.
 *   - A biller or package missing from the feed is switched off and marked
 *     missing_since. Nothing is ever deleted, because payments point at
 *     billers, and nothing is ever switched on.
 *
 * A dry run counts the same way and writes no biller or package. Every run,
 * dry or not, records its report on the provider's sync_state.
 */
class BillerSynchronizer
{
    public function __construct(private readonly BillPaymentProviderManager $providers) {}

    /**
     * @param list<string> $localCategoryCodes bill_categories codes to sync; empty means every mapped category
     */
    public function sync(BillPaymentProvider $provider, string $country, array $localCategoryCodes = [], bool $dryRun = false): BillerSyncReport
    {
        $report  = new BillerSyncReport;
        $country = strtoupper(trim($country));

        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $report->fail(__('Choose a two-letter country code, such as NG or KE.'));

            return $report;
        }

        try {
            $driver = $this->providers->driverFor($provider);
        } catch (Throwable $exception) {
            report($exception);
            $report->fail(__('The provider driver could not be loaded.'));

            return $this->record($provider, $country, $report, $dryRun);
        }

        if (! $driver instanceof SyncsBillers) {
            $report->fail(__('This provider cannot sync billers.'));

            return $this->record($provider, $country, $report, $dryRun);
        }

        $map = $this->categoryMap((string) $provider->driver, $driver, $localCategoryCodes);

        if ($map === []) {
            $report->fail(__('There is no catalogue category to sync. Choose at least one category the provider maps.'));

            return $this->record($provider, $country, $report, $dryRun);
        }

        /** @var array<string, int> $categoryIds local code => id */
        $categoryIds = BillCategory::query()
            ->whereIn('code', array_values(array_unique($map)))
            ->pluck('id', 'code')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $feed = $this->drain($driver, $country, array_keys($map), $report);

        if ($feed === null) {
            return $this->record($provider, $country, $report, $dryRun);
        }

        try {
            $seen = $this->apply($provider, $country, $feed, $map, $categoryIds, $report, $dryRun);

            $this->disableMissing(
                $provider,
                $country,
                array_map('strval', array_keys(array_filter($map, static fn (string $localCode): bool => isset($categoryIds[$localCode])))),
                $seen,
                $report,
                $dryRun,
            );
        } catch (Throwable $exception) {
            report($exception);
            $report->fail(__('The sync stopped part way because a biller could not be saved. Billers saved before it keep their changes; nothing was disabled as missing.'));
        }

        return $this->record($provider, $country, $report, $dryRun);
    }

    /**
     * Provider category => local category code, for the categories this run
     * covers: mapped in config, known to the driver, and asked for.
     *
     * @param  list<string>          $localCategoryCodes
     * @return array<string, string>
     */
    private function categoryMap(string $driverKey, SyncsBillers $driver, array $localCategoryCodes): array
    {
        $configured = (array) config('bill_payments.category_map.'.$driverKey, []);
        $known      = array_flip(array_map('strval', $driver->providerCategories()));
        $wanted     = array_values(array_filter(array_map(
            static fn (mixed $code): string => strtolower(trim((string) $code)),
            $localCategoryCodes,
        )));

        $map = [];

        foreach ($configured as $providerCategory => $localCode) {
            $providerCategory = (string) $providerCategory;
            $localCode        = strtolower(trim((string) $localCode));

            if ($localCode === '' || ! isset($known[$providerCategory])) {
                continue;
            }

            if ($wanted === [] || in_array($localCode, $wanted, true)) {
                $map[$providerCategory] = $localCode;
            }
        }

        return $map;
    }

    /**
     * The whole feed, or null when it could not be read (the reason is on
     * the report).
     *
     * @param  list<string>            $providerCategories
     * @return list<SyncedBiller>|null
     */
    private function drain(SyncsBillers $driver, string $country, array $providerCategories, BillerSyncReport $report): ?array
    {
        try {
            $feed = [];

            foreach ($driver->fetchBillers($country, $providerCategories) as $biller) {
                if (! $biller instanceof SyncedBiller) {
                    throw new BillerSyncException(__('The provider catalogue could not be read.'));
                }

                $feed[] = $biller;
            }

            return $feed;
        } catch (BillerSyncException $exception) {
            $report->fail($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $report->fail(__('The provider catalogue could not be read.'));
        }

        return null;
    }

    /**
     * Write every importable biller of the feed, and say which external codes
     * it holds.
     *
     * @param  list<SyncedBiller>    $feed
     * @param  array<string, string> $map
     * @param  array<string, int>    $categoryIds
     * @return array<string, true>
     */
    private function apply(BillPaymentProvider $provider, string $country, array $feed, array $map, array $categoryIds, BillerSyncReport $report, bool $dryRun): array
    {
        $claimed = [];
        $seen    = [];

        foreach ($feed as $synced) {
            $code = trim($synced->externalCode);

            if (isset($claimed[$code])) {
                $report->skip(BillerSyncReport::SKIP_DUPLICATE_CODE);

                continue;
            }

            $claimed[$code] = true;
            $decimals       = null;
            $reason         = $this->skipReason($synced, $country, $map, $categoryIds, $decimals);

            if ($reason !== null || $decimals === null) {
                $report->skip($reason ?? BillerSyncReport::SKIP_CURRENCY_UNKNOWN);

                continue;
            }

            $seen[$code] = true;
            $categoryId  = $categoryIds[$map[$synced->providerCategory]];

            $tally = $dryRun
                ? $this->upsert($provider, $synced, $code, $categoryId, $decimals, true)
                : $this->persist($provider, $synced, $code, $categoryId, $decimals);

            $this->merge($report, $tally);
        }

        return $seen;
    }

    /**
     * Why this biller is not imported, or null. On null, $decimals holds the
     * biller currency's precision.
     *
     * @param array<string, string> $map
     * @param array<string, int>    $categoryIds
     */
    private function skipReason(SyncedBiller $synced, string $country, array $map, array $categoryIds, ?int &$decimals): ?string
    {
        if ($synced->skipReason !== null) {
            return $synced->skipReason;
        }

        if (strtoupper(trim($synced->country)) !== $country) {
            return BillerSyncReport::SKIP_COUNTRY_MISMATCH;
        }

        $localCode = $map[$synced->providerCategory] ?? null;

        if ($localCode === null) {
            return BillerSyncReport::SKIP_CATEGORY_UNMAPPED;
        }

        if (! isset($categoryIds[$localCode])) {
            return BillerSyncReport::SKIP_CATEGORY_MISSING;
        }

        $currency = $synced->currency === null ? '' : strtoupper(trim($synced->currency));

        // decimalsIfKnown('') would answer the default currency's precision.
        $decimals = $currency === '' ? null : Money::decimalsIfKnown($currency);

        return $decimals === null ? BillerSyncReport::SKIP_CURRENCY_UNKNOWN : null;
    }

    /**
     * One biller in its own transaction. Two runs creating the same biller
     * at once are settled by billers_provider_external_unique: the loser
     * reads the winner's row and updates it instead.
     *
     * @return array<string, mixed>
     */
    private function persist(BillPaymentProvider $provider, SyncedBiller $synced, string $code, int $categoryId, int $decimals): array
    {
        $write = fn (): array => DB::transaction(fn (): array => $this->upsert($provider, $synced, $code, $categoryId, $decimals, false));

        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            return $write();
        }
    }

    /**
     * Create or update one biller and its packages, and count what changed.
     * Nothing is written on a dry run.
     *
     * @return array<string, mixed>
     */
    private function upsert(BillPaymentProvider $provider, SyncedBiller $synced, string $code, int $categoryId, int $decimals, bool $dryRun): array
    {
        $tally = self::emptyTally();
        $query = Biller::query()
            ->where('bill_payment_provider_id', $provider->id)
            ->where('external_code', $code);

        $biller = $dryRun ? $query->first() : $query->lockForUpdate()->first();
        $items  = $this->payableItems($synced, $decimals, $tally);

        if ($biller === null) {
            $this->create($provider, $synced, $code, $categoryId, $items, $tally, $dryRun);

            return $tally;
        }

        $oldMeta = is_array($biller->external_meta) ? $biller->external_meta : [];

        if (strtoupper(trim((string) $biller->currency)) !== strtoupper(trim((string) $synced->currency))) {
            $newMeta                  = $oldMeta;
            $newMeta['source']        = 'sync';
            $newMeta['sync_conflict'] = BillerSyncReport::CONFLICT_CURRENCY_CHANGED;
            unset($newMeta['missing_since'], $newMeta['synced_at']);

            $biller->status       = false;
            $tally['conflicts'][] = ['external_code' => $code, 'kind' => BillerSyncReport::CONFLICT_CURRENCY_CHANGED];

            $this->saveBiller($biller, $oldMeta, $newMeta, $tally, $dryRun);

            return $tally;
        }

        $conflict = null;

        if ($biller->amount_type?->value !== $synced->amountType) {
            $biller->amount_type = BillerAmountType::from($synced->amountType);
        }

        if ($synced->amountType === BillerAmountType::Fixed->value) {
            $this->setDecimal($biller, 'min_amount', null);
            $this->setDecimal($biller, 'max_amount', null);
        } elseif (! $this->narrowLimits($biller, $synced)) {
            $conflict             = BillerSyncReport::CONFLICT_LIMITS;
            $biller->status       = false;
            $tally['conflicts'][] = ['external_code' => $code, 'kind' => $conflict];
        }

        $missingItems = $this->syncItems($biller, $items, $oldMeta, $tally, $dryRun);

        $this->saveBiller($biller, $oldMeta, $this->meta($synced, $missingItems, $conflict), $tally, $dryRun);

        return $tally;
    }

    /**
     * The feed's packages that can be stored at the biller currency's
     * precision. A price with more decimals than the currency has is never
     * rounded; the package is left out and counted as `precision`.
     *
     * @param  array<string, mixed>   $tally
     * @return list<SyncedBillerItem>
     */
    private function payableItems(SyncedBiller $synced, int $decimals, array &$tally): array
    {
        if ($synced->amountType !== BillerAmountType::Fixed->value) {
            return [];
        }

        $items = [];
        $seen  = [];

        foreach ($synced->items as $item) {
            $code = trim($item->externalCode);

            if (isset($seen[$code])) {
                continue;
            }

            $seen[$code] = true;

            if (self::fractionDigits($item->amount) > $decimals) {
                $tally['skipped'][] = BillerSyncReport::SKIP_PRECISION;

                continue;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param list<SyncedBillerItem> $items
     * @param array<string, mixed>   $tally
     */
    private function create(BillPaymentProvider $provider, SyncedBiller $synced, string $code, int $categoryId, array $items, array &$tally, bool $dryRun): void
    {
        $fixed   = $synced->amountType === BillerAmountType::Fixed->value;
        $label   = self::text($synced->customerNumberLabel, 80);
        $pattern = self::text($synced->customerNumberPattern, 255);

        $tally['created']++;
        $tally['items_created'] += count($items);

        if ($dryRun) {
            return;
        }

        $biller = Biller::query()->create([
            'bill_category_id'         => $categoryId,
            'bill_payment_provider_id' => $provider->id,
            'name'                     => mb_substr(trim($synced->name), 0, 120),
            'country'                  => strtoupper(trim($synced->country)),
            'currency'                 => strtoupper(trim((string) $synced->currency)),
            'customer_number_label'    => $label ?? __('Account number'),
            'customer_number_pattern'  => $pattern !== null && BillerRules::compiles($pattern) ? $pattern : null,
            'amount_type'              => $synced->amountType,
            'min_amount'               => $fixed ? null : self::stored($synced->minAmount),
            'max_amount'               => $fixed ? null : self::stored($synced->maxAmount),
            'token_label'              => self::text($synced->tokenLabel, 80),
            'external_code'            => $code,
            'external_meta'            => $this->meta($synced, [], null) + ['synced_at' => now()->toIso8601String()],
            'status'                   => false,
            'sort_order'               => 0,
        ]);

        foreach ($items as $position => $item) {
            BillerItem::query()->create([
                'biller_id'     => $biller->id,
                'name'          => mb_substr(trim($item->name), 0, 120),
                'external_code' => trim($item->externalCode),
                'amount'        => self::stored($item->amount),
                'status'        => true,
                'sort_order'    => $position,
            ]);
        }
    }

    /**
     * Narrow the admin's open range into the provider's: the larger minimum
     * and the smaller maximum, never wider than the admin set. False, with
     * nothing changed, when the narrowed range would be upside down.
     */
    private function narrowLimits(Biller $biller, SyncedBiller $synced): bool
    {
        $adminMin = $biller->min_amount === null ? null : (string) $biller->min_amount;
        $adminMax = $biller->max_amount === null ? null : (string) $biller->max_amount;

        $min = match (true) {
            $synced->minAmount === null => $adminMin,
            $adminMin          === null => $synced->minAmount,
            default                     => DecimalMoney::compare($adminMin, $synced->minAmount) >= 0 ? $adminMin : $synced->minAmount,
        };

        $max = match (true) {
            $synced->maxAmount === null => $adminMax,
            $adminMax          === null => $synced->maxAmount,
            default                     => DecimalMoney::compare($adminMax, $synced->maxAmount) <= 0 ? $adminMax : $synced->maxAmount,
        };

        if ($min !== null && $max !== null && DecimalMoney::compare($min, $max) > 0) {
            return false;
        }

        $this->setDecimal($biller, 'min_amount', $min);
        $this->setDecimal($biller, 'max_amount', $max);

        return true;
    }

    /**
     * Bring an existing biller's packages in line with the feed and return
     * the external-meta entries of the packages the feed no longer lists.
     *
     * @param  list<SyncedBillerItem>              $items
     * @param  array<string, mixed>                $oldMeta
     * @param  array<string, mixed>                $tally
     * @return array<string, array<string, mixed>>
     */
    private function syncItems(Biller $biller, array $items, array $oldMeta, array &$tally, bool $dryRun): array
    {
        $existing = BillerItem::query()
            ->where('biller_id', $biller->id)
            ->whereNotNull('external_code')
            ->get()
            ->keyBy(static fn (BillerItem $item): string => (string) $item->external_code);

        $nextPosition = ((int) BillerItem::query()->where('biller_id', $biller->id)->max('sort_order')) + 1;
        $listed       = [];

        foreach ($items as $item) {
            $code          = trim($item->externalCode);
            $listed[$code] = true;
            $row           = $existing->get($code);

            if ($row === null) {
                $tally['items_created']++;

                if (! $dryRun) {
                    BillerItem::query()->create([
                        'biller_id'     => $biller->id,
                        'name'          => mb_substr(trim($item->name), 0, 120),
                        'external_code' => $code,
                        'amount'        => self::stored($item->amount),
                        'status'        => false,
                        'sort_order'    => $nextPosition++,
                    ]);
                }

                continue;
            }

            $name = mb_substr(trim($item->name), 0, 120);

            if ($row->name !== $name) {
                $row->name = $name;
            }

            if (DecimalMoney::compare((string) $row->amount, $item->amount) !== 0) {
                $row->amount = self::stored($item->amount);
            }

            if ($row->isDirty()) {
                $tally['items_updated']++;

                if (! $dryRun) {
                    $row->save();
                }
            }
        }

        $oldItemsMeta = is_array($oldMeta['items'] ?? null) ? $oldMeta['items'] : [];
        $missing      = [];

        foreach ($existing as $code => $row) {
            if (isset($listed[$code])) {
                continue;
            }

            $entry                   = is_array($oldItemsMeta[$code] ?? null) ? $oldItemsMeta[$code] : [];
            $entry['missing_since']  = is_string($entry['missing_since'] ?? null) ? $entry['missing_since'] : now()->toIso8601String();
            $missing[(string) $code] = $entry;

            if ($row->status) {
                $tally['items_disabled']++;

                if (! $dryRun) {
                    $row->forceFill(['status' => false])->save();
                }
            }
        }

        return $missing;
    }

    /**
     * The provider-owned external meta of a synced biller.
     *
     * @param  array<string, array<string, mixed>> $missingItems
     * @return array<string, mixed>
     */
    private function meta(SyncedBiller $synced, array $missingItems, ?string $conflict): array
    {
        $items = is_array($synced->meta['items'] ?? null) ? $synced->meta['items'] : [];

        foreach ($missingItems as $code => $entry) {
            $items[$code] = $entry;
        }

        $meta = [
            'source'           => 'sync',
            'provider_type'    => $synced->meta['provider_type'] ?? $synced->providerCategory,
            'service_type'     => $synced->meta['service_type']  ?? null,
            'fees'             => $synced->meta['fees']          ?? null,
            'fx'               => $synced->meta['fx']            ?? null,
            'requires_invoice' => (bool) ($synced->meta['requires_invoice'] ?? false),
            'customer_lookup'  => $synced->supportsLookup,
            'items'            => $items,
        ];

        if ($conflict !== null) {
            $meta['sync_conflict'] = $conflict;
        }

        return $meta;
    }

    /**
     * Save the biller only when something really changed. `synced_at` moves
     * only with a change, so a replay of an unchanged feed leaves the row,
     * and its updated_at, alone.
     *
     * @param array<string, mixed> $oldMeta
     * @param array<string, mixed> $newMeta
     * @param array<string, mixed> $tally
     */
    private function saveBiller(Biller $biller, array $oldMeta, array $newMeta, array &$tally, bool $dryRun): void
    {
        $previous = $oldMeta;
        unset($previous['synced_at']);

        if (self::canonical($previous) !== self::canonical($newMeta)) {
            $biller->external_meta = $newMeta + ['synced_at' => now()->toIso8601String()];
        }

        if (! $biller->isDirty()) {
            $tally['unchanged']++;

            return;
        }

        $tally['updated']++;

        if (! $dryRun) {
            $biller->save();
        }
    }

    /**
     * Switch off and mark every biller of this provider and country, synced
     * from one of this run's provider categories, that the feed no longer
     * lists. Never deleted: payments point at it.
     *
     * Scoped by the provider category stored on the biller at sync
     * (`external_meta.provider_type`), never by its local category: the
     * admin owns that after create, so a biller moved to another local
     * category is still judged by the feed it came from. A biller with no
     * provider category (made by hand) is never marked missing.
     *
     * @param list<string>        $providerCategories
     * @param array<string, true> $seen
     */
    private function disableMissing(BillPaymentProvider $provider, string $country, array $providerCategories, array $seen, BillerSyncReport $report, bool $dryRun): void
    {
        $types = array_fill_keys(array_map(static fn (string $type): string => strtoupper(trim($type)), $providerCategories), true);

        if ($types === []) {
            return;
        }

        $ids = Biller::query()
            ->where('bill_payment_provider_id', $provider->id)
            ->where('country', $country)
            ->whereNotNull('external_code')
            ->whereNotIn('external_code', array_map('strval', array_keys($seen)))
            ->orderBy('id')
            ->get(['id', 'external_meta'])
            ->filter(static function (Biller $biller) use ($types): bool {
                $type = is_array($biller->external_meta) ? ($biller->external_meta['provider_type'] ?? null) : null;

                return is_string($type) && isset($types[strtoupper(trim($type))]);
            })
            ->pluck('id');

        foreach ($ids as $id) {
            $disabled = $dryRun
                ? $this->markMissing((int) $id, true)
                : DB::transaction(fn (): bool => $this->markMissing((int) $id, false));

            if ($disabled) {
                $report->disabledMissing++;
            }
        }
    }

    private function markMissing(int $id, bool $dryRun): bool
    {
        $query  = Biller::query()->whereKey($id);
        $biller = $dryRun ? $query->first() : $query->lockForUpdate()->first();

        if ($biller === null) {
            return false;
        }

        $meta = is_array($biller->external_meta) ? $biller->external_meta : [];

        if (! $biller->status && is_string($meta['missing_since'] ?? null)) {
            return false;
        }

        $meta['missing_since'] ??= now()->toIso8601String();

        if (! $dryRun) {
            $biller->forceFill(['status' => false, 'external_meta' => $meta])->save();
        }

        return true;
    }

    /**
     * Keep the report on the provider, per country, under a lock so two runs
     * for different countries never lose each other's entry. Only
     * sync_state is written; the admin's config is never touched.
     */
    private function record(BillPaymentProvider $provider, string $country, BillerSyncReport $report, bool $dryRun): BillerSyncReport
    {
        $state = DB::transaction(function () use ($provider, $country, $report, $dryRun): ?array {
            $locked = BillPaymentProvider::query()->lockForUpdate()->find($provider->id);

            if ($locked === null) {
                return null;
            }

            $state           = is_array($locked->sync_state) ? $locked->sync_state : [];
            $state[$country] = [
                'at'      => now()->toIso8601String(),
                'dry_run' => $dryRun,
                'report'  => $report->toArray(),
            ];

            ksort($state);

            $locked->forceFill(['sync_state' => $state])->save();

            return $state;
        });

        if ($state !== null) {
            $provider->forceFill(['sync_state' => $state])->syncOriginalAttribute('sync_state');
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $tally
     */
    private function merge(BillerSyncReport $report, array $tally): void
    {
        $report->created       += $tally['created'];
        $report->updated       += $tally['updated'];
        $report->unchanged     += $tally['unchanged'];
        $report->itemsCreated  += $tally['items_created'];
        $report->itemsUpdated  += $tally['items_updated'];
        $report->itemsDisabled += $tally['items_disabled'];

        foreach ($tally['skipped'] as $reason) {
            $report->skip($reason);
        }

        foreach ($tally['conflicts'] as $conflict) {
            $report->conflict($conflict['external_code'], $conflict['kind']);
        }
    }

    /**
     * Counts kept per biller and added to the report only once its
     * transaction committed, so a retried write is never counted twice.
     *
     * @return array{created: int, updated: int, unchanged: int, items_created: int, items_updated: int, items_disabled: int, skipped: list<string>, conflicts: list<array{external_code: string, kind: string}>}
     */
    private static function emptyTally(): array
    {
        return [
            'created'        => 0,
            'updated'        => 0,
            'unchanged'      => 0,
            'items_created'  => 0,
            'items_updated'  => 0,
            'items_disabled' => 0,
            'skipped'        => [],
            'conflicts'      => [],
        ];
    }

    /**
     * Assign a decimal column only when the value really differs, so an
     * equal amount written with other padding never dirties the row.
     */
    private function setDecimal(Biller $biller, string $column, ?string $value): void
    {
        $current = $biller->getAttribute($column);

        if ($current === null && $value === null) {
            return;
        }

        if ($current !== null && $value !== null && DecimalMoney::compare((string) $current, $value) === 0) {
            return;
        }

        $biller->setAttribute($column, self::stored($value));
    }

    /** A decimal string at the columns' eight places, or null. */
    private static function stored(?string $value): ?string
    {
        return $value === null ? null : DecimalMoney::normalize($value, 8);
    }

    /** How many significant decimals an amount has: 1500.50000000 has one. */
    private static function fractionDigits(string $amount): int
    {
        $fraction = explode('.', trim($amount), 2)[1] ?? '';

        return strlen(rtrim($fraction, '0'));
    }

    private static function text(?string $value, int $max): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }

    /**
     * A key-order-independent form of a meta array, for comparing two of
     * them.
     *
     * @param array<array-key, mixed> $value
     */
    private static function canonical(array $value): string
    {
        $sort = static function (array $array) use (&$sort): array {
            ksort($array);

            foreach ($array as $key => $item) {
                if (is_array($item)) {
                    $array[$key] = $sort($item);
                }
            }

            return $array;
        };

        return (string) json_encode($sort($value));
    }
}
