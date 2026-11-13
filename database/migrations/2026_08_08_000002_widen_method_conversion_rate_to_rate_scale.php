<?php

use App\Support\SchemaColumnDefault;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give payment-method conversion rates the same scale as currency rates.
 *
 * `currencies.exchange_rate` was widened to decimal(36,18) because a rate needs
 * *relative* precision, but `deposit_methods.conversion_rate` and
 * `withdraw_methods.conversion_rate` were widened alongside the money columns
 * and landed at decimal(24,8) — amount scale. They are rates, and they price
 * exactly the same conversion.
 *
 * Eight fractional digits is enough only while the base currency is a
 * high-value one. Against a low-denomination base the smallest representable
 * value swallows the rate whole:
 *
 *     base USD  ->  1 USD = 0.000016 BTC        fits
 *     base NGN  ->  1 NGN = 0.0000000116 BTC    rounds to 0.00000001, ~14% out
 *     base IDR  ->  1 IDR = 0.00000000059 BTC   rounds to 0.00000000, deposit is zero
 *
 * A method priced at zero does not fail; `netAmount = amount x 0` books a
 * deposit of nothing. Widening a DECIMAL is value preserving, so no data
 * migration is needed.
 */
return new class extends Migration
{
    private const TABLES = ['deposit_methods', 'withdraw_methods'];

    public function up(): void
    {
        $this->apply(36, 18);
    }

    /**
     * Narrowing back re-introduces the truncation above. Lossy but
     * non-throwing, so a rollback cannot strand an operator part way through.
     */
    public function down(): void
    {
        $this->apply(24, 8);
    }

    private function apply(int $total, int $places): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'conversion_rate')) {
                continue;
            }

            $current = collect(Schema::getColumns($table))->firstWhere('name', 'conversion_rate');

            if (strtolower((string) ($current['type'] ?? '')) === "decimal({$total},{$places})") {
                continue;
            }

            // Restate the existing nullability and default rather than
            // hardcoding them: ->change() regenerates the whole column
            // definition and silently drops whatever is left out.
            Schema::table($table, function (Blueprint $blueprint) use ($current, $total, $places): void {
                $column = $blueprint->decimal('conversion_rate', $total, $places)
                    ->nullable((bool) ($current['nullable'] ?? false));

                $default = SchemaColumnDefault::normalize($current['default'] ?? null);

                if ($default !== null) {
                    $column->default($default);
                }

                $column->change();
            });
        }
    }
};
