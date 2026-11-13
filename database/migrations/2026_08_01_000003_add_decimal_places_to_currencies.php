<?php

use App\Constants\CurrencyType;
use App\Support\CurrencyPrecision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give every currency its own display precision.
 *
 * A single site-wide `site_decimal` cannot serve JPY (ISO minor unit 0), USD
 * (2), KWD (3) and BTC (8) simultaneously, so crypto rendered as "0.00" at the
 * default of 2 while raising the setting made USD render as "$100.00000000".
 *
 * The backfill is deliberately NON-REGRESSIVE. An install currently running
 * site_decimal = 4 renders every fiat amount at 4 places today; backfilling
 * plain ISO would drop those to 2 and look like a regression on upgrade day.
 * Taking max(ISO, site_decimal) means upgrade day is visually a no-op and the
 * only thing that changes is that crypto finally renders correctly.
 *
 * `site_decimal` is intentionally left in place as the fallback for amounts
 * with no currency context.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('currencies')) {
            return;
        }

        if (! Schema::hasColumn('currencies', 'decimal_places')) {
            Schema::table('currencies', function (Blueprint $table): void {
                $table->unsignedTinyInteger('decimal_places')
                    ->default(CurrencyPrecision::DEFAULT_FIAT_SCALE)
                    ->after('type')
                    ->comment('ISO 4217 minor unit for fiat; conventional asset precision for crypto.');
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        if (Schema::hasColumn('currencies', 'decimal_places')) {
            Schema::table('currencies', function (Blueprint $table): void {
                $table->dropColumn('decimal_places');
            });
        }
    }

    private function backfill(): void
    {
        $siteDecimal = CurrencyPrecision::clamp(setting('site_decimal', CurrencyPrecision::DEFAULT_FIAT_SCALE));

        DB::table('currencies')
            ->select(['id', 'code', 'type'])
            ->orderBy('id')
            ->chunkById(200, function ($currencies) use ($siteDecimal): void {
                foreach ($currencies as $currency) {
                    if ($currency->type === CurrencyType::CRYPTO) {
                        $places = CurrencyPrecision::for($currency->code, CurrencyType::CRYPTO);
                    } else {
                        // A currency with an explicit ISO 4217 minor unit takes
                        // it: JPY really is 0 and KWD really is 3, and honouring
                        // site_decimal there would defeat the whole point.
                        // Everything else (the ordinary 2-decimal bulk) keeps
                        // the operator's configured width so an install running
                        // site_decimal = 4 does not visually regress.
                        $places = CurrencyPrecision::iso($currency->code)
                            ?? max(CurrencyPrecision::DEFAULT_FIAT_SCALE, $siteDecimal);
                    }

                    DB::table('currencies')
                        ->where('id', $currency->id)
                        ->update(['decimal_places' => min($places, CurrencyPrecision::MAX_SCALE)]);
                }
            });
    }
};
