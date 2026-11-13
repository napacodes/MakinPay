<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('remittance_quotes', function (Blueprint $table) {
            $table->string('rate_provider', 64)->nullable()->after('mid_market_rate');
            $table->timestamp('rate_fetched_at')->nullable()->after('rate_provider');
        });

        Schema::table('remittance_transfers', function (Blueprint $table) {
            $table->string('provider_idempotency_key', 64)->nullable()->unique()->after('payout_gateway');
            $table->json('recipient_snapshot')->nullable()->after('provider_idempotency_key');
            $table->string('dispatch_state', 32)->default('not_started')->index()->after('recipient_snapshot');
            $table->unsignedSmallInteger('dispatch_attempts')->default(0)->after('dispatch_state');
            $table->timestamp('last_dispatch_at')->nullable()->after('dispatch_attempts');
            $table->foreignId('refund_transaction_id')
                ->nullable()
                ->unique()
                ->after('transaction_id')
                ->constrained('transactions')
                ->restrictOnDelete();

            $table->unique('quote_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('remittance_transfers', function (Blueprint $table) {
            $table->dropForeign(['refund_transaction_id']);
            $table->dropUnique(['refund_transaction_id']);
            $table->dropUnique(['quote_id']);
            $table->dropUnique(['provider_idempotency_key']);
            $table->dropIndex(['dispatch_state']);
            $table->dropColumn([
                'provider_idempotency_key',
                'recipient_snapshot',
                'dispatch_state',
                'dispatch_attempts',
                'last_dispatch_at',
                'refund_transaction_id',
            ]);
        });

        Schema::table('remittance_quotes', function (Blueprint $table) {
            $table->dropColumn(['rate_provider', 'rate_fetched_at']);
        });
    }
};
