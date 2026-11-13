<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A card is kept in the provider's currency (USD for StroWallet and Bitnob)
 * while the wallet that pays for it may be NGN. Every movement between the
 * two now records the card-side figure and the rate it was priced at, so the
 * ledger can reproduce exactly what was debited and what the card received.
 *
 * Every column is nullable: a same-currency card leaves them empty and
 * behaves exactly as before, and existing rows need no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('virtual_card_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('virtual_card_requests', 'card_currency')) {
                $table->string('card_currency', 10)->nullable()->after('initial_load_amount');
            }

            if (! Schema::hasColumn('virtual_card_requests', 'card_load_amount')) {
                $table->decimal('card_load_amount', 24, 8)->nullable()->after('card_currency');
            }

            if (! Schema::hasColumn('virtual_card_requests', 'load_exchange_rate')) {
                $table->decimal('load_exchange_rate', 28, 12)->nullable()->after('card_load_amount');
            }
        });

        Schema::table('virtual_card_topups', function (Blueprint $table): void {
            if (! Schema::hasColumn('virtual_card_topups', 'card_amount')) {
                $table->decimal('card_amount', 24, 8)->nullable()->after('total_amount');
            }

            if (! Schema::hasColumn('virtual_card_topups', 'card_currency')) {
                $table->string('card_currency', 10)->nullable()->after('currency');
            }

            if (! Schema::hasColumn('virtual_card_topups', 'exchange_rate')) {
                $table->decimal('exchange_rate', 28, 12)->nullable()->after('card_currency');
            }
        });
    }

    public function down(): void
    {
        Schema::table('virtual_card_requests', function (Blueprint $table): void {
            foreach (['card_currency', 'card_load_amount', 'load_exchange_rate'] as $column) {
                if (Schema::hasColumn('virtual_card_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('virtual_card_topups', function (Blueprint $table): void {
            foreach (['card_amount', 'card_currency', 'exchange_rate'] as $column) {
                if (Schema::hasColumn('virtual_card_topups', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
