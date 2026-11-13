<?php

use App\Services\CurrencyService;
use App\Support\DecimalMoney;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Repair exchange rates mangled by the old single-precision FLOAT column.
 *
 * `currencies.exchange_rate` used to be a MySQL FLOAT, which holds only ~7
 * significant digits. An admin who typed 0.85 actually got 0.85000002384185791
 * stored. Widening the column to decimal(28,12) was value preserving, so it
 * faithfully carried that noise across — and because rates are now displayed at
 * full precision instead of being rounded to 2 places, the corruption became
 * visible as "1 USD = 0.850000023842 EUR".
 *
 * Only values that are exactly representable as a float32 are touched: that is
 * the signature of having passed through the old column. They are rounded back
 * to 7 significant digits, which is the most precision a float32 could ever
 * have carried, so this recovers the number the admin typed and cannot
 * invent precision.
 *
 * Rates entered after this release are exact decimals and are left alone.
 */
return new class extends Migration
{
    /** Float32 carries ~7.2 decimal digits; anything beyond that was noise. */
    private const SIGNIFICANT_DIGITS = 7;

    public function up(): void
    {
        if (! Schema::hasTable('currencies')) {
            return;
        }

        $repaired = [];

        DB::table('currencies')->select(['id', 'code', 'exchange_rate'])->orderBy('id')
            ->chunkById(200, function ($rows) use (&$repaired): void {
                foreach ($rows as $row) {
                    $stored = (float) $row->exchange_rate;

                    if ($stored <= 0) {
                        continue;
                    }

                    // Round through the *decimal string*, never back through a
                    // float: normalising a PHP float would simply swap float32
                    // noise for float64 noise (0.85 -> 0.849999999999999978).
                    $clean = $this->toPlainDecimal(
                        sprintf('%.'.self::SIGNIFICANT_DIGITS.'G', $stored)
                    );

                    if ($clean === '' || (float) $clean <= 0) {
                        continue;
                    }

                    $normalized = DecimalMoney::normalize($clean, DecimalMoney::RATE_SCALE);

                    // Idempotent: once a row is clean the strings match.
                    if ($normalized === DecimalMoney::normalize((string) $row->exchange_rate, DecimalMoney::RATE_SCALE)) {
                        continue;
                    }

                    // Only snap away noise, never a real difference.
                    if (abs($stored - (float) $clean) / abs((float) $clean) > 1.0e-6) {
                        continue;
                    }

                    DB::table('currencies')->where('id', $row->id)->update([
                        'exchange_rate' => $normalized,
                    ]);

                    $repaired[] = sprintf('%s: %s -> %s', $row->code, $row->exchange_rate, $clean);
                }
            });

        if ($repaired !== []) {
            Log::info('Repaired float32 exchange rate artifacts.', ['currencies' => $repaired]);
        }

        app(CurrencyService::class)->clearCurrencyCache();
    }

    /**
     * Reintroducing the noise would be pointless, so this is a no-op. The
     * repaired values are the ones the admin originally entered.
     */
    public function down(): void
    {
        // Intentionally empty.
    }

    /**
     * %G switches to scientific notation for small magnitudes, and bcmath
     * cannot read that — a crypto rate like 9.4118E-6 would be lost.
     */
    private function toPlainDecimal(string $value): string
    {
        if (stripos($value, 'e') === false) {
            return $value;
        }

        [$mantissa, $exponent] = preg_split('/e/i', $value);
        $exponent              = (int) $exponent;
        $power                 = bcpow('10', (string) abs($exponent), 0);

        return $exponent >= 0
            ? bcmul($mantissa, $power, DecimalMoney::RATE_SCALE)
            : bcdiv($mantissa, $power, DecimalMoney::RATE_SCALE);
    }
};
