<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * BP1: a bill payment provider's circuit breaker and its last known balance.
 *
 *   paused_at       set when a payment is refused for the provider account's
 *                   own fault (its balance, its credentials); no new payment
 *                   is taken for its billers while it is set. Cleared by a
 *                   passing connection test.
 *   pause_reason    which fault (App\Enums\BillPayment\ProviderFault).
 *   pause_message   what the provider said, for the provider screen.
 *   balance_amount, balance_currency, balance_checked_at
 *                   the balance the last passing connection test read.
 *
 * All nullable, so an upgrade changes nothing until a fault happens.
 * Guarded so a second run is harmless.
 */
return new class extends Migration
{
    private const TABLE = 'bill_payment_providers';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'paused_at')) {
                $table->timestamp('paused_at')->nullable();
            }

            if (! Schema::hasColumn(self::TABLE, 'pause_reason')) {
                $table->string('pause_reason', 32)->nullable();
            }

            if (! Schema::hasColumn(self::TABLE, 'pause_message')) {
                $table->string('pause_message', 255)->nullable();
            }

            if (! Schema::hasColumn(self::TABLE, 'balance_amount')) {
                $table->decimal('balance_amount', 24, 8)->nullable();
            }

            if (! Schema::hasColumn(self::TABLE, 'balance_currency')) {
                $table->string('balance_currency', 8)->nullable();
            }

            if (! Schema::hasColumn(self::TABLE, 'balance_checked_at')) {
                $table->timestamp('balance_checked_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            foreach (['balance_checked_at', 'balance_currency', 'balance_amount', 'pause_message', 'pause_reason', 'paused_at'] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
