<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Find payment methods priced away from the currency they settle in.
 *
 * The platform holds the same asset's rate in more than one place. `currencies`
 * carries the rate everything is quoted against, and each deposit and withdraw
 * method carries a `conversion_rate` of its own. That override is legitimate —
 * a processor prices its own FX and adds a spread, and forcing every method
 * onto the platform rate would misstate what the customer is actually charged.
 *
 * What is not legitimate is nobody knowing the two disagree. This install had
 * BTC at 0.000016 on the currency, 0.000016 on one BTC method and 0.000015 on
 * another: the same deposit priced 6% apart depending on which button the
 * customer pressed, with nothing anywhere reporting it.
 *
 * So the currency's rate is the authority and a method's is a declared
 * deviation from it. This measures the deviation. Anything inside the tolerance
 * is a spread; anything beyond it is a stale or mistyped rate.
 */
class RateDivergence
{
    /**
     * Processor spreads sit low single digits. Above that a "spread" is not
     * what is being looked at.
     */
    public const DEFAULT_TOLERANCE_PERCENT = 5.0;

    private const SCALE = DecimalMoney::RATE_SCALE;

    /** @var array<string, string> */
    private const METHODS = [
        'deposit_methods'  => 'Deposit',
        'withdraw_methods' => 'Withdraw',
    ];

    /**
     * Methods whose rate is further from the currency's than the tolerance
     * allows, worst first.
     *
     * @return Collection<int, array{table: string, kind: string, name: string, currency: string, method_rate: string, currency_rate: string, percent: string}>
     */
    public function beyondTolerance(?float $tolerancePercent = null): Collection
    {
        $tolerance = DecimalMoney::normalize(
            $tolerancePercent ?? (float) setting('rate_divergence_tolerance_percent', self::DEFAULT_TOLERANCE_PERCENT),
            self::SCALE,
        );

        return $this->all()
            ->filter(fn (array $row): bool => bccomp($row['percent'], $tolerance, self::SCALE) > 0)
            ->sortByDesc(fn (array $row): float => (float) $row['percent'])
            ->values();
    }

    /**
     * Method currencies with no currency record at all.
     *
     * Such a method cannot be used — the deposit path requires the method's
     * currency to match the wallet's — but it stays in the admin list looking
     * available, and its limits cannot be reconciled against anything.
     *
     * @return Collection<int, array{table: string, kind: string, name: string, currency: string}>
     */
    public function unknownCurrencies(): Collection
    {
        $known = $this->currencyRates()->keys();

        return $this->methods()
            ->reject(fn (array $row): bool => $known->contains(strtoupper((string) $row['currency'])))
            ->map(fn (array $row): array => [
                'table'    => $row['table'],
                'id'       => (int) $row['id'],
                'kind'     => $row['kind'],
                'name'     => (string) $row['name'],
                'currency' => strtoupper((string) $row['currency']),
            ])
            ->values();
    }

    /**
     * Every method that can be compared, with its distance from the authority.
     *
     * @return Collection<int, array{table: string, kind: string, name: string, currency: string, method_rate: string, currency_rate: string, percent: string}>
     */
    public function all(): Collection
    {
        $rates = $this->currencyRates();

        return $this->methods()
            ->map(function (array $row) use ($rates): ?array {
                $code         = strtoupper((string) $row['currency']);
                $currencyRate = $rates->get($code);

                if ($currencyRate === null) {
                    return null;
                }

                $methodRate = $this->toRate($row['conversion_rate']);

                // A zero on either side is a broken rate, not a divergent one.
                // RateGuard and the zero-rate audit own that case; measuring a
                // percentage against zero would only divide by it.
                if (bccomp($methodRate, '0', self::SCALE) <= 0 || bccomp($currencyRate, '0', self::SCALE) <= 0) {
                    return null;
                }

                return [
                    'table' => $row['table'],
                    // Carried so a caller can act on the row rather than only
                    // report it: two methods may share a name, and
                    // `money:reconcile-method-rates` must update exactly one.
                    'id'            => (int) $row['id'],
                    'kind'          => $row['kind'],
                    'name'          => (string) $row['name'],
                    'currency'      => $code,
                    'method_rate'   => DecimalMoney::rate($methodRate),
                    'currency_rate' => DecimalMoney::rate($currencyRate),
                    'percent'       => $this->percentApart($methodRate, $currencyRate),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * A raw column value as a decimal string bcmath can parse.
     *
     * These rates are read through the query builder, so they arrive without
     * the `?? '0'` normalization that Currency and the method models apply in
     * their own accessors. `conversion_rate` is NULLABLE on both method
     * tables, and a method saved with live rates enabled leaves it NULL —
     * validation only requires the field when the live toggle is off. NULL
     * casts to an empty string, DecimalMoney rejects it as non-numeric, and
     * the throw took the whole admin dashboard down with a 500 rather than
     * skipping one unpriced method.
     *
     * Zero is what the models already read a missing rate as, and the guard in
     * all() discards it before any percentage is measured against it.
     */
    private function toRate(mixed $value): string
    {
        $string = trim((string) $value);

        return DecimalMoney::normalize(is_numeric($string) ? $string : '0', self::SCALE);
    }

    /**
     * Relative distance, not absolute: rates span twelve orders of magnitude
     * between a BTC rate and an IDR one, so no fixed threshold can serve both.
     */
    private function percentApart(string $methodRate, string $currencyRate): string
    {
        $difference = bcsub($methodRate, $currencyRate, self::SCALE);

        if (str_starts_with($difference, '-')) {
            $difference = substr($difference, 1);
        }

        return bcmul(bcdiv($difference, $currencyRate, self::SCALE), '100', 8);
    }

    /**
     * @return Collection<string, string>
     */
    private function currencyRates(): Collection
    {
        return DB::table('currencies')
            ->select('code', 'exchange_rate')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                strtoupper((string) $row->code) => $this->toRate($row->exchange_rate),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function methods(): Collection
    {
        return collect(self::METHODS)->flatMap(
            fn (string $kind, string $table): array => DB::table($table)
                ->select('id', 'name', 'currency', 'conversion_rate')
                ->where('status', true)
                ->get()
                ->map(fn (object $row): array => (array) $row + ['table' => $table, 'kind' => $kind])
                ->all()
        );
    }
}
