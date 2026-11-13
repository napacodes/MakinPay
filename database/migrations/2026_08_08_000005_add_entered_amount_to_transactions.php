<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record the figure the customer actually typed.
 *
 * Every money form used to take its amount in the platform's base currency, so
 * a BTC wallet holder had to work out that 0.5 BTC was "31250" and type that.
 * The forms now take the wallet's own currency, and the controller converts to
 * base at the entry point — which keeps `amount` base-denominated and leaves
 * the ledger contract, the FX snapshot and every API consumer reading `amount`
 * exactly as they were.
 *
 * The conversion loses one thing worth keeping: what the customer saw. A
 * receipt, a dispute or a support ticket needs to show "0.5 BTC", not the
 * base figure it became. These columns hold that, and nothing computes from
 * them — they are a record of the input, not a second source of truth.
 *
 * NULL means the row predates the change, or was written by a flow that has no
 * customer-entered amount at all (a reward, a referral bonus, an admin
 * adjustment). Readers fall back to `amount` / `currency`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transactions') || Schema::hasColumn('transactions', 'entered_amount')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table): void {
            $table->decimal('entered_amount', 24, 8)->nullable()->after('wallet_fee')
                ->comment('The amount the customer typed, in entered_currency. Display only.');

            $table->string('entered_currency', 16)->nullable()->after('entered_amount')
                ->comment('The currency entered_amount is denominated in; normally the wallet currency.');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('transactions') || ! Schema::hasColumn('transactions', 'entered_amount')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['entered_amount', 'entered_currency']);
        });
    }
};
