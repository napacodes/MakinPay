<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The currency a user wants their totals *reported* in.
 *
 * This changes nothing about what is stored or charged. The ledger keeps one
 * functional currency (IAS 21) and every wallet keeps its own — a 0.5 BTC
 * transaction is 0.5 BTC on every screen it appears on. What this column
 * governs is the aggregate figures that have no currency of their own: the
 * dashboard's "total deposits" across four wallets has to be *presented* in
 * something, and until now that something was always the site base, whatever
 * the customer actually held.
 *
 * A code rather than a foreign key: the money layer speaks in codes
 * end to end — `siteCurrency()`, `Money::format($x, $code)`,
 * `CurrencyService::getCurrencyByCode()` — and a code keeps meaning if the row
 * is removed and re-added. Null means "follow the site base", which is what
 * every existing user gets and what the setting resets to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'display_currency')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('display_currency', 10)->nullable()->after('country')
                ->comment('Currency code totals are presented in. Null follows the site base.');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'display_currency')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('display_currency');
        });
    }
};
