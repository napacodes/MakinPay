<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Data\RestatementReport;
use App\Models\Currency;
use App\Models\Transaction;
use App\Support\DecimalMoney;
use Illuminate\Database\Eloquent\Builder;

/**
 * Express ledger totals in a currency of the reader's choosing.
 *
 * Under IAS 21 the reporting currency is a presentation choice, not a property
 * of the money itself — the same ledger can be stated in USD for one audience
 * and EUR for another without either being more true. That is only possible
 * because each entry now records the rate that priced it (Phase 9); before
 * that, a total could not be restated at all.
 *
 * Two kinds of figure come out of this, and they are deliberately not summed:
 *
 *  - **exact** — the entry's `base_currency` is already the currency asked
 *    for, so its recorded `base_amount` is used verbatim. No estimation.
 *
 *  - **estimated** — the entry was recorded against a different base, and
 *    translating that base into the requested currency needs a rate for a date
 *    we have no historical rate for. The current rate is used and the result
 *    is reported separately, because presenting it beside an exact figure
 *    would overstate what is known.
 *
 * Entries with no recorded rate are counted but excluded from both totals.
 * They predate the snapshot and cannot be translated; inventing a rate for
 * them would be the same mistake this work has refused everywhere else.
 */
class LedgerRestatement
{
    /**
     * @param callable(Builder): Builder|null $scope Narrow which entries count.
     */
    public function totals(string $presentationCurrency, ?callable $scope = null): RestatementReport
    {
        $presentationCurrency = strtoupper(trim($presentationCurrency));

        $query = Transaction::query()->select([
            'exchange_rate', 'base_amount', 'base_currency',
        ]);

        if ($scope !== null) {
            $query = $scope($query);
        }

        $exact          = '0';
        $estimated      = '0';
        $entries        = 0;
        $untranslatable = 0;

        $rates = $this->rateIndex();

        $query->orderBy('id')->chunk(500, function ($rows) use (
            $presentationCurrency, $rates, &$exact, &$estimated, &$entries, &$untranslatable
        ): void {
            foreach ($rows as $row) {
                $amount = $row->getRawOriginal('base_amount');
                $base   = strtoupper(trim((string) $row->base_currency));

                if ($row->getRawOriginal('exchange_rate') === null || $amount === null || $base === '') {
                    $untranslatable++;

                    continue;
                }

                $amount = DecimalMoney::normalize($amount);

                if ($base === $presentationCurrency) {
                    $exact = bcadd($exact, $amount, 8);
                    $entries++;

                    continue;
                }

                $converted = $this->translate($amount, $base, $presentationCurrency, $rates);

                if ($converted === null) {
                    $untranslatable++;

                    continue;
                }

                $estimated = bcadd($estimated, $converted, 8);
                $entries++;
            }
        });

        return new RestatementReport(
            presentationCurrency: $presentationCurrency,
            exact: $exact,
            estimated: $estimated,
            entries: $entries,
            untranslatable: $untranslatable,
        );
    }

    /**
     * Cross a base-currency amount into the presentation currency.
     *
     * Both rates are quoted against the platform's current base, so the pair
     * is `rate(to) / rate(from)` — the same relation the exchange flow uses,
     * and the reason a rebase preserves every cross-rate.
     *
     * @param array<string, string> $rates
     */
    private function translate(string $amount, string $from, string $to, array $rates): ?string
    {
        $fromRate = $rates[$from] ?? null;
        $toRate   = $rates[$to]   ?? null;

        if ($fromRate === null || $toRate === null) {
            return null;
        }

        if (bccomp($fromRate, '0', DecimalMoney::RATE_SCALE) <= 0) {
            return null;
        }

        $pair = bcdiv($toRate, $fromRate, DecimalMoney::RATE_SCALE);

        return DecimalMoney::round(bcmul($amount, $pair, 10), 8);
    }

    /**
     * Current rates by currency code, as exact decimal strings.
     *
     * @return array<string, string>
     */
    private function rateIndex(): array
    {
        return Currency::query()
            ->get(['code', 'exchange_rate'])
            ->mapWithKeys(fn (Currency $currency): array => [
                strtoupper((string) $currency->code) => DecimalMoney::rate(
                    $currency->getRawOriginal('exchange_rate') ?? 0
                ),
            ])
            ->all();
    }
}
