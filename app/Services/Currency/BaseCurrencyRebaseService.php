<?php

declare(strict_types=1);

namespace App\Services\Currency;

use App\Data\RebaseReport;
use App\Exceptions\NotifyErrorException;
use App\Models\Currency;
use App\Models\CurrencyRateRevision;
use App\Services\CurrencyService;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Re-express every stored exchange rate against a new base currency.
 *
 * Each currency stores r(x), meaning "1 unit of the base buys r(x) units of x",
 * so r(base) is 1 by construction. Switching the base without rewriting those
 * numbers silently reinterprets every one of them: with a USD base and
 * NGN = 1600, flipping the base to NGN made the system read "1 NGN = 1600 NGN"
 * and every conversion in the platform became wrong at once.
 *
 * Rebasing B -> N is a single division:
 *
 *     r'(x) = r(x) / r(N)      for every currency x
 *     r'(N) = 1                exactly, never a rounded quotient
 *
 * The invariant this preserves is that every cross rate is unchanged —
 * r'(x)/r'(y) == r(x)/r(y) — so no wallet's value relative to any other wallet
 * moves. Only the unit the platform reports in changes.
 *
 * bcdiv at rate scale is used rather than float division because a rate like
 * 0.0000094118 does not survive single-precision arithmetic.
 */
class BaseCurrencyRebaseService
{
    public function __construct(private readonly CurrencyService $currencies) {}

    /**
     * @throws NotifyErrorException when the incoming base has no usable rate
     */
    public function rebaseTo(Currency $newBase, ?int $adminId = null): RebaseReport
    {
        $divisor = DecimalMoney::normalize(
            (string) $newBase->getRawOriginal('exchange_rate'),
            DecimalMoney::RATE_SCALE,
        );

        // A zero or negative divisor would produce infinities, and a missing
        // rate cannot be guessed. Refuse rather than corrupt every rate.
        if (bccomp($divisor, '0', DecimalMoney::RATE_SCALE) <= 0) {
            throw new NotifyErrorException(
                __('Set a valid exchange rate for :code before making it the base currency.', ['code' => $newBase->code])
            );
        }

        $oldBaseCode = (string) (Currency::query()->where('default', true)->value('code') ?? siteCurrency() ?? '');
        $newBaseCode = (string) $newBase->code;
        $batchUuid   = (string) Str::uuid();

        $rates = [];

        DB::transaction(function () use ($newBase, $divisor, $oldBaseCode, $newBaseCode, $batchUuid, $adminId, &$rates): void {
            foreach (Currency::query()->orderBy('id')->get() as $currency) {
                $oldRate = DecimalMoney::normalize(
                    (string) $currency->getRawOriginal('exchange_rate'),
                    DecimalMoney::RATE_SCALE,
                );

                // The new base is pinned to exactly 1 rather than to
                // r(N)/r(N), which rounding could render as 0.999999999999.
                $newRate = $currency->is($newBase)
                    ? DecimalMoney::normalize('1', DecimalMoney::RATE_SCALE)
                    : bcdiv($oldRate, $divisor, DecimalMoney::RATE_SCALE);

                CurrencyRateRevision::query()->create([
                    'batch_uuid'    => $batchUuid,
                    'currency_id'   => $currency->id,
                    'currency_code' => $currency->code,
                    'old_rate'      => $oldRate,
                    'new_rate'      => $newRate,
                    'old_base_code' => $oldBaseCode,
                    'new_base_code' => $newBaseCode,
                    'reason'        => 'base_currency_change',
                    'admin_id'      => $adminId,
                ]);

                // A rebased rate is as freshly established as a fetched one —
                // it is arithmetic on a rate that was already trusted, so it
                // must not inherit the old timestamp and read as stale.
                $currency->forceFill([
                    'exchange_rate'   => $newRate,
                    'rate_updated_at' => now(),
                    'rate_source'     => 'rebase',
                ])->saveQuietly();

                $rates[] = [
                    'code'     => (string) $currency->code,
                    'old_rate' => $oldRate,
                    'new_rate' => $newRate,
                ];
            }

            // Move the default flag inside the same transaction. Rebasing the
            // rates without moving the flag leaves rates expressed in the new
            // base while the app still reports the old one — every conversion
            // then reads wrong. revertBatch() already restored the flag, so
            // leaving it out here made the two halves asymmetric.
            Currency::query()->where('default', true)->update(['default' => false]);
            Currency::query()->whereKey($newBase->getKey())->update(['default' => true]);
        });

        $this->currencies->clearCurrencyCache();

        return new RebaseReport($batchUuid, $oldBaseCode, $newBaseCode, $divisor, $rates);
    }

    /**
     * Restore the rates captured in a batch. The revisions table is what makes
     * an accidental base change recoverable.
     */
    public function revertBatch(string $batchUuid): int
    {
        $revisions = CurrencyRateRevision::query()->where('batch_uuid', $batchUuid)->get();

        if ($revisions->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($revisions): void {
            foreach ($revisions as $revision) {
                Currency::query()
                    ->whereKey($revision->currency_id)
                    ->update(['exchange_rate' => $revision->getRawOriginal('old_rate')]);
            }

            $baseCode = (string) $revisions->first()->old_base_code;

            Currency::query()->update(['default' => false]);
            Currency::query()->where('code', $baseCode)->update(['default' => true]);
        });

        $this->currencies->clearCurrencyCache();

        return $revisions->count();
    }
}
